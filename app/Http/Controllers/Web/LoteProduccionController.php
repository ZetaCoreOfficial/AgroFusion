<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\AlmacenajeLoteProduccion;
use App\Models\AsignacionEtapaPlanta;
use App\Models\EvaluacionFinalLoteProduccion;
use App\Models\LoteProduccionPedido;
use App\Models\MaquinaPlanta;
use App\Models\Pedido;
use App\Models\RegistroProcesoMaquinaPlanta;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\AlmacenCapacidadService;
use App\Services\InventarioPresentacionService;
use App\Services\LoteProduccionPlantaService;
use App\Services\PresentacionProductoPlantaService;
use App\Services\ProductoPlantaInventarioService;
use App\Support\AlmacenAmbito;
use App\Support\AlmacenajeLoteCondiciones;
use App\Support\AsignacionEtapaPlantaService;
use App\Support\EmpaquePlantaCatalogo;
use App\Support\LoteProduccionNombre;
use App\Support\LoteProduccionParametrosService;
use App\Support\LoteProduccionRutaService;
use App\Support\LoteProduccionTrazabilidadService;
use App\Support\LoteProduccionTransformacionService;
use App\Support\MaquinaProcesoCompatibilidad;
use App\Support\ParametroRangoPlanta;
use App\Support\ProcesoPlantaCatalogo;
use App\Support\ProductoPlantaCatalogo;
use App\Support\UsuarioRol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoteProduccionController extends Controller
{
    public function __construct(
        private readonly LoteProduccionPlantaService $loteService,
        private readonly LoteProduccionTrazabilidadService $trazabilidad,
        private readonly LoteProduccionTransformacionService $transformacion,
        private readonly ProductoPlantaInventarioService $inventarioPlanta,
        private readonly InventarioPresentacionService $inventarioPresentacion,
        private readonly PresentacionProductoPlantaService $presentacionPlanta,
        private readonly AlmacenCapacidadService $capacidadService,
        private readonly AsignacionEtapaPlantaService $asignacionEtapa,
    ) {}

    public function index(Request $request): View
    {
        $productoFiltro = LoteProduccionNombre::normalizarProducto((string) $request->query('producto', ''));
        $estadoFiltro = (string) $request->query('estado', '');
        $busqueda = trim((string) $request->query('q', ''));
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        $user = $request->user();
        $baseQuery = LoteProduccionPedido::query();
        $this->aplicarFiltroOperarioPlanta($baseQuery, $user);

        $lotesQuery = (clone $baseQuery)
            ->with(['pedido', 'unidadMedida', 'materiasPrimas.insumo', 'procesoPlanta', 'evaluacionesFinales', 'almacenajes'])
            ->orderByDesc('fecha_creacion');

        if ($productoFiltro !== '') {
            $term = '%'.mb_strtolower($productoFiltro).'%';
            $lotesQuery->where(function ($q) use ($term) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('lote_produccion_pedido', 'producto')) {
                    $q->whereRaw('LOWER(producto) LIKE ?', [$term]);
                }
                $q->orWhereRaw('LOWER(nombre) LIKE ?', [$term]);
            });
        }

        if ($busqueda !== '') {
            $term = '%'.mb_strtolower($busqueda).'%';
            $lotesQuery->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(codigo_lote) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', [$term])
                    ->orWhereHas('pedido', fn ($p) => $p->whereRaw('LOWER(numero_solicitud) LIKE ?', [$term]));
            });
        }

        if ($desde) {
            $lotesQuery->whereDate('fecha_creacion', '>=', $desde);
        }
        if ($hasta) {
            $lotesQuery->whereDate('fecha_creacion', '<=', $hasta);
        }

        if ($estadoFiltro === 'pendiente') {
            $lotesQuery->whereNull('hora_fin')
                ->whereDoesntHave('evaluacionesFinales')
                ->whereDoesntHave('registrosProceso');
        } elseif ($estadoFiltro === 'en_proceso') {
            $lotesQuery->whereNull('hora_fin')
                ->whereHas('registrosProceso')
                ->whereDoesntHave('almacenajes');
        } elseif ($estadoFiltro === 'completado') {
            $lotesQuery->where(function ($q) {
                $q->whereNotNull('hora_fin')->orWhereHas('almacenajes');
            });
        }

        $lotes = $lotesQuery->paginate(15)->withQueryString();

        $lotes->getCollection()->transform(function (LoteProduccionPedido $lote) {
            $lote->fase_label = LoteProduccionTrazabilidadService::FASES[$this->trazabilidad->resolverFaseActual($lote)]['label'] ?? '—';
            $lote->estado_operativo = $this->trazabilidad->estadoOperativo($lote);

            return $lote;
        });

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'pendientes' => (clone $baseQuery)->whereNull('hora_fin')->whereDoesntHave('registrosProceso')->count(),
            'en_proceso' => (clone $baseQuery)->whereNull('hora_fin')->whereHas('registrosProceso')->whereDoesntHave('almacenajes')->count(),
            'completados' => (clone $baseQuery)->where(fn ($q) => $q->whereNotNull('hora_fin')->orWhereHas('almacenajes'))->count(),
        ];

        $pedidoLabel = '';
        if (old('pedidoid')) {
            $pedidoLabel = Pedido::find(old('pedidoid'))?->numero_solicitud ?? '';
        }

        $almacenes = AlmacenAmbito::scope(Almacen::query(), AlmacenAmbito::PLANTA)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        $filtroAlmacenes = array_merge(
            [['value' => '', 'label' => 'Todos los almacenes de planta']],
            $almacenes->map(fn (Almacen $a) => [
                'value' => (string) $a->almacenid,
                'label' => $a->nombre,
            ])->all()
        );

        $estadosPedido = ['pendiente', 'confirmado', 'en produccion', 'aprobado', 'aceptado', 'en_proceso', 'asignado'];
        $existentes = Pedido::query()->distinct()->pluck('estado')->filter()->all();
        $todosEstados = array_values(array_unique(array_merge($estadosPedido, $existentes)));
        $filtroEstadosPedido = array_merge(
            [['value' => '', 'label' => 'Todos los estados']],
            array_map(fn ($e) => [
                'value' => $e,
                'label' => ucfirst(str_replace('_', ' ', (string) $e)),
            ], $todosEstados)
        );

        $unidadesMedida = UnidadMedida::query()->orderBy('nombre')->get();
        $productosLote = LoteProduccionNombre::productosDistintos();
        $procesosPlanta = \App\Support\ProcesoPlantaCatalogo::activosOrdenados();
        $plantillaLabel = null;
        if (old('plantillatransformacionid')) {
            $pltOld = \App\Models\PlantillaTransformacion::query()
                ->find((int) old('plantillatransformacionid'));
            $plantillaLabel = $pltOld?->nombre;
        }

        $empaquesPlanta = EmpaquePlantaCatalogo::opcionesSelect();

        return view('procesamiento.index', compact(
            'lotes',
            'stats',
            'pedidoLabel',
            'almacenes',
            'filtroAlmacenes',
            'filtroEstadosPedido',
            'unidadesMedida',
            'productosLote',
            'productoFiltro',
            'estadoFiltro',
            'busqueda',
            'desde',
            'hasta',
            'procesosPlanta',
            'plantillaLabel',
            'empaquesPlanta',
        ));
    }

    public function show(LoteProduccionPedido $loteProduccion): View
    {
        abort_unless(
            auth()->user()?->can('lote_produccion.view') || auth()->user()?->can('lote_produccion.create'),
            403
        );

        $this->autorizarLoteOperarioPlanta($loteProduccion, auth()->user());

        $loteProduccion->load(['pedido', 'unidadMedida', 'materiasPrimas.insumo.unidadMedida', 'almacenajes']);

        if ($loteProduccion->almacenajes->isNotEmpty() && ! $loteProduccion->hora_fin) {
            $loteProduccion->update([
                'hora_fin' => $loteProduccion->almacenajes->max('fecha_almacenaje') ?? now(),
            ]);
            $loteProduccion->refresh();
        }

        $dash = $this->trazabilidad->dashboardLote($loteProduccion);
        $procesosPlanta = ProcesoPlantaCatalogo::paraTransformacion();
        $procesosDisponibles = $this->transformacion->procesosDisponiblesParaAsignar($loteProduccion);
        $procesosUsadosIds = $this->transformacion->procesosRegistradosIds($loteProduccion);
        $maquinasPlanta = MaquinaPlanta::query()->where('activo', true)->orderBy('nombre')->get();
        $mapaCompatibilidad = MaquinaProcesoCompatibilidad::mapaSelectores();
        $almacenesPlanta = AlmacenAmbito::scope(
            Almacen::with(['tipoAlmacen', 'unidadMedida', 'almacenamientos'])->where('activo', true),
            AlmacenAmbito::PLANTA
        )->orderBy('nombre')->get();
        $resumenesCapacidadPlanta = [];
        foreach ($almacenesPlanta as $almacenPlanta) {
            $resumenesCapacidadPlanta[$almacenPlanta->almacenid] = $this->capacidadService->resumen($almacenPlanta);
        }
        $datosAlmacenEnvioPlanta = [];
        if (($dash['panel_fase_activo'] ?? '') === 'almacenaje' && empty($dash['almacenaje'])) {
            $datosAlmacenEnvioPlanta = app(\App\Support\AlmacenEnvioSelectorService::class)->datosPlanta();
        }
        $condicionesAlmacenaje = AlmacenajeLoteCondiciones::opciones();

        $cantidadProductoAlmacen = (float) ($loteProduccion->cantidad_objetivo ?? 0);
        if ($cantidadProductoAlmacen <= 0) {
            $cantidadProductoAlmacen = (float) $loteProduccion->materiasPrimas->sum('cantidad_usada');
        }
        $productoLote = LoteProduccionNombre::productoDesdeLote($loteProduccion);
        $produccionEstimada = ProductoPlantaCatalogo::resumenProduccion($loteProduccion, $this->capacidadService);
        $unidadProductoAlmacen = ProductoPlantaCatalogo::unidadEtiqueta(
            $productoLote,
            $loteProduccion->unidadMedida,
            $loteProduccion
        );
        $cantidadProductoAlmacen = $produccionEstimada['cantidad'] > 0
            ? $produccionEstimada['cantidad']
            : $cantidadProductoAlmacen;
        $cantidadProductoAlmacenKg = $produccionEstimada['kg'];
        $almacenajeRegistrado = $loteProduccion->almacenajes->first();
        $cantidadAlmacenajeMostrar = ProductoPlantaCatalogo::cantidadAlmacenajeMostrar(
            $loteProduccion,
            $this->capacidadService,
            $almacenajeRegistrado ? (float) $almacenajeRegistrado->cantidad : null
        );

        $loteProduccion->load('plantillaTransformacion.pasos.proceso', 'plantillaTransformacion.pasos.maquina');

        $rutaService = app(LoteProduccionRutaService::class);
        $rutaService->asegurarRuta($loteProduccion);
        $rutaService->repararEmpaquetadoAlFinal($loteProduccion);
        $loteProduccion->refresh();

        $this->asignacionEtapa->sincronizarPromocionCola($loteProduccion);
        $this->transformacion->limpiarAsignacionesObsoletas($loteProduccion);

        $rutaPlantilla = $this->transformacion->rutaPlantilla($loteProduccion);
        $siguientePasoPlantilla = $this->transformacion->siguientePasoPlantilla($loteProduccion);
        $user = auth()->user();
        $puedeAsignarEtapa = UsuarioRol::gestionaPlanta($user);
        $puedeCertificar = $puedeAsignarEtapa;
        $operadoresPlanta = $puedeAsignarEtapa
            ? UsuarioRol::queryOperariosPlanta()->orderBy('nombre')->orderBy('apellido')->get()
            : collect();
        $asignacionesPendientesLote = $this->transformacion->asignacionesPendientes($loteProduccion);

        $parametrosLote = app(LoteProduccionParametrosService::class)
            ->parametrosEfectivosPorPlantilla($loteProduccion);
        $ordenActual = $this->transformacion->ordenPasoActual($loteProduccion);
        $parametrosEtapaActual = null;
        if ($rutaService->tieneRuta($loteProduccion)) {
            $pasoRuta = $rutaService->pasoEnOrden($loteProduccion, $ordenActual);
            if ($pasoRuta) {
                $pasoRuta->loadMissing(['proceso', 'variables.variableEstandar']);
                $parametrosEtapaActual = [
                    'orden' => $ordenActual,
                    'proceso' => $pasoRuta->proceso?->nombre ?? '—',
                    'variables' => $pasoRuta->variables->map(fn ($v) => [
                        'nombre' => $v->variableEstandar?->nombre ?? '—',
                        'unidad' => $v->variableEstandar?->unidad,
                        'valor_minimo' => (float) $v->valor_minimo,
                        'valor_maximo' => (float) $v->valor_maximo,
                        'es_override' => false,
                    ])->values()->all(),
                ];
            }
        } else {
            $parametrosEtapaActual = collect($parametrosLote)->firstWhere('orden', $ordenActual);
        }

        $parametrosPorAsignacion = [];
        foreach ($asignacionesPendientesLote as $asig) {
            $parametrosPorAsignacion[(int) $asig->asignacionetapaplantaid] = app(LoteProduccionParametrosService::class)
                ->parametrosRequeridosParaAsignacion($asig);
        }

        $timelineVisual = $this->transformacion->timelineVisual($loteProduccion);
        $pasosPlanAsignacion = [];
        if ($puedeAsignarEtapa && $this->asignacionEtapa->puedeAsignarPlan($loteProduccion)) {
            $completadosRuta = $this->transformacion->etapasCompletadasCount($loteProduccion);
            $pasosPlanAsignacion = $rutaService->pasosOrdenados($loteProduccion)
                ->where('orden', '>', $completadosRuta)
                ->values();
        }
        $asignacionesPlanLote = $this->transformacion->asignacionesActivas($loteProduccion);

        return view('procesamiento.show', array_merge($dash, [
            'lote' => $loteProduccion,
            'rutaPlantilla' => $rutaPlantilla,
            'siguientePasoPlantilla' => $siguientePasoPlantilla,
            'procesosPlanta' => $procesosPlanta,
            'procesosDisponibles' => $procesosDisponibles,
            'procesosUsadosIds' => $procesosUsadosIds,
            'maquinasPlanta' => $maquinasPlanta,
            'mapaCompatibilidad' => $mapaCompatibilidad,
            'almacenesPlanta' => $almacenesPlanta,
            'resumenesCapacidadPlanta' => $resumenesCapacidadPlanta,
            'condicionesAlmacenaje' => $condicionesAlmacenaje,
            'cantidadProductoAlmacen' => $cantidadProductoAlmacen,
            'cantidadProductoAlmacenKg' => $cantidadProductoAlmacenKg,
            'unidadProductoAlmacen' => $unidadProductoAlmacen,
            'produccionEstimada' => $produccionEstimada,
            'cantidadAlmacenajeMostrar' => $cantidadAlmacenajeMostrar,
            'fases' => LoteProduccionTrazabilidadService::FASES,
            'puedeEliminar' => $this->loteService->puedeEliminar($loteProduccion),
            'puedeAsignarEtapa' => $puedeAsignarEtapa,
            'puedeCertificar' => $puedeCertificar,
            'operadoresPlanta' => $operadoresPlanta,
            'asignacionesPendientesLote' => $asignacionesPendientesLote,
            'puedeAsignarNuevaEtapa' => $puedeAsignarEtapa
                && $this->asignacionEtapa->puedeAsignar($loteProduccion)
                && ! $this->asignacionEtapa->puedeAsignarPlan($loteProduccion),
            'puedeAsignarPlanEtapas' => $puedeAsignarEtapa && $this->asignacionEtapa->puedeAsignarPlan($loteProduccion),
            'puedeReiniciarTodoAsignaciones' => $puedeAsignarEtapa && $this->asignacionEtapa->puedeReiniciarTodo($loteProduccion),
            'puedeReiniciarPlanAsignaciones' => $puedeAsignarEtapa && $this->asignacionEtapa->puedeReiniciarTodo($loteProduccion),
            'pasosPlanAsignacion' => $pasosPlanAsignacion,
            'asignacionesPlanLote' => $asignacionesPlanLote,
            'mensajeBloqueoAsignacion' => $this->transformacion->mensajeBloqueoAsignacion($loteProduccion),
            'ordenEtapaActual' => $ordenActual,
            'parametrosLote' => $parametrosLote,
            'parametrosEtapaActual' => $parametrosEtapaActual,
            'parametrosPorAsignacion' => $parametrosPorAsignacion,
            'timelineVisual' => $timelineVisual,
            'etapasCompletadasRuta' => $this->transformacion->etapasCompletadasCount($loteProduccion),
            'parametrosPreviewEtapa' => app(LoteProduccionParametrosService::class)
                ->parametrosParaEtapaActual($loteProduccion, (int) ($siguientePasoPlantilla?->maquinaplantaid ?? 0)),
            'rutaPasosJson' => app(LoteProduccionRutaService::class)->payloadPasosParaSincronizar($loteProduccion),
        ], $datosAlmacenEnvioPlanta));
    }

    public function asignarEtapa(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::gestionaPlanta($request->user()), 403);

        $data = $request->validate([
            'procesoplantaid' => ['required', 'integer', 'exists:proceso_planta,procesoplantaid'],
            'maquinaplantaid' => ['required', 'integer', 'exists:maquina_planta,maquinaplantaid'],
            'operador_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $asignacion = $this->asignacionEtapa->asignar($loteProduccion, $data, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $operador = $asignacion->operador;

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', 'Etapa asignada a '.($operador?->nombreCompleto() ?? 'operario').'. Recibirá una alerta en su panel.');
    }

    public function asignarPlanEtapas(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::gestionaPlanta($request->user()), 403);

        $data = $request->validate([
            'etapas' => ['required', 'array', 'min:1'],
            'etapas.*.loteproduccionrutapasoid' => ['required', 'integer'],
            'etapas.*.operador_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'etapas.*.variables' => ['nullable', 'array'],
            'etapas.*.variables.*.variableestandarid' => ['required_with:etapas.*.variables', 'integer'],
            'etapas.*.variables.*.valor_minimo' => ['required_with:etapas.*.variables', 'numeric'],
            'etapas.*.variables.*.valor_maximo' => ['required_with:etapas.*.variables', 'numeric'],
        ]);

        try {
            $this->asignacionEtapa->asignarPlanEtapas(
                $loteProduccion,
                array_values($data['etapas']),
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', 'Todas las fases pendientes fueron cerradas. Los operarios ejecutarán las etapas en orden.');
    }

    public function cerrarFase(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse|JsonResponse
    {
        abort_unless(UsuarioRol::gestionaPlanta($request->user()), 403);

        $request->validate([
            'cerrar_paso' => ['required', 'integer'],
            'etapas' => ['required', 'array'],
        ]);

        $pasoId = (int) $request->input('cerrar_paso');
        $etapas = $request->input('etapas', []);
        $etapa = $etapas[$pasoId] ?? $etapas[(string) $pasoId] ?? null;

        if (! is_array($etapa)) {
            $etapa = collect($etapas)->first(
                fn ($f) => (int) ($f['loteproduccionrutapasoid'] ?? 0) === $pasoId
            );
        }

        if (! is_array($etapa)) {
            return back()->with('error', 'No se encontraron datos de la etapa a cerrar.')->withInput();
        }

        $etapaValidada = validator($etapa, [
            'loteproduccionrutapasoid' => ['required', 'integer'],
            'operador_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'variables' => ['nullable', 'array'],
            'variables.*.variableestandarid' => ['required_with:variables', 'integer'],
            'variables.*.valor_minimo' => ['required_with:variables', 'numeric'],
            'variables.*.valor_maximo' => ['required_with:variables', 'numeric'],
        ])->validate();

        $etapaValidada['loteproduccionrutapasoid'] = $pasoId;

        try {
            $this->asignacionEtapa->cerrarFase($loteProduccion, $etapaValidada, $request->user());
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage())->withInput();
        }

        if ($request->expectsJson() || $request->ajax()) {
            return $this->respuestaAjaxTimeline($loteProduccion, $request->user(), 'Fase cerrada correctamente.');
        }

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', 'Fase cerrada correctamente.');
    }

    public function cambiarFase(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::gestionaPlanta($request->user()), 403);

        $data = $request->validate([
            'loteproduccionrutapasoid' => ['required', 'integer'],
        ]);

        try {
            $this->asignacionEtapa->cambiarFase(
                $loteProduccion,
                (int) $data['loteproduccionrutapasoid'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', 'Fase liberada. Puede elegir otro operario o ajustar los rangos de variables.');
    }

    public function reiniciarTodoAsignaciones(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::gestionaPlanta($request->user()), 403);

        try {
            $this->asignacionEtapa->reiniciarTodo($loteProduccion, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', 'Operarios quitados de todas las fases no completadas. Los parámetros de la ruta se conservan.');
    }

    /** @deprecated Use reiniciarTodoAsignaciones() */
    public function reiniciarPlanAsignaciones(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        return $this->reiniciarTodoAsignaciones($request, $loteProduccion);
    }

    public function completarEtapaAsignada(
        Request $request,
        LoteProduccionPedido $loteProduccion,
        AsignacionEtapaPlanta $asignacion,
    ): RedirectResponse|JsonResponse {
        $user = $request->user();
        $esOperador = UsuarioRol::esOperarioPlanta($user)
            && (int) $asignacion->operador_usuarioid === (int) $user->usuarioid;
        $esSupervisor = UsuarioRol::gestionaPlanta($user);

        abort_unless($esOperador || $esSupervisor, 403);

        if ((int) $asignacion->loteproduccionpedidoid !== (int) $loteProduccion->loteproduccionpedidoid) {
            abort(404);
        }

        $respondeJson = $request->expectsJson() || $request->ajax();

        $data = $request->validate([
            'parametros' => ['nullable', 'array'],
            'parametros.*.variableestandarid' => ['required_with:parametros', 'integer'],
            'parametros.*.valor' => ['required_with:parametros', 'numeric'],
        ]);

        try {
            $payload = [
                'hora_inicio' => ($asignacion->creado_en ?? now())->toDateTimeString(),
                'hora_fin' => now()->toDateTimeString(),
                'parametros' => array_values($data['parametros'] ?? []),
            ];

            $registro = $esOperador
                ? $this->asignacionEtapa->completar($asignacion, $payload, $user)
                : $this->asignacionEtapa->completarPorSupervisor(
                    $asignacion,
                    $user,
                    $payload['parametros'],
                );
        } catch (\InvalidArgumentException $e) {
            if ($respondeJson) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            if ($respondeJson) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No se pudo completar la etapa. '.$e->getMessage(),
                ], 500);
            }

            throw $e;
        }

        $asignacion->load(['proceso', 'maquina', 'operador']);
        $loteProduccion->refresh();

        if ($respondeJson) {
            $etapas = $this->transformacion->timeline($loteProduccion);
            $ultima = $etapas[count($etapas) - 1] ?? null;

            return $this->respuestaAjaxTimeline($loteProduccion, $user, 'Etapa completada correctamente.', [
                'asignacion_id' => (int) $asignacion->asignacionetapaplantaid,
                'etapa' => $ultima ? [
                    'numero' => $ultima['numero'],
                    'proceso' => $ultima['proceso'],
                    'maquina' => $ultima['maquina'],
                    'inicio_fmt' => $ultima['inicio']?->format('d/m/Y H:i'),
                    'fin_fmt' => $ultima['fin']?->format('d/m/Y H:i'),
                    'operador' => $ultima['operador'],
                    'observaciones' => $ultima['observaciones'],
                    'es_cierre' => ! empty($ultima['es_cierre']),
                    'parametros_medidos' => $ultima['parametros_medidos'] ?? [],
                ] : null,
                'mensaje_bloqueo' => $this->transformacion->mensajeBloqueoAsignacion($loteProduccion),
                'puede_asignar_nueva' => $this->asignacionEtapa->puedeAsignar($loteProduccion),
                'asignaciones_pendientes_count' => $this->transformacion->asignacionesPendientes($loteProduccion)->count(),
            ]);
        }

        return redirect()->route('procesamiento.show', $loteProduccion);
    }

    public function actualizarRuta(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse|JsonResponse
    {
        abort_unless(UsuarioRol::gestionaPlanta($request->user()), 403);

        if ($this->trazabilidad->transformacionCompleta($loteProduccion)) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'La transformación ya finalizó.'], 422);
            }

            return back()->with('error', 'La transformación ya finalizó; no puede modificar la ruta.');
        }

        $data = $request->validate([
            'pasos' => ['required', 'array', 'min:1'],
            'pasos.*.loteproduccionrutapasoid' => ['nullable', 'integer'],
            'pasos.*.procesoplantaid' => ['required', 'integer', 'exists:proceso_planta,procesoplantaid'],
            'pasos.*.maquinaplantaid' => ['nullable', 'integer', 'exists:maquina_planta,maquinaplantaid'],
            'pasos.*.notas' => ['nullable', 'string', 'max:255'],
            'pasos.*.variables' => ['nullable', 'array'],
            'pasos.*.variables.*.variableestandarid' => ['required_with:pasos.*.variables', 'integer'],
            'pasos.*.variables.*.valor_minimo' => ['required_with:pasos.*.variables', 'numeric'],
            'pasos.*.variables.*.valor_maximo' => ['required_with:pasos.*.variables', 'numeric'],
        ]);

        try {
            app(LoteProduccionRutaService::class)->sincronizarRuta($loteProduccion, $data['pasos']);
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage())->withInput();
        }

        $loteProduccion->refresh();
        $this->transformacion->limpiarAsignacionesObsoletas($loteProduccion);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'etapa_asignar' => $this->transformacion->datosEtapaAsignacion($loteProduccion),
            ]);
        }

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', 'Ruta del lote actualizada.');
    }

    public function registrarEtapa(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::esPlantaOperativo($request->user()), 403);

        if ($this->trazabilidad->transformacionCompleta($loteProduccion)) {
            return back()->with('error', 'La transformación ya finalizó con «'.ProcesoPlantaCatalogo::PROCESO_CIERRE_TRANSFORMACION.'».');
        }

        if ($this->transformacion->plantillaAgotada($loteProduccion)) {
            return back()->with('error', 'Ya registró todos los pasos del proceso de transformación predefinido.');
        }

        $data = $request->validate([
            'procesoplantaid' => ['required', 'integer', 'exists:proceso_planta,procesoplantaid'],
            'maquinaplantaid' => ['required', 'integer', 'exists:maquina_planta,maquinaplantaid'],
            'hora_inicio' => ['required', 'date'],
            'hora_fin' => ['required', 'date', 'after_or_equal:hora_inicio'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        $proceso = \App\Models\ProcesoPlanta::query()->findOrFail($data['procesoplantaid']);
        if (in_array($proceso->nombre, ['Control de Calidad'], true)) {
            return back()->with('error', '«Control de Calidad» corresponde a la fase de certificación, no a transformación.');
        }

        $maquina = MaquinaPlanta::find($data['maquinaplantaid']);

        if (! MaquinaProcesoCompatibilidad::compatible((int) $data['procesoplantaid'], (int) $data['maquinaplantaid'])) {
            return back()->with('error', 'La maquinaria «'.($maquina?->nombre ?? '').'» no es compatible con el proceso «'.$proceso->nombre.'».');
        }

        if ($maquina?->enMantenimiento()) {
            return back()->with('error', 'La maquinaria «'.$maquina->nombre.'» está en mantenimiento. Espere a que vuelva a estar activa para registrar la etapa.');
        }

        try {
            $paso = $this->transformacion->resolverPasoProcesoMaquina(
                (int) $data['procesoplantaid'],
                (int) $data['maquinaplantaid']
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        RegistroProcesoMaquinaPlanta::create([
            'procesomaquinaplantaid' => $paso->procesomaquinaplantaid,
            'loteproduccionpedidoid' => $loteProduccion->loteproduccionpedidoid,
            'usuarioid' => $request->user()->usuarioid,
            'variables_ingresadas' => json_encode([
                'proceso' => $proceso->nombre,
                'maquina' => MaquinaPlanta::find($data['maquinaplantaid'])?->nombre,
            ]),
            'cumple_estandar' => true,
            'observaciones' => $data['observaciones'] ?? null,
            'hora_inicio' => $data['hora_inicio'],
            'hora_fin' => $data['hora_fin'],
            'fecha_registro' => $data['hora_fin'],
        ]);

        if (! $loteProduccion->hora_inicio) {
            $loteProduccion->update(['hora_inicio' => $data['hora_inicio']]);
        }

        $loteProduccion->update(['procesoplantaid' => $data['procesoplantaid']]);

        $loteProduccion->refresh();
        $mensaje = 'Etapa «'.$proceso->nombre.'» registrada.';
        if ($this->trazabilidad->transformacionCompleta($loteProduccion)) {
            $mensaje .= ' Transformación completada con «'.ProcesoPlantaCatalogo::PROCESO_CIERRE_TRANSFORMACION.'»: ya puede certificar el lote.';
        }

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', $mensaje);
    }

    public function certificar(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::gestionaPlanta($request->user()), 403);

        if (! $this->trazabilidad->transformacionCompleta($loteProduccion)) {
            return back()->with('error', 'Complete la transformación antes de certificar.');
        }

        $data = $request->validate([
            'razon' => ['required', 'string', Rule::in(EvaluacionFinalLoteProduccion::RAZONES)],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'recomendaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['razon'] === EvaluacionFinalLoteProduccion::RAZON_NO_CONFORME && blank($data['observaciones'] ?? null)) {
            return back()->withErrors(['observaciones' => 'Indique el motivo del no conforme.'])->withInput();
        }

        EvaluacionFinalLoteProduccion::updateOrCreate(
            ['loteproduccionpedidoid' => $loteProduccion->loteproduccionpedidoid],
            [
                'inspector_usuarioid' => $request->user()->usuarioid,
                'razon' => $data['razon'],
                'observaciones' => $data['observaciones'] ?? null,
                'recomendaciones' => $data['recomendaciones'] ?? null,
                'fecha_evaluacion' => now(),
            ]
        );

        if ($data['razon'] === EvaluacionFinalLoteProduccion::RAZON_NO_CONFORME) {
            $loteProduccion->almacenajes()->delete();
            $loteProduccion->update(['hora_fin' => now()]);
            $mensaje = 'Lote marcado como no conforme. No puede ingresar a almacén; el lote queda cerrado.';
        } else {
            $mensaje = 'Lote certificado. Ya puede registrar el almacenaje del producto terminado.';
        }

        $redirect = $request->input('redirect_to') === 'certificaciones-planta'
            ? route('certificaciones-planta.index')
            : route('procesamiento.show', $loteProduccion);

        return redirect()
            ->to($redirect)
            ->with('success', $mensaje);
    }

    public function almacenar(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::esPlantaOperativo($request->user()), 403);

        if (! $this->trazabilidad->evaluacionAprobada($loteProduccion)) {
            if ($this->trazabilidad->loteRechazado($loteProduccion)) {
                return back()->with('error', 'Este lote fue marcado como no conforme y no puede ingresar a almacén.');
            }

            return back()->with('error', 'Debe certificar el lote (resultado «Certificado») antes de almacenar.');
        }

        $data = $request->validate([
            'almacenid' => ['required', 'integer', 'exists:almacen,almacenid'],
            'condicion' => ['required', 'string', Rule::in(AlmacenajeLoteCondiciones::opciones())],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        if (! ProductoPlantaCatalogo::loteTieneEmpaquePlanificado($loteProduccion)) {
            return back()->with('error', 'Este lote no tiene empaque planificado. Vuelva a crear el lote indicando presentación comercial.');
        }

        $loteProduccion->loadMissing('materiasPrimas.insumo.unidadMedida', 'unidadMedida', 'presentacion');

        $resumen = ProductoPlantaCatalogo::resumenProduccion($loteProduccion, $this->capacidadService);
        $cantidad = (float) ($resumen['cantidad'] ?? 0);
        $cantidadKg = (float) ($resumen['kg'] ?? 0);

        if ($cantidad <= 0 || $cantidadKg <= 0) {
            return back()->with('error', 'No hay materia prima registrada para calcular el empaquetado.');
        }

        $almacen = Almacen::query()->with('unidadMedida')->findOrFail($data['almacenid']);
        if (($almacen->ambito ?? '') !== AlmacenAmbito::PLANTA) {
            return back()->with('error', 'Seleccione un almacén de planta.');
        }

        $capacidadAlmacen = $this->capacidadService->resumen($almacen);
        if ($cantidadKg > $capacidadAlmacen['disponible_kg']) {
            return back()->with('error',
                'La cantidad del lote excede la capacidad disponible del almacén. Disponible: '.
                round($capacidadAlmacen['disponible_kg'], 2).' kg'
            );
        }

        AlmacenajeLoteProduccion::create([
            'loteproduccionpedidoid' => $loteProduccion->loteproduccionpedidoid,
            'almacenid' => $almacen->almacenid,
            'ubicacion' => $almacen->nombre,
            'cantidad' => $cantidad,
            'condicion' => $data['condicion'],
            'observaciones' => ($data['observaciones'] ?? null)
                ?: 'Producto terminado: '.$loteProduccion->nombre,
            'fecha_almacenaje' => now(),
        ]);

        $this->inventarioPlanta->sincronizarLoteAlmacenado($loteProduccion, $almacen);
        $insumoTerminado = \App\Models\Insumo::query()
            ->where('almacenid', $almacen->almacenid)
            ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim(LoteProduccionNombre::productoDesdeLote($loteProduccion)))])
            ->where('tipoinsumoid', $this->inventarioPlanta->tipoProductoTerminadoId())
            ->firstOrFail();

        $presentacion = $this->presentacionPlanta->resolverPresentacionParaLote($loteProduccion, $insumoTerminado);
        $this->inventarioPresentacion->ingresar(
            (int) $almacen->almacenid,
            (int) $insumoTerminado->insumoid,
            (int) $presentacion->insumo_presentacionid,
            (int) $loteProduccion->loteproduccionpedidoid,
            $loteProduccion->codigo_lote,
            $cantidad,
            $cantidadKg
        );

        $loteProduccion->update([
            'cantidad_objetivo' => $cantidad,
            'cantidad_producida' => $cantidad,
            'insumo_presentacionid' => $presentacion->insumo_presentacionid,
            'unidadmedidaid' => ProductoPlantaCatalogo::resolverUnidadMedidaId(
                ProductoPlantaCatalogo::nombreProducto($loteProduccion),
                $loteProduccion->unidadmedidaid
            ) ?? $loteProduccion->unidadmedidaid,
        ]);

        if (! $loteProduccion->hora_fin) {
            $loteProduccion->update(['hora_fin' => now()]);
        }

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', 'Almacenaje registrado: '.number_format($cantidad, 0).' '
                .$presentacion->etiquetaUnidad().' de «'.$presentacion->nombre.'». Lote completado.');
    }

    public function completar(LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::esPlantaOperativo(auth()->user()), 403);

        if (! $loteProduccion->almacenajes()->exists()) {
            return back()->with('error', 'Registre el almacenaje antes de cerrar el lote.');
        }

        if (! $loteProduccion->hora_fin) {
            $loteProduccion->update(['hora_fin' => now()]);
        }

        return redirect()
            ->route('procesamiento.show', $loteProduccion)
            ->with('success', 'Lote marcado como completado.');
    }

    public function siguienteNombre(Request $request): JsonResponse
    {
        abort_unless(UsuarioRol::esPlantaOperativo($request->user()), 403);

        $producto = LoteProduccionNombre::normalizarProducto((string) $request->query('producto', ''));
        if ($producto === '') {
            return response()->json(['nombre' => '', 'numero' => 1]);
        }

        $numero = LoteProduccionNombre::siguienteNumero($producto);

        return response()->json([
            'nombre' => LoteProduccionNombre::formatear($producto, $numero),
            'numero' => $numero,
        ]);
    }

    public function calcularPlanificacion(Request $request): JsonResponse
    {
        abort_unless(UsuarioRol::esPlantaOperativo($request->user()), 403);

        $data = $request->validate([
            'empaque_catalogo_slug' => ['required', 'string', 'max:40'],
            'modo_planificacion' => ['required', 'string', Rule::in([EmpaquePlantaCatalogo::MODO_EMPAQUES, EmpaquePlantaCatalogo::MODO_MATERIA_PRIMA])],
            'cantidad_empaques' => ['nullable', 'numeric', 'min:0'],
            'materia_prima_kg' => ['nullable', 'numeric', 'min:0'],
            'empaque_peso_neto_kg' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        if (! EmpaquePlantaCatalogo::esSlugValido($data['empaque_catalogo_slug'])) {
            return response()->json(['error' => 'Empaque no válido'], 422);
        }

        $peso = EmpaquePlantaCatalogo::pesoNetoKg(
            $data['empaque_catalogo_slug'],
            $data['empaque_peso_neto_kg'] ?? null
        );

        if ($data['modo_planificacion'] === EmpaquePlantaCatalogo::MODO_EMPAQUES) {
            $und = (float) ($data['cantidad_empaques'] ?? 0);
            if ($und <= 0) {
                return response()->json(['error' => 'Indique cantidad de empaques'], 422);
            }
            $calc = EmpaquePlantaCatalogo::calcularDesdeEmpaques($und, $peso, $data['empaque_catalogo_slug']);
        } else {
            $kg = (float) ($data['materia_prima_kg'] ?? 0);
            if ($kg <= 0) {
                return response()->json(['error' => 'Indique kg de materia prima'], 422);
            }
            $calc = EmpaquePlantaCatalogo::calcularDesdeMateriaPrima($kg, $peso, $data['empaque_catalogo_slug']);
        }

        return response()->json([
            'data' => $calc,
            'empaque_label' => EmpaquePlantaCatalogo::etiquetaEmpaquePlanificado(
                $data['empaque_catalogo_slug'],
                null,
                $data['empaque_peso_neto_kg'] ?? null
            ),
            'rendimiento_pct' => EmpaquePlantaCatalogo::rendimientoPorcentaje(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(UsuarioRol::esPlantaOperativo($request->user()), 403);

        $data = $request->validate([
            'producto' => ['required', 'string', 'max:100'],
            'pedidoid' => ['nullable', 'integer', 'exists:pedido,pedidoid'],
            'cantidad_objetivo' => ['nullable', 'numeric', 'min:0'],
            'unidadmedidaid' => ['nullable', 'integer', 'exists:unidadmedida,unidadmedidaid'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'materias' => ['required', 'array', 'size:1'],
            'materias.*.insumoid' => ['required', 'integer', 'exists:insumo,insumoid'],
            'materias.*.cantidad' => ['required', 'numeric', 'min:0.001'],
            'plantillatransformacionid' => ['nullable', 'integer', 'exists:plantilla_transformacion,plantillatransformacionid'],
            'empaque_catalogo_slug' => ['required', 'string', 'max:40'],
            'modo_planificacion' => ['required', 'string', Rule::in([EmpaquePlantaCatalogo::MODO_EMPAQUES, EmpaquePlantaCatalogo::MODO_MATERIA_PRIMA])],
            'cantidad_empaques_objetivo' => ['nullable', 'numeric', 'min:0'],
            'empaque_nombre_personalizado' => ['nullable', 'string', 'max:120'],
            'empaque_peso_neto_kg' => ['nullable', 'numeric', 'min:0.001'],
            'empaque_tipo_envase' => ['nullable', 'string', Rule::in(['bolsa', 'lata', 'frasco', 'bidon', 'caja'])],
            'personalizar_parametros' => ['nullable'],
            'parametros_lote' => ['nullable', 'array'],
            'parametros_lote.*.plantillapasoid' => ['required_with:personalizar_parametros', 'integer'],
            'parametros_lote.*.variableestandarid' => ['required_with:personalizar_parametros', 'integer', 'exists:variable_estandar,variableestandarid'],
            'parametros_lote.*.valor_minimo' => ['required_with:personalizar_parametros', 'numeric'],
            'parametros_lote.*.valor_maximo' => ['required_with:personalizar_parametros', 'numeric'],
        ], [
            'materias.size' => 'Solo puede usar una materia prima por lote.',
        ]);

        if (! EmpaquePlantaCatalogo::esSlugValido($data['empaque_catalogo_slug'])) {
            return back()->withInput()->with('error', 'Seleccione un tipo de empaque del catálogo.');
        }

        if ($data['empaque_catalogo_slug'] === EmpaquePlantaCatalogo::SLUG_PERSONALIZADO) {
            if (empty($data['empaque_nombre_personalizado']) || empty($data['empaque_peso_neto_kg'])) {
                return back()->withInput()->with('error', 'Indique nombre y peso neto de la presentación personalizada.');
            }
        }

        if ($data['modo_planificacion'] === EmpaquePlantaCatalogo::MODO_EMPAQUES
            && (float) ($data['cantidad_empaques_objetivo'] ?? 0) <= 0
            && (float) ($data['cantidad_objetivo'] ?? 0) <= 0) {
            return back()->withInput()->with('error', 'Indique cuántos empaques desea producir.');
        }

        if ($data['modo_planificacion'] === EmpaquePlantaCatalogo::MODO_MATERIA_PRIMA
            && (float) ($data['cantidad_objetivo'] ?? 0) <= 0) {
            return back()->withInput()->with('error', 'Indique cuántos kg de materia prima usará.');
        }

        $lineas = collect($data['materias'])
            ->map(fn (array $m) => [
                'insumoid' => (int) $m['insumoid'],
                'cantidad' => (float) $m['cantidad'],
            ])
            ->all();

        foreach ($lineas as $linea) {
            $insumo = \App\Models\Insumo::query()->find($linea['insumoid']);
            if ($insumo && ! $insumo->tieneStockSuficiente($linea['cantidad'])) {
                return back()->withInput()->with(
                    'error',
                    'Stock insuficiente de «'.$insumo->nombre.'». Disponible: '.
                    number_format((float) $insumo->stock, 2).' '.
                    ($insumo->unidadMedida?->abreviatura ?? $insumo->unidadMedida?->nombre ?? 'ud')
                );
            }
        }

        if (! empty($data['plantillatransformacionid'])) {
            $plantilla = \App\Models\PlantillaTransformacion::query()
                ->with(['pasos.maquina', 'pasos.variables'])
                ->find((int) $data['plantillatransformacionid']);

            if (! $plantilla || ! $plantilla->estaOperativa()) {
                $maquinas = $plantilla?->maquinasEnMantenimiento()->pluck('nombre')->join(', ') ?: 'desconocida';

                return back()->withInput()->with('error', 'No se puede asignar ese proceso: hay máquinas en mantenimiento ('.$maquinas.').');
            }

            if (! empty($data['personalizar_parametros']) && ! empty($data['parametros_lote'])) {
                $pasosPorId = $plantilla->pasos->keyBy('plantillapasoid');
                $nombres = \App\Models\VariableEstandar::query()->pluck('nombre', 'variableestandarid')->all();

                foreach ($data['parametros_lote'] as $fila) {
                    $paso = $pasosPorId->get((int) ($fila['plantillapasoid'] ?? 0));
                    $maqId = $paso?->maquinaplantaid ? (int) $paso->maquinaplantaid : null;
                    $varId = (int) ($fila['variableestandarid'] ?? 0);
                    $error = ParametroRangoPlanta::validarRango(
                        $maqId,
                        $varId,
                        (float) ($fila['valor_minimo'] ?? 0),
                        (float) ($fila['valor_maximo'] ?? 0),
                        $nombres[$varId] ?? null,
                    );
                    if ($error !== null) {
                        return back()->withInput()->with('error', $error);
                    }
                }
            }
        }

        $parametrosLote = null;
        if (! empty($data['personalizar_parametros']) && ! empty($data['parametros_lote'])) {
            $parametrosLote = array_values($data['parametros_lote']);
        }

        try {
            $lote = $this->loteService->crear(
                $request->user(),
                $data['producto'],
                isset($data['pedidoid']) ? (int) $data['pedidoid'] : null,
                isset($data['cantidad_objetivo']) ? (float) $data['cantidad_objetivo'] : null,
                isset($data['unidadmedidaid']) ? (int) $data['unidadmedidaid'] : null,
                $lineas,
                $data['observaciones'] ?? null,
                isset($data['plantillatransformacionid']) ? (int) $data['plantillatransformacionid'] : null,
                $data['empaque_catalogo_slug'],
                $data['modo_planificacion'],
                isset($data['cantidad_empaques_objetivo']) ? (float) $data['cantidad_empaques_objetivo'] : null,
                $data['empaque_nombre_personalizado'] ?? null,
                isset($data['empaque_peso_neto_kg']) ? (float) $data['empaque_peso_neto_kg'] : null,
                $data['empaque_tipo_envase'] ?? null,
                $parametrosLote,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('procesamiento.show', $lote)
            ->with('success', 'Lote «'.$lote->nombre.'» creado ('.$lote->codigo_lote.'). Se descontó el stock del almacén de planta.');
    }

    public function edit(LoteProduccionPedido $loteProduccion): View
    {
        abort_unless(UsuarioRol::esPlantaOperativo(auth()->user()), 403);

        $loteProduccion->load(['pedido', 'unidadMedida', 'materiasPrimas.insumo.unidadMedida']);

        $pedidoLabel = $loteProduccion->pedido?->numero_solicitud ?? '';
        $productoActual = LoteProduccionNombre::productoDesdeLote($loteProduccion);
        $fase = $this->trazabilidad->resolverFaseActual($loteProduccion);
        $puedeEditarMaterias = $this->loteService->puedeEditarMaterias($loteProduccion);

        $almacenes = AlmacenAmbito::scope(Almacen::query(), AlmacenAmbito::PLANTA)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        $filtroAlmacenes = array_merge(
            [['value' => '', 'label' => 'Todos los almacenes de planta']],
            $almacenes->map(fn (Almacen $a) => [
                'value' => (string) $a->almacenid,
                'label' => $a->nombre,
            ])->all()
        );

        $estadosPedido = ['pendiente', 'confirmado', 'en produccion', 'aprobado', 'aceptado', 'en_proceso', 'asignado'];
        $existentes = Pedido::query()->distinct()->pluck('estado')->filter()->all();
        $todosEstados = array_values(array_unique(array_merge($estadosPedido, $existentes)));
        $filtroEstadosPedido = array_merge(
            [['value' => '', 'label' => 'Todos los estados']],
            array_map(fn ($e) => [
                'value' => $e,
                'label' => ucfirst(str_replace('_', ' ', (string) $e)),
            ], $todosEstados)
        );

        $unidadesMedida = UnidadMedida::query()->orderBy('nombre')->get();
        $productosLote = LoteProduccionNombre::productosDistintos();

        $materiasIniciales = $loteProduccion->materiasPrimas->map(function ($mp) {
            return [
                'id' => $mp->insumoid,
                'label' => $mp->insumo?->nombre ?? 'Insumo',
                'meta' => 'Stock actual en almacén',
                'stock' => (float) ($mp->insumo?->stock ?? 0) + (float) $mp->cantidad_usada,
                'unidad' => $mp->insumo?->unidadMedida?->abreviatura ?? $mp->insumo?->unidadMedida?->nombre ?? 'ud',
                'cantidad' => (float) $mp->cantidad_usada,
            ];
        })->values();

        return view('procesamiento.edit', compact(
            'loteProduccion',
            'pedidoLabel',
            'productoActual',
            'fase',
            'puedeEditarMaterias',
            'filtroAlmacenes',
            'filtroEstadosPedido',
            'unidadesMedida',
            'productosLote',
            'materiasIniciales'
        ));
    }

    public function update(Request $request, LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::esPlantaOperativo($request->user()), 403);

        $puedeEditarMaterias = $this->loteService->puedeEditarMaterias($loteProduccion);

        $rules = [
            'pedidoid' => ['nullable', 'integer', 'exists:pedido,pedidoid'],
            'cantidad_objetivo' => ['nullable', 'numeric', 'min:0'],
            'unidadmedidaid' => ['nullable', 'integer', 'exists:unidadmedida,unidadmedidaid'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ];

        if ($puedeEditarMaterias) {
            $rules['producto'] = ['required', 'string', 'max:100'];
            $rules['materias'] = ['required', 'array', 'size:1'];
            $rules['materias.*.insumoid'] = ['required', 'integer', 'exists:insumo,insumoid'];
            $rules['materias.*.cantidad'] = ['required', 'numeric', 'min:0.001'];
        }

        $request->merge(['pedidoid' => $request->input('pedidoid') ?: null]);

        $data = $request->validate($rules);

        $lineas = null;
        if ($puedeEditarMaterias) {
            $lineas = collect($data['materias'])
                ->map(fn (array $m) => [
                    'insumoid' => (int) $m['insumoid'],
                    'cantidad' => (float) $m['cantidad'],
                ])
                ->all();
        }

        try {
            $lote = $this->loteService->actualizar(
                $request->user(),
                $loteProduccion,
                array_key_exists('pedidoid', $data) ? ($data['pedidoid'] !== null ? (int) $data['pedidoid'] : null) : null,
                array_key_exists('cantidad_objetivo', $data) ? ($data['cantidad_objetivo'] !== null ? (float) $data['cantidad_objetivo'] : null) : null,
                array_key_exists('unidadmedidaid', $data) ? ($data['unidadmedidaid'] !== null ? (int) $data['unidadmedidaid'] : null) : null,
                $data['observaciones'] ?? null,
                $puedeEditarMaterias ? $data['producto'] : null,
                $lineas
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('procesamiento.show', $lote)
            ->with('success', 'Lote «'.$lote->nombre.'» actualizado.');
    }

    public function destroy(LoteProduccionPedido $loteProduccion): RedirectResponse
    {
        abort_unless(UsuarioRol::esPlantaOperativo(auth()->user()), 403);

        $nombre = $loteProduccion->nombre;

        try {
            $this->loteService->eliminar(auth()->user(), $loteProduccion);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('procesamiento.index')
                ->with([
                    'error' => $e->getMessage(),
                    'error_modal' => true,
                    'error_modal_titulo' => 'No se puede eliminar',
                ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('procesamiento.index')
                ->with([
                    'error' => 'No se pudo eliminar el lote. Revise si tiene datos vinculados o contacte al administrador.',
                    'error_modal' => true,
                    'error_modal_titulo' => 'Error al eliminar',
                ]);
        }

        return redirect()
            ->route('procesamiento.index')
            ->with('success', 'Lote «'.$nombre.'» eliminado. Se revirtió el stock de materias primas.');
    }

    private function aplicarFiltroOperarioPlanta($query, ?Usuario $user): void
    {
        if (! UsuarioRol::debeAcotarLotesProduccionPorAsignacion($user)) {
            return;
        }

        $query->whereHas('asignacionesEtapa', fn ($q) => $q->where('operador_usuarioid', (int) $user->usuarioid));
    }

    private function autorizarLoteOperarioPlanta(LoteProduccionPedido $lote, ?Usuario $user): void
    {
        if (! UsuarioRol::debeAcotarLotesProduccionPorAsignacion($user)) {
            return;
        }

        $asignado = $lote->asignacionesEtapa()
            ->where('operador_usuarioid', (int) $user->usuarioid)
            ->exists();

        abort_unless(
            $asignado,
            403,
            'Solo puede ver lotes en los que tiene etapas asignadas.'
        );
    }

    private function sincronizarLoteTimeline(LoteProduccionPedido $lote): void
    {
        $rutaService = app(LoteProduccionRutaService::class);
        $rutaService->asegurarRuta($lote);
        $rutaService->repararEmpaquetadoAlFinal($lote);
        $lote->refresh();
        $this->asignacionEtapa->sincronizarPromocionCola($lote);
        $this->transformacion->limpiarAsignacionesObsoletas($lote);
    }

    /** @return array<string, mixed> */
    private function variablesTimelinePartial(LoteProduccionPedido $lote, ?Usuario $user, string $panelActivo = 'transformacion'): array
    {
        $puedeAsignarEtapa = UsuarioRol::gestionaPlanta($user);
        $transformacionCompleta = $this->trazabilidad->transformacionCompleta($lote);
        $puedeAsignarPlanEtapas = $puedeAsignarEtapa && $this->asignacionEtapa->puedeAsignarPlan($lote);

        return [
            'items' => $this->transformacion->timelineVisual($lote),
            'sortable' => $panelActivo === 'transformacion' && $puedeAsignarPlanEtapas,
            'modoPlan' => $puedeAsignarPlanEtapas,
            'puedeGestionarPlan' => $puedeAsignarEtapa && ! $transformacionCompleta,
            'puedeMarcarCompletada' => $puedeAsignarEtapa && ! $transformacionCompleta,
            'usuarioActualId' => (int) ($user?->usuarioid ?? 0),
            'esOperarioPlanta' => UsuarioRol::esOperarioPlanta($user),
            'lote' => $lote,
            'formPlanActivo' => $puedeAsignarPlanEtapas,
            'cerrarFaseUrl' => route('procesamiento.cerrar-fase', $lote),
            'cambiarFaseUrl' => route('procesamiento.cambiar-fase', $lote),
            'rutaUrl' => route('procesamiento.actualizar-ruta', $lote),
            'rutaPasosJson' => app(LoteProduccionRutaService::class)->payloadPasosParaSincronizar($lote),
            'etapasCompletadas' => $this->transformacion->etapasCompletadasCount($lote),
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private function respuestaAjaxTimeline(LoteProduccionPedido $lote, ?Usuario $user, string $message = '', array $extra = []): JsonResponse
    {
        $this->asignacionEtapa->sincronizarPromocionCola($lote);
        $this->transformacion->limpiarAsignacionesObsoletas($lote);
        $lote->refresh();

        $vars = $this->variablesTimelinePartial($lote, $user);
        $rutaPlantilla = $this->transformacion->rutaPlantilla($lote);
        $ordenActual = $this->transformacion->ordenPasoActual($lote);
        $totalRuta = count($rutaPlantilla);
        $siguiente = $this->transformacion->siguientePasoPlantilla($lote);

        $toolbarMeta = $this->trazabilidad->transformacionCompleta($lote)
            ? '<i class="fas fa-check-circle text-success mr-1"></i>Ruta de transformación completada'
            : sprintf(
                'Etapa %d de %s%s',
                min($ordenActual, max(1, $totalRuta)),
                $totalRuta ?: '—',
                $siguiente
                    ? ' · Siguiente: <strong>'.e($siguiente->proceso?->nombre ?? '').'</strong>'
                    : ''
            );

        return response()->json(array_merge([
            'ok' => true,
            'message' => $message,
            'timeline_html' => view('planta.partials.timeline-transformacion', $vars)->render(),
            'toolbar_meta' => $toolbarMeta,
            'transformacion_completa' => $this->trazabilidad->transformacionCompleta($lote),
            'puede_asignar_plan' => $this->asignacionEtapa->puedeAsignarPlan($lote),
            'ruta_plantilla' => $rutaPlantilla,
        ], $extra));
    }
}
