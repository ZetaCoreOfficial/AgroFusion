<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EnvioAsignacionMultiple;
use App\Models\Usuario;
use App\Services\CierreEnvioAgricolaService;
use App\Services\NotificacionUsuarioService;
use App\Services\RecepcionPlantaEnvioService;
use App\Services\SimulacionRutaService;
use App\Models\PerfilTransportista;
use App\Models\RutaMultiEntrega;
use App\Models\RutaParada;
use App\Support\EnvioAsignacionEstadoCatalogo;
use App\Support\EnvioListadoService;
use App\Support\EnvioPedidoService;
use App\Support\PedidoCatalogo;
use App\Support\PedidoReservaService;
use App\Support\UsuarioRol;
use App\Support\ViajeAcceso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AsignacionMultipleController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        return $this->listado($request);
    }

    public function show(EnvioAsignacionMultiple $asignacion): View
    {
        $user = auth()->user();
        $esTransportistaAsignado = (int) $asignacion->transportista_usuarioid === (int) $user?->usuarioid;
        // El conductor solo abre SUS envíos (TRA-02): `asignaciones.view` no basta para ver los ajenos.
        $puedeVer = ViajeAcceso::esConductor($user)
            ? $esTransportistaAsignado && PedidoCatalogo::envioOperativoParaTransportista($asignacion)
            : ViajeAcceso::puedeVerEnvioAgricola($user, $asignacion);
        if (! $puedeVer) {
            abort(403, $esTransportistaAsignado
                ? 'Este envío estará disponible cuando producción agrícola confirme el pedido.'
                : 'No tiene permiso para ver este envío.');
        }

        $asignacion->load([
            'pedido.detalles.insumo',
            'transportista.perfilTransportista.vehiculo',
            'asignadoPor',
            'ruta.paradas',
            'almacen',
            'recepcionConfirmadaPor',
        ]);

        $paradasMapa = EnvioPedidoService::paradasMapaEnvio($asignacion);
        $pedido = $asignacion->pedido;
        $logistica = EnvioPedidoService::datosLogistica($asignacion);
        $pendienteAgricola = $pedido !== null && PedidoCatalogo::pendienteAprobacionAgricola($pedido);
        $estadoVisual = $pedido !== null
            ? PedidoCatalogo::badgeEstadoLista($logistica, $pedido)
            : ['clase' => 'env-estado-asignado', 'etiqueta' => EnvioAsignacionEstadoCatalogo::etiqueta($asignacion->estado), 'titulo' => ''];

        return view('logistica.asignaciones.show', [
            'asignacion' => $asignacion,
            'trayectoTexto' => EnvioPedidoService::trayectoTexto($asignacion),
            'trayectoPartes' => EnvioPedidoService::trayectoPartes($asignacion),
            'paradasMapa' => $paradasMapa,
            'urlTrazadoRuta' => $asignacion->ruta
                ? route('logistica.rutas.trazado', $asignacion->ruta)
                : null,
            'llegoDestino' => EnvioAsignacionEstadoCatalogo::llegoADestino($asignacion),
            'puedeGestionar' => EnvioAsignacionEstadoCatalogo::puedeGestionarAdmin($asignacion),
            'puedeEditar' => PedidoCatalogo::puedeEditarAsignacionEnvio($asignacion),
            'logistica' => $logistica,
            'pendienteAgricola' => $pendienteAgricola,
            'estadoVisual' => $estadoVisual,
            'erroresStock' => $pendienteAgricola && $pedido
                ? app(PedidoReservaService::class)->verificarDisponibilidad($pedido)
                : [],
            'puedeAceptarAgricola' => $pendienteAgricola && auth()->user()?->can('pedidos.update'),
        ]);
    }

    public function edit(EnvioAsignacionMultiple $asignacion): View|RedirectResponse
    {
        $asignacion->load(['transportista', 'ruta', 'pedido']);
        $nivelEdicion = PedidoCatalogo::nivelEdicionAsignacionEnvio($asignacion);

        if ($nivelEdicion === PedidoCatalogo::EDICION_ASIGNACION_NINGUNA) {
            return redirect()
                ->route('logistica.asignaciones.show', $asignacion)
                ->with('warning', 'Este envío ya no puede editarse en su estado actual.');
        }

        $pedido = $asignacion->pedido;
        $vehiculo = EnvioPedidoService::resolverVehiculoAsignado($asignacion);
        $recogidasExtra = EnvioPedidoService::recogidasExtraDesdeEnvio($asignacion);
        $origenLabel = $pedido
            ? (EnvioPedidoService::trayectoPartesPedido($pedido)['recogidas'][0] ?? $pedido->origen_direccion ?? '')
            : '';

        $rutaEsAutomatica = $asignacion->ruta !== null
            && EnvioPedidoService::esRutaRecogidasAutomatica($asignacion->ruta);

        $vehiculoLabel = '';
        if ($vehiculo !== null) {
            $vehiculoLabel = (string) $vehiculo->placa;
            $detalleVehiculo = trim(($vehiculo->marca ?? '').' '.($vehiculo->modelo ?? ''));
            if ($detalleVehiculo !== '') {
                $vehiculoLabel .= ' — '.$detalleVehiculo;
            }
        }

        $fechaEntregaValor = old(
            'fechaEntregaDeseada',
            optional($pedido?->fechaEntregaDeseada)->format('Y-m-d') ?: now()->format('Y-m-d')
        );

        return view('logistica.asignaciones.edit', compact(
            'asignacion',
            'pedido',
            'vehiculo',
            'vehiculoLabel',
            'recogidasExtra',
            'origenLabel',
            'rutaEsAutomatica',
            'nivelEdicion',
            'fechaEntregaValor',
        ));
    }

    public function update(Request $request, EnvioAsignacionMultiple $asignacion): RedirectResponse
    {
        $asignacion->loadMissing('pedido');
        $nivelEdicion = PedidoCatalogo::nivelEdicionAsignacionEnvio($asignacion);

        if ($nivelEdicion === PedidoCatalogo::EDICION_ASIGNACION_NINGUNA) {
            return redirect()
                ->route('logistica.asignaciones.show', $asignacion)
                ->with('error', 'Este envío ya no puede editarse en su estado actual.');
        }

        if ($nivelEdicion === PedidoCatalogo::EDICION_ASIGNACION_SOLO_TRANSPORTISTA) {
            $validated = $request->validate([
                'transportista_usuarioid' => ['nullable', 'integer', 'exists:usuario,usuarioid'],
            ]);
        } else {
            $rules = [
                'transportista_usuarioid' => ['nullable', 'integer', 'exists:usuario,usuarioid'],
                'vehiculoid' => ['nullable', 'integer', 'exists:vehiculo,vehiculoid'],
                'vehiculo_ref' => ['nullable', 'string', 'max:80'],
                'fechaEntregaDeseada' => ['nullable', 'date'],
                'origen_latitud' => ['nullable', 'numeric', 'between:-90,90'],
                'origen_longitud' => ['nullable', 'numeric', 'between:-180,180'],
                'origen_direccion' => ['nullable', 'string', 'max:255'],
                'recogidas' => ['nullable', 'array', 'max:5'],
                'recogidas.*.latitud' => ['required_with:recogidas', 'numeric', 'between:-90,90'],
                'recogidas.*.longitud' => ['required_with:recogidas', 'numeric', 'between:-180,180'],
                'recogidas.*.direccion' => ['nullable', 'string', 'max:255'],
            ];

            if ($asignacion->pedidoid) {
                $rules['origen_latitud'] = ['required', 'numeric', 'between:-90,90'];
                $rules['origen_longitud'] = ['required', 'numeric', 'between:-180,180'];
            }

            $validated = $request->validate($rules);

            if (! empty($validated['transportista_usuarioid']) xor ! empty($validated['vehiculoid'])) {
                return back()->withInput()->with('error', 'Seleccione transportista y vehículo juntos, o deje ambos vacíos.');
            }
        }

        try {
            EnvioPedidoService::actualizarAsignacionEnvio(
                $asignacion,
                $validated,
                (int) auth()->id(),
                $nivelEdicion
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('logistica.asignaciones.show', $asignacion)
            ->with('success', 'Asignación actualizada correctamente.');
    }

    public function destroy(EnvioAsignacionMultiple $asignacion): RedirectResponse
    {
        if (! EnvioAsignacionEstadoCatalogo::puedeGestionarAdmin($asignacion)) {
            return redirect()
                ->route('logistica.asignaciones.listado')
                ->with('error', 'No puede eliminar un envío que ya llegó a destino.');
        }

        $codigo = $asignacion->externo_envio_id;
        $asignacion->delete();

        return redirect()
            ->route('logistica.asignaciones.listado')
            ->with('success', "El envío {$codigo} fue eliminado.");
    }

    public function listado(Request $request): View
    {
        abort_unless(
            $request->user()?->can('asignaciones.view') || $request->user()?->can('pedidos.view'),
            403
        );

        return view('logistica.envios.index', EnvioListadoService::prepararListado($request));
    }

    public function create(Request $request): View
    {
        $enviosPendientes = $this->enviosPendientesDeAsignar(100);

        $transportistaSeleccionado = null;
        $vehiculoPlaca = '';

        if ($request->filled('transportista')) {
            $transportistaSeleccionado = Usuario::query()
                ->where('role', 'transportista')
                ->where('activo', true)
                ->with('perfilTransportista.vehiculo')
                ->find((int) $request->transportista);

            $vehiculoPlaca = $transportistaSeleccionado?->perfilTransportista?->vehiculo?->placa ?? '';
        }

        return view('logistica.asignaciones.create', compact(
            'enviosPendientes',
            'transportistaSeleccionado',
            'vehiculoPlaca'
        ));
    }

    public function seleccionarTransportista(Request $request): View
    {
        $query = Usuario::query()
            ->where('role', 'transportista')
            ->with('perfilTransportista.vehiculo')
            ->orderBy('nombre')
            ->orderBy('apellido');

        if ($request->filled('buscar')) {
            $term = '%'.$request->string('buscar')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('nombre', 'like', $term)
                    ->orWhere('apellido', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('telefono', 'like', $term);
            });
        }

        if ($request->filled('placa')) {
            $placa = '%'.$request->string('placa')->trim().'%';
            $query->whereHas('perfilTransportista.vehiculo', fn ($v) => $v->where('placa', 'like', $placa));
        }

        $estado = $request->string('estado')->toString();
        if ($estado === 'inactivo') {
            $query->where('activo', false);
        } elseif ($estado === 'todos') {
            // sin filtro adicional
        } else {
            $query->where('activo', true);
        }

        $transportistas = $query->paginate(12)->withQueryString();

        return view('logistica.asignaciones.seleccionar-transportista', compact('transportistas'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'externo_envio_id' => ['required', 'string', 'max:64'],
            'pedidoid' => ['nullable', 'integer', 'exists:pedido,pedidoid'],
            'transportista_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'rutamultientregaid' => ['nullable', 'integer', 'exists:ruta_multi_entrega,rutamultientregaid'],
            'vehiculo_ref' => ['nullable', 'string', 'max:80'],
            'almacenid' => ['nullable', 'integer', 'exists:almacen,almacenid'],
        ]);

        $envioPendiente = EnvioAsignacionMultiple::query()
            ->with('pedido')
            ->where('externo_envio_id', $validated['externo_envio_id'])
            ->first();

        if ($envioPendiente && ! $this->envioListoParaLogistica($envioPendiente)) {
            return back()->with('error', 'Producción agrícola debe aceptar el pedido y reservar stock antes de asignar transportista.');
        }

        EnvioAsignacionMultiple::updateOrCreate(
            [
                'externo_envio_id' => $validated['externo_envio_id'],
                'transportista_usuarioid' => $validated['transportista_usuarioid'],
            ],
            EnvioAsignacionEstadoCatalogo::applyToAttributes([
                'pedidoid' => $validated['pedidoid'] ?? null,
                'asignadopor_usuarioid' => auth()->id(),
                'rutamultientregaid' => $validated['rutamultientregaid'] ?? null,
                'vehiculo_ref' => $validated['vehiculo_ref'] ?? null,
                'almacenid' => $validated['almacenid'] ?? null,
                'estado' => 'asignado',
                'fecha_asignacion' => now(),
            ])
        );

        return back()->with('success', 'Envío asignado correctamente.');
    }

    public function storeBatch(Request $request): RedirectResponse|Response
    {
        $validated = $request->validate([
            'envio_ids' => ['required', 'array', 'min:1'],
            'envio_ids.*' => ['required', 'string', 'max:64'],
            'transportista_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'rutamultientregaid' => ['nullable', 'integer', 'exists:ruta_multi_entrega,rutamultientregaid'],
            'vehiculo_ref' => ['nullable', 'string', 'max:80'],
            'almacenid' => ['nullable', 'integer', 'exists:almacen,almacenid'],
        ]);

        $transportistaId = (int) $validated['transportista_usuarioid'];
        $vehiculoDefault = $validated['vehiculo_ref'] ?? $this->vehiculoRefForTransportista($transportistaId);

        $bloqueo = $this->validarEnviosListosParaLogistica($validated['envio_ids']);
        if ($bloqueo !== null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $bloqueo], 422);
            }

            return back()->with('error', $bloqueo);
        }

        $enviosNotificar = [];

        foreach ($validated['envio_ids'] as $envioId) {
            $pendiente = EnvioAsignacionMultiple::query()
                ->where('externo_envio_id', $envioId)
                ->whereNull('transportista_usuarioid')
                ->first();

            $attrs = EnvioAsignacionEstadoCatalogo::applyToAttributes([
                'transportista_usuarioid' => $transportistaId,
                'pedidoid' => $pendiente?->pedidoid,
                'asignadopor_usuarioid' => auth()->id(),
                'rutamultientregaid' => $validated['rutamultientregaid'] ?? null,
                'vehiculo_ref' => $vehiculoDefault ?? $pendiente?->vehiculo_ref,
                'almacenid' => $validated['almacenid'] ?? null,
                'estado' => 'asignado',
                'fecha_asignacion' => now(),
            ]);

            if ($pendiente) {
                $pendiente->update($attrs);
                $enviosNotificar[] = $pendiente->fresh(['pedido.detalles']);
                continue;
            }

            $existente = EnvioAsignacionMultiple::query()
                ->where('externo_envio_id', $envioId)
                ->first();

            $asignacion = EnvioAsignacionMultiple::updateOrCreate(
                [
                    'externo_envio_id' => $envioId,
                    'transportista_usuarioid' => $transportistaId,
                ],
                array_merge($attrs, [
                    'pedidoid' => $existente?->pedidoid,
                ])
            );
            $enviosNotificar[] = $asignacion->fresh(['pedido.detalles']);
        }

        $notificaciones = app(NotificacionUsuarioService::class);
        foreach ($enviosNotificar as $envioAsignado) {
            if ($envioAsignado->pedido && PedidoCatalogo::listoParaLogistica($envioAsignado->pedido)) {
                $notificaciones->envioListoParaRecoger($envioAsignado);
            }
        }

        $transportista = Usuario::query()->find($transportistaId);
        $nombreTransportista = trim(($transportista?->nombre ?? '').' '.($transportista?->apellido ?? '')) ?: ($transportista?->nombreusuario ?? 'Transportista');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Los envíos se asignaron correctamente al chofer seleccionado.',
                'transportista' => $nombreTransportista,
                'transportista_id' => $transportistaId,
                'vehiculo' => $vehiculoDefault ?? '—',
                'envios' => array_values($validated['envio_ids']),
                'cantidad' => count($validated['envio_ids']),
                'urls' => [
                    'listado' => route('logistica.asignaciones.listado', [
                        'transportista' => $transportistaId,
                    ]),
                    'nueva' => route('logistica.asignaciones.create'),
                    'documentos' => route('logistica.documentos.index'),
                ],
            ]);
        }

        return redirect()
            ->route('logistica.asignaciones.create')
            ->with('success', 'Los envíos se asignaron correctamente al chofer seleccionado.');
    }

    public function markEnTransportePlanta(EnvioAsignacionMultiple $asignacion): RedirectResponse
    {
        return $this->empezarRuta($asignacion);
    }

    public function empezarRuta(EnvioAsignacionMultiple $asignacion, SimulacionRutaService $simulacion): RedirectResponse
    {
        $user = auth()->user();
        // Solo el conductor asignado inicia SU ruta (TRA-08); ningún otro permiso lo sustituye.
        abort_unless(ViajeAcceso::esConductorAsignado($user, $asignacion->transportista_usuarioid), 403);

        if (! PedidoCatalogo::envioOperativoParaTransportista($asignacion)) {
            abort(403, 'Este envío estará disponible cuando producción agrícola confirme el pedido.');
        }

        try {
            $simulacion->empezarAgricola($asignacion);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('logistica.asignaciones.cierre.panel', $asignacion)
            ->with('success', 'Ruta iniciada. Confirme la llegada cuando llegue al destino.');
    }

    public function markLlegadaDestino(
        EnvioAsignacionMultiple $asignacion,
        CierreEnvioAgricolaService $cierre,
    ): RedirectResponse {
        $user = auth()->user();
        abort_unless(ViajeAcceso::esConductorAsignado($user, $asignacion->transportista_usuarioid), 403);

        if ($asignacion->fecha_recepcion_planta) {
            return back()->with('error', 'Este envío ya fue recibido en planta.');
        }

        $resumen = $cierre->resumenPasos($asignacion);

        if ($resumen['puede_confirmar_llegada'] ?? false) {
            try {
                $cierre->confirmarLlegada($asignacion, $user);
            } catch (\InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage());
            }

            return redirect()
                ->route('logistica.asignaciones.cierre.panel', $asignacion)
                ->with('success', 'Llegada confirmada. Registre incidentes para continuar el cierre.');
        }

        return redirect()
            ->route('logistica.asignaciones.cierre.panel', $asignacion)
            ->with('info', 'Complete el cierre operativo del envío (condiciones, llegada, incidentes y firmas).');
    }

    /** @deprecated La recepción en planta se confirma desde Gestión de pedidos. */
    public function markDelivered(EnvioAsignacionMultiple $asignacion): RedirectResponse
    {
        return redirect()
            ->route('logistica.asignaciones.listado')
            ->with('warning', 'Confirme la llegada a planta desde el listado unificado de envíos.');
    }

    /**
     * Asigna en bloque los envíos pendientes a un chofer y, si se pide, crea la ruta de entrega.
     */
    public function asignarAutomatica(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'transportista_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'vehiculo_ref' => ['nullable', 'string', 'max:80'],
            'rutamultientregaid' => ['nullable', 'integer', 'exists:ruta_multi_entrega,rutamultientregaid'],
            'crear_ruta' => ['nullable', 'boolean'],
        ]);

        $envios = $this->enviosPendientesDeAsignar(50)
            ->filter(fn (EnvioAsignacionMultiple $envio) => $this->envioListoParaLogistica($envio));

        if ($envios->isEmpty()) {
            return redirect()
                ->route('logistica.asignaciones.index')
                ->with('warning', 'No hay envíos listos para asignar. Producción agrícola debe aceptar los pedidos y reservar stock primero.');
        }

        $transportistaId = (int) $validated['transportista_usuarioid'];
        $chofer = Usuario::find($transportistaId);
        $vehiculoRef = $validated['vehiculo_ref'] ?? $this->vehiculoRefForTransportista($transportistaId);
        $rutaIdFinal = isset($validated['rutamultientregaid']) ? (int) $validated['rutamultientregaid'] : null;
        $crearRuta = $request->boolean('crear_ruta', true);

        $asignados = DB::transaction(function () use ($envios, $transportistaId, $validated, $rutaIdFinal, $crearRuta, $chofer, $vehiculoRef) {
            $rutaId = $rutaIdFinal;

            if ($crearRuta && ! $rutaId) {
                $ruta = RutaMultiEntrega::create([
                    'nombre' => 'Ruta '.now()->format('d/m/Y H:i').' — '.($chofer?->nombreusuario ?? 'chofer'),
                    'creadopor_usuarioid' => auth()->id(),
                    'transportista_usuarioid' => $transportistaId,
                    'fecha_salida' => now(),
                    'estado' => 'planificada',
                ]);
                $rutaId = $ruta->rutamultientregaid;

                foreach ($envios as $index => $envio) {
                    RutaParada::create([
                        'rutamultientregaid' => $rutaId,
                        'orden' => $index + 1,
                        'destino' => $envio->pedido?->nombre_planta
                            ?: $envio->pedido?->direccion_texto
                            ?: 'Entrega '.$envio->externo_envio_id,
                        'externo_envio_id' => $envio->externo_envio_id,
                        'pedidoid' => $envio->pedidoid,
                        'estado' => 'pendiente',
                    ]);
                }
            }

            $count = 0;
            foreach ($envios as $envio) {
                $envio->update(EnvioAsignacionEstadoCatalogo::applyToAttributes([
                    'transportista_usuarioid' => $transportistaId,
                    'asignadopor_usuarioid' => auth()->id(),
                    'rutamultientregaid' => $rutaId,
                    'vehiculo_ref' => $vehiculoRef ?? $envio->vehiculo_ref,
                    'estado' => 'asignado',
                    'fecha_asignacion' => now(),
                ]));
                $count++;
            }

            return ['count' => $count, 'ruta_id' => $rutaId];
        });

        $msg = "Se asignaron {$asignados['count']} envíos al chofer ".($chofer?->nombreusuario ?? '').'.';
        if (! empty($asignados['ruta_id'])) {
            $msg .= ' También se creó o vinculó una ruta de entrega.';
        }

        return redirect()
            ->route('logistica.asignaciones.create')
            ->with('success', $msg);
    }

    private function vehiculoRefForTransportista(int $transportistaId): ?string
    {
        $perfil = PerfilTransportista::query()
            ->with('vehiculo')
            ->where('usuarioid', $transportistaId)
            ->first();

        return $perfil?->vehiculo?->placa;
    }

    /**
     * Envíos que aún no tienen chofer o siguen en situación pendiente.
     *
     * @return \Illuminate\Support\Collection<int, EnvioAsignacionMultiple>
     */
    private function enviosPendientesDeAsignar(int $limit = 30)
    {
        return EnvioAsignacionMultiple::query()
            ->with('pedido')
            ->whereNotIn('estado', ['entregado', 'cancelado'])
            ->where(function ($q) {
                $q->whereNull('transportista_usuarioid')
                    ->orWhereRaw('LOWER(TRIM(COALESCE(estado, \'\'))) = ?', ['pendiente']);
            })
            ->orderByDesc('envioasignacionmultipleid')
            ->limit($limit)
            ->get();
    }

    private function envioListoParaLogistica(EnvioAsignacionMultiple $envio): bool
    {
        if (! $envio->relationLoaded('pedido')) {
            $envio->load('pedido');
        }

        if (! $envio->pedido) {
            return false;
        }

        return PedidoCatalogo::listoParaLogistica($envio->pedido);
    }

    /**
     * @param  array<int, string>  $envioIds
     */
    private function validarEnviosListosParaLogistica(array $envioIds): ?string
    {
        $envios = EnvioAsignacionMultiple::query()
            ->with('pedido')
            ->whereIn('externo_envio_id', $envioIds)
            ->get();

        foreach ($envios as $envio) {
            if (! $this->envioListoParaLogistica($envio)) {
                return "El envío {$envio->externo_envio_id} requiere aceptación de producción agrícola antes de asignar transportista.";
            }
        }

        return null;
    }

    private function marcarRecibidoPlantaSimple(EnvioAsignacionMultiple $asignacion, Usuario $user): void
    {
        $asignacion->update(EnvioAsignacionEstadoCatalogo::applyToAttributes([
            'estado' => 'recibido_planta',
            'fecha_recepcion_planta' => now(),
            'recepcion_usuarioid' => $user->usuarioid,
        ]));
    }

    /**
     * @return array<string, int>
     */
    private function resumenEnviosAsignados(): array
    {
        $base = EnvioAsignacionMultiple::query();

        return [
            'total' => (clone $base)->count(),
            'asignados' => (clone $base)->whereIn('estado', ['asignado', 'asignada', 'pendiente', 'creada'])->count(),
            'en_camino' => (clone $base)->whereIn('estado', ['en_transporte_planta', 'en_ruta', 'en_transito'])->count(),
            'recibidos' => (clone $base)->where(function ($q) {
                $q->whereIn('estado', ['recibido_planta', 'entregado', 'entregada'])
                    ->orWhereNotNull('fecha_recepcion_planta');
            })->count(),
            'recibidos_hoy' => (clone $base)->whereDate('fecha_recepcion_planta', now()->toDateString())->count(),
        ];
    }

    public function aceptarPedidoAgricola(
        Request $request,
        EnvioAsignacionMultiple $asignacion,
        NotificacionUsuarioService $notificaciones
    ): RedirectResponse {
        abort_unless($request->user()?->can('pedidos.update'), 403);

        $asignacion->loadMissing('pedido');
        $pedido = $asignacion->pedido;
        if (! $pedido) {
            return back()->with('error', 'Este envío no tiene pedido agrícola asociado.');
        }

        if (! PedidoCatalogo::pendienteAprobacionAgricola($pedido)) {
            return back()->with('warning', 'Este envío ya fue procesado por producción agrícola.');
        }

        $reserva = app(PedidoReservaService::class);
        $envioActivado = null;

        try {
            DB::transaction(function () use ($pedido, $reserva, $asignacion, &$envioActivado) {
                $reserva->reservar($pedido);

                $pedido->update([
                    'estado' => PedidoCatalogo::ESTADO_CONFIRMADO,
                    'fecha_aceptacion_agricola' => now(),
                    'aceptado_por_usuarioid' => auth()->id(),
                ]);

                EnvioPedidoService::activarTransportistaProgramado($pedido->fresh());

                if (! $asignacion->transportista_usuarioid) {
                    $asignacion->update(EnvioAsignacionEstadoCatalogo::applyToAttributes([
                        'estado' => 'pendiente',
                    ]));
                }

                if ($asignacion->transportista_usuarioid) {
                    $envioActivado = $asignacion->fresh(['pedido.detalles']);
                }
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($envioActivado) {
            $notificaciones->envioListoParaRecoger($envioActivado);
        }

        $mensaje = $asignacion->fresh()->transportista_usuarioid
            ? 'Envío aceptado. Se reservó material del almacén agrícola y el transportista programado quedó asignado.'
            : 'Envío aceptado. Se reservó material del almacén agrícola. Ya se puede asignar un transportista.';

        return redirect()
            ->route('logistica.asignaciones.show', $asignacion)
            ->with('success', $mensaje);
    }

    public function rechazarPedidoAgricola(Request $request, EnvioAsignacionMultiple $asignacion): RedirectResponse
    {
        abort_unless($request->user()?->can('pedidos.update'), 403);

        $asignacion->loadMissing('pedido');
        $pedido = $asignacion->pedido;
        if (! $pedido) {
            return back()->with('error', 'Este envío no tiene pedido agrícola asociado.');
        }

        if (! PedidoCatalogo::pendienteAprobacionAgricola($pedido)) {
            return back()->with('warning', 'Este envío ya fue procesado.');
        }

        $data = $request->validate([
            'motivo_rechazo' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($pedido, $asignacion, $data) {
            $obs = trim(($pedido->observaciones ?? '')."\n[Rechazado agrícola] ".($data['motivo_rechazo'] ?? 'Sin motivo.'));

            $pedido->update([
                'estado' => PedidoCatalogo::ESTADO_RECHAZADO,
                'observaciones' => $obs,
            ]);

            $asignacion->update(EnvioAsignacionEstadoCatalogo::applyToAttributes([
                'estado' => 'cancelado',
            ]));
        });

        return redirect()
            ->route('logistica.asignaciones.show', $asignacion)
            ->with('success', 'Envío rechazado. Logística no podrá despachar esta solicitud.');
    }
}

