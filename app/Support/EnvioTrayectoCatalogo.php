<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EnvioTrayectoCatalogo
{
    public const TRAYECTO_PLANTA = 'planta';

    public const TRAYECTO_MAYORISTA = 'mayorista';

    public const TRAYECTO_PDV = 'punto-venta';

    /** @return list<string> */
    public static function trayectosPermitidos(?Usuario $user): array
    {
        // El admin supervisa: no registra envíos en ningún trayecto.
        if (! UsuarioRol::puedeOperar($user)) {
            return [];
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            return [self::TRAYECTO_PLANTA];
        }

        if (UsuarioRol::esJefePlanta($user)) {
            return [self::TRAYECTO_MAYORISTA];
        }

        if (UsuarioRol::esMayorista($user)) {
            return [self::TRAYECTO_PDV];
        }

        return [];
    }

    public static function puedeCrearAlguno(?Usuario $user): bool
    {
        return $user !== null
            && $user->can('pedidos.create')
            && self::trayectosPermitidos($user) !== [];
    }

    public static function puedeUsarTrayecto(?Usuario $user, string $trayecto): bool
    {
        return in_array($trayecto, self::trayectosPermitidos($user), true);
    }

    public static function destinoInicialPermitido(?Usuario $user, ?string $queryDestino): string
    {
        $permitidos = self::trayectosPermitidos($user);

        if ($permitidos === []) {
            return self::TRAYECTO_PLANTA;
        }

        if ($queryDestino !== null && $queryDestino !== '' && self::puedeUsarTrayecto($user, $queryDestino)) {
            return $queryDestino;
        }

        if (old('almacen_planta_origenid') && self::puedeUsarTrayecto($user, self::TRAYECTO_MAYORISTA)) {
            return self::TRAYECTO_MAYORISTA;
        }

        return $permitidos[0];
    }

    public static function urlCrearEnvio(?Usuario $user): string
    {
        $destino = self::destinoInicialPermitido($user, null);

        return route('pedidos.create', ['destino' => $destino]);
    }

    public static function autorizarTrayecto(?Usuario $user, string $trayecto): void
    {
        if (! self::puedeUsarTrayecto($user, $trayecto)) {
            abort(403, 'Su rol no puede registrar envíos de este tipo de trayecto.');
        }
    }

    public static function etiqueta(string $trayecto): string
    {
        return match ($trayecto) {
            self::TRAYECTO_PLANTA => 'Agrícola → Planta',
            self::TRAYECTO_MAYORISTA => 'Planta → Mayorista',
            self::TRAYECTO_PDV => 'Mayorista → Punto de Venta',
            default => $trayecto,
        };
    }

    public static function puedeRegistrarStorePdv(Request $request, ?Usuario $user): bool
    {
        // El admin no crea pedidos (ni como minorista ni como mayorista): evita crear y aceptar el mismo pedido.
        if (! UsuarioRol::puedeOperar($user)) {
            return false;
        }

        if (UsuarioRol::esMinorista($user)) {
            return ! PedidoDistribucionVista::esBandejaMayorista($request, $user);
        }

        if (UsuarioRol::esMayorista($user) && ! UsuarioRol::esMinorista($user)) {
            return EnvioTrayectoCatalogo::puedeUsarTrayecto($user, self::TRAYECTO_PDV);
        }

        return false;
    }

    /** @throws InvalidArgumentException */
    public static function resolverAlmacenMayoristaOrigen(?Usuario $user, ?int $almacenId): int
    {
        if ($user === null) {
            throw new InvalidArgumentException('Usuario no autenticado.');
        }

        if (! UsuarioRol::esMayorista($user)) {
            throw new InvalidArgumentException('No puede definir el almacén mayorista de origen.');
        }

        $ids = MayoristaAccess::idsAlmacenesMayorista($user);
        if ($ids === []) {
            throw new InvalidArgumentException('No tiene un almacén mayorista asignado.');
        }

        if ($almacenId !== null && $almacenId > 0) {
            if (! in_array($almacenId, $ids, true)) {
                abort(403, 'No puede usar ese almacén mayorista.');
            }

            return $almacenId;
        }

        if (count($ids) === 1) {
            return $ids[0];
        }

        throw new InvalidArgumentException('Seleccione el almacén mayorista de origen.');
    }
}
