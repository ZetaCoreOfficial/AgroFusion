<?php

namespace App\Support;

use App\Models\FirmaRecepcionEnvio;
use App\Models\FirmaTransportistaEnvio;
use App\Models\Usuario;
use InvalidArgumentException;

/**
 * Regla única de firmas para los tres trayectos (CROSS-A: MAY-15, MIN-07, TRA-01, TRA-03, TRA-12).
 *
 *   transportista asignado  → firma de ENTREGA (firma_transportista)
 *   actor destino autorizado → firma de RECEPCIÓN (firma_recepcion)
 *   la misma cuenta no cubre ambas y el admin supervisor no firma por nadie.
 *
 * Los servicios de cierre deciden quién es «actor destino» (planta, mayorista dueño, minorista dueño);
 * aquí se aplican las restricciones comunes y la validación antes de acreditar inventario.
 */
final class FirmaCierreReglas
{
    public static function asegurarPuedeFirmarComoTransportista(Usuario $usuario, int|string|null $transportistaAsignadoId): void
    {
        if (! ViajeAcceso::esConductorAsignado($usuario, $transportistaAsignadoId)) {
            throw new InvalidArgumentException('Solo el transportista asignado puede firmar como transportista.');
        }
    }

    public static function asegurarPuedeFirmarRecepcion(
        Usuario $usuario,
        int|string|null $transportistaAsignadoId,
        bool $esActorDestino,
        string $mensajeSinPermiso,
    ): void {
        if (! UsuarioRol::puedeOperar($usuario)) {
            throw new InvalidArgumentException('No tiene permiso: el administrador supervisa el cierre pero no firma la recepción.');
        }

        if ((int) $transportistaAsignadoId > 0 && (int) $transportistaAsignadoId === (int) $usuario->usuarioid) {
            throw new InvalidArgumentException('El transportista no puede firmar la recepción: debe firmar el receptor del destino.');
        }

        if (! $esActorDestino) {
            throw new InvalidArgumentException($mensajeSinPermiso);
        }
    }

    /** Firma de recepción firmada por una cuenta autenticada distinta del transportista. */
    public static function recepcionValida(?FirmaRecepcionEnvio $firma, int|string|null $transportistaAsignadoId): bool
    {
        return $firma !== null
            && (int) $firma->firmante_usuarioid > 0
            && (int) $firma->firmante_usuarioid !== (int) $transportistaAsignadoId;
    }

    /** Firma de entrega del conductor asignado (las firmas históricas sin firmante se aceptan). */
    public static function entregaValida(?FirmaTransportistaEnvio $firma, int|string|null $transportistaAsignadoId): bool
    {
        if ($firma === null) {
            return false;
        }

        return $firma->firmante_usuarioid === null
            || (int) $firma->firmante_usuarioid === (int) $transportistaAsignadoId;
    }

    /**
     * Antes de acreditar inventario: entrega firmada por el conductor y recepción firmada por el receptor real.
     * Una firma de recepción anónima (QR sin sesión, anterior a esta regla) no alcanza.
     */
    public static function asegurarFirmasParaCierre(
        ?FirmaTransportistaEnvio $entrega,
        ?FirmaRecepcionEnvio $recepcion,
        int|string|null $transportistaAsignadoId,
    ): void {
        if (! self::entregaValida($entrega, $transportistaAsignadoId)) {
            throw new InvalidArgumentException('Falta la firma de entrega del transportista asignado.');
        }

        if (! self::recepcionValida($recepcion, $transportistaAsignadoId)) {
            throw new InvalidArgumentException('La recepción debe firmarla el receptor del destino con su cuenta antes de cerrar.');
        }
    }
}
