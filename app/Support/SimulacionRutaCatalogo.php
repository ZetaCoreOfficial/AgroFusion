<?php

namespace App\Support;

use App\Models\EnvioAsignacionMultiple;
use App\Models\RutaDistribucion;
use App\Models\Usuario;

final class SimulacionRutaCatalogo
{
    public const TIPO_AGRICOLA = 'agricola';

    public const TIPO_DISTRIBUCION = 'distribucion';

    public const TIPO_PLANTA_MAYORISTA = 'planta_mayorista';

    /** @return array<string, array{etiqueta: string, variante: string, color: string, icono: string}> */
    public static function catalogoVariantes(): array
    {
        return [
            self::TIPO_AGRICOLA => [
                'etiqueta' => 'Almacén agrícola → Planta',
                'variante' => 'agricola_planta',
                'color' => '#16a34a',
                'icono' => 'fa-seedling',
            ],
            self::TIPO_PLANTA_MAYORISTA => [
                'etiqueta' => 'Planta → Almacén mayorista',
                'variante' => 'planta_mayorista',
                'color' => '#ea580c',
                'icono' => 'fa-industry',
            ],
            self::TIPO_DISTRIBUCION => [
                'etiqueta' => 'Mayorista → Punto de venta',
                'variante' => 'mayorista_pdv',
                'color' => '#2563eb',
                'icono' => 'fa-store',
            ],
        ];
    }

    /** @return array{etiqueta: string, variante: string, color: string, icono: string} */
    public static function metaVariante(string $tipo): array
    {
        return self::catalogoVariantes()[$tipo] ?? [
            'etiqueta' => 'Ruta',
            'variante' => $tipo,
            'color' => '#64748b',
            'icono' => 'fa-truck',
        ];
    }

    /** Duración fija de la simulación (demo de trazabilidad en vivo). */
    public const DURACION_DEMO_SEG = 60;

    public static function duracionEfectiva(?int $duracionAlmacenada = null): int
    {
        return self::DURACION_DEMO_SEG;
    }

    public static function simulacionActivaAgricola(EnvioAsignacionMultiple $envio): bool
    {
        return $envio->simulacion_inicio_at !== null
            && ! EnvioAsignacionEstadoCatalogo::llegoADestino($envio);
    }

    public static function simulacionActivaDistribucion(RutaDistribucion $ruta): bool
    {
        return $ruta->simulacion_inicio_at !== null
            && $ruta->estado === RutaDistribucionCatalogo::ESTADO_EN_RUTA;
    }

    public static function puedeEmpezarAgricola(EnvioAsignacionMultiple $envio): bool
    {
        if ($envio->simulacion_inicio_at !== null) {
            return false;
        }

        if (EnvioAsignacionEstadoCatalogo::llegoADestino($envio)) {
            return false;
        }

        if (! $envio->transportista_usuarioid) {
            return false;
        }

        $estado = strtolower(trim((string) ($envio->estado ?? '')));
        if (! in_array($estado, ['asignado', 'asignada', 'pendiente', 'creada'], true)) {
            return false;
        }

        if ($envio->pedido && ! PedidoCatalogo::listoParaLogistica($envio->pedido)) {
            return false;
        }

        return true;
    }

    public static function puedeEmpezarDistribucion(RutaDistribucion $ruta): bool
    {
        if ($ruta->simulacion_inicio_at !== null) {
            return false;
        }

        if ($ruta->estado !== RutaDistribucionCatalogo::ESTADO_PLANIFICADA) {
            return false;
        }

        if ($ruta->transportista_usuarioid === null) {
            return false;
        }

        return self::pedidosListosParaSalida($ruta);
    }

    public static function pedidosListosParaSalida(RutaDistribucion $ruta): bool
    {
        $ruta->loadMissing('pedidos');

        foreach ($ruta->pedidos as $pedido) {
            if (PedidoDistribucionCatalogo::pendienteConfirmacionMinorista($pedido)) {
                return false;
            }
        }

        return true;
    }

    /** Transportista asignado o supervisor con permiso de actualizar asignaciones. */
    public static function usuarioPuedeEmpezarAgricola(?Usuario $user, EnvioAsignacionMultiple $envio): bool
    {
        if (! self::puedeEmpezarAgricola($envio) || $user === null) {
            return false;
        }

        return ViajeAcceso::esConductorAsignado($user, $envio->transportista_usuarioid);
    }

    /** El transportista asignado no ve su propia ruta en tiempo real; solo supervisores. */
    public static function usuarioPuedeVerTiempoRealAgricola(?Usuario $user, EnvioAsignacionMultiple $envio): bool
    {
        if ($user === null || ! self::simulacionActivaAgricola($envio)) {
            return false;
        }

        if (UsuarioRol::esTransportista($user)) {
            return false;
        }

        return UsuarioRol::esAdminGlobal($user)
            || UsuarioRol::esJefePlanta($user)
            || UsuarioRol::esJefeAgricultor($user)
            || ($user->can('asignaciones.read') && ! UsuarioRol::esMinorista($user));
    }

    /** Transportista asignado, jefe de planta/admin o quien gestione asignaciones. */
    public static function usuarioPuedeEmpezarDistribucion(?Usuario $user, RutaDistribucion $ruta): bool
    {
        if (! self::puedeEmpezarDistribucion($ruta) || $user === null) {
            return false;
        }

        // El mayorista despacha y designa transporte, pero no conduce (MAY-16): el botón
        // «Empezar ruta» solo aparece al conductor asignado, igual que la regla del backend.
        return ViajeAcceso::esConductorAsignado($user, $ruta->transportista_usuarioid);
    }
}
