<?php

namespace App\Services;

use App\Models\DocumentoEntrega;
use App\Models\EnvioAsignacionMultiple;
use App\Models\FirmaRecepcionEnvio;
use App\Models\RecepcionQrEnvio;
use App\Models\RutaDistribucion;
use App\Models\Usuario;
use App\Support\DocumentoEntregaCatalogo;
use App\Support\PublicUrlHelper;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecepcionQrFirmaService
{
    public static function nombreDesdeUsuario(Usuario $usuario): string
    {
        return DocumentoEntregaCatalogo::etiquetaUsuario($usuario);
    }

    public function ensureToken(RutaDistribucion|EnvioAsignacionMultiple $operacion): RecepcionQrEnvio
    {
        if ($operacion instanceof RutaDistribucion) {
            $existente = RecepcionQrEnvio::query()
                ->where('rutadistribucionid', $operacion->rutadistribucionid)
                ->first();
            if ($existente) {
                return $existente;
            }

            return RecepcionQrEnvio::create([
                'token' => $this->generarTokenUnico(),
                'rutadistribucionid' => $operacion->rutadistribucionid,
            ]);
        }

        $existente = RecepcionQrEnvio::query()
            ->where('envioasignacionmultipleid', $operacion->envioasignacionmultipleid)
            ->first();
        if ($existente) {
            return $existente;
        }

        return RecepcionQrEnvio::create([
            'token' => $this->generarTokenUnico(),
            'envioasignacionmultipleid' => $operacion->envioasignacionmultipleid,
        ]);
    }

    public function urlPublica(RecepcionQrEnvio $qr): string
    {
        $path = route('recepcion.publica', ['token' => $qr->token], false);

        return PublicUrlHelper::absoluteForQr($path);
    }

    /**
     * @param  array<string, mixed>  $resumen
     * @return array<string, mixed>
     */
    public function enriquecerResumen(array $resumen, RutaDistribucion|EnvioAsignacionMultiple $operacion): array
    {
        $resumen['puede_firmar_recepcion'] = false;
        $resumen['esperando_firma_qr'] = false;
        $resumen['qr_recepcion_url'] = null;

        $firmaTransportista = (bool) ($resumen['firma_transportista'] ?? false);
        $firmaRecepcion = (bool) ($resumen['firma_recepcion'] ?? false);

        if ($firmaTransportista && ! $firmaRecepcion) {
            $qr = $this->ensureToken($operacion);
            $resumen['esperando_firma_qr'] = true;
            $resumen['qr_recepcion_url'] = $this->urlPublica($qr);
        }

        return $resumen;
    }

    /**
     * @return array<string, mixed>
     */
    public function estadoJson(RutaDistribucion|EnvioAsignacionMultiple $operacion): array
    {
        if ($operacion instanceof RutaDistribucion) {
            if ($operacion->esTrasladoPlantaMayorista()) {
                $resumen = app(CierreEnvioPlantaMayoristaService::class)->resumenPasos($operacion);
            } else {
                $resumen = app(CierreEnvioDistribucionPdvService::class)->resumenPasos($operacion);
            }
        } else {
            $resumen = app(CierreEnvioAgricolaService::class)->resumenPasos($operacion);
        }

        return [
            'firma_transportista' => (bool) ($resumen['firma_transportista'] ?? false),
            'firma_recepcion' => (bool) ($resumen['firma_recepcion'] ?? false),
            'esperando_firma_qr' => (bool) ($resumen['esperando_firma_qr'] ?? false),
            'qr_recepcion_url' => $resumen['qr_recepcion_url'] ?? null,
            'puede_finalizar' => (bool) ($resumen['puede_finalizar'] ?? false),
            'completado' => $this->estaCompletado($operacion, $resumen),
            'paso_actual' => $resumen['paso_actual'] ?? null,
            'en_ruta' => (bool) ($resumen['en_ruta'] ?? false),
            'progreso' => (float) ($resumen['progreso'] ?? 0),
            'llegada_confirmada' => (bool) ($resumen['llegada_confirmada'] ?? false),
            'puede_confirmar_llegada' => (bool) ($resumen['puede_confirmar_llegada'] ?? false),
            'esperando_confirmacion' => (bool) ($resumen['esperando_confirmacion'] ?? false),
        ];
    }

    public function resolverPorToken(string $token): RecepcionQrEnvio
    {
        $qr = RecepcionQrEnvio::query()->where('token', $token)->first();
        if ($qr === null) {
            throw new InvalidArgumentException('El enlace de recepción no es válido o ya expiró.');
        }

        return $qr;
    }

    public function resolverOperacion(RecepcionQrEnvio $qr): RutaDistribucion|EnvioAsignacionMultiple
    {
        if ($qr->rutadistribucionid !== null) {
            return RutaDistribucion::query()->findOrFail($qr->rutadistribucionid);
        }

        if ($qr->envioasignacionmultipleid !== null) {
            return EnvioAsignacionMultiple::query()->findOrFail($qr->envioasignacionmultipleid);
        }

        throw new InvalidArgumentException('El enlace de recepción no está asociado a un envío.');
    }

    /**
     * Firma de recepción desde el QR con la cuenta del receptor (CROSS-A).
     *
     * Antes cualquiera con el enlace (incluido el propio transportista, que lo ve en su pantalla)
     * podía firmar sin sesión escribiendo un nombre. Ahora el QR solo abre la pantalla: la firma
     * pasa por el mismo servicio de cierre que valida que sea el receptor del destino.
     */
    /** @param  array<int|string, array{recibido?: mixed, motivo?: string|null}>  $recepcion  Cantidades recibidas (solo planta → mayorista). */
    public function guardarFirmaRecepcionConCuenta(string $token, Usuario $usuario, string $imagenBase64, array $recepcion = []): FirmaRecepcionEnvio
    {
        $operacion = $this->resolverOperacion($this->resolverPorToken($token));

        if ($operacion instanceof RutaDistribucion && $operacion->esTrasladoPlantaMayorista()) {
            return app(CierreEnvioPlantaMayoristaService::class)->guardarFirmaRecepcion($operacion, $usuario, $imagenBase64, $recepcion);
        }

        return $this->servicioCierre($operacion)->guardarFirmaRecepcion($operacion, $usuario, $imagenBase64);
    }

    public function esReceptorAutorizado(RutaDistribucion|EnvioAsignacionMultiple $operacion, ?Usuario $usuario): bool
    {
        if ($usuario === null
            || ! \App\Support\UsuarioRol::puedeOperar($usuario)
            || (int) $operacion->transportista_usuarioid === (int) $usuario->usuarioid) {
            return false;
        }

        return $this->servicioCierre($operacion)->esReceptor($usuario, $operacion);
    }

    public function recepcionFirmada(RutaDistribucion|EnvioAsignacionMultiple $operacion): bool
    {
        $operacion->loadMissing('firmaRecepcion');

        return \App\Support\FirmaCierreReglas::recepcionValida($operacion->firmaRecepcion, $operacion->transportista_usuarioid);
    }

    private function servicioCierre(RutaDistribucion|EnvioAsignacionMultiple $operacion): CierreEnvioAgricolaService|CierreEnvioPlantaMayoristaService|CierreEnvioDistribucionPdvService
    {
        if ($operacion instanceof EnvioAsignacionMultiple) {
            return app(CierreEnvioAgricolaService::class);
        }

        return $operacion->esTrasladoPlantaMayorista()
            ? app(CierreEnvioPlantaMayoristaService::class)
            : app(CierreEnvioDistribucionPdvService::class);
    }

    public function finalizarSiCompleto(RutaDistribucion|EnvioAsignacionMultiple $operacion): ?DocumentoEntrega
    {
        $transportista = $this->resolverTransportista($operacion);
        if ($transportista === null) {
            return null;
        }

        if ($operacion instanceof EnvioAsignacionMultiple) {
            $cierre = app(CierreEnvioAgricolaService::class);
            $resumen = $cierre->resumenPasos($operacion);
            if (! ($resumen['puede_finalizar'] ?? false)) {
                return null;
            }

            try {
                return $cierre->finalizarEntrega($operacion, $transportista);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        if ($operacion->esTrasladoPlantaMayorista()) {
            $cierre = app(CierreEnvioPlantaMayoristaService::class);
        } else {
            $cierre = app(CierreEnvioDistribucionPdvService::class);
        }

        $resumen = $cierre->resumenPasos($operacion);
        if (! ($resumen['puede_finalizar'] ?? false)) {
            return null;
        }

        try {
            return $cierre->finalizarEntrega($operacion, $transportista);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function resolverTransportista(RutaDistribucion|EnvioAsignacionMultiple $operacion): ?Usuario
    {
        $operacion->loadMissing('transportista');

        return $operacion->transportista;
    }

    /**
     * @param  array<string, mixed>  $resumen
     */
    private function estaCompletado(RutaDistribucion|EnvioAsignacionMultiple $operacion, array $resumen): bool
    {
        if ($operacion instanceof EnvioAsignacionMultiple) {
            return (bool) ($resumen['recibido_planta'] ?? false);
        }

        if ($operacion->esTrasladoPlantaMayorista()) {
            return (bool) ($resumen['recibido_planta'] ?? false);
        }

        return $operacion->estado === 'completada' || (bool) ($resumen['recibido_planta'] ?? false);
    }

    private function generarTokenUnico(): string
    {
        do {
            $token = Str::random(32);
        } while (RecepcionQrEnvio::query()->where('token', $token)->exists());

        return $token;
    }

    private function normalizarImagenFirma(string $imagen): string
    {
        $imagen = trim($imagen);
        if ($imagen === '' || ! str_starts_with($imagen, 'data:image/')) {
            throw new InvalidArgumentException('La firma no es válida. Dibuje su firma en el recuadro.');
        }

        return $imagen;
    }
}
