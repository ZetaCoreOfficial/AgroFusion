<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\RutaDistribucion;
use App\Services\RecepcionPlantaMayoristaService;
use App\Services\SimulacionRutaService;
use App\Services\TrasladoPlantaMayoristaService;
use App\Support\AlmacenAmbito;
use App\Support\EnvioTrayectoCatalogo;
use App\Support\MayoristaAccess;
use App\Support\PlantaAccess;
use App\Support\RutaDistribucionCatalogo;
use App\Support\SimulacionRutaCatalogo;
use App\Support\UbicacionGpsParser;
use App\Support\UsuarioRol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrasladoPlantaMayoristaController extends Controller
{
    public function __construct(
        private readonly TrasladoPlantaMayoristaService $traslados,
        private readonly RecepcionPlantaMayoristaService $recepcion,
    ) {}

    public function create(): View
    {
        return redirect()->route('pedidos.create', ['destino' => 'mayorista']);
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->puedeVerListado($user), 403);

        $esVistaMayorista = $request->routeIs('almacen-mayorista.traslados-planta.*');
        $filtro = (string) $request->query('filtro', RecepcionPlantaMayoristaService::FILTRO_TODOS);

        $query = $this->recepcion->queryListado($user, $esVistaMayorista ? $filtro : null)
            ->with(['almacenPlantaOrigen', 'almacenMayoristaDestino', 'paradas', 'transportista', 'detallesTraslado', 'firmaTransportista', 'firmaRecepcion']);

        $traslados = $query->paginate(15)->withQueryString();
        $pendientesCount = RutaDistribucion::query()
            ->where('tipo_ruta', RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA)
            ->where('estado', RutaDistribucionCatalogo::ESTADO_PENDIENTE_APROBACION)
            ->when(
                UsuarioRol::esMayorista($user) && ! UsuarioRol::esAdminGlobal($user),
                fn ($q) => $q->whereIn('almacen_mayorista_destinoid', MayoristaAccess::idsAlmacenesMayorista($user) ?: [-1])
            )
            ->count();

        $conteosRecepcion = $esVistaMayorista ? $this->recepcion->conteos($user) : null;
        $estadosRecepcion = [];
        if ($esVistaMayorista) {
            foreach ($traslados as $t) {
                $estadosRecepcion[$t->rutadistribucionid] = $this->recepcion->estadoRecepcion($t);
            }
        }

        return view('logistica.traslados-planta.index', [
            'traslados' => $traslados,
            'pendientesCount' => $pendientesCount,
            'esVistaMayorista' => $esVistaMayorista,
            'rutaPrefijo' => $esVistaMayorista ? 'almacen-mayorista.traslados-planta' : 'logistica.traslados-planta',
            'filtroRecepcion' => $filtro,
            'conteosRecepcion' => $conteosRecepcion,
            'estadosRecepcion' => $estadosRecepcion,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        EnvioTrayectoCatalogo::autorizarTrayecto($request->user(), EnvioTrayectoCatalogo::TRAYECTO_MAYORISTA);

        $data = $request->validate([
            'almacen_planta_origenid' => 'required|integer|exists:almacen,almacenid',
            'almacen_mayorista_destinoid' => 'required|integer|exists:almacen,almacenid',
            'recogidas' => 'nullable|array|max:5',
            'recogidas.*.almacenid' => 'required|integer|exists:almacen,almacenid',
            'transportista_usuarioid' => 'required|integer|exists:usuario,usuarioid',
            'vehiculoid' => 'required|integer|exists:vehiculo,vehiculoid',
            'nombre' => 'nullable|string|max:200',
            'costo_bs' => 'required|numeric|min:1',
            'detalles' => 'required|array|min:1',
            'detalles.*.almacen_plantaid' => 'nullable|integer|exists:almacen,almacenid',
            'detalles.*.insumoid' => 'required|integer|exists:insumo,insumoid',
            'detalles.*.insumo_presentacionid' => 'nullable|integer|exists:insumo_presentacion,insumo_presentacionid',
            'detalles.*.inventario_presentacion_loteid' => 'nullable|integer|exists:inventario_presentacion_lote,inventario_presentacion_loteid',
            'detalles.*.cantidad_unidades' => 'nullable|numeric|min:0.01',
            'detalles.*.cantidad' => 'nullable|numeric|min:0.01',
            'detalles.*.observaciones' => 'nullable|string|max:255',
        ]);
        $planta = Almacen::query()->findOrFail((int) $data['almacen_planta_origenid']);
        $mayorista = Almacen::query()->findOrFail((int) $data['almacen_mayorista_destinoid']);
        $recogidasExtra = collect($data['recogidas'] ?? [])
            ->pluck('almacenid')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id !== (int) $planta->almacenid)
            ->values()
            ->all();

        try {
            $ruta = $this->traslados->crear(
                $planta,
                $mayorista,
                (int) $data['transportista_usuarioid'],
                isset($data['vehiculoid']) ? (int) $data['vehiculoid'] : null,
                (int) auth()->id(),
                $data['detalles'],
                $data['nombre'] ?? null,
                (float) $data['costo_bs'],
                $recogidasExtra,
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('pedidos.create', ['destino' => 'mayorista'])
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('logistica.traslados-planta.show', $ruta);
    }

    public function show(Request $request, RutaDistribucion $ruta): View|RedirectResponse
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);
        $this->autorizarVer($ruta);

        $user = auth()->user();
        if (\App\Support\RutaDistribucionNavegacion::transportistaDebeUsarCierre($ruta, $user)) {
            return redirect()
                ->route('logistica.traslados-planta.cierre.panel', $ruta)
                ->with('info', 'Complete el cierre operativo de este traslado.');
        }

        $ruta->load([
            'transportista',
            'vehiculo.tipoVehiculo',
            'almacenPlantaOrigen',
            'almacenMayoristaDestino',
            'paradas',
            'detallesTraslado.insumo.unidadMedida',
            'aprobadoPor',
            'checklistCondicionVehiculo',
        ]);

        $lineasProducto = \App\Support\TrasladoPlantaMayoristaPresentacion::lineasProducto($ruta);
        $nombreDestinoMayorista = \App\Support\TrasladoPlantaMayoristaPresentacion::nombreDestinoMayorista($ruta);

        $esVistaMayorista = $request->routeIs('almacen-mayorista.traslados-planta.*');
        $user = auth()->user();
        $pendienteAprobacion = RutaDistribucionCatalogo::pendienteAprobacionPlanta($ruta);
        $puedeAprobarPlanta = PlantaAccess::puedeAprobarTraslado($user, $ruta);

        $estadoRecepcion = $esVistaMayorista ? $this->recepcion->estadoRecepcion($ruta) : null;
        $resumenCierre = app(\App\Services\CierreEnvioPlantaMayoristaService::class)->resumenPasos($ruta);

        return view('logistica.traslados-planta.show', [
            'ruta' => $ruta,
            'trayecto' => $this->traslados->trayectoTexto($ruta),
            'simulacionActiva' => SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta),
            'puedeEmpezar' => SimulacionRutaCatalogo::usuarioPuedeEmpezarDistribucion($user, $ruta),
            'pendienteAprobacion' => $pendienteAprobacion,
            'puedeAprobarPlanta' => $puedeAprobarPlanta,
            'documentoEntrega' => app(\App\Services\CierreEnvioPlantaMayoristaService::class)->documentoEntrega($ruta),
            'rutaPrefijo' => $esVistaMayorista ? 'almacen-mayorista.traslados-planta' : 'logistica.traslados-planta',
            'volverUrl' => $esVistaMayorista
                ? route('almacen-mayorista.traslados-planta.index')
                : route('logistica.asignaciones.index'),
            'esVistaMayorista' => $esVistaMayorista,
            'estadoRecepcion' => $estadoRecepcion,
            'resumenCierre' => $resumenCierre,
            'lineasProducto' => $lineasProducto,
            'nombreDestinoMayorista' => $nombreDestinoMayorista,
        ]);
    }

    public function aceptar(RutaDistribucion $ruta): RedirectResponse
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);
        abort_unless(PlantaAccess::puedeAprobarTraslado(auth()->user(), $ruta), 403);

        try {
            $this->traslados->aceptar($ruta, auth()->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $rutaPrefijo = request()->routeIs('almacen-mayorista.traslados-planta.*')
            ? 'almacen-mayorista.traslados-planta'
            : 'logistica.traslados-planta';

        return redirect()
            ->route($rutaPrefijo.'.show', $ruta)
            ->with('success', 'Traslado aprobado por planta. El transportista puede marcar en ruta cuando salga.');
    }

    public function rechazar(Request $request, RutaDistribucion $ruta): RedirectResponse
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);
        abort_unless(PlantaAccess::puedeAprobarTraslado(auth()->user(), $ruta), 403);

        $data = $request->validate(['motivo_rechazo' => 'nullable|string|max:500']);

        try {
            $this->traslados->rechazar($ruta, auth()->user(), $data['motivo_rechazo'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $volver = request()->routeIs('almacen-mayorista.traslados-planta.*')
            ? route('almacen-mayorista.traslados-planta.index')
            : route('pedidos.index');

        return redirect($volver)
            ->with('success', 'Traslado rechazado por planta.');
    }

    public function empezarRuta(RutaDistribucion $ruta, SimulacionRutaService $simulacion): RedirectResponse
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);

        $user = auth()->user();
        if (! $this->puedeGestionarPlanta()
            && (int) $ruta->transportista_usuarioid !== (int) $user?->usuarioid) {
            abort(403);
        }

        if (! RutaDistribucionCatalogo::puedeEmpezarTrasladoPlanta($ruta)) {
            return back()->with('error', 'El traslado debe ser aprobado por el jefe de planta antes de marcar en ruta.');
        }

        try {
            $simulacion->empezarDistribucion($ruta);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $redirect = redirect()
            ->route('logistica.traslados-planta.cierre.panel', $ruta)
            ->with('success', 'Traslado marcado en ruta. Confirme la llegada cuando llegue al almacén mayorista.');

        if ($this->puedeGestionarPlanta()) {
            $redirect->with('info', 'Los supervisores pueden seguir el vehículo en Logística → Ruta en tiempo real.');
        }

        return $redirect;
    }

    private function puedeGestionarPlanta(): bool
    {
        $user = auth()->user();

        // Gestión operativa del traslado (iniciar ruta, etc.): el admin solo supervisa.
        return UsuarioRol::puedeOperar($user) && (
            UsuarioRol::esJefePlanta($user)
            || $user->can('asignaciones.update')
        );
    }

    private function puedeVerListado($user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->puedeGestionarPlanta()
            || UsuarioRol::esMayorista($user)
            || UsuarioRol::esAdminGlobal($user);
    }

    private function autorizarVer(RutaDistribucion $ruta): void
    {
        $user = auth()->user();
        if ($this->puedeGestionarPlanta() || UsuarioRol::esAdminGlobal($user)) {
            return;
        }
        if (MayoristaAccess::puedeGestionarTraslado($user, $ruta)) {
            return;
        }
        if (UsuarioRol::esTransportista($user) && (int) $ruta->transportista_usuarioid === (int) $user->usuarioid) {
            return;
        }

        abort(403);
    }
}
