<?php

namespace App\Services;

use App\Models\ChecklistCondicionLogistica;
use App\Models\ChecklistCondicionLogisticaDetalle;
use App\Models\ChecklistIncidenteEnvio;
use App\Models\ChecklistIncidenteEnvioDetalle;
use App\Models\CondicionTransporte;
use App\Models\DocumentoEntrega;
use App\Models\FirmaRecepcionEnvio;
use App\Models\FirmaTransportistaEnvio;
use App\Models\PedidoDistribucion;
use App\Models\RutaDistribucion;
use App\Models\TipoIncidenteTransporte;
use App\Models\Usuario;
use App\Support\DocumentoEntregaArchivo;
use App\Support\EnvioCierreAgricolaCatalogo;
use App\Support\FirmaCierreReglas;
use App\Support\MayoristaAccess;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\PuntoVentaAccess;
use App\Support\RutaDistribucionCatalogo;
use App\Support\SimulacionRutaCatalogo;
use App\Support\UsuarioRol;
use App\Support\ViajeAcceso;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CierreEnvioDistribucionPdvService
{
    public function __construct(
        private readonly SimulacionRutaService $simulacion,
        private readonly RecepcionPuntoVentaService $recepcionPdv,
    ) {}

    public function tieneCondicionesVehiculo(RutaDistribucion $ruta): bool
    {
        return ChecklistCondicionLogistica::query()
            ->where('rutadistribucionid', $ruta->rutadistribucionid)
            ->whereIn('estado_general', [
                EnvioCierreAgricolaCatalogo::ESTADO_VEHICULO_PERFECTO,
                EnvioCierreAgricolaCatalogo::ESTADO_VEHICULO_REVISADO,
            ])
            ->exists();
    }

    public function documentoEntrega(RutaDistribucion $ruta): ?DocumentoEntrega
    {
        return DocumentoEntrega::query()
            ->where('metadata->rutadistribucionid', $ruta->rutadistribucionid)
            ->where('tipo_documento', 'guia_transporte')
            ->where('metadata->envio_cierre_mayorista_pdv', true)
            ->orderByDesc('documentoentregaid')
            ->first();
    }

    /** @return array<string, mixed> */
    public function resumenPasos(RutaDistribucion $ruta): array
    {
        $this->validarRutaPdv($ruta);

        $ruta->loadMissing([
            'checklistCondicionVehiculo.detalles.condicion',
            'checklistIncidente.detalles.tipoIncidente',
            'firmaTransportista',
            'firmaRecepcion',
        ]);

        $estadoSim = $this->simulacion->estadoDistribucion($ruta);
        $progreso = (float) ($estadoSim['progreso'] ?? 0);
        $enRuta = SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta);
        $llegadaConfirmada = $ruta->llegada_confirmada_at !== null;
        $recibido = $ruta->estado === RutaDistribucionCatalogo::ESTADO_COMPLETADA;
        $tieneCondiciones = $this->tieneCondicionesVehiculo($ruta);
        $tieneIncidentes = $ruta->checklistIncidente !== null;
        $firmaTransportista = $ruta->firmaTransportista !== null;
        // Solo cuenta la recepción firmada por el minorista receptor autenticado (CROSS-A).
        $firmaRecepcion = FirmaCierreReglas::recepcionValida($ruta->firmaRecepcion, $ruta->transportista_usuarioid);
        $pendienteConfirmacionMinorista = ! SimulacionRutaCatalogo::pedidosListosParaSalida($ruta);
        $condicionesVigentes = $tieneCondiciones && ! $pendienteConfirmacionMinorista;

        $pasoActual = EnvioCierreAgricolaCatalogo::PASO_CONDICIONES;
        if ($recibido) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_COMPLETADO;
        } elseif ($pendienteConfirmacionMinorista) {
            $pasoActual = '__espera_confirmacion_minorista__';
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
        } elseif ($condicionesVigentes) {
            $pasoActual = EnvioCierreAgricolaCatalogo::PASO_EN_RUTA;
        }

        return app(RecepcionQrFirmaService::class)->enriquecerResumen([
            'paso_actual' => $pasoActual,
            'tiene_condiciones' => $tieneCondiciones,
            'condiciones_vigentes' => $condicionesVigentes,
            'en_ruta' => $enRuta,
            'progreso' => $progreso,
            'esperando_confirmacion' => ! $recibido && ! $llegadaConfirmada && $progreso >= 100,
            'llegada_confirmada' => $llegadaConfirmada,
            'tiene_incidentes' => $tieneIncidentes,
            'firma_transportista' => $firmaTransportista,
            'firma_recepcion' => $firmaRecepcion,
            'recibido_pdv' => $recibido,
            'recibido_planta' => $recibido,
            'pendiente_confirmacion_minorista' => $pendienteConfirmacionMinorista,
            'puede_registrar_condiciones' => ! $pendienteConfirmacionMinorista
                && ! $tieneCondiciones && ! $enRuta && ! $recibido
                && $ruta->estado === RutaDistribucionCatalogo::ESTADO_PLANIFICADA
                && $ruta->transportista_usuarioid !== null,
            'puede_empezar_ruta' => SimulacionRutaCatalogo::puedeEmpezarDistribucion($ruta) && $tieneCondiciones,
            'puede_confirmar_llegada' => $enRuta && ! $llegadaConfirmada && ! $recibido && $progreso >= 100,
            'puede_registrar_incidentes' => $llegadaConfirmada && ! $tieneIncidentes && ! $recibido,
            'puede_firmar_transportista' => $llegadaConfirmada && $tieneIncidentes && ! $recibido && ! $firmaTransportista,
            'puede_firmar_recepcion' => $llegadaConfirmada && $tieneIncidentes && ! $recibido
                && $firmaTransportista && ! $firmaRecepcion,
            'puede_finalizar' => $llegadaConfirmada && $tieneIncidentes && $firmaTransportista && $firmaRecepcion && ! $recibido,
        ], $ruta);
    }

    /**
     * @param  array<int, array{id: int, valor: bool}>|null  $condiciones
     */
    public function registrarCondicionesVehiculo(
        RutaDistribucion $ruta,
        Usuario $usuario,
        bool $perfectasCondiciones,
        ?array $condiciones = null,
        ?string $observaciones = null,
    ): ChecklistCondicionLogistica {
        $this->validarRutaPdv($ruta);

        if ($this->tieneCondicionesVehiculo($ruta)) {
            throw new InvalidArgumentException('Las condiciones del vehículo ya fueron registradas.');
        }

        if (SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta)) {
            throw new InvalidArgumentException('No puede modificar condiciones con la ruta en curso.');
        }

        if ($ruta->estado === RutaDistribucionCatalogo::ESTADO_COMPLETADA) {
            throw new InvalidArgumentException('Esta entrega ya fue completada.');
        }

        if (! SimulacionRutaCatalogo::pedidosListosParaSalida($ruta)) {
            throw new InvalidArgumentException('El minorista del punto de venta debe confirmar el envío antes de registrar las condiciones del vehículo.');
        }

        $catalogo = CondicionTransporte::query()->orderBy('condiciontransporteid')->get();
        if ($catalogo->isEmpty()) {
            throw new InvalidArgumentException('No hay condiciones de transporte configuradas en el catálogo.');
        }

        return DB::transaction(function () use ($ruta, $usuario, $perfectasCondiciones, $condiciones, $observaciones, $catalogo) {
            $checklist = ChecklistCondicionLogistica::create([
                'rutadistribucionid' => $ruta->rutadistribucionid,
                'almacenid' => $ruta->almacen_mayorista_origenid,
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

    public function confirmarLlegada(RutaDistribucion $ruta, Usuario $usuario): void
    {
        $this->autorizarConfirmacionLlegada($usuario, $ruta);
        $this->validarRutaPdv($ruta);

        if ($ruta->llegada_confirmada_at) {
            throw new InvalidArgumentException('La llegada ya fue confirmada.');
        }

        if ($ruta->estado === RutaDistribucionCatalogo::ESTADO_COMPLETADA) {
            throw new InvalidArgumentException('Esta entrega ya fue completada.');
        }

        if (! SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta)) {
            throw new InvalidArgumentException('La ruta debe estar en camino para confirmar la llegada.');
        }

        $estado = $this->simulacion->estadoDistribucion($ruta);
        if ((float) ($estado['progreso'] ?? 0) < 100) {
            throw new InvalidArgumentException('Primero debe llegar al destino. Espere a que el recorrido GPS llegue al 100% antes de confirmar la llegada.');
        }

        // Actualización condicional atómica (TRA-15): una segunda confirmación concurrente no pisa la primera.
        $actualizadas = RutaDistribucion::query()
            ->whereKey($ruta->rutadistribucionid)
            ->whereNull('llegada_confirmada_at')
            ->update([
                'llegada_confirmada_at' => now(),
                'llegada_confirmada_usuarioid' => $usuario->usuarioid,
            ]);

        if ($actualizadas === 0) {
            throw new InvalidArgumentException('La llegada ya fue confirmada.');
        }

        $ruta->refresh();
    }

    /**
     * @param  array<int, array{id: int, ocurrio: bool}>|null  $incidentes
     */
    public function registrarIncidentes(
        RutaDistribucion $ruta,
        Usuario $usuario,
        bool $sinIncidentes,
        ?array $incidentes = null,
        ?string $observaciones = null,
    ): ChecklistIncidenteEnvio {
        $this->autorizarIncidentes($usuario, $ruta);
        $this->validarRutaPdv($ruta);

        if ($ruta->llegada_confirmada_at === null) {
            throw new InvalidArgumentException('Debe confirmar la llegada antes de registrar incidentes.');
        }

        if ($ruta->checklistIncidente()->exists()) {
            throw new InvalidArgumentException('Los incidentes ya fueron registrados para esta entrega.');
        }

        $catalogo = TipoIncidenteTransporte::query()->orderBy('tipoincidentetransporteid')->get();
        if ($catalogo->isEmpty()) {
            throw new InvalidArgumentException('No hay tipos de incidente configurados en el catálogo.');
        }

        return DB::transaction(function () use ($ruta, $sinIncidentes, $incidentes, $observaciones, $catalogo) {
            RutaDistribucion::query()->whereKey($ruta->rutadistribucionid)->lockForUpdate()->first();
            if ($ruta->checklistIncidente()->exists()) {
                throw new InvalidArgumentException('Los incidentes ya fueron registrados.');
            }

            $checklist = ChecklistIncidenteEnvio::create([
                'rutadistribucionid' => $ruta->rutadistribucionid,
                'fecha' => now(),
                'observaciones' => $observaciones ?? ($sinIncidentes
                    ? 'Transporte sin incidentes reportados.'
                    : null),
            ]);

            $mapaManual = collect($incidentes ?? [])->keyBy('id');

            foreach ($catalogo as $tipo) {
                $ocurrio = $sinIncidentes
                    ? false
                    : $this->valorBooleano($mapaManual->get($tipo->tipoincidentetransporteid)['ocurrio'] ?? false);

                ChecklistIncidenteEnvioDetalle::create([
                    'checklistincidenteenvioid' => $checklist->checklistincidenteenvioid,
                    'tipoincidentetransporteid' => $tipo->tipoincidentetransporteid,
                    'ocurrio' => $ocurrio,
                    'descripcion' => null,
                ]);
            }

            return $checklist->load('detalles.tipoIncidente');
        });
    }

    public function guardarFirmaTransportista(RutaDistribucion $ruta, Usuario $usuario, string $imagenBase64): FirmaTransportistaEnvio
    {
        FirmaCierreReglas::asegurarPuedeFirmarComoTransportista($usuario, $ruta->transportista_usuarioid);
        $this->validarPreFirmas($ruta);
        $imagen = $this->normalizarImagenFirma($imagenBase64);

        $firma = DB::transaction(function () use ($ruta, $usuario, $imagen) {
            RutaDistribucion::query()->whereKey($ruta->rutadistribucionid)->lockForUpdate()->first();

            if ($ruta->firmaTransportista()->exists()) {
                throw new InvalidArgumentException('La firma del transportista ya fue registrada.');
            }

            return FirmaTransportistaEnvio::create([
                'rutadistribucionid' => $ruta->rutadistribucionid,
                'imagenfirma' => $imagen,
                'nombrefirmante' => RecepcionQrFirmaService::nombreDesdeUsuario($usuario),
                'firmante_usuarioid' => $usuario->usuarioid,
                'fechafirma' => now(),
            ]);
        });

        app(RecepcionQrFirmaService::class)->ensureToken($ruta);

        app(NotificacionUsuarioService::class)->distribucionPdvPendienteFirmaMinorista(
            $ruta->fresh(['pedidos.puntoVenta', 'transportista'])
        );

        return $firma;
    }

    public function guardarFirmaRecepcion(RutaDistribucion $ruta, Usuario $usuario, string $imagenBase64): FirmaRecepcionEnvio
    {
        $this->validarRutaPdv($ruta);
        FirmaCierreReglas::asegurarPuedeFirmarRecepcion(
            $usuario,
            $ruta->transportista_usuarioid,
            $this->esReceptor($usuario, $ruta),
            'No tiene permiso para firmar la recepción en el punto de venta.',
        );
        $this->validarPreFirmas($ruta);
        $imagen = $this->normalizarImagenFirma($imagenBase64);

        return DB::transaction(function () use ($ruta, $usuario, $imagen) {
            RutaDistribucion::query()->whereKey($ruta->rutadistribucionid)->lockForUpdate()->first();

            if (! $ruta->firmaTransportista()->exists()) {
                throw new InvalidArgumentException('Primero debe firmar el transportista la entrega.');
            }

            $existente = $ruta->firmaRecepcion()->first();
            if (FirmaCierreReglas::recepcionValida($existente, $ruta->transportista_usuarioid)) {
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

            return FirmaRecepcionEnvio::create(['rutadistribucionid' => $ruta->rutadistribucionid] + $datos);
        });
    }

    /** Minorista dueño del punto de venta destino (MIN-07). */
    public function esReceptor(?Usuario $usuario, RutaDistribucion $ruta): bool
    {
        return PuntoVentaAccess::puedeFirmarRecepcionRuta($usuario, $ruta);
    }

    public function finalizarEntrega(RutaDistribucion $ruta, Usuario $usuario): DocumentoEntrega
    {
        $this->autorizarFinalizar($usuario, $ruta);
        $resumen = $this->resumenPasos($ruta);

        if (! ($resumen['puede_finalizar'] ?? false)) {
            throw new InvalidArgumentException('Complete condiciones, llegada, incidentes y firmas antes de finalizar.');
        }

        $documento = DB::transaction(function () use ($ruta, $usuario) {
            // Lock + revalidación: dos finalizaciones concurrentes no acreditan dos veces (MIN-03, TRA-15).
            $bloqueada = RutaDistribucion::query()->whereKey($ruta->rutadistribucionid)->lockForUpdate()->firstOrFail();
            if ($bloqueada->estado === RutaDistribucionCatalogo::ESTADO_COMPLETADA) {
                throw new InvalidArgumentException('Esta entrega ya fue completada.');
            }

            // La entrega logística no acredita inventario sin la recepción del minorista (MIN-10).
            $ruta->load(['firmaTransportista', 'firmaRecepcion']);
            FirmaCierreReglas::asegurarFirmasParaCierre($ruta->firmaTransportista, $ruta->firmaRecepcion, $ruta->transportista_usuarioid);

            $ruta->loadMissing('pedidos');
            foreach ($ruta->pedidos as $pedido) {
                if (PedidoDistribucionCatalogo::puedeConfirmarRecepcion($pedido)) {
                    $this->recepcionPdv->confirmar($pedido, $usuario);
                }
            }

            $ruta->update([
                'estado' => RutaDistribucionCatalogo::ESTADO_COMPLETADA,
            ]);

            $ruta->refresh();
            $documento = $this->generarDocumentoTransporte($ruta, $usuario);
            app(NotificacionUsuarioService::class)->distribucionPdvRecibidaEnTienda(
                $ruta->fresh(['pedidos.puntoVenta', 'transportista'])
            );

            return $documento;
        });

        DocumentoEntregaArchivo::materializarPdfDocumento($documento);

        return $documento;
    }

    public function autorizarConfirmacionLlegada(Usuario $usuario, RutaDistribucion $ruta): void
    {
        if ($this->esTransportistaAsignado($usuario, $ruta) || $this->esAdminOperativo($usuario)) {
            return;
        }

        throw new InvalidArgumentException('No tiene permiso para confirmar la llegada de esta entrega.');
    }

    public function autorizarIncidentes(Usuario $usuario, RutaDistribucion $ruta): void
    {
        if ($this->esTransportistaAsignado($usuario, $ruta) || $this->esAdminOperativo($usuario)) {
            return;
        }

        throw new InvalidArgumentException('No tiene permiso para registrar incidentes en esta entrega.');
    }

    private function autorizarFinalizar(Usuario $usuario, RutaDistribucion $ruta): void
    {
        if (ViajeAcceso::esConductorAsignado($usuario, $ruta->transportista_usuarioid)
            || $this->esAdminOperativo($usuario)
            || PuntoVentaAccess::puedeFirmarRecepcionRuta($usuario, $ruta)
            || MayoristaAccess::puedeGestionarRutaDistribucion($usuario, $ruta)) {
            return;
        }

        throw new InvalidArgumentException('No tiene permiso para finalizar esta entrega.');
    }

    private function validarPreFirmas(RutaDistribucion $ruta): void
    {
        if ($ruta->llegada_confirmada_at === null) {
            throw new InvalidArgumentException('Debe confirmar la llegada antes de las firmas.');
        }

        if (! $ruta->checklistIncidente()->exists()) {
            throw new InvalidArgumentException('Debe registrar incidentes (o «Sin incidentes») antes de las firmas.');
        }
    }

    private function validarRutaPdv(RutaDistribucion $ruta): void
    {
        if ($ruta->esTrasladoPlantaMayorista()) {
            throw new InvalidArgumentException('La ruta no es una distribución mayorista → punto de venta.');
        }
    }

    private function esTransportistaAsignado(Usuario $usuario, RutaDistribucion $ruta): bool
    {
        return (int) $ruta->transportista_usuarioid === (int) $usuario->usuarioid;
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

    private function generarDocumentoTransporte(RutaDistribucion $ruta, Usuario $usuario): DocumentoEntrega
    {
        $codigo = $ruta->codigo ?? ('DIST-'.$ruta->rutadistribucionid);
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $codigo) ?: 'distribucion';
        $path = 'documentos/entrega/'.$slug.'_pdv_'.now()->format('Ymd_His').'.pdf';

        $primerPedido = $ruta->pedidos()->with('detalles')->first();

        $existente = DocumentoEntrega::query()
            ->where('metadata->rutadistribucionid', $ruta->rutadistribucionid)
            ->where('tipo_documento', 'guia_transporte')
            ->where('metadata->envio_cierre_mayorista_pdv', true)
            ->first();

        if ($existente) {
            if ($primerPedido) {
                $existente->update([
                    'metadata' => array_merge($existente->metadata ?? [], [
                        'pedidodistribucionid' => $primerPedido->pedidodistribucionid,
                    ]),
                ]);
            }
            DocumentoEntregaArchivo::generarPdfOperativo($existente);

            return $existente->fresh();
        }

        $documento = DocumentoEntrega::create([
            'externo_envio_id' => $codigo,
            'pedidoid' => null,
            'usuarioid' => $usuario->usuarioid,
            'tipo_documento' => 'guia_transporte',
            'titulo' => 'Comprobante de entrega PDV — '.$codigo,
            'archivo_path' => $path,
            'almacenid' => $ruta->almacen_mayorista_origenid,
            'metadata' => [
                'envio_cierre_mayorista_pdv' => true,
                'rutadistribucionid' => $ruta->rutadistribucionid,
                'pedidodistribucionid' => $primerPedido?->pedidodistribucionid,
            ],
        ]);

        DocumentoEntregaArchivo::generarPdfOperativo($documento);

        return $documento;
    }
}
