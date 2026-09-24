<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\RutaDistribucion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class PlantaAccess
{
    public static function puedeAprobarTraslado(?Usuario $user, RutaDistribucion $ruta): bool
    {
        if (! $user || ! RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)) {
            return false;
        }

        if (! UsuarioRol::puedeOperar($user)) {
            return false;
        }

        if (! UsuarioRol::esJefePlanta($user)
            && ! ($user->can('panel_planta.view') && $user->can('pedidos.update'))) {
            return false;
        }

        $ruta->loadMissing('almacenPlantaOrigen');
        $origen = $ruta->almacenPlantaOrigen;
        if ($origen === null) {
            return UsuarioRol::esJefePlanta($user);
        }

        return self::puedeGestionarAlmacen($user, $origen);
    }

    /** @return list<int> */
    public static function idsAlmacenesPlanta(?Usuario $user): array
    {
        if (! $user) {
            return [];
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return AlmacenAmbito::scope(Almacen::query(), AlmacenAmbito::PLANTA)
                ->pluck('almacenid')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if (! Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return [];
        }

        $responsableIds = [];

        if (UsuarioRol::esJefePlanta($user)) {
            $responsableIds[] = (int) $user->usuarioid;
        } elseif (UsuarioRol::esOperarioPlanta($user) && $user->supervisor_usuarioid) {
            $responsableIds[] = (int) $user->supervisor_usuarioid;
        }

        if ($responsableIds === []) {
            return [];
        }

        return AlmacenAmbito::scope(Almacen::query(), AlmacenAmbito::PLANTA)
            ->whereIn('responsable_usuarioid', $responsableIds)
            ->pluck('almacenid')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public static function puedeVerAlmacen(?Usuario $user, Almacen $almacen): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (($almacen->ambito ?? '') !== AlmacenAmbito::PLANTA) {
            return false;
        }

        $ids = self::idsAlmacenesPlanta($user);

        return in_array((int) $almacen->almacenid, $ids, true);
    }

    public static function puedeGestionarAlmacen(?Usuario $user, Almacen $almacen): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (! UsuarioRol::esJefePlanta($user)) {
            return false;
        }

        return self::puedeVerAlmacen($user, $almacen);
    }

    /**
     * @param  Builder<\App\Models\Almacen>  $query
     * @return Builder<\App\Models\Almacen>
     */
    public static function scopeAlmacenesPlanta(Builder $query, ?Usuario $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return AlmacenAmbito::scope($query, AlmacenAmbito::PLANTA);
        }

        $ids = self::idsAlmacenesPlanta($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return AlmacenAmbito::scope($query, AlmacenAmbito::PLANTA)
            ->whereIn('almacenid', $ids);
    }

    /** Operarios planta del equipo del jefe (supervisor_usuarioid). */
    public static function queryOperariosAsignables(?Usuario $jefe): Builder
    {
        $query = UsuarioRol::queryOperariosPlanta();

        if (! $jefe || UsuarioRol::esAdminGlobal($jefe)) {
            return $query;
        }

        if (! UsuarioRol::esJefePlanta($jefe)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('supervisor_usuarioid', (int) $jefe->usuarioid);
    }

    public static function puedeAsignarOperario(?Usuario $jefe, Usuario $operario): bool
    {
        if (! $jefe || ! UsuarioRol::esOperarioPlanta($operario)) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($jefe)) {
            return true;
        }

        if (! UsuarioRol::esJefePlanta($jefe)) {
            return false;
        }

        return (int) ($operario->supervisor_usuarioid ?? 0) === (int) $jefe->usuarioid;
    }
}
