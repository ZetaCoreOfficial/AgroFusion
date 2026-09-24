<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance de almacenes agrícolas (jefe vs operario).
 * El operario solo consulta almacenes de su equipo; no administra.
 */
final class CampoAccess
{
    /** @return list<int> */
    public static function idsResponsablesVisibles(?Usuario $user): array
    {
        if (! $user) {
            return [];
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return [];
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            return [(int) $user->usuarioid];
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            $ids = [(int) $user->usuarioid];
            if ($user->supervisor_usuarioid) {
                $ids[] = (int) $user->supervisor_usuarioid;
            }

            return array_values(array_unique($ids));
        }

        return [];
    }

    public static function puedeVerAlmacen(?Usuario $user, Almacen $almacen): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (($almacen->ambito ?? '') !== AlmacenAmbito::AGRICOLA) {
            return false;
        }

        if (! Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return UsuarioRol::gestionaCampo($user);
        }

        $ids = self::idsResponsablesVisibles($user);
        if ($ids === []) {
            return false;
        }

        return in_array((int) ($almacen->responsable_usuarioid ?? 0), $ids, true);
    }

    public static function puedeGestionarAlmacen(?Usuario $user, Almacen $almacen): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (! UsuarioRol::esJefeAgricultor($user)) {
            return false;
        }

        return self::puedeVerAlmacen($user, $almacen)
            && (int) ($almacen->responsable_usuarioid ?? 0) === (int) $user->usuarioid;
    }

    /**
     * @param  Builder<\App\Models\Almacen>  $query
     * @return Builder<\App\Models\Almacen>
     */
    public static function scopeAlmacenesAgricolas(Builder $query, ?Usuario $user): Builder
    {
        $query = AlmacenAmbito::scope($query, AlmacenAmbito::AGRICOLA);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        if (! Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return $query->whereRaw('1 = 0');
        }

        $ids = self::idsResponsablesVisibles($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('responsable_usuarioid', $ids);
    }
}
