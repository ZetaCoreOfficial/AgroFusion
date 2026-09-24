<?php

namespace App\Support;

use App\Models\EnvioAsignacionMultiple;
use App\Models\RutaDistribucion;
use App\Models\Usuario;
use App\Services\CierreEnvioAgricolaService;
use App\Services\CierreEnvioDistribucionPdvService;
use App\Services\CierreEnvioPlantaMayoristaService;

final class RutaTiempoRealCierreAdmin
{
    /**
     * @param  array<string, mixed>  $estado
     * @return array{puede: bool, url: string|null, mensaje: string}
     */
    public static function resolverConfirmarLlegada(?Usuario $user, string $tipo, int $id, array $estado): array
    {
        $vacío = ['puede' => false, 'url' => null, 'mensaje' => ''];

        if ($user === null || ! self::esAdminOperativo($user)) {
            return $vacío;
        }

        $espera = (bool) ($estado['esperando_confirmacion'] ?? false)
            || (float) ($estado['progreso'] ?? 0) >= 100;

        if (! $espera) {
            return $vacío;
        }

        if ($tipo === SimulacionRutaCatalogo::TIPO_AGRICOLA) {
            $envio = EnvioAsignacionMultiple::query()->find($id);
            if ($envio === null) {
                return $vacío;
            }
            $resumen = app(CierreEnvioAgricolaService::class)->resumenPasos($envio);
            if (($resumen['llegada_confirmada'] ?? false) || ($resumen['recibido_planta'] ?? false)) {
                return $vacío;
            }

            return [
                'puede' => true,
                'url' => route('logistica.asignaciones.cierre.confirmar-llegada', $envio),
                'mensaje' => '¿Confirma que el envío llegó a la planta destino?',
            ];
        }

        if ($tipo === SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA) {
            $ruta = RutaDistribucion::query()->find($id);
            if ($ruta === null || ! RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)) {
                return $vacío;
            }
            $resumen = app(CierreEnvioPlantaMayoristaService::class)->resumenPasos($ruta);
            if (($resumen['llegada_confirmada'] ?? false) || ($resumen['recibido_planta'] ?? false)) {
                return $vacío;
            }

            return [
                'puede' => true,
                'url' => route('logistica.traslados-planta.cierre.confirmar-llegada', $ruta),
                'mensaje' => '¿Confirma que el traslado llegó al almacén mayorista?',
            ];
        }

        if ($tipo === SimulacionRutaCatalogo::TIPO_DISTRIBUCION) {
            $ruta = RutaDistribucion::query()->find($id);
            if ($ruta === null || RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)) {
                return $vacío;
            }
            $resumen = app(CierreEnvioDistribucionPdvService::class)->resumenPasos($ruta);
            if (($resumen['llegada_confirmada'] ?? false) || ($resumen['recibido_pdv'] ?? false)) {
                return $vacío;
            }

            return [
                'puede' => true,
                'url' => route('logistica.rutas-distribucion.cierre.confirmar-llegada', $ruta),
                'mensaje' => '¿Confirma que la entrega llegó al punto de venta?',
            ];
        }

        return $vacío;
    }

    private static function esAdminOperativo(Usuario $user): bool
    {
        // Coordinador logístico; el admin supervisor ya no confirma llegadas en nombre del receptor.
        return UsuarioRol::puedeOperar($user) && $user->can('asignaciones.update');
    }
}
