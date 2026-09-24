<?php

namespace App\Services;

use App\Models\ChecklistCondicionLogistica;
use App\Models\ChecklistCondicionLogisticaDetalle;
use App\Models\ChecklistIncidenteEnvio;
use App\Models\ChecklistIncidenteEnvioDetalle;
use App\Models\CondicionTransporte;
use App\Models\DocumentoEntrega;
use App\Models\EnvioAsignacionMultiple;
use App\Models\FirmaRecepcionEnvio;
use App\Models\FirmaTransportistaEnvio;
use App\Models\TipoIncidenteTransporte;
use App\Models\Usuario;
use App\Support\DocumentoEntregaArchivo;
use App\Support\EnvioAsignacionEstadoCatalogo;
use App\Support\EnvioCierreAgricolaCatalogo;
use App\Support\FirmaCierreReglas;
use App\Support\PedidoCatalogo;
use App\Support\SimulacionRutaCatalogo;
use App\Support\UsuarioRol;
use App\Support\ViajeAcceso;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CierreEnvioAgricolaService
{
    public function __construct(
        private readonly RecepcionPlantaEnvioService $recepcionPlanta,
        private readonly SimulacionRutaService $simulacion,
    ) {}

    public function tieneCondicionesVehiculo(EnvioAsignacionMultiple $envio): bool
    {
        return ChecklistCondicionLogistica::query()
            ->where('envioasignacionmultipleid', $envio->envioasignacionmultipleid)
            ->whereIn('estado_general', [
                EnvioCierreAgricolaCatalogo::ESTADO_VEHICULO_PERFECTO,
                EnvioCierreAgricolaCatalogo::ESTADO_VEHICULO_REVISADO,
            ])
            ->exists();
    }

    /** @return array<string, mixed> */
    public function resumenPasos(EnvioAsignacionMultiple $envio): array
    {
        $envio->loadMissing([
            'checklistCondicionVehiculo.detalles.condicion',
            'checklistIncidente.detalles.tipoIncidente',
            'firmaTransportista',
            'firmaRecepcion',
        ]);

        $estadoSim = $this->simulacion->estadoAgricola($envio);
        $progreso = (float) ($estadoSim['progreso'] ?? 0);
        $enRuta = SimulacionRutaCatalogo::simulacionActivaAgricola($envio);
        $llegadaConfirmada = $envio->llegada_confirmada_at !== null;
        $recibido = EnvioAsignacionEstadoCatalogo::llegoADestino($envio);
        $tieneCondiciones = $this->tieneCondicionesVehiculo($envio);
        $tieneIncidentes = $envio->checklistIncidente !== null;
        $firmaTransportista = $envio->firmaTransportista !== null;
        // Solo cuenta la recepción firmada por planta con su cuenta (CROSS-A).
        $firmaRecepcion = FirmaCierreReglas::recepcionValida($envio->firmaRecepcion, $envio->transportista_usuarioid);
        $pedidoConfirmado = PedidoCatalogo::envioOperativoParaTransportista($envio);

        $pasoActual = EnvioCierreAgricolaCatalogo::PASO_CONDICIONES;
        if ($recibido) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_COMPLETADO;
        } elseif ($firmaTransportista && $firmaRecepcion) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_FIRMA_RECEPCION;
        } elseif ($firmaTransportista) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_FIRMA_RECEPCION;
        } elseif ($tieneIncidentes && $llegadaConfirmada) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_FIRMA_TRANSPORTISTA;
        } elseif ($llegadaConfirmada) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_INCIDENTES;
        } elseif ($enRuta) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_ESPERA_LLEGADA;
        } elseif ($tieneCondiciones) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_EN_RUTA;
        }

        return app(RecepcionQrFirmaService::class)->enriquecerResumen([
            'paso_actual' => $pasoActual,
            'tiene_condiciones' => $tieneCondiciones,
            'en_ruta' => $enRuta,
            'progreso' => $progreso,
            'esperando_confirmacion' => ! $recibido && ! $llegadaConfirmada && $progreso >= 100,
            'llegada_confirmada' => $llegadaConfirmada,
            'tiene_incidentes' => $tieneIncidentes,
            'firma_transportista' => $firmaTransportista,
            'firma_recepcion' => $firmaRecepcion,
            'recibido_planta' => $recibido,
            'pedido_confirmado' => $pedidoConfirmado,
            'puede_registrar_condiciones' => $pedidoConfirmado && ! $tieneCondiciones && ! $enRuta && ! $recibido,
            'puede_empezar_ruta' => $pedidoConfirmado && SimulacionRutaCatalogo::puedeEmpezarAgricola($envio) && $tieneCondiciones,
            'puede_confirmar_llegada' => $enRuta && ! $llegadaConfirmada && ! $recibido && $progreso >= 100,
            'puede_registrar_incidentes' => $llegadaConfirmada && ! $tieneIncidentes && ! $recibido,
            'puede_firmar_transportista' => $llegadaConfirmada && $tieneIncidentes && ! $recibido && ! $firmaTransportista,
            'puede_firmar_recepcion' => $llegadaConfirmada && $tieneIncidentes && ! $recibido
                && $firmaTransportista && ! $firmaRecepcion,
            'puede_finalizar' => $llegadaConfirmada && $tieneIncidentes && $firmaTransportista && $firmaRecepcion && ! $recibido,
        ], $envio);
    }

    /**
     * @param  array<int, array{id: int, valor: bool}>|null  $condiciones
     */
    public function registrarCondicionesVehiculo(
        EnvioAsignacionMultiple $envio,
        Usuario $usuario,
        bool $perfectasCondiciones,
        ?array $condiciones = null,
        ?string $observaciones = null,
    ): ChecklistCondicionLogistica {
        if ($this->tieneCondicionesVehiculo($envio)) {
            throw new InvalidArgumentException('Las condiciones del vehículo ya fueron registradas.');
        }

        if (SimulacionRutaCatalogo::simulacionActivaAgricola($envio)) {
            throw new InvalidArgumentException('No puede modificar condiciones con la ruta en curso.');
        }

        if (EnvioAsignacionEstadoCatalogo::llegoADestino($envio)) {
            throw new InvalidArgumentException('Este envío ya fue recibido en planta.');
        }

        $envio->loadMissing('pedido');
        if ($envio->pedido && ! PedidoCatalogo::listoParaLogistica($envio->pedido)) {
            if ($this->esTransportistaAsignado($usuario, $envio) && ! $this->esAdminOperativo($usuario)) {
                throw new InvalidArgumentException('El pedido aún no fue confirmado por producción agrícola.');
            }
        }

        $catalogo = CondicionTransporte::query()->orderBy('condiciontransporteid')->get();
        if ($catalogo->isEmpty()) {
            throw new InvalidArgumentException('No hay condiciones de transporte configuradas en el catálogo.');
        }

        return DB::transaction(function () use ($envio, $usuario, $perfectasCondiciones, $condiciones, $observaciones, $catalogo) {
            $checklist = ChecklistCondicionLogistica::create([
                'envioasignacionmultipleid' => $envio->envioasignacionmultipleid,
                'almacenid' => $envio->almacenid,
                'revisado_por_usuarioid' => $usuario->usuarioid,
                'estado_general' => $perfectasCondiciones
                    ? EnvioCierreAgricolaCatalogo::ESTADO_VEHICULO_PERFECTO
                    : EnvioCierreAgricolaCatalogo::ESTADO_VEHICULO_REVISADO,
                'observaciones' => $observaciones ?? ($perfectasCondiciones
                    ? 'Vehículo en perfectas condiciones.'
                    : null),
                'fecha_revision' => now(),
                'created_at' => now(),
            ]);

            $mapaManual = collect($condiciones ?? [])->keyBy('id');

            foreach ($catalogo as $condicion) {
                $valor = $perfectasCondiciones
                    ? true
                    : $this->valorBooleano($mapaManual->get($condicion->condiciontransporteid)['valor'] ?? false);

                ChecklistCondicionLogisticaDetalle::create([
                    'checklistcondicionid' => $checklist->checklistcondicionid,
                    'condiciontransporteid' => $condicion->condiciontransporteid,
                    'valor' => $valor,
                    'comentario' => null,
                ]);
            }

            return $checklist->load('detalles.condicion');
        });
    }

    public function confirmarLlegada(EnvioAsignacionMultiple $envio, Usuario $usuario): void
    {
        $this->autorizarConfirmacionLlegada($usuario, $envio);

        if ($envio->llegada_confirmada_at) {
            throw new InvalidArgumentException('La llegada ya fue confirmada.');
        }

        if (EnvioAsignacionEstadoCatalogo::llegoADestino($envio)) {
            throw new InvalidArgumentException('Este envío ya fue recibido en planta.');
        }

        if (! SimulacionRutaCatalogo::simulacionActivaAgricola($envio)) {
            throw new InvalidArgumentException('El envío debe estar en ruta para confirmar la llegada.');
        }

        $estado = $this->simulacion->estadoAgricola($envio);
        if ((float) ($estado['progreso'] ?? 0) < 100) {
            throw new InvalidArgumentException('Primero debe llegar al destino. Espere a que el recorrido GPS llegue al 100% antes de confirmar la llegada.');
        }

        $envio->update([
            'llegada_confirmada_at' => now(),
            'llegada_confirmada_usuarioid' => $usuario->usuarioid,
        ]);
    }

    /**
     * @param  array<int, array{id: int, ocurrio: bool}>|null  $incidentes
     */
    public function registrarIncidentes(
        EnvioAsignacionMultiple $envio,
        Usuario $usuario,
        bool $sinIncidentes,
        ?array $incidentes = null,
        ?string $observaciones = null,
    ): ChecklistIncidenteEnvio {
        $this->autorizarIncidentes($usuario, $envio);

        if ($envio->llegada_confirmada_at === null) {
            throw new InvalidArgumentException('Debe confirmar la llegada antes de registrar incidentes.');
        }

        if ($envio->checklistIncidente()->exists()) {
            throw new InvalidArgumentException('Los incidentes ya fueron registrados para este envío.');
        }

        $catalogo = TipoIncidenteTransporte::query()->orderBy('tipoincidentetransporteid')->get();
        if ($catalogo->isEmpty()) {
            throw new InvalidArgumentException('No hay tipos de incidente configurados en el catálogo.');
        }

        return DB::transaction(function () use ($envio, $sinIncidentes, $incidentes, $observaciones, $catalogo) {
            $checklist = ChecklistIncidenteEnvio::create([
                'envioasignacionmultipleid' => $envio->envioasignacionmultipleid,
                'fecha' => now(),
                'observaciones' => $observaciones ?? ($sinIncidentes
                    ? 'Transporte sin incidentes reportados.'
                    : null),
            ]);

            $mapaManual = collect($incidentes ?? [])->keyBy('id');
            $filas = [];
            $ahora = now();

            foreach ($catalogo as $tipo) {
                $ocurrio = $sinIncidentes
                    ? false
                    : $this->valorBooleano($mapaManual->get($tipo->tipoincidentetransporteid)['ocurrio'] ?? false);

                $filas[] = [
                    'checklistincidenteenvioid' => $checklist->checklistincidenteenvioid,
                    'tipoincidentetransporteid' => $tipo->tipoincidentetransporteid,
                    'ocurrio' => $ocurrio,
                    'descripcion' => null,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            if ($filas !== []) {
                ChecklistIncidenteEnvioDetalle::insert($filas);
            }

            return $checklist->load('detalles.tipoIncidente');
        });
    }

    public function guardarFirmaTransportista(EnvioAsignacionMultiple $envio, Usuario $usuario, string $imagenBase64): FirmaTransportistaEnvio
    {
        FirmaCierreReglas::asegurarPuedeFirmarComoTransportista($usuario, $envio->transportista_usuarioid);
        $this->validarPreFirmas($envio);
        $imagen = $this->normalizarImagenFirma($imagenBase64);

        $firma = DB::transaction(function () use ($envio, $usuario, $imagen) {
            EnvioAsignacionMultiple::query()->whereKey($envio->envioasignacionmultipleid)->lockForUpdate()->first();

            if ($envio->firmaTransportista()->exists()) {
                throw new InvalidArgumentException('La firma del transportista ya fue registrada.');
            }

            return FirmaTransportistaEnvio::create([
                'envioasignacionmultipleid' => $envio->envioasignacionmultipleid,
                'imagenfirma' => $imagen,
                'nombrefirmante' => RecepcionQrFirmaService::nombreDesdeUsuario($usuario),
                'firmante_usuarioid' => $usuario->usuarioid,
                'fechafirma' => now(),
            ]);
        });

        app(RecepcionQrFirmaService::class)->ensureToken($envio);

        return $firma;
    }

    public function guardarFirmaRecepcion(EnvioAsignacionMultiple $envio, Usuario $usuario, string $imagenBase64): FirmaRecepcionEnvio
    {
        FirmaCierreReglas::asegurarPuedeFirmarRecepcion(
            $usuario,
            $envio->transportista_usuarioid,
            $this->esReceptor($usuario, $envio),
            'No tiene permiso para firmar la recepción en planta.',
        );
        $this->validarPreFirmas($envio);
        $imagen = $this->normalizarImagenFirma($imagenBase64);

        return DB::transaction(function () use ($envio, $usuario, $imagen) {
            EnvioAsignacionMultiple::query()->whereKey($envio->envioasignacionmultipleid)->lockForUpdate()->first();

            if (! $envio->firmaTransportista()->exists()) {
                throw new InvalidArgumentException('Primero debe firmar el transportista la entrega.');
            }

            $existente = $envio->firmaRecepcion()->first();
            if (FirmaCierreReglas::recepcionValida($existente, $envio->transportista_usuarioid)) {
                throw new InvalidArgumentException('La firma de recepción ya fue registrada.');
            }

            $datos = [
                'imagenfirma' => $imagen,
                'nombrefirmante' => RecepcionQrFirmaService::nombreDesdeUsuario($usuario),
                'firmante_usuarioid' => $usuario->usuarioid,
                'fechafirma' => now(),
            ];

            // Una firma anónima previa (QR sin sesión) se reemplaza por la del receptor autenticado.
            if ($existente !== null) {
                $existente->update($datos);

                return $existente->fresh();
            }

            return FirmaRecepcionEnvio::create(['envioasignacionmultipleid' => $envio->envioasignacionmultipleid] + $datos);
        });
    }

    /** Personal de planta que confirma recepciones (nunca el transportista del envío). */
    public function esReceptor(?Usuario $usuario, EnvioAsignacionMultiple $envio): bool
    {
        return $usuario !== null && $usuario->can('recepcion_planta.confirm');
    }

    public function finalizarEntrega(EnvioAsignacionMultiple $envio, Usuario $usuario): DocumentoEntrega
    {
        $this->autorizarFinalizar($usuario, $envio);
        $envio->refresh();

        if (EnvioAsignacionEstadoCatalogo::llegoADestino($envio)) {
            $existente = $this->buscarDocumentoTransporte($envio);
            if ($existente !== null) {
                DocumentoEntregaArchivo::materializarPdfDocumento($existente);

                return $existente;
            }
        }

        $resumen = $this->resumenPasos($envio);

        if (! ($resumen['puede_finalizar'] ?? false)) {
            throw new InvalidArgumentException('Complete condiciones, llegada, incidentes y firmas antes de finalizar.');
        }

        $envio->loadMissing('pedido');

        $documento = DB::transaction(function () use ($envio, $usuario) {
            EnvioAsignacionMultiple::query()->whereKey($envio->envioasignacionmultipleid)->lockForUpdate()->first();
            $envio->load(['firmaTransportista', 'firmaRecepcion']);
            FirmaCierreReglas::asegurarFirmasParaCierre($envio->firmaTransportista, $envio->firmaRecepcion, $envio->transportista_usuarioid);

            if ($envio->pedido) {
                $this->recepcionPlanta->confirmarDesdePedido($envio->pedido, $usuario);
            } else {
                throw new InvalidArgumentException('El envío no tiene pedido asociado para registrar la recepción en planta.');
            }

            $envio->refresh();

            return $this->generarDocumentoTransporte($envio, $usuario);
        });

        DocumentoEntregaArchivo::materializarPdfDocumento($documento);

        return $documento;
    }

    public function autorizarConfirmacionLlegada(Usuario $usuario, EnvioAsignacionMultiple $envio): void
    {
        if ($this->esTransportistaAsignado($usuario, $envio) || $this->esAdminOperativo($usuario)) {
            return;
        }

        throw new InvalidArgumentException('No tiene permiso para confirmar la llegada de este envío.');
    }

    public function autorizarIncidentes(Usuario $usuario, EnvioAsignacionMultiple $envio): void
    {
        if ($this->esTransportistaAsignado($usuario, $envio) || $this->esAdminOperativo($usuario)) {
            return;
        }

        throw new InvalidArgumentException('No tiene permiso para registrar incidentes en este envío.');
    }

    private function autorizarFinalizar(Usuario $usuario, EnvioAsignacionMultiple $envio): void
    {
        if (ViajeAcceso::esConductorAsignado($usuario, $envio->transportista_usuarioid) || $this->esAdminOperativo($usuario)) {
            return;
        }

        throw new InvalidArgumentException('No tiene permiso para finalizar este envío.');
    }

    private function validarPreFirmas(EnvioAsignacionMultiple $envio): void
    {
        if ($envio->llegada_confirmada_at === null) {
            throw new InvalidArgumentException('Debe confirmar la llegada antes de las firmas.');
        }

        if (! $envio->checklistIncidente()->exists()) {
            throw new InvalidArgumentException('Debe registrar incidentes (o «Sin incidentes») antes de las firmas.');
        }
    }

    private function esTransportistaAsignado(Usuario $usuario, EnvioAsignacionMultiple $envio): bool
    {
        return (int) $envio->transportista_usuarioid === (int) $usuario->usuarioid;
    }

    private function normalizarImagenFirma(string $imagen): string
    {
        $imagen = trim($imagen);
        if ($imagen === '' || ! str_starts_with($imagen, 'data:image/')) {
            throw new InvalidArgumentException('La firma no es válida. Dibuje su firma en el recuadro.');
        }

        return $imagen;
    }

    private function valorBooleano(mixed $valor): bool
    {
        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /** Coordinador logístico con permiso de asignaciones (el admin supervisor no opera cierres). */
    private function esAdminOperativo(Usuario $usuario): bool
    {
        return UsuarioRol::puedeOperar($usuario) && $usuario->can('asignaciones.update');
    }

    private function generarDocumentoTransporte(EnvioAsignacionMultiple $envio, Usuario $usuario): DocumentoEntrega
    {
        $codigo = $envio->externo_envio_id ?? ('ENV-'.$envio->envioasignacionmultipleid);
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $codigo) ?: 'envio';
        $path = 'documentos/entrega/'.$slug.'_transporte_'.now()->format('Ymd_His').'.pdf';

        $existente = DocumentoEntrega::query()
            ->where('externo_envio_id', $envio->externo_envio_id)
            ->where('tipo_documento', 'guia_transporte')
            ->where('metadata->envio_cierre_agricola', true)
            ->first();

        if ($existente) {
            return $existente;
        }

        $documento = DocumentoEntrega::create([
            'externo_envio_id' => $envio->externo_envio_id,
            'pedidoid' => $envio->pedidoid,
            'usuarioid' => $usuario->usuarioid,
            'tipo_documento' => 'guia_transporte',
            'titulo' => 'Documento de transporte de carga — '.$codigo,
            'archivo_path' => $path,
            'almacenid' => $envio->almacenid,
            'metadata' => [
                'envio_cierre_agricola' => true,
                'envioasignacionmultipleid' => $envio->envioasignacionmultipleid,
            ],
        ]);

        return $documento;
    }

    private function buscarDocumentoTransporte(EnvioAsignacionMultiple $envio): ?DocumentoEntrega
    {
        return DocumentoEntrega::query()
            ->where('externo_envio_id', $envio->externo_envio_id)
            ->where('tipo_documento', 'guia_transporte')
            ->where('metadata->envio_cierre_agricola', true)
            ->first();
    }
}
