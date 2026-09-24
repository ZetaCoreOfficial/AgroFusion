<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ownership de almacenes para endpoints que no pasan por un módulo de ámbito (API).
 *
 * AUTH + PERMISO los resuelve la ruta; aquí se aplica la tercera capa, OWNERSHIP:
 * cada ámbito que el usuario puede ver se acota con la misma regla que su módulo web
 * (mayorista → solo sus almacenes, punto de venta → solo los de sus PDV).
 */
final class AlmacenAcceso
{
    /** @return list<int> */
    public static function idsVisibles(?Usuario $user): array
    {
        if ($user === null) {
            return [];
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return Almacen::query()->pluck('almacenid')->map(fn ($id) => (int) $id)->values()->all();
        }

        $ids = [];
        foreach ([AlmacenAmbito::AGRICOLA, AlmacenAmbito::PLANTA, AlmacenAmbito::MAYORISTA, AlmacenAmbito::PUNTO_VENTA] as $ambito) {
            if (! AlmacenAmbito::usuarioPuedeVer($user, $ambito)) {
                continue;
            }

            $ids = array_merge($ids, self::scopeAmbito(Almacen::query(), $ambito, $user)->pluck('almacenid')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function scopeVisibles(Builder $query, ?Usuario $user, string $columna = 'almacenid'): Builder
    {
        if ($user !== null && UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        return $query->whereIn($columna, self::idsVisibles($user) ?: [-1]);
    }

    public static function puedeVer(?Usuario $user, Almacen $almacen): bool
    {
        return in_array((int) $almacen->almacenid, self::idsVisibles($user), true);
    }

    /** Operar (escribir) sobre el almacén: actor operativo, almacén visible y, si es mayorista/PDV, propio. */
    public static function puedeGestionar(?Usuario $user, Almacen $almacen): bool
    {
        if (! UsuarioRol::puedeOperar($user) || ! self::puedeVer($user, $almacen)) {
            return false;
        }

        return match (AlmacenAmbito::resolverAmbito($almacen)) {
            AlmacenAmbito::MAYORISTA => MayoristaAccess::puedeGestionarAlmacen($user, $almacen),
            AlmacenAmbito::PUNTO_VENTA => UsuarioRol::esMinorista($user),
            default => true,
        };
    }

    public static function asegurarPuedeGestionar(?Usuario $user, Almacen $almacen): void
    {
        abort_unless(self::puedeGestionar($user, $almacen), 403, 'No tiene permiso para operar este almacén.');
    }

    private static function scopeAmbito(Builder $query, string $ambito, Usuario $user): Builder
    {
        if ($ambito === AlmacenAmbito::PUNTO_VENTA) {
            $idsPdv = PuntoVentaAccess::scopePuntosDelUsuario(PuntoVenta::query(), $user)
                ->whereNotNull('almacenid')
                ->pluck('almacenid')
                ->all();

            return $query->whereIn('almacenid', $idsPdv ?: [-1]);
        }

        return AlmacenAmbito::scopeParaUsuario($query, $ambito, $user);
    }
}
