<?php

namespace App\Support;

use App\Models\RutaDistribucion;
use App\Models\Usuario;

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

        return UsuarioRol::esJefePlanta($user)
            || ($user->can('panel_planta.view') && $user->can('pedidos.update'));
    }
}
