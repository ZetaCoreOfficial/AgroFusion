<?php

namespace App\Support;

use App\Models\EnvioAsignacionMultiple;
use App\Models\IncidenteEnvio;
use App\Models\RutaDistribucion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ownership de viajes (los tres trayectos) e incidentes.
 *
 * Para el conductor la regla es transportista_usuarioid == usuario: tener `asignaciones.view`
 * o `incidentes.*` no alcanza para ver u operar viajes ajenos (TRA-02, TRA-07).
 */
final class ViajeAcceso
{
    /** Conductor operativo: su visibilidad se acota siempre a sus propios viajes. */
    public static function esConductor(?Usuario $user): bool
    {
        return $user !== null && UsuarioRol::esTransportista($user) && ! UsuarioRol::esAdminGlobal($user);
    }

    public static function esConductorAsignado(?Usuario $user, int|string|null $transportistaId): bool
    {
        return $user !== null
            && UsuarioRol::puedeOperar($user)
            && UsuarioRol::esTransportista($user)
            && (int) $transportistaId > 0
            && (int) $transportistaId === (int) $user->usuarioid;
    }

    /** Envío Agricultura → Planta. */
    public static function puedeVerEnvioAgricola(?Usuario $user, EnvioAsignacionMultiple $envio): bool
    {
        if ($user === null) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (self::esConductor($user)) {
            return (int) $envio->transportista_usuarioid === (int) $user->usuarioid;
        }

        // Comercial (mayorista/minorista) no participa del trayecto agrícola.
        if (UsuarioRol::esMayorista($user) || UsuarioRol::esMinorista($user)) {
            return false;
        }

        return $user->can('asignaciones.view');
    }

    /** Planta → Mayorista y Mayorista → PDV. */
    public static function puedeVerRutaDistribucion(?Usuario $user, RutaDistribucion $ruta): bool
    {
        if ($user === null) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (self::esConductor($user)) {
            return (int) $ruta->transportista_usuarioid === (int) $user->usuarioid;
        }

        if (RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)) {
            return MayoristaAccess::puedeGestionarTraslado($user, $ruta)
                || UsuarioRol::esPlantaOperativo($user);
        }

        return MayoristaAccess::puedeGestionarRutaDistribucion($user, $ruta)
            || PuntoVentaAccess::puedeFirmarRecepcionRuta($user, $ruta);
    }

    public static function scopeEnviosAgricolas(Builder $query, ?Usuario $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        if (self::esConductor($user)) {
            return $query->where('transportista_usuarioid', $user->usuarioid);
        }

        if (UsuarioRol::esMayorista($user) || UsuarioRol::esMinorista($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /** Códigos de viaje (envío agrícola y rutas) y pedidos agrícolas del conductor. */
    public static function scopeIncidentes(Builder $query, ?Usuario $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esAdminGlobal($user) || ! self::esConductor($user)) {
            return $query;
        }

        [$codigos, $pedidos] = self::viajesDelConductor($user);

        return $query->where(function (Builder $q) use ($user, $codigos, $pedidos) {
            $q->where('reportadopor_usuarioid', $user->usuarioid)
                ->orWhereIn('externo_envio_id', $codigos ?: ['__sin_viajes__'])
                ->orWhereIn('pedidoid', $pedidos ?: [-1]);
        });
    }

    public static function puedeVerIncidente(?Usuario $user, IncidenteEnvio $incidente): bool
    {
        return self::scopeIncidentes(IncidenteEnvio::query(), $user)
            ->whereKey($incidente->incidenteenvioid)
            ->exists();
    }

    /** El conductor solo modifica incidentes que él reportó; nunca elimina ni edita globalmente. */
    public static function puedeModificarIncidente(?Usuario $user, IncidenteEnvio $incidente): bool
    {
        if (! UsuarioRol::puedeOperar($user)) {
            return false;
        }

        if (self::esConductor($user)) {
            return (int) $incidente->reportadopor_usuarioid === (int) $user->usuarioid;
        }

        return true;
    }

    /** Un incidente nuevo del conductor debe referirse a uno de sus viajes. */
    public static function conductorPuedeReportarEn(Usuario $user, ?string $externoEnvioId, ?int $pedidoId): bool
    {
        if (! self::esConductor($user)) {
            return true;
        }

        [$codigos, $pedidos] = self::viajesDelConductor($user);
        $codigo = trim((string) $externoEnvioId);

        if ($codigo === '' && ! $pedidoId) {
            return false;
        }

        return ($codigo === '' || in_array($codigo, $codigos, true))
            && (! $pedidoId || in_array((int) $pedidoId, $pedidos, true));
    }

    /**
     * Código del viaje EN CURSO del conductor en cualquiera de los tres trayectos, o null.
     * Un conductor no puede tener dos viajes en ruta a la vez (TRA-06).
     */
    public static function viajeEnCursoDelConductor(
        int $conductorId,
        ?EnvioAsignacionMultiple $excluirEnvio = null,
        ?RutaDistribucion $excluirRuta = null,
    ): ?string {
        if ($conductorId <= 0) {
            return null;
        }

        $envio = EnvioAsignacionMultiple::query()
            ->where('transportista_usuarioid', $conductorId)
            ->whereNotNull('simulacion_inicio_at')
            ->whereNull('fecha_recepcion_planta')
            ->whereNotIn('estado', ['recibido_planta', 'entregado', 'entregada'])
            ->when($excluirEnvio, fn (Builder $q) => $q->whereKeyNot($excluirEnvio->envioasignacionmultipleid))
            ->first();
        if ($envio !== null) {
            return (string) ($envio->externo_envio_id ?: 'Envío #'.$envio->envioasignacionmultipleid);
        }

        $ruta = RutaDistribucion::query()
            ->where('transportista_usuarioid', $conductorId)
            ->where('estado', RutaDistribucionCatalogo::ESTADO_EN_RUTA)
            ->when($excluirRuta, fn (Builder $q) => $q->whereKeyNot($excluirRuta->rutadistribucionid))
            ->first();

        return $ruta !== null ? (string) ($ruta->codigo ?: 'Ruta #'.$ruta->rutadistribucionid) : null;
    }

    /** @return array{0: list<string>, 1: list<int>} */
    private static function viajesDelConductor(Usuario $user): array
    {
        $envios = EnvioAsignacionMultiple::query()
            ->where('transportista_usuarioid', $user->usuarioid)
            ->get(['externo_envio_id', 'pedidoid']);

        $codigosRutas = RutaDistribucion::query()
            ->where('transportista_usuarioid', $user->usuarioid)
            ->whereNotNull('codigo')
            ->pluck('codigo');

        $codigos = $envios->pluck('externo_envio_id')
            ->merge($codigosRutas)
            ->filter()
            ->map(fn ($c) => (string) $c)
            ->unique()
            ->values()
            ->all();

        $pedidos = $envios->pluck('pedidoid')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [$codigos, $pedidos];
    }
}
