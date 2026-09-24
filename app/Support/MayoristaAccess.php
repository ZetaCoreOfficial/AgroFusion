<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\PedidoDistribucion;
use App\Models\RutaDistribucion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership de almacenes mayoristas.
 *
 * Única definición de «almacén propio» (visibilidad y operación usan la misma):
 * ámbito mayorista Y (responsable_usuarioid = usuario
 * O almacén asignado en usuario.almacenid sin otro responsable).
 * Antes el listado usaba solo el responsable y otras vistas sumaban usuario.almacenid
 * sin filtrar ámbito ni responsable, por lo que un almacén aparecía en unas vistas y no en otras.
 */
final class MayoristaAccess
{
    /** Visibilidad (no operación): el admin supervisa todos; el mayorista solo los propios. */
    public static function puedeVerAlmacen(?Usuario $user, Almacen $almacen): bool
    {
        if (! $user || AlmacenAmbito::resolverAmbito($almacen) !== AlmacenAmbito::MAYORISTA) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        return UsuarioRol::esMayorista($user)
            && in_array((int) $almacen->almacenid, self::idsAlmacenesMayorista($user), true);
    }

    public static function puedeGestionarAlmacen(?Usuario $user, Almacen $almacen): bool
    {
        if (! $user) {
            return false;
        }

        // El admin supervisa almacenes mayoristas pero no los opera.
        if (! UsuarioRol::puedeOperar($user) || ! UsuarioRol::esMayorista($user)) {
            return false;
        }

        if (AlmacenAmbito::resolverAmbito($almacen) !== AlmacenAmbito::MAYORISTA) {
            return false;
        }

        return in_array((int) $almacen->almacenid, self::idsAlmacenesMayorista($user), true);
    }

    public static function puedeGestionarRutaDistribucion(?Usuario $user, RutaDistribucion $ruta): bool
    {
        if (RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)) {
            return false;
        }

        $ruta->loadMissing('almacenOrigen');
        $almacen = $ruta->almacenOrigen;

        return $almacen !== null && self::puedeGestionarAlmacen($user, $almacen);
    }

    public static function puedeGestionarTraslado(?Usuario $user, RutaDistribucion $ruta): bool
    {
        if (! RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)) {
            return false;
        }

        $ruta->loadMissing('almacenMayoristaDestino');
        $almacen = $ruta->almacenMayoristaDestino;

        if ($almacen === null) {
            return false;
        }

        return self::puedeGestionarAlmacen($user, $almacen);
    }

    public static function scopeAlmacenesMayorista(Builder $query, ?Usuario $user): Builder
    {
        $query = AlmacenAmbito::scope($query, AlmacenAmbito::MAYORISTA);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        if (! UsuarioRol::esMayorista($user)) {
            return $query->whereRaw('1 = 0');
        }

        if (! Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return $query;
        }

        $usuarioId = (int) $user->usuarioid;
        $almacenAsignado = (int) ($user->almacenid ?? 0);

        return $query->where(function (Builder $q) use ($usuarioId, $almacenAsignado) {
            $q->where('responsable_usuarioid', $usuarioId);

            if ($almacenAsignado > 0) {
                $q->orWhere(function (Builder $legacy) use ($almacenAsignado) {
                    $legacy->where('almacenid', $almacenAsignado)
                        ->whereNull('responsable_usuarioid');
                });
            }
        });
    }

    /** @return list<int> */
    public static function idsAlmacenesMayorista(?Usuario $user): array
    {
        return self::scopeAlmacenesMayorista(Almacen::query(), $user)
            ->pluck('almacenid')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Almacenes mayoristas que el usuario opera (misma regla que el listado).
     * El admin recibe todos los activos solo como alcance de consultas de supervisión.
     *
     * @return list<int>
     */
    public static function idsAlmacenesOperados(?Usuario $user): array
    {
        if (! $user) {
            return [];
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return AlmacenAmbito::scope(Almacen::query()->where('activo', true), AlmacenAmbito::MAYORISTA)
                ->pluck('almacenid')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return self::idsAlmacenesMayorista($user);
    }

    /** Mayorista activo responsable del almacén (quien lo opera y recibe), o null si no tiene dueño válido. */
    public static function responsableMayorista(Almacen $almacen): ?Usuario
    {
        if (! Schema::hasColumn('almacen', 'responsable_usuarioid') || ! $almacen->responsable_usuarioid) {
            return null;
        }

        $responsable = Usuario::query()->find((int) $almacen->responsable_usuarioid);

        if ($responsable === null || ! $responsable->activo || ! UsuarioRol::esMayorista($responsable)) {
            return null;
        }

        return $responsable;
    }

    public static function puedeVerPedidoDistribucion(?Usuario $user, PedidoDistribucion $pedido): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (! UsuarioRol::puedeGestionarDistribucionMayorista($user)) {
            return false;
        }

        // Un pedido sin almacén de origen no queda abierto a «cualquier mayorista» (MAY-11).
        $almacenId = (int) $pedido->almacen_mayorista_origenid;

        return $almacenId > 0 && in_array($almacenId, self::idsAlmacenesOperados($user), true);
    }

    public static function asegurarPuedeVerPedido(?Usuario $user, PedidoDistribucion $pedido): void
    {
        if (! self::puedeVerPedidoDistribucion($user, $pedido)) {
            abort(403, 'Este pedido no está dirigido a su almacén mayorista.');
        }
    }

    public static function asegurarPuedeVer(?Usuario $user, Almacen $almacen): void
    {
        if (! self::puedeVerAlmacen($user, $almacen)) {
            abort(403, 'No tiene acceso a este almacén mayorista.');
        }
    }

    public static function asegurarPuedeGestionar(?Usuario $user, Almacen $almacen): void
    {
        if (! self::puedeGestionarAlmacen($user, $almacen)) {
            abort(403, 'No tiene permiso para gestionar este almacén mayorista.');
        }
    }
}
