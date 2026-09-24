<?php

namespace App\Support;

use App\Models\PedidoDistribucion;
use App\Models\PuntoVenta;
use App\Models\RutaDistribucion;
use App\Models\Usuario;
use App\Support\MayoristaAccess;

final class PuntoVentaAccess
{
    public static function puedeVerPunto(?Usuario $user, PuntoVenta $puntoVenta): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        return UsuarioRol::esMinorista($user)
            && (int) $puntoVenta->usuarioid === (int) $user->usuarioid;
    }

    /** Solo el minorista dueño edita su punto de venta; el admin únicamente lo consulta. */
    public static function puedeEditarPunto(?Usuario $user, PuntoVenta $puntoVenta): bool
    {
        return UsuarioRol::puedeOperar($user) && self::puedeVerPunto($user, $puntoVenta);
    }

    public static function puedeVerPedido(?Usuario $user, PedidoDistribucion $pedido): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (UsuarioRol::puedeGestionarDistribucionMayorista($user)) {
            return MayoristaAccess::puedeVerPedidoDistribucion($user, $pedido);
        }

        if (UsuarioRol::esTransportista($user)) {
            $pedido->loadMissing('rutaDistribucion');

            return (int) $pedido->transportista_usuarioid === (int) $user->usuarioid
                || (int) $pedido->rutaDistribucion?->transportista_usuarioid === (int) $user->usuarioid;
        }

        if (! UsuarioRol::esMinorista($user)) {
            return false;
        }

        $pedido->loadMissing('puntoVenta');

        return $pedido->puntoVenta !== null
            && (int) $pedido->puntoVenta->usuarioid === (int) $user->usuarioid;
    }

    public static function scopePuntosDelUsuario($query, ?Usuario $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        if (UsuarioRol::esMinorista($user)) {
            return $query->where('usuarioid', $user->usuarioid);
        }

        return $query->whereRaw('1 = 0');
    }

    /** Firma de recepción: solo el minorista receptor (doble control con el transportista). */
    public static function puedeFirmarRecepcionRuta(?Usuario $user, RutaDistribucion $ruta): bool
    {
        if (! UsuarioRol::puedeOperar($user) || ! UsuarioRol::esMinorista($user)) {
            return false;
        }

        $ruta->loadMissing('pedidos.puntoVenta');

        return $ruta->pedidos->contains(
            fn (PedidoDistribucion $pedido) => $pedido->puntoVenta !== null
                && (int) $pedido->puntoVenta->usuarioid === (int) $user->usuarioid
        );
    }

    public static function scopePedidosDelUsuario($query, ?Usuario $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        if (UsuarioRol::puedeGestionarDistribucionMayorista($user)) {
            $almacenIds = MayoristaAccess::idsAlmacenesOperados($user);

            // Solo pedidos dirigidos a sus almacenes: un custom sin origen no es de «cualquier mayorista» (MAY-11).
            if ($almacenIds !== []) {
                return $query->whereIn('almacen_mayorista_origenid', $almacenIds);
            }

            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esTransportista($user)) {
            return $query->where(function ($w) use ($user) {
                $w->where('transportista_usuarioid', $user->usuarioid)
                    ->orWhereHas('rutaDistribucion', fn ($r) => $r->where('transportista_usuarioid', $user->usuarioid));
            });
        }

        if (UsuarioRol::esMinorista($user)) {
            return $query->whereHas('puntoVenta', fn ($q) => $q->where('usuarioid', $user->usuarioid));
        }

        return $query->whereRaw('1 = 0');
    }
}
