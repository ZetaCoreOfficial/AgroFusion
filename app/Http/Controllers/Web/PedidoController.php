<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\EnvioAsignacionMultiple;
use App\Models\Pedido;
use App\Models\Insumo;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Services\TransporteCapacidadService;
use App\Support\LocalOrgTrackFallback;
use App\Support\MayoristaAccess;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\PuntoVentaAccess;
use App\Services\CostoEnvioRutaService;
use App\Services\NotificacionUsuarioService;
use App\Services\RecepcionPlantaEnvioService;
use App\Support\AlmacenAmbito;
use App\Support\EnvioAsignacionEstadoCatalogo;
use App\Support\EnvioListadoService;
use App\Support\EnvioPedidoService;
use App\Support\EnvioTrayectoCatalogo;
use App\Support\PedidoCatalogo;
use App\Support\RutaDistribucionCatalogo;
use App\Support\RutaPorCallesService;
use App\Support\RutaTiempoRealAcceso;
use App\Support\SimulacionRutaCatalogo;
use App\Support\UbicacionGpsParser;
use App\Support\UsuarioRol;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PedidoController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()?->can('asignaciones.view')) {
            return redirect()->route('logistica.asignaciones.listado', $request->query());
        }

        return view('logistica.envios.index', EnvioListadoService::prepararListado($request));
    }

    public function create()
    {
        $user = request()->user();
        abort_unless(EnvioTrayectoCatalogo::puedeCrearAlguno($user), 403);

        AlmacenAmbito::asegurarAmbitosEnRegistros();

        $trayectosPermitidos = EnvioTrayectoCatalogo::trayectosPermitidos($user);
        $queryDestino = request()->string('destino')->toString() ?: null;
        if ($queryDestino !== null && $queryDestino !== '' && ! EnvioTrayectoCatalogo::puedeUsarTrayecto($user, $queryDestino)) {
            abort(403, 'Su rol no puede registrar envíos de este tipo de trayecto.');
        }
        $destinoInicial = EnvioTrayectoCatalogo::destinoInicialPermitido($user, $queryDestino);

        $numeroSolicitud = PedidoCatalogo::generarNumeroSolicitud();

        $filtroAlmacenesAgricola = AlmacenAmbito::scope(
            Almacen::query()->where('activo', true),
            AlmacenAmbito::AGRICOLA
        )->orderBy('nombre')->get()->map(fn (Almacen $a) => [
            'value' => (string) $a->almacenid,
            'label' => $a->nombre,
        ])->values()->all();

        $filtroAlmacenesPlanta = AlmacenAmbito::scope(
            Almacen::query()->where('activo', true),
            AlmacenAmbito::PLANTA
        )->orderBy('nombre')->get()->map(fn (Almacen $a) => [
            'value' => (string) $a->almacenid,
            'label' => $a->nombre,
        ])->values()->all();

        return view('pedidos.create', array_merge([
            'numeroSolicitud' => $numeroSolicitud,
            'codigoTrasladoPreview' => RutaDistribucionCatalogo::generarCodigoTraslado(),
            'hubLat' => RutaPorCallesService::HUB_LAT,
            'hubLng' => RutaPorCallesService::HUB_LNG,
            'filtroAlmacenesAgricola' => $filtroAlmacenesAgricola,
            'filtroAlmacenesPlanta' => $filtroAlmacenesPlanta,
            'almacenesMapa' => $this->almacenesParaMapaEnvio(),
            'almacenesMapaTraslado' => $this->almacenesParaMapaTraslado(),
            'puntosMapaPdv' => $this->puntosMapaDistribucionPdv(request()->user()),
            'destinoInicial' => $destinoInicial,
            'trayectosPermitidos' => $trayectosPermitidos,
            'mostrarSelectorTrayecto' => count($trayectosPermitidos) > 1,
            'catalogosEmpaque' => [
                'tiposEmpaque' => LocalOrgTrackFallback::tiposEmpaqueCatalogList(),
                'tamanoConteo' => LocalOrgTrackFallback::tamanoConteoCatalogList(),
            ],
        ], $this->datosVistaDistribucionPdv(request()->user())));
    }

    /** @return array<string, mixed> */
    private function datosVistaDistribucionPdv(?Usuario $user): array
    {
        $user = $user ?? auth()->user();
        $esMinorista = $user && UsuarioRol::esMinorista($user);
        $esAdmin = $user && UsuarioRol::esAdminGlobal($user);
        $esMayorista = $user && UsuarioRol::esMayorista($user) && ! $esMinorista && ! $esAdmin;
        $esOrigenMayoristaPdv = $esAdmin || $esMayorista;

        $puntosMinorista = $user
            ? PuntoVentaAccess::scopePuntosDelUsuario(
                PuntoVenta::query()->where('activo', true)->with('minorista')->orderBy('nombre'),
                $user
            )->get()
            : collect();

        return [
            'numeroSolicitudDist' => PedidoDistribucionCatalogo::generarNumeroSolicitud(),
            'puntosMinorista' => $puntosMinorista,
            'esMinoristaPdv' => $esMinorista,
            'esAdminPdv' => $esAdmin,
            'esMayoristaPdv' => $esMayorista,
            'esOrigenMayoristaPdv' => $esOrigenMayoristaPdv,
            'pdvConAsignacion' => $esOrigenMayoristaPdv,
            'oldMinoristaId' => old('minorista_usuarioid'),
            'oldMinoristaLabel' => '',
            'oldPuntoLabel' => '',
            'oldPuntoId' => old('puntoventaid'),
            'oldAlmacenLabel' => '',
            'oldProductoLabel' => '',
            'oldProductoUnidad' => '',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function almacenesParaMapaEnvio(): array
    {
        $items = [];

        foreach ([AlmacenAmbito::AGRICOLA, AlmacenAmbito::PLANTA] as $ambito) {
            $almacenes = AlmacenAmbito::scope(
                Almacen::query()->where('activo', true),
                $ambito
            )->orderBy('nombre')->get();

            foreach ($almacenes as $almacen) {
                $resuelto = UbicacionGpsParser::resolverAlmacen(
                    (int) $almacen->almacenid,
                    $almacen->nombre,
                    $almacen->ubicacion
                );

                $items[] = [
                    'id' => $almacen->almacenid,
                    'label' => $almacen->nombre,
                    'extra' => [
                        'lat' => $resuelto['lat'],
                        'lng' => $resuelto['lng'],
                        'direccion' => $resuelto['direccion'],
                        'ambito' => $almacen->ambito ?? $ambito,
                    ],
                ];
            }
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function almacenesParaMapaTraslado(): array
    {
        $items = [];

        foreach ([AlmacenAmbito::PLANTA, AlmacenAmbito::MAYORISTA] as $ambito) {
            $almacenes = AlmacenAmbito::scope(
                Almacen::query()->where('activo', true),
                $ambito
            )
                // Destino mayorista solo con dueño que pueda recibirlo (MAY-BUG-01).
                ->when($ambito === AlmacenAmbito::MAYORISTA, fn ($q) => $q->whereHas(
                    'responsable',
                    fn ($r) => $r->where('activo', true)
                        ->whereHas('roles', fn ($rol) => $rol->whereIn('name', ['mayorista', 'jefe_mayorista']))
                ))
                ->orderBy('nombre')
                ->get();

            foreach ($almacenes as $almacen) {
                $resuelto = UbicacionGpsParser::resolverAlmacen(
                    (int) $almacen->almacenid,
                    $almacen->nombre,
                    $almacen->ubicacion
                );
                $items[] = [
                    'id' => $almacen->almacenid,
                    'label' => $almacen->nombre,
                    'extra' => [
                        'lat' => $resuelto['lat'],
                        'lng' => $resuelto['lng'],
                        'direccion' => $resuelto['direccion'],
                        'ambito' => $almacen->ambito ?? $ambito,
                    ],
                ];
            }
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function puntosMapaDistribucionPdv(?Usuario $user): array
    {
        $items = [];

        $mayoristas = AlmacenAmbito::scope(
            Almacen::query()->where('activo', true),
            AlmacenAmbito::MAYORISTA
        )->orderBy('nombre');

        if ($user && UsuarioRol::esMayorista($user) && ! UsuarioRol::esAdminGlobal($user)) {
            $mayoristas = MayoristaAccess::scopeAlmacenesMayorista($mayoristas, $user);
        }

        foreach ($mayoristas->get() as $almacen) {
            $resuelto = UbicacionGpsParser::resolverAlmacen(
                (int) $almacen->almacenid,
                $almacen->nombre,
                $almacen->ubicacion
            );
            $items[] = [
                'id' => $almacen->almacenid,
                'label' => $almacen->nombre,
                'tipo' => 'mayorista',
                'extra' => [
                    'lat' => $resuelto['lat'],
                    'lng' => $resuelto['lng'],
                    'direccion' => $resuelto['direccion'],
                    'ambito' => 'mayorista',
                ],
            ];
        }

        $puntosQuery = PuntoVenta::query()->where('activo', true)->with('minorista')->orderBy('nombre');
        if ($user && UsuarioRol::esMinorista($user) && ! UsuarioRol::esAdminGlobal($user)) {
            $puntosQuery = PuntoVentaAccess::scopePuntosDelUsuario($puntosQuery, $user);
        }

        foreach ($puntosQuery->get() as $pdv) {
            $lat = $pdv->latitud;
            $lng = $pdv->longitud;
            if ($lat === null || $lng === null) {
                $coords = UbicacionGpsParser::fromTexto($pdv->direccion);
                if ($coords === null) {
                    continue;
                }
                $lat = $coords['lat'];
                $lng = $coords['lng'];
            }

            $items[] = [
                'id' => $pdv->puntoventaid,
                'label' => $pdv->nombre,
                'tipo' => 'pdv',
                'extra' => [
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'direccion' => $pdv->direccion,
                    'minorista_usuarioid' => $pdv->usuarioid,
                    'minorista_nombre' => $pdv->nombreMinorista(),
                ],
            ];
        }

        return $items;
    }

    public function store(Request $request)
    {
        EnvioTrayectoCatalogo::autorizarTrayecto($request->user(), EnvioTrayectoCatalogo::TRAYECTO_PLANTA);

        $data = $request->validate([
            'origen_latitud' => 'required|numeric|between:-90,90',
            'origen_longitud' => 'required|numeric|between:-180,180',
            'origen_direccion' => 'nullable|string|max:255',
            'origen_almacenid' => 'nullable|integer|exists:almacen,almacenid',
            'latitud' => 'required|numeric|between:-90,90',
            'longitud' => 'required|numeric|between:-180,180',
            'direccion_texto' => 'nullable|string|max:255',
            'fechaEntregaDeseada' => 'required|date',
            'hora_recogida' => 'nullable|date_format:H:i',
            'hora_entrega_estimada' => 'nullable|date_format:H:i',
            'instrucciones_recogida' => 'nullable|string|max:2000',
            'instrucciones_entrega' => 'nullable|string|max:2000',
            'observaciones' => 'nullable|string',
            'transportista_usuarioid' => 'required|integer|exists:usuario,usuarioid',
            'vehiculoid' => 'required|integer|exists:vehiculo,vehiculoid',
            'costo_bs' => 'required|integer|min:1|max:99999999',
            'recogidas' => 'nullable|array|max:5',
            'recogidas.*.latitud' => 'required|numeric|between:-90,90',
            'recogidas.*.longitud' => 'required|numeric|between:-180,180',
            'recogidas.*.direccion' => 'nullable|string|max:255',
            'recogidas.*.almacenid' => 'nullable|integer|exists:almacen,almacenid',
            'detalles' => 'required|array|min:1',
            'detalles.*.producto_ref' => ['required', 'string', 'regex:/^(insumo|cosecha|cultivo):\d+$/'],
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.observaciones' => 'nullable|string',
        ], [
            'detalles.*.producto_ref.regex' => 'Seleccione un producto válido de producción agrícola.',
            'fechaEntregaDeseada.required' => 'Indique la fecha de entrega deseada.',
        ]);

        $erroresStock = PedidoCatalogo::validarStockDetallesPedido(
            $data['detalles'],
            isset($data['origen_almacenid']) ? (int) $data['origen_almacenid'] : null,
            array_values($data['recogidas'] ?? [])
        );
        if ($erroresStock !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages($erroresStock);
        }

        $erroresPresentacion = PedidoCatalogo::validarPresentacionDetallesPedido($data['detalles']);
        if ($erroresPresentacion !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages($erroresPresentacion);
        }

        $pesoTotalKg = (float) array_sum(array_map(
            fn (array $d) => (float) ($d['cantidad'] ?? 0),
            $data['detalles']
        ));
        $vehiculo = Vehiculo::query()->findOrFail((int) $data['vehiculoid']);
        app(TransporteCapacidadService::class)->validarCarga($vehiculo, $pesoTotalKg);

        $transportistaId = (int) $data['transportista_usuarioid'];
        $vehiculoId = (int) $data['vehiculoid'];
        $costoBs = (float) $data['costo_bs'];
        $recogidasExtra = array_values($data['recogidas'] ?? []);

        $pedido = null;

        try {
            DB::transaction(function () use ($data, $transportistaId, $vehiculoId, $costoBs, $recogidasExtra, &$pedido) {
            $detallesInput = $data['detalles'];
            unset($data['detalles'], $data['transportista_usuarioid'], $data['vehiculoid'], $data['recogidas'], $data['costo_bs']);

            $pedido = Pedido::create([
                ...$data,
                'numero_solicitud' => PedidoCatalogo::generarNumeroSolicitud(),
                'nombre_planta' => null,
                'estado' => PedidoCatalogo::ESTADO_INICIAL,
                'fechapedido' => now(),
            ]);

            foreach ($detallesInput as $detalle) {
                $producto = PedidoCatalogo::resolverProductoPedido($detalle['producto_ref']);

                $pedido->detalles()->create([
                    'insumoid' => $producto['insumoid'],
                    'producto_ref' => $detalle['producto_ref'],
                    'produccionalmacenamientoid' => str_starts_with($detalle['producto_ref'], 'cosecha:')
                        ? (int) substr($detalle['producto_ref'], 8)
                        : null,
                    'cultivo_personalizado' => $producto['cultivo'],
                    'cantidad' => $detalle['cantidad'],
                    'observaciones' => $detalle['observaciones'] ?? null,
                ]);
            }

            $envio = EnvioPedidoService::programarTransportista(
                $pedido,
                $transportistaId,
                $vehiculoId,
                (int) auth()->id(),
                $costoBs
            );

            if ($recogidasExtra !== []) {
                EnvioPedidoService::crearRutaRecogidasMultiples(
                    $pedido,
                    $envio,
                    $recogidasExtra,
                    $transportistaId,
                    (int) auth()->id()
                );
            }
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors([
                'vehiculoid' => $e->getMessage(),
            ]);
        }

        app(NotificacionUsuarioService::class)->pedidoPendienteAgricola($pedido->fresh(['detalles']));

        $mensaje = 'Envío registrado con transportista asignado.';
        if ($recogidasExtra !== []) {
            $totalRecogidas = count($recogidasExtra) + 1;
            $mensaje .= " Ruta con {$totalRecogidas} punto(s) de recogida hacia planta.";
        } else {
            $mensaje .= ' Producción agrícola debe aceptarlo para activar la salida.';
        }

        return redirect()->route('logistica.asignaciones.listado')->with('success', $mensaje);
    }

    public function show($id)
    {
        $pedido = Pedido::with([
            'detalles',
            'envioAsignacion.transportista.perfilTransportista.vehiculo.tipoVehiculo',
            'envioAsignacion.asignadoPor',
            'envioAsignacion.ruta.paradas',
            'aceptadoPor',
        ])->findOrFail($id);

        $envio = $pedido->envioAsignacion
            ?? EnvioAsignacionMultiple::query()
                ->with([
                    'transportista.perfilTransportista.vehiculo.tipoVehiculo',
                    'asignadoPor',
                    'ruta.paradas',
                ])
                ->where('externo_envio_id', $pedido->numero_solicitud)
                ->first();

        if ($envio !== null) {
            $pedido->setRelation('envioAsignacion', $envio);
        }

        $trayectoPartes = EnvioPedidoService::trayectoPartesPedido($pedido);
        if ($envio !== null) {
            $envio->setRelation('pedido', $pedido);
            $paradasMapa = EnvioPedidoService::paradasMapaEnvio($envio);
            $simulacionActiva = SimulacionRutaCatalogo::simulacionActivaAgricola($envio);
            $urlTiempoReal = ($simulacionActiva && auth()->user() !== null
                && ! UsuarioRol::esTransportista(auth()->user())
                && RutaTiempoRealAcceso::puedeVerEnvioAgricola(auth()->user(), (int) $envio->envioasignacionmultipleid))
                ? route('logistica.rutas-tiempo-real.show', ['tipo' => 'agricola', 'id' => $envio->envioasignacionmultipleid])
                : null;
        } else {
            $paradasMapa = [];
            $simulacionActiva = false;
            $urlTiempoReal = null;
        }

        return view('pedidos.show', compact(
            'pedido',
            'trayectoPartes',
            'paradasMapa',
            'simulacionActiva',
            'urlTiempoReal',
        ));
    }

    public function edit(Pedido $pedido)
    {
        $pedido->load([
            'detalles',
            'envioAsignacion.transportista.perfilTransportista.vehiculo.tipoVehiculo',
            'envioAsignacion.asignadoPor',
        ]);

        $logistica = EnvioPedidoService::datosLogistica($pedido->envioAsignacion);
        $puedeAsignarLogistica = PedidoCatalogo::puedeAsignarTransportista($pedido);

        return view('pedidos.edit', compact('pedido', 'logistica', 'puedeAsignarLogistica'));
    }

    public function update(Request $request, Pedido $pedido)
    {
        if ($request->has('estado') && ! $request->has('fechaEntregaDeseada') && ! $request->has('observaciones')) {
            $data = $request->validate([
                'estado' => 'required|in:sin asignacion,pendiente,confirmado,en produccion,rechazado',
            ]);

            if (in_array($data['estado'], PedidoCatalogo::estadosListosParaLogistica(), true)
                && PedidoCatalogo::pendienteAprobacionAgricola($pedido)) {
                return back()->with('error', 'Solo producción agrícola puede aceptar el pedido y reservar stock del almacén.');
            }

            $pedido->update($data);

            return back()->with('success', 'Estado actualizado.');
        }

        $data = $request->validate([
            'nombre_planta' => 'nullable|string|max:255',
            'fechaEntregaDeseada' => 'nullable|date',
            'direccion_texto' => 'nullable|string|max:255',
            'observaciones' => 'nullable|string|max:3000',
            'estado' => 'required|in:sin asignacion,pendiente,confirmado,en produccion,rechazado',
            'transportista_usuarioid' => 'nullable|integer|exists:usuario,usuarioid',
            'vehiculoid' => 'nullable|integer|exists:vehiculo,vehiculoid',
        ]);

        if (in_array($data['estado'], PedidoCatalogo::estadosListosParaLogistica(), true)
            && PedidoCatalogo::pendienteAprobacionAgricola($pedido)) {
            return back()->with('error', 'Solo producción agrícola puede aceptar el pedido y reservar stock del almacén.');
        }

        $transportistaId = isset($data['transportista_usuarioid']) ? (int) $data['transportista_usuarioid'] : 0;
        $vehiculoId = isset($data['vehiculoid']) ? (int) $data['vehiculoid'] : 0;
        unset($data['transportista_usuarioid'], $data['vehiculoid']);

        $pedido->update($data);

        if ($transportistaId > 0 && $vehiculoId > 0 && PedidoCatalogo::puedeAsignarTransportista($pedido)) {
            try {
                EnvioPedidoService::asignarTransportistaYVehiculo(
                    $pedido,
                    $transportistaId,
                    $vehiculoId,
                    (int) auth()->id(),
                    true
                );
            } catch (\InvalidArgumentException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        } elseif ($transportistaId > 0 xor $vehiculoId > 0) {
            return back()->withInput()->with('error', 'Debe seleccionar transportista y vehículo juntos.');
        }

        return redirect()->route('pedidos.show', $pedido)->with('success', 'Pedido actualizado correctamente.');
    }

    public function destroy($id)
    {
        Pedido::findOrFail($id)->delete();

        return redirect()->route('logistica.asignaciones.listado');
    }

    public function asignarTransportista(Request $request, Pedido $pedido): RedirectResponse
    {
        $data = $request->validate([
            'transportista_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'vehiculoid' => ['required', 'integer', 'exists:vehiculo,vehiculoid'],
            'costo_bs' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        $envio = null;

        try {
            $envio = EnvioPedidoService::asignarTransportistaYVehiculo(
                $pedido,
                (int) $data['transportista_usuarioid'],
                (int) $data['vehiculoid'],
                (int) auth()->id(),
                false,
                (float) $data['costo_bs']
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($envio) {
            app(NotificacionUsuarioService::class)->envioListoParaRecoger($envio->fresh(['pedido.detalles']));
        }

        $transportista = Usuario::find($data['transportista_usuarioid']);
        $vehiculo = \App\Models\Vehiculo::find($data['vehiculoid']);
        $nombre = trim(($transportista->nombre ?? '').' '.($transportista->apellido ?? ''));

        return back()->with('success', "Transportista {$nombre} asignado con vehículo {$vehiculo->placa} al pedido {$pedido->numero_solicitud}.");
    }

    public function confirmarCargaEnvio(Pedido $pedido): RedirectResponse
    {
        $envio = EnvioAsignacionMultiple::query()
            ->with('pedido')
            ->where(function ($q) use ($pedido) {
                $q->where('pedidoid', $pedido->pedidoid)
                    ->orWhere('externo_envio_id', $pedido->numero_solicitud);
            })
            ->first();

        if (! $envio) {
            return back()->with('error', 'Este pedido no tiene envío registrado.');
        }

        try {
            EnvioPedidoService::confirmarCargaHaciaPlanta($envio);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Carga confirmada. El envío está en camino hacia planta.');
    }

    public function confirmarLlegadaPlanta(Pedido $pedido, RecepcionPlantaEnvioService $recepcionService): RedirectResponse
    {
        try {
            $recepcionService->confirmarDesdePedido($pedido, auth()->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Pedido {$pedido->numero_solicitud} recibido en planta. La carga se registró en el almacén de destino.");
    }

    public function calcularCostoEnvio(Request $request, CostoEnvioRutaService $costoEnvio): JsonResponse
    {
        $data = $request->validate([
            'paradas' => ['required', 'array', 'min:2'],
            'paradas.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'paradas.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'distancia_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($costoEnvio->calcular(
            $data['paradas'],
            isset($data['distancia_m']) ? (float) $data['distancia_m'] : null
        ));
    }
}
