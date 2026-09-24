<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\RutaDistribucion;
use App\Services\DistribucionRutaService;
use App\Services\SimulacionRutaService;
use App\Support\AlmacenAmbito;
use App\Support\RutaDistribucionCatalogo;
use App\Support\RutaPorCallesService;
use App\Support\UbicacionGpsParser;
use App\Support\UsuarioRol;
use App\Support\ViajeAcceso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RutaDistribucionController extends Controller
{
    public function __construct(
        private readonly DistribucionRutaService $rutas
    ) {}

    private function autorizarPlanificador(): void
    {
        abort_unless(UsuarioRol::puedePlanificarDistribucion(auth()->user()), 403);
    }

    private function autorizarVerRuta(RutaDistribucion $ruta): void
    {
        // El admin supervisa todas; el mayorista solo las de su almacén y el conductor solo las suyas.
        abort_unless(ViajeAcceso::puedeVerRutaDistribucion(auth()->user(), $ruta), 403);
    }

    public function index(Request $request): View
    {
        $this->autorizarPlanificador();

        $this->rutas->sincronizarEstadosRutasActivas();

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['todos', 'pedidos', 'en_ruta', 'historial'], true)) {
            $tab = 'todos';
        }

        $mostrarPedidos = in_array($tab, ['todos', 'pedidos'], true);
        $mostrarRutas = in_array($tab, ['todos', 'en_ruta', 'historial'], true);

        $pedidosTodos = $this->rutas->pedidosListosParaRuta();
        $pedidosListos = $pedidosTodos;

        if ($mostrarPedidos && $request->filled('buscar')) {
            $term = mb_strtolower(trim((string) $request->buscar));
            $pedidosListos = $pedidosListos->filter(function ($pedido) use ($term) {
                $det = $pedido->detalles->first();

                return str_contains(mb_strtolower($pedido->numero_solicitud ?? ''), $term)
                    || str_contains(mb_strtolower($pedido->puntoVenta?->nombre ?? ''), $term)
                    || str_contains(mb_strtolower($det?->producto_nombre ?? ''), $term)
                    || str_contains(mb_strtolower($pedido->almacenMayoristaOrigen?->nombre ?? ''), $term);
            })->values();
        }

        $rutasQuery = RutaDistribucion::query()
            ->with(['transportista', 'vehiculo', 'almacenOrigen'])
            ->withCount('pedidos')
            ->orderByDesc('rutadistribucionid');

        if ($tab === 'en_ruta') {
            $rutasQuery->where('estado', RutaDistribucionCatalogo::ESTADO_EN_RUTA);
        } elseif ($tab === 'historial') {
            $rutasQuery->whereIn('estado', [
                RutaDistribucionCatalogo::ESTADO_COMPLETADA,
                RutaDistribucionCatalogo::ESTADO_CANCELADA,
            ]);
        }

        if ($mostrarRutas && $request->filled('buscar')) {
            $term = '%'.trim((string) $request->buscar).'%';
            $rutasQuery->where(function ($q) use ($term) {
                $q->where('codigo', 'like', $term)
                    ->orWhereHas('almacenOrigen', fn ($a) => $a->where('nombre', 'like', $term))
                    ->orWhereHas('transportista', function ($t) use ($term) {
                        $t->where('nombre', 'like', $term)
                            ->orWhere('apellido', 'like', $term);
                    });
            });
        }

        $rutas = $mostrarRutas ? $rutasQuery->get() : collect();

        $stats = [
            'pedidos_listos' => $pedidosTodos->count(),
            'rutas_en_curso' => RutaDistribucion::where('estado', RutaDistribucionCatalogo::ESTADO_EN_RUTA)->count(),
            'rutas_total' => RutaDistribucion::count(),
        ];

        return view('punto_venta.rutas.index', compact(
            'pedidosListos',
            'rutas',
            'tab',
            'stats',
            'mostrarPedidos',
            'mostrarRutas'
        ));
    }

    public function create(): View
    {
        $this->autorizarPlanificador();

        AlmacenAmbito::asegurarAmbitosEnRegistros();

        $pedidosListos = $this->rutas->pedidosListosParaRuta();
        $almacenesIdsConPedidos = $pedidosListos
            ->pluck('almacen_mayorista_origenid')
            ->filter()
            ->unique()
            ->values();

        $almacenesMayorista = AlmacenAmbito::scope(
            Almacen::query()->where('activo', true),
            AlmacenAmbito::MAYORISTA
        )
            ->whereIn('almacenid', $almacenesIdsConPedidos)
            ->orderBy('nombre')
            ->get();

        $almacenesMapa = $almacenesMayorista->map(function (Almacen $almacen) use ($pedidosListos) {
            $resuelto = UbicacionGpsParser::resolverAlmacen(
                (int) $almacen->almacenid,
                $almacen->nombre,
                $almacen->ubicacion
            );

            $pedidosEnAlmacen = $pedidosListos
                ->where('almacen_mayorista_origenid', $almacen->almacenid)
                ->count();

            return [
                'id' => $almacen->almacenid,
                'label' => $almacen->nombre,
                'tipo' => 'mayorista',
                'lat' => $resuelto['lat'],
                'lng' => $resuelto['lng'],
                'extra' => [
                    'lat' => $resuelto['lat'],
                    'lng' => $resuelto['lng'],
                    'direccion' => $resuelto['direccion'],
                    'pedidos' => $pedidosEnAlmacen,
                ],
            ];
        })
            ->filter(fn (array $item) => ($item['extra']['pedidos'] ?? 0) > 0)
            ->values()
            ->all();

        $pdvsMapa = [];
        foreach ($pedidosListos as $pedido) {
            $pdv = $pedido->puntoVenta;
            if ($pdv === null || $pdv->latitud === null || $pdv->longitud === null) {
                continue;
            }

            $key = (int) $pdv->puntoventaid;
            if (! isset($pdvsMapa[$key])) {
                $pdvsMapa[$key] = [
                    'id' => $key,
                    'tipo' => 'pdv',
                    'label' => $pdv->nombre,
                    'lat' => (float) $pdv->latitud,
                    'lng' => (float) $pdv->longitud,
                    'direccion' => $pdv->direccion,
                    'almacen_origen_id' => $pedido->almacen_mayorista_origenid,
                    'pedidos' => 0,
                ];
            }
            $pdvsMapa[$key]['pedidos']++;
        }

        $oldAlmacenId = old('almacen_mayorista_origenid');
        $oldAlmacenLabel = $oldAlmacenId
            ? ($almacenesMayorista->firstWhere('almacenid', (int) $oldAlmacenId)?->nombre ?? '')
            : '';

        return view('punto_venta.rutas.create', [
            'pedidosListos' => $pedidosListos,
            'almacenesMapa' => $almacenesMapa,
            'almacenesIdsConPedidos' => $almacenesIdsConPedidos->implode(','),
            'puntosMapaDisponibles' => array_merge($almacenesMapa, array_values($pdvsMapa)),
            'oldAlmacenLabel' => $oldAlmacenLabel,
            'hubLat' => RutaPorCallesService::HUB_LAT,
            'hubLng' => RutaPorCallesService::HUB_LNG,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autorizarPlanificador();

        $data = $request->validate([
            'almacen_mayorista_origenid' => 'required|integer|exists:almacen,almacenid',
            'transportista_usuarioid' => 'required|integer|exists:usuario,usuarioid',
            'vehiculoid' => 'required|integer|exists:vehiculo,vehiculoid',
            'costo_bs' => 'required|numeric|min:0.01|max:99999999.99',
            'pedidos' => 'required|array|min:1',
            'pedidos.*' => 'integer|exists:pedido_distribucion,pedidodistribucionid',
        ]);

        $almacen = Almacen::query()->findOrFail((int) $data['almacen_mayorista_origenid']);
        if ($almacen->ambito !== AlmacenAmbito::MAYORISTA) {
            return back()->withInput()->with('error', 'Seleccione un almacén mayorista válido.');
        }

        $this->rutas->asegurarTransportistaMayorista((int) $data['transportista_usuarioid']);

        try {
            $ruta = $this->rutas->crear(
                $almacen,
                array_map('intval', $data['pedidos']),
                (int) $data['transportista_usuarioid'],
                (int) $data['vehiculoid'],
                (int) auth()->id(),
                null,
                (float) $data['costo_bs']
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('punto-venta.rutas.show', $ruta)
            ->with('success', 'Ruta de distribución creada. El transportista debe iniciar la ruta cuando esté listo.');
    }

    public function empezarRuta(RutaDistribucion $ruta, SimulacionRutaService $simulacion): RedirectResponse
    {
        $user = auth()->user();
        if (! UsuarioRol::puedeMarcarEnRutaDistribucion($user, $ruta)) {
            abort(403);
        }

        if ($ruta->esTrasladoPlantaMayorista()) {
            return redirect()
                ->route('logistica.traslados-planta.cierre.panel', $ruta)
                ->with('info', 'Complete el cierre operativo paso a paso antes de salir hacia el mayorista.');
        }

        if (! app(\App\Services\CierreEnvioDistribucionPdvService::class)->tieneCondicionesVehiculo($ruta)) {
            return redirect()
                ->route('punto-venta.rutas.cierre.panel', $ruta)
                ->with('info', 'Complete el cierre operativo paso a paso antes de salir hacia el punto de venta.');
        }

        try {
            $simulacion->empezarDistribucion($ruta);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $mensaje = 'Ruta marcada en marcha. El pedido ya está en camino al punto de venta.';

        return back()->with('success', $mensaje);
    }

    public function show(RutaDistribucion $ruta): View|RedirectResponse
    {
        $this->autorizarVerRuta($ruta);

        if ($ruta->esTrasladoPlantaMayorista()) {
            return redirect()->to(\App\Support\RutaDistribucionNavegacion::urlVer($ruta, auth()->user()));
        }

        $ruta->load([
            'paradas.pedido.detalles',
            'paradas.puntoVenta',
            'paradas.almacen',
            'pedidos.puntoVenta.minorista',
            'pedidos.detalles',
            'transportista.perfilTransportista.vehiculo',
            'vehiculo',
            'almacenOrigen',
            'creadoPor',
        ]);

        $trayectoPartes = $this->rutas->trayectoPartes($ruta);
        $trayectoTexto = $this->rutas->trayectoTexto($ruta);
        $paradasMapa = $this->rutas->paradasMapa($ruta);
        $badge = RutaDistribucionCatalogo::badgeEstadoCabecera($ruta);
        $simulacionActiva = \App\Support\SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta);
        $urlTiempoReal = $simulacionActiva
            ? route('logistica.rutas-tiempo-real.show', ['tipo' => 'distribucion', 'id' => $ruta->rutadistribucionid])
            : null;

        return view('punto_venta.rutas.show', compact(
            'ruta',
            'trayectoPartes',
            'trayectoTexto',
            'paradasMapa',
            'badge',
            'simulacionActiva',
            'urlTiempoReal',
        ));
    }
}
