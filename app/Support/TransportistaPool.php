<?php

namespace App\Support;

use App\Models\EnvioAsignacionMultiple;
use App\Models\RutaDistribucion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Pool único de conductores asignables (MAY-17, TRA-04, TRA-06, TRA-09, TRA-10).
 *
 * Un conductor entra al pool de un trayecto si:
 *   - está activo y tiene el rol Spatie canónico «transportista» (la columna legacy no basta),
 *   - su perfil tiene el ámbito de flota explícito del trayecto (agrícola, planta, mayorista),
 *   - su perfil está disponible (el flag `disponible` ahora sí excluye del pool),
 *   - no tiene un viaje en curso en ningún trayecto.
 * La licencia frente al vehículo la sigue validando TransporteCapacidadService (una sola lógica).
 * «Conductor» y «chofer» son solo textos de pantalla: el rol es siempre `transportista` (TRA-16).
 */
final class TransportistaPool
{
    public static function motivoNoAsignable(?Usuario $conductor, ?string $ambito): ?string
    {
        if ($conductor === null || ! $conductor->activo) {
            return 'El transportista seleccionado no está activo.';
        }

        if (! UsuarioRol::esTransportista($conductor)) {
            return 'El usuario seleccionado no tiene el rol de transportista.';
        }

        $perfil = $conductor->perfilTransportista()->first();
        if ($perfil === null) {
            return 'El transportista aún no tiene perfil de flota configurado.';
        }

        if ($ambito !== null && $perfil->ambito_flota !== $ambito) {
            return 'Seleccione un transportista de flota '.mb_strtolower(TransportistaFlotaCatalogo::categoriaCorta($ambito)).'.';
        }

        if ($perfil->disponible === false) {
            return 'El transportista está marcado como no disponible.';
        }

        $enCurso = ViajeAcceso::viajeEnCursoDelConductor((int) $conductor->usuarioid);
        if ($enCurso !== null) {
            return "El transportista tiene un viaje en curso ({$enCurso}).";
        }

        return null;
    }

    public static function asegurarAsignable(?Usuario $conductor, ?string $ambito): void
    {
        $motivo = self::motivoNoAsignable($conductor, $ambito);
        if ($motivo !== null) {
            throw new InvalidArgumentException($motivo);
        }
    }

    /** Filtro para selectores: mismos criterios que asegurarAsignable (salvo licencia/vehículo). */
    public static function scopePool(Builder $query, ?string $ambito): Builder
    {
        $enCursoRutas = RutaDistribucion::query()
            ->where('estado', RutaDistribucionCatalogo::ESTADO_EN_RUTA)
            ->whereNotNull('transportista_usuarioid')
            ->select('transportista_usuarioid');

        $enCursoEnvios = EnvioAsignacionMultiple::query()
            ->whereNotNull('simulacion_inicio_at')
            ->whereNull('fecha_recepcion_planta')
            ->whereNotIn('estado', ['recibido_planta', 'entregado', 'entregada'])
            ->whereNotNull('transportista_usuarioid')
            ->select('transportista_usuarioid');

        return $query
            ->where('activo', true)
            ->whereHas('roles', fn (Builder $r) => $r->where('name', 'transportista'))
            ->whereHas('perfilTransportista', function (Builder $p) use ($ambito) {
                $p->where(fn (Builder $d) => $d->where('disponible', true)->orWhereNull('disponible'));
                if ($ambito !== null) {
                    $p->where('ambito_flota', $ambito);
                }
            })
            ->whereNotIn('usuarioid', $enCursoRutas)
            ->whereNotIn('usuarioid', $enCursoEnvios);
    }
}
