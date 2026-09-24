<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Clima;
use App\Models\Lote;
use App\Models\Produccion;
use App\Models\Almacen;
use App\Support\AlmacenAmbito;
use App\Support\CampoJefeScope;
use App\Support\DashboardCharts;
use App\Support\DashboardFiltros;
use App\Support\DashboardPanelUsuario;
use App\Support\DashboardPresentacion;
use App\Support\EnvioAsignacionEstadoCatalogo;
use App\Models\Actividad;
use App\Models\DocumentoEntrega;
use App\Models\EnvioAsignacionMultiple;
use App\Models\IncidenteEnvio;
use App\Models\Insumo;
use App\Models\Pedido;
use App\Models\PedidoDistribucion;
use App\Models\PuntoVenta;
use App\Models\RutaMultiEntrega;
use App\Models\RutaDistribucion;
use App\Support\RutaDistribucionCatalogo;
use App\Support\TransporteIngresoService;
use App\Support\PedidoCatalogo;
use App\Support\PedidoDistribucionCatalogo;
use App\Models\Usuario;
use App\Models\Cultivo;
use App\Models\EstadoLoteTipo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\UsuarioNotificacion;
use App\Models\AsignacionEtapaPlanta;
use App\Models\LoteProduccionPedido;
use App\Services\NotificacionUsuarioService;
use App\Services\RecepcionPlantaMayoristaService;
use App\Support\AgricultorLoginNotificacion;
use App\Support\UsuarioRol;
use App\Support\EtiquetaDemo;
use App\Support\InsumoCatalogo;
use App\Support\MayoristaAccess;

class DashboardController extends Controller
{
    private function isAdmin($user): bool
    {
        return UsuarioRol::esAdminGlobal($user);
    }

    public function index(Request $request, NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        $filtros = DashboardFiltros::desdeRequest($request);
        $alertas = $notificaciones->noLeidasPara((int) $user->usuarioid, 8, $user);
        $totalAlertas = $notificaciones->contarNoLeidas((int) $user->usuarioid, $user);
        $extras = compact('alertas', 'totalAlertas', 'filtros');

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            $inicio = $this->buildAgricultorInicioData($user, $filtros);

            return view('dashboard.inicio.agricultor', array_merge(
                $inicio,
                $this->filtrosContextoAgricultor($user),
                [
                    'filtros' => $filtros,
                    'tareasPendientes' => $inicio['tareasPendientes'] ?? [],
                    'totalTareasPendientes' => $inicio['totalTareasPendientes'] ?? 0,
                ],
            ));
        }

        if (! $this->isAdmin($user) && UsuarioRol::esMayorista($user)) {
            return view('dashboard.inicio.mayorista', array_merge(
                $this->buildMayoristaInicioData($user, $filtros),
                $extras,
            ));
        }

        if (! $this->isAdmin($user) && UsuarioRol::esMinorista($user)) {
            return view('dashboard.inicio.minorista', array_merge(
                $this->buildMinoristaInicioData($user, $filtros),
                $extras,
            ));
        }

        if (! $this->isAdmin($user) && $user?->can('panel_transportista.view')) {
            return view('dashboard.inicio.transportista', array_merge(
                $this->buildTransportistaInicioData($user, $filtros),
                $extras,
            ));
        }

        if (! $this->isAdmin($user) && UsuarioRol::esOperarioPlanta($user)) {
            return view('dashboard.inicio.operario-planta', array_merge(
                $this->buildOperarioPlantaInicioData($user, $filtros),
                $extras,
            ));
        }

        if (! $this->isAdmin($user) && $user?->can('panel_planta.view')) {
            return view('dashboard.inicio.planta', array_merge(
                $this->buildPlantaInicioData($user, $filtros),
                $extras,
            ));
        }

        if (! $this->isAdmin($user) && $user?->can('panel_agricultor.view') && UsuarioRol::esJefeAgricultor($user)) {
            return view('dashboard.inicio.agricola', array_merge(
                $this->buildJefeAgricolaInicioData($user, $filtros),
                $this->filtrosContextoCampo($user),
                $extras,
            ));
        }

        return view('home', array_merge($this->buildAdminHomeData($filtros), $extras));
    }

    public function marcarNotificacionLeida(UsuarioNotificacion $notificacion, NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        $notificaciones->marcarLeida($notificacion, (int) $user->usuarioid);

        if ($notificaciones->esNotificacionOperarioPlanta($notificacion)
            && ! $notificaciones->puedeRecibirNotificacionOperarioPlanta($user)) {
            return redirect()
                ->route(UsuarioRol::esJefePlanta($user) ? 'dashboard.panel-planta' : 'dashboard')
                ->with('error', 'Las tareas de transformación se notifican solo a operarios con rol planta.');
        }

        if ($notificacion->enlace) {
            $destino = $notificaciones->resolverEnlace($notificacion, $user) ?? $notificacion->enlace;

            return redirect($destino);
        }

        return back();
    }

    public function descartarNotificacion(UsuarioNotificacion $notificacion, NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        $notificaciones->marcarLeida($notificacion, (int) $user->usuarioid);

        return back();
    }

    public function descartarTodasNotificaciones(NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        $notificaciones->marcarTodasLeidas((int) $user->usuarioid, $user);

        return back();
    }

    public function panelPlanta(Request $request, NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        abort_unless($user && ($user->can('panel_planta.view') || $this->isAdmin($user)), 403);

        $filtros = DashboardFiltros::desdeRequest($request);
        $ctxPanel = $this->contextoPanelUsuario($filtros, DashboardPanelUsuario::PANEL_PLANTA);
        $alertas = $notificaciones->noLeidasPara((int) $user->usuarioid, 8, $user);
        $totalAlertas = $notificaciones->contarNoLeidas((int) $user->usuarioid, $user);

        return view('dashboard.roles.planta', array_merge(
            $this->buildPlantaData($user, $filtros, $ctxPanel['usuarioFiltrado'], $ctxPanel['vistaTodosUsuarios']),
            $ctxPanel,
            [
                'charts' => DashboardCharts::paraPlanta($filtros, $ctxPanel['usuarioFiltrado']?->usuarioid),
                'etiquetaGrafico' => $filtros->etiquetaGrafico(),
            ],
            compact('alertas', 'totalAlertas', 'filtros'),
        ));
    }

    public function panelTransportista(Request $request, NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        abort_unless($user && ($user->can('panel_transportista.view') || $this->isAdmin($user)), 403);

        $filtros = DashboardFiltros::desdeRequest($request);
        $ctxPanel = $this->contextoPanelUsuario($filtros, DashboardPanelUsuario::PANEL_TRANSPORTISTA);
        $alertas = $notificaciones->noLeidasPara((int) $user->usuarioid, 8, $user);
        $totalAlertas = $notificaciones->contarNoLeidas((int) $user->usuarioid, $user);

        return view('dashboard.roles.transportista', array_merge(
            $this->buildTransportistaData(
                $user,
                $filtros,
                $ctxPanel['usuarioFiltrado'],
                $ctxPanel['vistaTodosUsuarios'],
            ),
            $ctxPanel,
            [
                'charts' => DashboardCharts::paraTransportista(
                    $filtros,
                    $ctxPanel['usuarioFiltrado']?->usuarioid,
                    $ctxPanel['vistaTodosUsuarios'],
                ),
                'etiquetaGrafico' => $filtros->etiquetaGrafico(),
            ],
            compact('alertas', 'totalAlertas', 'filtros'),
        ));
    }

    public function panelAgricola(Request $request, NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        abort_unless($user && ($user->can('panel_agricultor.view') || $this->isAdmin($user)), 403);

        $filtros = DashboardFiltros::desdeRequest($request);
        $ctxPanel = $this->contextoPanelUsuario($filtros, DashboardPanelUsuario::PANEL_AGRICOLA);
        $alertas = $notificaciones->noLeidasPara((int) $user->usuarioid, 8, $user);
        $totalAlertas = $notificaciones->contarNoLeidas((int) $user->usuarioid, $user);

        return view('dashboard.roles.agricola', array_merge(
            $this->buildJefeAgricolaData($filtros, $ctxPanel['usuarioFiltrado'], $ctxPanel['vistaTodosUsuarios'], $user),
            $this->filtrosContextoCampo($user),
            $ctxPanel,
            [
                'charts' => DashboardCharts::paraJefeAgricola(
                    $filtros,
                    $ctxPanel['usuarioFiltrado']?->usuarioid,
                    $ctxPanel['vistaTodosUsuarios'],
                    $user,
                ),
                'etiquetaGrafico' => $filtros->etiquetaGrafico(),
            ],
            compact('alertas', 'totalAlertas', 'filtros'),
        ));
    }

    public function panelMinorista(Request $request, NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        abort_unless($user && (UsuarioRol::esMinorista($user) || $this->isAdmin($user)), 403);

        $filtros = DashboardFiltros::desdeRequest($request);
        $ctxPanel = $this->contextoPanelUsuario($filtros, DashboardPanelUsuario::PANEL_MINORISTA);
        $alertas = $notificaciones->noLeidasPara((int) $user->usuarioid, 8, $user);
        $totalAlertas = $notificaciones->contarNoLeidas((int) $user->usuarioid, $user);

        return view('dashboard.roles.minorista', array_merge(
            $this->buildMinoristaData(
                $user,
                $filtros,
                $ctxPanel['usuarioFiltrado'],
                $ctxPanel['vistaTodosUsuarios'],
            ),
            $ctxPanel,
            [
                'charts' => DashboardCharts::paraMinorista(
                    $filtros,
                    $ctxPanel['usuarioFiltrado']?->usuarioid,
                    $ctxPanel['vistaTodosUsuarios'],
                ),
                'etiquetaGrafico' => $filtros->etiquetaGrafico(),
            ],
            compact('alertas', 'totalAlertas', 'filtros'),
        ));
    }

    public function panelMayorista(Request $request, NotificacionUsuarioService $notificaciones)
    {
        $user = auth()->user();
        abort_unless($user && ($user->can('panel_mayorista.view') || $this->isAdmin($user)), 403);

        $filtros = DashboardFiltros::desdeRequest($request);
        $ctxPanel = $this->contextoPanelUsuario($filtros, DashboardPanelUsuario::PANEL_MAYORISTA);
        $alertas = $notificaciones->noLeidasPara((int) $user->usuarioid, 8, $user);
        $totalAlertas = $notificaciones->contarNoLeidas((int) $user->usuarioid, $user);

        return view('dashboard.roles.mayorista', array_merge(
            $this->buildMayoristaData(
                $user,
                $filtros,
                $ctxPanel['usuarioFiltrado'],
                $ctxPanel['vistaTodosUsuarios'],
            ),
            $ctxPanel,
            [
                'charts' => DashboardCharts::paraMayorista(
                    $filtros,
                    $ctxPanel['usuarioFiltrado'],
                    $ctxPanel['vistaTodosUsuarios'],
                ),
                'etiquetaGrafico' => $filtros->etiquetaGrafico(),
            ],
            compact('alertas', 'totalAlertas', 'filtros'),
        ));
    }

    // ========================================
    // API: Obtener clima actual (OpenWeather)
    // ========================================
    public function getClima(Request $request)
    {
        $ciudad = (string) $request->get('ciudad', 'Santa Cruz de la Sierra');
        $apiKey = config('services.weather.key', env('OPENWEATHER_API_KEY', ''));

        if (is_string($apiKey) && trim($apiKey) !== '') {
            try {
                $response = Http::timeout(8)->get('https://api.openweathermap.org/data/2.5/weather', [
                    'q' => "{$ciudad},BO",
                    'appid' => $apiKey,
                    'units' => 'metric',
                    'lang' => 'es',
                ]);

                if ($response->successful()) {
                    $data = $response->json();

                    return response()->json([
                        'success' => true,
                        'temperatura' => round($data['main']['temp']),
                        'sensacion' => round($data['main']['feels_like']),
                        'humedad' => $data['main']['humidity'],
                        'descripcion' => ucfirst($data['weather'][0]['description']),
                        'icono' => $data['weather'][0]['icon'],
                        'viento' => round($data['wind']['speed'] * 3.6),
                        'ciudad' => $data['name'],
                        'fuente' => 'openweather',
                    ]);
                }
            } catch (\Exception $e) {
                // Continúa con fallback local
            }
        }

        return response()->json($this->climaFallbackLocal());
    }

    /**
     * @return array<string, mixed>
     */
    private function climaFallbackLocal(): array
    {
        if (Schema::hasTable('clima')) {
            $row = Clima::query()->orderByDesc('fecha')->first();
            if ($row) {
                return [
                    'success' => true,
                    'temperatura' => round((float) ($row->temperatura ?? 26)),
                    'humedad' => (int) round((float) ($row->humedad ?? 60)),
                    'descripcion' => $row->descripcion ?: 'Condición registrada en campo',
                    'icono' => $row->icono ?: '02d',
                    'viento' => (int) round((float) ($row->viento ?? 10)),
                    'ciudad' => 'Santa Cruz de la Sierra',
                    'fuente' => 'registro_local',
                ];
            }
        }

        return [
            'success' => true,
            'temperatura' => 28,
            'humedad' => 62,
            'descripcion' => 'Parcialmente nublado',
            'icono' => '02d',
            'viento' => 14,
            'ciudad' => 'Santa Cruz de la Sierra',
            'fuente' => 'referencia',
        ];
    }

    // ========================================
    // API: Obtener pronóstico 5 días
    // ========================================
    public function getPronostico(Request $request)
    {
        $apiKey = env('OPENWEATHER_API_KEY');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return response()->json(['success' => false, 'error' => 'Servicio de clima no configurado'], 503);
        }
        $ciudad = $request->get('ciudad', 'Santa Cruz de la Sierra');
        $pais = 'BO';

        try {
            $response = Http::timeout(10)->get("https://api.openweathermap.org/data/2.5/forecast", [
                'q' => "{$ciudad},{$pais}",
                'appid' => $apiKey,
                'units' => 'metric',
                'lang' => 'es',
                'cnt' => 40
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $pronostico = [];
                
                foreach ($data['list'] as $item) {
                    $fecha = date('Y-m-d', $item['dt']);
                    if (!isset($pronostico[$fecha])) {
                        $pronostico[$fecha] = [
                            'fecha' => $fecha,
                            'dia' => $this->getNombreDia($fecha),
                            'temp_max' => $item['main']['temp_max'],
                            'temp_min' => $item['main']['temp_min'],
                            'descripcion' => ucfirst($item['weather'][0]['description']),
                            'icono' => $item['weather'][0]['icon'],
                            'humedad' => $item['main']['humidity'],
                        ];
                    } else {
                        $pronostico[$fecha]['temp_max'] = max($pronostico[$fecha]['temp_max'], $item['main']['temp_max']);
                        $pronostico[$fecha]['temp_min'] = min($pronostico[$fecha]['temp_min'], $item['main']['temp_min']);
                    }
                }

                return response()->json([
                    'success' => true,
                    'pronostico' => array_values(array_slice($pronostico, 0, 5))
                ]);
            }

            return response()->json(['success' => false, 'error' => 'No se pudo obtener el pronóstico']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function getNombreDia($fecha)
    {
        $dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        return $dias[date('w', strtotime($fecha))];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAdminHomeData(DashboardFiltros $filtros): array
    {
        $lotesQuery = Lote::query();
        if (! $filtros->estadoLoteId) {
            $lotesQuery->whereHas('estadoTipo', function ($q) {
                $q->whereNotIn('nombre', ['cosechado', 'en descanso']);
            });
        }
        $filtros->aplicarEnLote($lotesQuery);

        $produccionQuery = Produccion::query();
        $filtros->aplicarFecha($produccionQuery, 'fechacosecha');
        $filtros->aplicarCultivoEnLote($produccionQuery);

        $enviosTransporteQuery = EnvioAsignacionMultiple::query()
            ->whereNotNull('costo_bs')
            ->where(function ($q) {
                $q->whereIn('estado', ['recibido_planta', 'entregado', 'entregada'])
                    ->orWhereNotNull('fecha_recepcion_planta');
            });
        $filtros->aplicarFecha($enviosTransporteQuery, 'fecha_recepcion_planta');

        $rutasTransporteQuery = RutaDistribucion::query()
            ->where('estado', RutaDistribucionCatalogo::ESTADO_COMPLETADA)
            ->whereNotNull('costo_bs');
        $filtros->aplicarFecha($rutasTransporteQuery, 'fecha_salida');

        $actividadesQuery = Actividad::query();
        $filtros->aplicarFecha($actividadesQuery, 'fechainicio');
        $filtros->aplicarCultivoEnLote($actividadesQuery);

        $stats = [
            'lotes_activos' => (clone $lotesQuery)->count(),
            'total_lotes' => Lote::count(),
            'produccion_mes_kg' => (clone $produccionQuery)->sum('cantidad'),
            'insumos_stock_bajo' => Insumo::where('stock', '<=', InsumoCatalogo::UMBRAL_ALERTA_STOCK)->count(),
            'transporte_costo_mes' => round(
                (float) (clone $enviosTransporteQuery)->sum('costo_bs')
                + (float) (clone $rutasTransporteQuery)->sum('costo_bs'),
                2
            ),
            'hectareas_totales' => Lote::sum('superficie') ?? 0,
            'total_actividades' => (clone $actividadesQuery)->count(),
            'usuarios' => Usuario::count(),
            'total_insumos' => Insumo::count(),
        ];

        if ($stats['lotes_activos'] == 0 && $stats['total_lotes'] > 0) {
            $stats['lotes_activos'] = $stats['total_lotes'];
        }

        $actividadesRecientes = Actividad::with(['lote', 'usuario', 'tipoActividad']);
        $filtros->aplicarCultivoEnLote($actividadesRecientes);
        $actividadesRecientes = $actividadesRecientes->orderBy('fechainicio', 'desc')->limit(5)->get();

        $topCultivos = Produccion::select('cultivo.cultivoid', 'cultivo.nombre', DB::raw('SUM(produccion.cantidad) as total'))
            ->join('lote', 'produccion.loteid', '=', 'lote.loteid')
            ->join('cultivo', 'lote.cultivoid', '=', 'cultivo.cultivoid');
        $filtros->aplicarFecha($topCultivos, 'produccion.fechacosecha');
        if ($filtros->cultivoId) {
            $topCultivos->where('lote.cultivoid', $filtros->cultivoId);
        }
        $topCultivos = $topCultivos->groupBy('cultivo.cultivoid', 'cultivo.nombre')->orderByDesc('total')->limit(4)->get();

        return [
            'stats' => $stats,
            'chartData' => DashboardCharts::produccionAdmin($filtros),
            'actividadesRecientes' => $actividadesRecientes,
            'insumosStockBajo' => InsumoCatalogo::aplicarFiltroOperativo(
                Insumo::with(['tipo', 'unidadMedida'])
            )
                ->where('stock', '<=', InsumoCatalogo::UMBRAL_ALERTA_STOCK)
                ->orderBy('stock')->limit(12)->get()
                ->filter(fn (Insumo $insumo) => ! EtiquetaDemo::esDemo($insumo->nombre))
                ->take(5)->values(),
            'lotesPorEstado' => Lote::select('estadolote_tipo.nombre', DB::raw('COUNT(*) as total'))
                ->join('estadolote_tipo', 'lote.estadolotetipoid', '=', 'estadolote_tipo.estadolotetipoid')
                ->groupBy('estadolote_tipo.nombre')->get(),
            'topCultivos' => $topCultivos,
            'resumenEstadistico' => DashboardPresentacion::resumenEstadistico($stats),
            'cultivos' => Cultivo::orderBy('nombre')->get(['cultivoid', 'nombre']),
            'lotes' => Lote::query()->orderBy('nombre')->get(['loteid', 'nombre', 'cultivoid']),
            'estadosLote' => EstadoLoteTipo::query()->orderBy('nombre')->get(['estadolotetipoid', 'nombre']),
            'etiquetaGrafico' => $filtros->etiquetaGrafico(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosContextoAgricultor($user): array
    {
        $uid = (int) $user->usuarioid;

        return [
            'lotes' => Lote::query()->where('usuarioid', $uid)->orderBy('nombre')->get(['loteid', 'nombre', 'cultivoid']),
            'mostrarLote' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosContextoCampo(?Usuario $user = null): array
    {
        $user ??= auth()->user();
        $lotesQuery = Lote::query()->orderBy('nombre');
        CampoJefeScope::aplicarEnLote($lotesQuery, $user);

        return [
            'cultivos' => Cultivo::orderBy('nombre')->get(['cultivoid', 'nombre']),
            'lotes' => $lotesQuery->get(['loteid', 'nombre', 'cultivoid']),
            'mostrarCultivo' => true,
            'mostrarLote' => true,
        ];
    }

    private function buildPlantaInicioData($user, ?DashboardFiltros $filtros = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());

        return array_merge($this->buildPlantaData($user, $filtros), [
            'charts' => DashboardCharts::paraPlanta($filtros),
            'etiquetaGrafico' => $filtros->etiquetaGrafico(),
            'envios_pendientes_planta' => \App\Support\PlantaLoginEnvio::enviosPendientesPlanta($user),
        ]);
    }

    private function buildOperarioPlantaInicioData(Usuario $user, ?DashboardFiltros $filtros = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());
        $operarioId = (int) $user->usuarioid;

        $lotesAsignadosQuery = LoteProduccionPedido::query()
            ->whereHas('asignacionesEtapa', fn ($q) => $q->where('operador_usuarioid', $operarioId));

        $tareasPendientes = AsignacionEtapaPlanta::query()
            ->with(['proceso', 'maquina', 'loteProduccion'])
            ->where('operador_usuarioid', $operarioId)
            ->pendientes()
            ->orderByDesc('creado_en')
            ->limit(8)
            ->get();

        $tareasPendientesCount = AsignacionEtapaPlanta::query()
            ->where('operador_usuarioid', $operarioId)
            ->pendientes()
            ->count();

        $tareasCompletadasCount = AsignacionEtapaPlanta::query()
            ->where('operador_usuarioid', $operarioId)
            ->where('estado', AsignacionEtapaPlanta::ESTADO_COMPLETADA)
            ->count();

        $lotesRecientes = (clone $lotesAsignadosQuery)
            ->with(['pedido', 'plantillaTransformacion'])
            ->orderByDesc('fecha_creacion')
            ->limit(6)
            ->get();

        return [
            'filtros' => $filtros,
            'stats' => [
                'lotes_asignados' => (clone $lotesAsignadosQuery)->count(),
                'tareas_pendientes' => $tareasPendientesCount,
                'tareas_completadas' => $tareasCompletadasCount,
            ],
            'tareasPendientes' => $tareasPendientes,
            'tareasPendientesCount' => $tareasPendientesCount,
            'lotesRecientes' => $lotesRecientes,
        ];
    }

    private function buildTransportistaInicioData($user, ?DashboardFiltros $filtros = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());

        return array_merge($this->buildTransportistaData($user, $filtros), [
            'charts' => DashboardCharts::paraTransportista($filtros, (int) $user->usuarioid),
            'etiquetaGrafico' => $filtros->etiquetaGrafico(),
        ]);
    }

    private function buildJefeAgricolaInicioData($user = null, ?DashboardFiltros $filtros = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());
        $user = $user ?? auth()->user();

        return array_merge($this->buildJefeAgricolaData($filtros, null, false, $user), [
            'charts' => DashboardCharts::paraJefeAgricola($filtros, null, false, $user),
            'etiquetaGrafico' => $filtros->etiquetaGrafico(),
        ]);
    }

    private function buildAgricultorInicioData($user, ?DashboardFiltros $filtros = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());

        return array_merge($this->buildAgricultorData($user, $filtros), [
            'charts' => DashboardCharts::paraAgricultor($user, $filtros),
            'etiquetaGrafico' => $filtros->etiquetaGrafico(),
        ]);
    }

    private function buildMinoristaInicioData($user, ?DashboardFiltros $filtros = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());

        return array_merge($this->buildMinoristaData($user, $filtros), [
            'charts' => DashboardCharts::paraMinorista($filtros, (int) $user->usuarioid),
            'etiquetaGrafico' => $filtros->etiquetaGrafico(),
        ]);
    }

    private function buildMayoristaInicioData($user, ?DashboardFiltros $filtros = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());

        return array_merge($this->buildMayoristaData($user, $filtros), [
            'charts' => DashboardCharts::paraMayorista($filtros, $user),
            'etiquetaGrafico' => $filtros->etiquetaGrafico(),
        ]);
    }

    private function buildMayoristaData($user, ?DashboardFiltros $filtros = null, ?Usuario $sujeto = null, bool $todos = false): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());
        $usuarioScope = $sujeto ?? ($todos ? null : $user);
        $almacenIds = [];

        if ($usuarioScope) {
            $almacenIds = MayoristaAccess::idsAlmacenesMayorista($usuarioScope);
        } elseif ($todos || $this->isAdmin($user)) {
            $almacenIds = AlmacenAmbito::scope(Almacen::query(), AlmacenAmbito::MAYORISTA)
                ->pluck('almacenid')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        } else {
            $almacenIds = MayoristaAccess::idsAlmacenesMayorista($user);
        }

        $pedidosQuery = PedidoDistribucion::query()->with('puntoVenta');
        $filtros->aplicarFecha($pedidosQuery, 'fechapedido');
        if ($almacenIds !== []) {
            $pedidosQuery->whereIn('almacen_mayorista_origenid', $almacenIds);
        } else {
            $pedidosQuery->whereRaw('1 = 0');
        }

        $inventarioQuery = Insumo::query();
        if ($almacenIds !== []) {
            $inventarioQuery->whereIn('almacenid', $almacenIds);
        } else {
            $inventarioQuery->whereRaw('1 = 0');
        }

        $almacenes = $usuarioScope
            ? AlmacenAmbito::scopeParaUsuario(
                Almacen::query()->orderBy('nombre'),
                AlmacenAmbito::MAYORISTA,
                $usuarioScope
            )->get()
            : AlmacenAmbito::scope(Almacen::query()->orderBy('nombre'), AlmacenAmbito::MAYORISTA)->get();

        $recepcionesPlanta = app(RecepcionPlantaMayoristaService::class)->conteos($user ?? $usuarioScope ?? $user);

        return [
            'stats' => [
                'almacenes' => $almacenes->count(),
                'pedidos_pendientes' => (clone $pedidosQuery)
                    ->where('estado', PedidoDistribucionCatalogo::ESTADO_PENDIENTE)
                    ->count(),
                'pedidos_activos' => (clone $pedidosQuery)
                    ->whereNotIn('estado', [
                        PedidoDistribucionCatalogo::ESTADO_RECIBIDO,
                        PedidoDistribucionCatalogo::ESTADO_RECHAZADO,
                        PedidoDistribucionCatalogo::ESTADO_CANCELADO,
                    ])
                    ->count(),
                'en_transito' => (clone $pedidosQuery)
                    ->where('estado', PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO)
                    ->count(),
                'productos_stock' => (clone $inventarioQuery)->count(),
                'stock_bajo' => (clone $inventarioQuery)
                    ->where('stockminimo', '>', 0)
                    ->whereColumn('stock', '<=', 'stockminimo')
                    ->count(),
                'recepciones_en_camino' => $recepcionesPlanta['en_camino'] ?? 0,
                'recepciones_pendiente_firma' => $recepcionesPlanta['esperando_firma'] ?? 0,
                'recepciones_recibidas' => $recepcionesPlanta['recibidos'] ?? 0,
            ],
            'almacenesResumen' => $almacenes,
            'recepcionesPlanta' => $recepcionesPlanta,
            'pedidosRecientes' => PedidoDistribucion::query()
                ->with('puntoVenta')
                ->when($almacenIds !== [], fn ($q) => $q->whereIn('almacen_mayorista_origenid', $almacenIds), fn ($q) => $q->whereRaw('1 = 0'))
                ->when($filtros->tieneRango(), fn ($q) => $filtros->aplicarFecha($q, 'fechapedido'))
                ->orderByDesc('pedidodistribucionid')
                ->limit(8)
                ->get(),
        ];
    }

    private function buildPlantaData($user = null, ?DashboardFiltros $filtros = null, ?Usuario $sujeto = null, bool $todos = false): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());

        $pedidos = Pedido::query();
        $asignaciones = EnvioAsignacionMultiple::query();
        $filtros->aplicarFecha($pedidos, 'fechapedido');
        $filtros->aplicarFecha($asignaciones, 'fecha_asignacion');

        $rutasActivas = RutaMultiEntrega::query()->whereIn('estado', ['planificada', 'en_ruta']);
        $incidentes = IncidenteEnvio::query()->where('estado', 'abierto');
        $documentos = DocumentoEntrega::query();

        $data = [
            'stats' => [
                'pedidos_totales' => $pedidos->count(),
                'asignaciones' => $asignaciones->count(),
                'rutas_activas' => (clone $rutasActivas)->count(),
                'incidentes_abiertos' => $incidentes->count(),
                'documentos' => $documentos->count(),
            ],
            'ultimas_asignaciones' => EnvioAsignacionMultiple::query()->latest()->take(8)->get(),
            'rutas_recientes' => RutaMultiEntrega::query()->latest()->take(6)->get(),
            'tareasPendientes' => collect(),
            'tareasPendientesCount' => 0,
        ];

        $operarioId = $sujeto?->usuarioid;
        if ($sujeto && UsuarioRol::esOperarioPlanta($sujeto)) {
            $operarioId = $sujeto->usuarioid;
        } elseif ($todos || ! $sujeto) {
            $operarioId = null;
        }

        if ($operarioId) {
            $tareasPendientes = AsignacionEtapaPlanta::query()
                ->with(['proceso', 'maquina', 'loteProduccion'])
                ->where('operador_usuarioid', $operarioId)
                ->pendientes()
                ->orderByDesc('creado_en')
                ->limit(8)
                ->get();
            $data['tareasPendientes'] = $tareasPendientes;
            $data['tareasPendientesCount'] = AsignacionEtapaPlanta::query()
                ->where('operador_usuarioid', $operarioId)
                ->pendientes()
                ->count();
        } elseif ($todos && $user && $this->isAdmin($user)) {
            $data['tareasPendientesCount'] = AsignacionEtapaPlanta::query()->pendientes()->count();
        } elseif ($user && UsuarioRol::esOperarioPlanta($user)) {
            $tareasPendientes = AsignacionEtapaPlanta::query()
                ->with(['proceso', 'maquina', 'loteProduccion'])
                ->where('operador_usuarioid', $user->usuarioid)
                ->pendientes()
                ->orderByDesc('creado_en')
                ->limit(8)
                ->get();
            $data['tareasPendientes'] = $tareasPendientes;
            $data['tareasPendientesCount'] = AsignacionEtapaPlanta::query()
                ->where('operador_usuarioid', $user->usuarioid)
                ->pendientes()
                ->count();
        }

        return $data;
    }

    private function buildAgricultorData($user, ?DashboardFiltros $filtros = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());
        $uid = (int) $user->usuarioid;
        $lotes = Lote::query()->where('usuarioid', $uid);
        if ($filtros->loteId) {
            $lotes->where('loteid', $filtros->loteId);
        }
        $actividades = Actividad::query()->whereHas('lote', fn ($q) => $q->where('usuarioid', $uid));
        if ($filtros->loteId) {
            $actividades->where('loteid', $filtros->loteId);
        }

        $completadas = (clone $actividades)->whereNotNull('fechafin');
        $filtros->aplicarFecha($completadas, 'fechafin');

        $tareasPendientes = AgricultorLoginNotificacion::todasActividadesPendientes($user);

        return [
            'stats' => [
                'lotes_asignados' => (clone $lotes)->count(),
                'actividades_pendientes' => count($tareasPendientes),
                'actividades_hoy' => (clone $actividades)->whereDate('fechainicio', now()->toDateString())->count(),
                'completadas_mes' => $completadas->count(),
            ],
            'lotesRecientes' => (clone $lotes)->with(['cultivo', 'estadoTipo'])->orderByDesc('loteid')->limit(5)->get(),
            'actividadesPendientes' => (clone $actividades)->with(['lote', 'tipoActividad'])
                ->whereNull('fechafin')->orderBy('fechainicio')->limit(8)->get(),
            'tareasPendientes' => $tareasPendientes,
            'totalTareasPendientes' => count($tareasPendientes),
        ];
    }

    private function buildJefeAgricolaData(?DashboardFiltros $filtros = null, ?Usuario $sujeto = null, bool $todos = false, ?Usuario $viewer = null): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());
        $viewer ??= auth()->user();

        $lotes = Lote::query();
        if ($filtros->cultivoId) {
            $lotes->where('cultivoid', $filtros->cultivoId);
        }
        if ($sujeto && ! $todos) {
            $lotes->where('usuarioid', $sujeto->usuarioid);
        } elseif (CampoJefeScope::debeAcotar($viewer) && ! $todos) {
            CampoJefeScope::aplicarEnLote($lotes, $viewer);
        }

        $actividades = Actividad::query()->whereNull('fechafin');
        $filtros->aplicarCultivoEnLote($actividades);
        if ($sujeto && ! $todos) {
            $actividades->whereHas('lote', fn ($q) => $q->where('usuarioid', $sujeto->usuarioid));
        } elseif (CampoJefeScope::debeAcotar($viewer) && ! $todos) {
            CampoJefeScope::aplicarEnRelacionLote($actividades, $viewer);
        }

        $cosechas = Produccion::query();
        $filtros->aplicarFecha($cosechas, 'fechacosecha');
        $filtros->aplicarCultivoEnLote($cosechas);
        if ($sujeto && ! $todos) {
            $cosechas->whereHas('lote', fn ($q) => $q->where('usuarioid', $sujeto->usuarioid));
        } elseif (CampoJefeScope::debeAcotar($viewer) && ! $todos) {
            CampoJefeScope::aplicarEnRelacionLote($cosechas, $viewer);
        }

        $pedidosPendientes = PedidoCatalogo::contarPendientesAgricola();
        if (CampoJefeScope::debeAcotar($viewer) && ! $todos) {
            $pedidosPendientes = EnvioAsignacionMultiple::query()
                ->whereHas('pedido', fn ($p) => PedidoCatalogo::aplicarFiltroLogistica($p))
                ->where(function ($q) {
                    $q->whereIn('estado', ['asignado', 'asignada', 'pendiente', 'creada', 'en_transporte_planta', 'en_ruta', 'en_transito'])
                        ->whereNull('fecha_recepcion_planta');
                })
                ->tap(fn ($q) => CampoJefeScope::aplicarEnEnvioAgricola($q, $viewer))
                ->count();
        }

        $lotesRecientes = Lote::query()
            ->with(['cultivo', 'estadoTipo'])
            ->when($sujeto && ! $todos, fn ($q) => $q->where('usuarioid', $sujeto->usuarioid))
            ->when(CampoJefeScope::debeAcotar($viewer) && ! $todos && ! $sujeto, function ($q) use ($viewer) {
                CampoJefeScope::aplicarEnLote($q, $viewer);
            })
            ->orderByDesc('loteid')
            ->limit(5)
            ->get();

        return [
            'stats' => [
                'lotes' => $lotes->count(),
                'actividades_pendientes' => $actividades->count(),
                'cosechas_mes' => $cosechas->sum('cantidad'),
                'pedidos_pendientes' => $pedidosPendientes,
            ],
            'lotesRecientes' => $lotesRecientes,
        ];
    }

    private function buildMinoristaData($user, ?DashboardFiltros $filtros = null, ?Usuario $sujeto = null, bool $todos = false): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());
        $uid = $sujeto?->usuarioid ?? ($todos ? null : (int) $user->usuarioid);

        $puntosQuery = PuntoVenta::query();
        if ($uid) {
            $puntosQuery->where('usuarioid', $uid);
        } elseif ($todos) {
            $puntosQuery->whereNotNull('usuarioid');
        } else {
            $puntosQuery->where('usuarioid', (int) $user->usuarioid);
        }
        $puntosIds = $puntosQuery->pluck('puntoventaid');
        $almacenIds = PuntoVenta::query()
            ->whereIn('puntoventaid', $puntosIds)
            ->pluck('almacenid')
            ->filter()
            ->values();

        $pedidosQuery = PedidoDistribucion::query()->whereIn('puntoventaid', $puntosIds);
        $filtros->aplicarFecha($pedidosQuery, 'fechapedido');

        $inventarioQuery = Insumo::query()->whereIn('almacenid', $almacenIds);

        return [
            'stats' => [
                'puntos_venta' => $puntosIds->count(),
                'pedidos_activos' => (clone $pedidosQuery)
                    ->whereNotIn('estado', [
                        PedidoDistribucionCatalogo::ESTADO_RECIBIDO,
                        PedidoDistribucionCatalogo::ESTADO_RECHAZADO,
                        PedidoDistribucionCatalogo::ESTADO_CANCELADO,
                    ])
                    ->count(),
                'pendientes_planta' => (clone $pedidosQuery)
                    ->where('estado', PedidoDistribucionCatalogo::ESTADO_PENDIENTE)
                    ->count(),
                'en_transito' => (clone $pedidosQuery)
                    ->where('estado', PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO)
                    ->count(),
            ],
            'inventario' => [
                'productos' => (clone $inventarioQuery)->count(),
                'bajo_stock' => (clone $inventarioQuery)
                    ->where('stockminimo', '>', 0)
                    ->whereColumn('stock', '<=', 'stockminimo')
                    ->count(),
            ],
            'pedidosRecientes' => PedidoDistribucion::query()
                ->with('puntoVenta')
                ->whereIn('puntoventaid', $puntosIds)
                ->when($filtros->tieneRango(), fn ($q) => $filtros->aplicarFecha($q, 'fechapedido'))
                ->orderByDesc('pedidodistribucionid')
                ->limit(5)
                ->get(),
        ];
    }

    private function buildTransportistaData($user, ?DashboardFiltros $filtros = null, ?Usuario $sujeto = null, bool $todos = false): array
    {
        $filtros ??= DashboardFiltros::desdeRequest(request());
        $uid = $sujeto?->usuarioid ?? ($todos ? null : (int) $user->usuarioid);

        $asignaciones = EnvioAsignacionMultiple::query();
        $rutasDistribucion = RutaDistribucion::query();
        $incidentes = IncidenteEnvio::query();
        $documentos = DocumentoEntrega::query();

        if ($uid) {
            $asignaciones->where('transportista_usuarioid', $uid);
            $rutasDistribucion->where('transportista_usuarioid', $uid);
            $incidentes->where('reportadopor_usuarioid', $uid);
            $documentos->where('usuarioid', $uid);
        } elseif ($todos) {
            $asignaciones->whereNotNull('transportista_usuarioid');
            $rutasDistribucion->whereNotNull('transportista_usuarioid');
        }

        $asignacionesPeriodo = (clone $asignaciones);
        $filtros->aplicarFecha($asignacionesPeriodo, 'fecha_asignacion');

        $rutas = RutaMultiEntrega::query();
        if ($uid) {
            $rutas->where('transportista_usuarioid', $uid);
        } elseif ($todos) {
            $rutas->whereNotNull('transportista_usuarioid');
        }

        $total = $asignacionesPeriodo->count();
        $enRuta = (clone $asignaciones)->where('estado', 'en_ruta')->count()
            + (clone $rutasDistribucion)->where('estado', RutaDistribucionCatalogo::ESTADO_EN_RUTA)->count();
        $entregadosPeriodo = (clone $asignacionesPeriodo)->where('estado', 'entregado')->count();

        $ingresos = $todos
            ? TransporteIngresoService::resumenPeriodoGlobal($filtros)
            : TransporteIngresoService::resumenPeriodo((int) $uid, $filtros);

        $rutasPendientesSalida = (clone $rutasDistribucion)
            ->where('estado', RutaDistribucionCatalogo::ESTADO_PLANIFICADA)
            ->with(['pedidos.detalles', 'detallesTraslado.insumo'])
            ->orderByDesc('rutadistribucionid')
            ->limit(8)
            ->get();

        $enviosPendientesRecoger = (clone $asignaciones)
            ->with(['pedido.detalles'])
            ->whereIn('estado', array_merge(
                EnvioAsignacionEstadoCatalogo::estadosEquivalentes('asignado'),
                EnvioAsignacionEstadoCatalogo::estadosEquivalentes('pendiente'),
            ))
            ->whereNull('simulacion_inicio_at')
            ->whereNull('fecha_recepcion_planta')
            ->whereHas('pedido', fn ($q) => $q->whereIn('estado', PedidoCatalogo::estadosListosParaLogistica()))
            ->orderByDesc('fecha_asignacion')
            ->get()
            ->filter(fn (EnvioAsignacionMultiple $asignacion) => EnvioAsignacionEstadoCatalogo::pendienteRecogerTransportista($asignacion))
            ->values();

        return [
            'stats' => [
                'asignados' => $total + (clone $rutasDistribucion)->count(),
                'por_recoger' => $enviosPendientesRecoger->count(),
                'en_camino' => $enRuta,
                'entregados_hoy' => $entregadosPeriodo,
                'productividad' => $total > 0 ? round(($entregadosPeriodo / $total) * 100, 2) : 0,
                'incidentes_abiertos' => (clone $incidentes)->where('estado', 'abierto')->count(),
                'documentos' => $documentos->count(),
                'ingresos_bs' => $ingresos['total_bs'],
                'servicios_completados' => $ingresos['servicios'],
            ],
            'mis_asignaciones' => (clone $asignaciones)
                ->with('pedido')
                ->where(function ($q) {
                    $q->whereDoesntHave('pedido')
                        ->orWhereHas('pedido', fn ($p) => $p->whereIn(
                            'estado',
                            PedidoCatalogo::estadosListosParaLogistica()
                        ));
                })
                ->latest()
                ->take(10)
                ->get(),
            'mis_rutas' => $rutas->latest()->take(6)->get(),
            'rutas_pendientes_salida' => $rutasPendientesSalida,
            'envios_pendientes_accion' => $enviosPendientesRecoger->take(5),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextoPanelUsuario(DashboardFiltros $filtros, string $panel): array
    {
        $ctx = DashboardPanelUsuario::resolver(auth()->user(), $filtros, $panel);

        return [
            'panelUsuario' => $ctx,
            'usuarioFiltrado' => $ctx['sujeto'],
            'vistaTodosUsuarios' => $ctx['todos'],
            'mostrarUsuario' => $ctx['es_admin'],
            'usuariosPanel' => $ctx['es_admin'] ? DashboardPanelUsuario::usuariosParaPanel($panel) : collect(),
            'etiquetaVistaUsuario' => DashboardPanelUsuario::etiquetaVista($ctx['todos'], $ctx['sujeto'], $panel),
        ];
    }

}