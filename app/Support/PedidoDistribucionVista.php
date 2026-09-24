<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Http\Request;

final class PedidoDistribucionVista
{
    /** Bandeja mayorista (sin crear pedidos). */
    public static function esBandejaMayorista(Request $request, ?Usuario $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (UsuarioRol::esMinorista($user) && ! UsuarioRol::esAdminGlobal($user)) {
            return false;
        }

        if ($request->query('ctx') === 'pdv') {
            return false;
        }

        if ($request->query('ctx') === 'mayorista') {
            return UsuarioRol::esAdminGlobal($user) || UsuarioRol::puedeGestionarDistribucionMayorista($user);
        }

        return UsuarioRol::esMayorista($user) && ! UsuarioRol::esMinorista($user);
    }

    public static function puedeCrearSolicitud(Request $request, ?Usuario $user): bool
    {
        return EnvioTrayectoCatalogo::puedeRegistrarStorePdv($request, $user);
    }
}
