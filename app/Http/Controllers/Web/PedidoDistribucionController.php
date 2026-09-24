<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\DetallePedidoDistribucion;
use App\Models\Insumo;
use App\Models\PedidoDistribucion;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Services\DisponibilidadMayoristaPdvService;
use App\Services\NotificacionUsuarioService;
use App\Services\PedidoDistribucionMayoristaService;
use App\Services\PuntoVentaAlmacenService;
use App\Services\RecepcionPuntoVentaService;
use App\Services\SimulacionRutaService;
use App\Services\TransporteCapacidadService;
use App\Support\AlmacenAmbito;
use App\Support\EnvioTrayectoCatalogo;
use App\Support\MayoristaAccess;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\PedidoDistribucionVista;
use App\Support\PuntoVentaAccess;
use App\Support\SimulacionRutaCatalogo;
use App\Support\UbicacionGpsParser;
use App\Support\UsuarioRol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\View\View;

class PedidoDistribucionController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $esBandejaMayorista = PedidoDistribucionVista::esBandejaMayorista($request, $user);

        $query = PuntoVentaAccess::scopePedidosDelUsuario(
            PedidoDistribucion::query()->with([
                'puntoVenta.minorista',
                'detalles.insumo.unidadMedida',
                'detalles.presentacion',
                'almacenMayoristaOrigen',
                'creadoPor',
                'rutaDistribucion',
            ]),
            $user
        );

        if ($request->filled('estado_grupo')) {
            $query = PedidoDistribucionCatalogo::aplicarFiltroGrupoEstado(
                $query,
                $request->string('estado_grupo')->toString()
            );
        } elseif ($request->filled('estado')) {
            $query->where('estado', $request->string('estado')->toString());
        }

        if ($request->filled('puntoventaid')) {
            $query->where('puntoventaid', (int) $request->input('puntoventaid'));
        }

        if ($esBandejaMayorista && $request->filled('almacenid')) {
            $query->where('almacen_mayorista_origenid', (int) $request->input('almacenid'));
        }

        if ($request->filled('q')) {
            $term = $request->string('q')->trim()->toString();
            $query->where(function ($w) use ($term) {
                $w->where('numero_solicitud', 'like', "%{$term}%")
                    ->orWhere('observaciones', 'like', "%{$term}%")
                    ->orWhereHas('puntoVenta', fn ($p) => $p->where('nombre', 'like', "%{$term}%"))
                    ->orWhereHas('puntoVenta.minorista', function ($m) use ($term) {
                        $m->where('nombre', 'like', "%{$term}%")
                            ->orWhere('apellido', 'like', "%{$term}%");
                    })
                    ->orWhereHas('detalles', fn ($d) => $d->where('producto_nombre', 'like', "%{$term}%"));
            });
        }

        $pedidos = $query->orderByDesc('pedidodistribucionid')->get();

        $pendientes = $pedidos->filter(fn (PedidoDistribucion $p) => PedidoDistribucionCatalogo::pendienteAprobacionMayorista($p));
        $procesados = $pedidos->reject(fn (PedidoDistribucion $p) => PedidoDistribucionCatalogo::pendienteAprobacionMayorista($p));
        $enRutaTiempoReal = $pedidos->filter(fn (PedidoDistribucion $p) => PedidoDistribucionCatalogo::estaEnRutaTiempoReal($p));

        $puntosVenta = PuntoVentaAccess::scopePuntosDelUsuario(
            PuntoVenta::query()->where('activo', true)->orderBy('nombre'),
            $user
        )->get();

        $filtroPdvId = $request->integer('puntoventaid') ?: null;
        $filtroPdvNombre = $filtroPdvId
            ? ($puntosVenta->firstWhere('puntoventaid', $filtroPdvId)?->nombre ?? '')
            : '';

        $puedeCrear = PedidoDistribucionVista::puedeCrearSolicitud($request, $user);
        $puedeGestionarMayorista = UsuarioRol::puedeGestionarDistribucionMayorista($user);
        $esMinorista = UsuarioRol::esMinorista($user);

        $almacenesMayorista = $esBandejaMayorista
            ? AlmacenAmbito::scope(Almacen::query()->where('activo', true), AlmacenAmbito::MAYORISTA)->orderBy('nombre')->get()
            : collect();

        $viewData = compact(
            'pedidos',
            'pendientes',
            'procesados',
            'enRutaTiempoReal',
            'puntosVenta',
            'filtroPdvId',
            'filtroPdvNombre',
            'puedeCrear',
            'puedeGestionarMayorista',
            'esMinorista',
            'esBandejaMayorista',
            'almacenesMayorista'
        );

        return view(
            $esBandejaMayorista ? 'punto_venta.pedidos.index-mayorista' : 'punto_venta.pedidos.index',
            $viewData
        );
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        abort_unless(PedidoDistribucionVista::puedeCrearSolicitud($request, $user), 403);

        AlmacenAmbito::asegurarAmbitosEnRegistros();

        $esMinorista = UsuarioRol::esMinorista($user);
        $esAdmin = UsuarioRol::esAdminGlobal($user);

        $puntosMinorista = PuntoVentaAccess::scopePuntosDelUsuario(
            PuntoVenta::query()->where('activo', true)->with('minorista')->orderBy('nombre'),
            $user
        )->get();

        $oldPunto = old('puntoventaid') ? PuntoVenta::with('minorista')->find(old('puntoventaid')) : null;
        if ($oldPunto === null && $esMinorista && $puntosMinorista->count() === 1) {
            $oldPunto = $puntosMinorista->first();
        }
        $oldMinoristaId = old('minorista_usuarioid');
        $oldMinoristaLabel = '';
        if ($esAdmin) {
            if ($oldMinoristaId) {
                $minoristaUser = Usuario::find((int) $oldMinoristaId);
                $oldMinoristaLabel = $minoristaUser
                    ? trim($minoristaUser->nombre.' '.($minoristaUser->apellido ?? ''))
                    : '';
            } elseif ($oldPunto?->minorista) {
                $oldMinoristaId = (int) $oldPunto->usuarioid;
                $oldMinoristaLabel = trim($oldPunto->minorista->nombre.' '.($oldPunto->minorista->apellido ?? ''));
            }
        }
        $oldAlmacen = old('almacen_mayorista_origenid') ? Almacen::find(old('almacen_mayorista_origenid')) : null;
        $oldInsumo = old('insumoid') ? Insumo::with('unidadMedida', 'almacen')->find(old('insumoid')) : null;

        $puntosVentaMapa = $puntosMinorista->map(fn (PuntoVenta $pv) => [
            'id' => $pv->puntoventaid,
            'label' => $pv->nombre,
            'minorista_usuarioid' => (int) $pv->usuarioid,
            'lat' => $pv->latitud,
            'lng' => $pv->longitud,
            'resumen' => $pv->resumenUbicacion(),
            'direccion' => $pv->direccionParaMostrar(),
        ])->values()->all();

        return view('punto_venta.pedidos.create', [
            'numeroSolicitud' => PedidoDistribucionCatalogo::generarNumeroSolicitud(),
            'puntosMinorista' => $puntosMinorista,
            'puntosVentaMapa' => $puntosVentaMapa,
            'esMinorista' => $esMinorista,
            'esAdmin' => $esAdmin,
            'oldMinoristaId' => $oldMinoristaId,
            'oldMinoristaLabel' => $oldMinoristaLabel,
            'oldPuntoLabel' => $oldPunto?->nombre ?? '',
            'oldPuntoResumen' => $oldPunto?->resumenUbicacion() ?? '',
            'oldPuntoId' => $oldPunto?->puntoventaid,
            'oldAlmacenLabel' => $oldAlmacen?->nombre ?? '',
            'oldProductoLabel' => $oldInsumo
                ? $oldInsumo->nombre.' · Stock '.number_format((float) $oldInsumo->stock, 2).' '.($oldInsumo->unidadMedida?->abreviatura ?? '')
                : '',
            'oldProductoUnidad' => $oldInsumo?->unidadMedida?->abreviatura ?? $oldInsumo?->unidadMedida?->nombre ?? '',
            'oldProductoStock' => $oldInsumo ? (float) $oldInsumo->stock : null,
        ]);
    }

    public function store(Request $request, DisponibilidadMayoristaPdvService $disponibilidadMayorista, PuntoVentaAlmacenService $almacenPdv, NotificacionUsuarioService $notificaciones): RedirectResponse
    {
        $user = $request->user();
        abort_unless(PedidoDistribucionVista::puedeCrearSolicitud($request, $user), 403);

        $esAdmin = UsuarioRol::esAdminGlobal($user);
        $esMayorista = UsuarioRol::esMayorista($user) && ! UsuarioRol::esMinorista($user);
        $esMinorista = UsuarioRol::esMinorista($user) && ! $esAdmin;

        if (! $esMinorista) {
            EnvioTrayectoCatalogo::autorizarTrayecto($user, EnvioTrayectoCatalogo::TRAYECTO_PDV);
        }

        $esIniciadorMayorista = $esMayorista || $esAdmin;
        $requiereAsignacion = $esIniciadorMayorista;

        $request->merge(['tipo_solicitud' => PedidoDistribucionCatalogo::TIPO_SOLICITUD_STOCK]);

        $reglas = [
            'puntoventaid' => 'required|integer|exists:punto_venta,puntoventaid',
            'minorista_usuarioid' => [
                $esAdmin ? 'required' : 'nullable',
                'integer',
                'exists:usuario,usuarioid',
            ],
            'almacen_mayorista_origenid' => [
                $esMinorista ? 'required' : 'nullable',
                'integer',
                'exists:almacen,almacenid',
            ],
            'tipo_solicitud' => 'required|in:stock',
            'fecha_entrega_deseada' => 'required|date|after_or_equal:today',
            'hora_entrega_deseada' => 'nullable|date_format:H:i',
            'observaciones' => 'nullable|string|max:2000',
            'detalles' => 'nullable|array|min:1',
            'detalles.*.insumoid' => 'required|integer|exists:insumo,insumoid',
            'detalles.*.insumo_presentacionid' => 'required|integer|exists:insumo_presentacion,insumo_presentacionid',
            'detalles.*.inventario_presentacion_loteid' => 'nullable|integer|exists:inventario_presentacion_lote,inventario_presentacion_loteid',
            'detalles.*.almacen_mayorista_origenid' => 'nullable|integer|exists:almacen,almacenid',
            'detalles.*.cantidad' => 'required|numeric|gt:0',
            'almacenes_recogida_orden' => 'nullable|array',
            'almacenes_recogida_orden.*' => 'integer|exists:almacen,almacenid',
            'canal_origen' => [
                $esIniciadorMayorista ? 'required' : 'nullable',
                'in:'.implode(',', array_keys(PedidoDistribucionCatalogo::CANALES_EXTERNOS)),
            ],
            'insumoid' => 'nullable|integer|exists:insumo,insumoid',
            'insumo_presentacionid' => 'nullable|integer|exists:insumo_presentacion,insumo_presentacionid',
            'cantidad' => 'nullable|numeric|gt:0',
            'transportista_usuarioid' => [
                $requiereAsignacion ? 'required' : 'nullable',
                'integer',
                'exists:usuario,usuarioid',
            ],
            'vehiculoid' => [
                $requiereAsignacion ? 'required' : 'nullable',
                'integer',
                'exists:vehiculo,vehiculoid',
            ],
        ];

        $data = $request->validate($reglas, [
            'puntoventaid.required' => 'Seleccione un punto de venta destino.',
            'minorista_usuarioid.required' => 'Seleccione el minorista destino.',
            'fecha_entrega_deseada.required' => 'Indique la fecha de entrega deseada.',
            'almacen_mayorista_origenid.required' => 'Seleccione el mayorista desde el catálogo de productos.',
            'detalles.min' => 'Agregue al menos un producto al envío.',
            'detalles.*.insumoid.required' => 'Seleccione un producto disponible en almacén mayorista.',
            'detalles.*.insumo_presentacionid.required' => 'Seleccione la presentación del producto.',
            'detalles.*.cantidad.required' => 'Indique la cantidad solicitada.',
            'detalles.*.cantidad.gt' => 'La cantidad debe ser mayor que cero.',
            'transportista_usuarioid.required' => 'Seleccione el transportista para el envío.',
            'vehiculoid.required' => 'Seleccione el vehículo para el envío.',
            'canal_origen.required' => 'Indique por qué canal recibió el pedido (WhatsApp, teléfono, presencial u otro).',
        ]);

        $lineasEntrada = $data['detalles'] ?? [];
        if ($lineasEntrada === [] && ! empty($data['insumoid'])) {
            $lineasEntrada = [[
                'insumoid' => (int) $data['insumoid'],
                'insumo_presentacionid' => (int) ($data['insumo_presentacionid'] ?? 0),
                'cantidad' => (float) ($data['cantidad'] ?? 0),
            ]];
        }

        if ($lineasEntrada === []) {
            return back()->withInput()->with('error', 'Agregue al menos un producto al envío.');
        }

        $punto = PuntoVenta::query()->findOrFail($data['puntoventaid']);
        if (UsuarioRol::esMinorista($user) && (int) $punto->usuarioid !== (int) $user->usuarioid) {
            return back()->withInput()->with('error', 'Solo puede solicitar productos para sus propios puntos de venta.');
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            if ((int) $punto->usuarioid !== (int) $data['minorista_usuarioid']) {
                return back()->withInput()->with('error', 'El punto de venta no pertenece al minorista seleccionado.');
            }
        }

        if (! $punto->activo) {
            return back()->withInput()->with('error', 'El punto de venta seleccionado está inactivo.');
        }

        $tipoSolicitud = PedidoDistribucionCatalogo::TIPO_SOLICITUD_STOCK;

        $almacenOrigenId = null;
        if ($esIniciadorMayorista) {
            try {
                $almacenOrigenId = EnvioTrayectoCatalogo::resolverAlmacenMayoristaOrigen(
                    $user,
                    isset($data['almacen_mayorista_origenid']) ? (int) $data['almacen_mayorista_origenid'] : null
                );
            } catch (InvalidArgumentException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        } elseif ($esMinorista && ! empty($data['almacen_mayorista_origenid'])) {
            $almacenOrigenId = (int) $data['almacen_mayorista_origenid'];
        }

        $detallesPayload = [];
        $kgTotalPedido = 0.0;
        $almacenesEnPedido = [];
        // Ownership (MAY-04, MAY-12): el mayorista solo despacha desde sus almacenes, línea por línea.
        $almacenesPropios = $esIniciadorMayorista ? MayoristaAccess::idsAlmacenesMayorista($user) : [];

        foreach ($lineasEntrada as $linea) {
            $cantidad = (float) ($linea['cantidad'] ?? 0);
            if ($cantidad <= 0) {
                return back()->withInput()->with('error', 'La cantidad debe ser mayor que cero.');
            }

            $insumoRef = Insumo::query()->with('almacen')->findOrFail((int) $linea['insumoid']);
            if ($insumoRef->almacen?->ambito !== AlmacenAmbito::MAYORISTA) {
                return back()->withInput()->with('error', 'El producto debe estar en almacén mayorista.');
            }

            $lineaAlmacenId = isset($linea['almacen_mayorista_origenid'])
                ? (int) $linea['almacen_mayorista_origenid']
                : $almacenOrigenId;

            if ($lineaAlmacenId !== null && $lineaAlmacenId > 0 && (int) $insumoRef->almacenid !== $lineaAlmacenId) {
                return back()->withInput()->with('error', 'El producto «'.$insumoRef->nombre.'» no corresponde al almacén mayorista seleccionado.');
            }

            $lineaAlmacenId = (int) $insumoRef->almacenid;
            if ($esIniciadorMayorista && ! in_array($lineaAlmacenId, $almacenesPropios, true)) {
                abort(403, 'El producto «'.$insumoRef->nombre.'» pertenece a un almacén mayorista que no es suyo.');
            }
            if (! in_array($lineaAlmacenId, $almacenesEnPedido, true)) {
                $almacenesEnPedido[] = $lineaAlmacenId;
            }

            if ($almacenOrigenId === null) {
                $almacenOrigenId = $lineaAlmacenId;
            }

            $presentacionId = (int) $linea['insumo_presentacionid'];
            $presentacion = \App\Models\InsumoPresentacion::query()
                ->where('insumo_presentacionid', $presentacionId)
                ->where('activo', true)
                ->first();

            if ($presentacion === null) {
                return back()->withInput()->with('error', 'La presentación seleccionada no es válida.');
            }

            if (! $disponibilidadMayorista->presentacionValidaParaProducto((int) $linea['insumoid'], $presentacionId)) {
                return back()->withInput()->with('error', 'La presentación no corresponde al producto «'.$insumoRef->nombre.'».');
            }

            if (! $disponibilidadMayorista->productoEnCatalogoMayorista((int) $linea['insumoid'])) {
                return back()->withInput()->with('error', 'El producto «'.$insumoRef->nombre.'» no está disponible en la red mayorista.');
            }

            if ($disponibilidadMayorista->necesitaEsperaStock((int) $linea['insumoid'], $presentacionId, $cantidad)) {
                return back()->withInput()->with(
                    'error',
                    'La cantidad de «'.$insumoRef->nombre.'» supera el stock disponible. Reduzca la cantidad o elija otra presentación.'
                );
            }

            try {
                $disponibilidadMayorista->resolverOrigenStock((int) $linea['insumoid'], $presentacionId, $cantidad);
            } catch (InvalidArgumentException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }

            $invLoteId = isset($linea['inventario_presentacion_loteid'])
                ? (int) $linea['inventario_presentacion_loteid']
                : null;
            $referenciaLote = null;
            if ($invLoteId) {
                $invLote = \App\Models\InventarioPresentacionLote::query()->find($invLoteId);
                if ($invLote === null) {
                    return back()->withInput()->with('error', 'El lote seleccionado no es válido.');
                }
                if ((int) $invLote->almacenid !== $lineaAlmacenId) {
                    return back()->withInput()->with('error', 'El lote no corresponde al almacén mayorista del producto.');
                }
                if ((float) $invLote->cantidad_unidades + 0.0001 < $cantidad) {
                    return back()->withInput()->with('error', 'La cantidad supera el stock del lote seleccionado.');
                }
                $referenciaLote = trim((string) ($invLote->referencia_lote ?: $invLote->etiquetaLote()));
            }

            $kgLinea = $cantidad * $presentacion->pesoNetoKg();
            $kgTotalPedido += $kgLinea;

            $detallesPayload[] = [
                'cantidad' => $cantidad,
                'es_solicitud_custom' => false,
                'insumoid' => $insumoRef->insumoid,
                'almacen_mayorista_origenid' => $lineaAlmacenId,
                'insumo_presentacionid' => $presentacion->insumo_presentacionid,
                'inventario_presentacion_loteid' => $invLoteId,
                'referencia_lote' => $referenciaLote,
                'tipo_envase' => $presentacion->tipo_envase,
                'producto_nombre' => $insumoRef->nombre.' · '.$presentacion->nombre,
            ];
        }

        $ordenRecogida = array_values(array_filter(array_map('intval', $data['almacenes_recogida_orden'] ?? [])));
        // El orden de recogida solo puede nombrar almacenes de las líneas del pedido (MAY-04).
        if (array_diff($ordenRecogida, $almacenesEnPedido) !== []) {
            return back()->withInput()->with('error', 'El orden de recogida incluye un almacén que no corresponde a los productos del pedido.');
        }

        // Todas las líneas se abastecen de almacenes de UN mismo mayorista con dueño: ninguna línea
        // descuenta stock de otro mayorista ni queda un pedido «de cualquiera» (MAY-11, MAY-12).
        $duenos = Almacen::query()
            ->whereIn('almacenid', $almacenesEnPedido ?: [-1])
            ->pluck('responsable_usuarioid')
            ->map(fn ($id) => (int) $id);
        if ($duenos->contains(0) || $duenos->unique()->count() > 1) {
            return back()->withInput()->with('error', 'Un pedido solo puede abastecerse de almacenes de un mismo mayorista responsable.');
        }

        if ($ordenRecogida !== []) {
            $almacenOrigenId = $ordenRecogida[0];
        } elseif ($almacenesEnPedido !== []) {
            $almacenOrigenId = $almacenesEnPedido[0];
        }

        try {
            $almacenPdv->validarIngresoPedido($punto, $kgTotalPedido);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->with('error', $e->validator->errors()->first() ?? 'El pedido supera la capacidad del almacén del punto de venta.');
        }

        $estadoInicial = $esIniciadorMayorista
            ? PedidoDistribucionCatalogo::ESTADO_CONFIRMADO
            : PedidoDistribucionCatalogo::ESTADO_PENDIENTE;

        try {
            $pedido = DB::transaction(function () use (
                $data,
                $tipoSolicitud,
                $detallesPayload,
                $request,
                $almacenOrigenId,
                $estadoInicial,
                $esIniciadorMayorista,
                $user
            ) {
                $pedido = PedidoDistribucion::create([
                    'numero_solicitud' => PedidoDistribucionCatalogo::generarNumeroSolicitud(),
                    'puntoventaid' => (int) $data['puntoventaid'],
                    'almacen_mayorista_origenid' => $almacenOrigenId,
                    'estado' => $estadoInicial,
                    'tipo_solicitud' => $tipoSolicitud,
                    'espera_stock' => false,
                    'requiere_coordinacion_planta' => false,
                    'coordinacion_planta_resuelta' => false,
                    'fechapedido' => now(),
                    'fecha_entrega_deseada' => $data['fecha_entrega_deseada'],
                    'hora_entrega_deseada' => $data['hora_entrega_deseada'] ?? null,
                    'observaciones' => $data['observaciones'] ?? null,
                    'creado_por_usuarioid' => $user->usuarioid,
                    'envio_iniciado_mayorista' => $esIniciadorMayorista,
                    'fecha_confirmacion_minorista' => null,
                    'fecha_aceptacion' => $esIniciadorMayorista ? now() : null,
                    'aceptado_por_usuarioid' => $esIniciadorMayorista ? $user->usuarioid : null,
                    // Pedido externo explícito (MAY-13): canal y quién lo registró; no aparenta ser del minorista.
                    'canal_origen' => $esIniciadorMayorista
                        ? $data['canal_origen']
                        : PedidoDistribucionCatalogo::CANAL_SISTEMA,
                    'registrado_manual_por_usuarioid' => $esIniciadorMayorista ? $user->usuarioid : null,
                ]);

                foreach ($detallesPayload as $detallePayload) {
                    DetallePedidoDistribucion::create(array_merge([
                        'pedidodistribucionid' => $pedido->pedidodistribucionid,
                    ], $detallePayload));
                }

                // El envío iniciado por el mayorista nace confirmado: reserva el stock ya (MAY-10).
                if ($estadoInicial === PedidoDistribucionCatalogo::ESTADO_CONFIRMADO) {
                    app(\App\Services\PedidoDistribucionReservaService::class)->reservar($pedido->fresh(['detalles.presentacion', 'detalles.insumo']));
                }

                return $pedido;
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($esIniciadorMayorista) {
            try {
                app(PedidoDistribucionMayoristaService::class)->designarTransportista(
                    $pedido,
                    (int) $data['transportista_usuarioid'],
                    (int) $data['vehiculoid'],
                    (int) $user->usuarioid,
                    $ordenRecogida !== [] ? $ordenRecogida : null
                );
            } catch (\Throwable $e) {
                $pedido->detalles()->delete();
                $pedido->delete();

                return back()->withInput()->with('error', $e->getMessage());
            }

            $pedido->load(['detalles.insumo', 'puntoVenta.minorista', 'almacenMayoristaOrigen', 'transportista', 'vehiculo']);
            $notificaciones->envioMayoristaPendienteConfirmacionMinorista($pedido);
        } elseif ($esMinorista) {
            $pedido->load(['detalles.insumo', 'puntoVenta.minorista', 'almacenMayoristaOrigen']);
            $notificaciones->solicitudPedidoMinoristaMayorista($pedido);
        }

        $ctxRedirect = $esIniciadorMayorista ? 'mayorista' : 'pdv';
        $mensajeExito = $esIniciadorMayorista
            ? 'Envío registrado y transportista asignado. El minorista del punto de venta debe confirmar antes de salir en ruta.'
            : 'Solicitud enviada. El centro mayorista revisará el pedido y preparará el envío.';

        $paramsRedirect = ['pedido' => $pedido->fresh(), 'ctx' => $ctxRedirect];
        if ($esIniciadorMayorista) {
            $paramsRedirect['paso'] = 3;
        }

        return redirect()->route('punto-venta.pedidos.show', $paramsRedirect);
    }

    public function show(Request $request, PedidoDistribucion $pedido): View
    {
        abort_unless(PuntoVentaAccess::puedeVerPedido(auth()->user(), $pedido), 403);

        $pedido->load([
            'puntoVenta.minorista',
            'detalles.insumo.unidadMedida',
            'detalles.insumo.almacen',
            'detalles.insumoPlantaReferencia.unidadMedida',
            'detalles.presentacion.tipoEmpaque',
            'almacenMayoristaOrigen',
            'aceptadoPor',
            'creadoPor',
            'transportista',
            'vehiculo.tipoVehiculo',
            'rutaDistribucion.transportista',
            'rutaDistribucion.vehiculo',
            'solicitudesProduccionPlanta.creadoPor',
        ]);

        $user = auth()->user();
        $ruta = $pedido->rutaDistribucion;
        $puedeGestionarMayorista = UsuarioRol::puedeGestionarDistribucionMayorista($user);
        $esMinoristaDueño = UsuarioRol::esMinorista($user)
            && (int) $pedido->puntoVenta?->usuarioid === (int) auth()->id();
        $puedeAnunciarLlegada = false;
        $estadoRecepcionPdv = [];
        $resumenCierrePdv = null;
        if ($ruta !== null && ! $ruta->esTrasladoPlantaMayorista()) {
            $resumenCierrePdv = app(\App\Services\CierreEnvioDistribucionPdvService::class)->resumenPasos($ruta);
            $estadoRecepcionPdv = app(\App\Services\RecepcionPdvMinoristaService::class)->estadoRecepcion($ruta, $pedido);
            $puedeAnunciarLlegada = $esMinoristaDueño && ($estadoRecepcionPdv['puede_firmar'] ?? false);
        } elseif ($esMinoristaDueño && PedidoDistribucionCatalogo::puedeConfirmarRecepcion($pedido)) {
            $puedeAnunciarLlegada = true;
        }

        $pendienteMayorista = PedidoDistribucionCatalogo::pendienteAprobacionMayorista($pedido);
        $pendienteConfirmacionMinorista = PedidoDistribucionCatalogo::pendienteConfirmacionMinorista($pedido);
        $puedeConfirmarEnvioMayorista = $esMinoristaDueño && $pendienteConfirmacionMinorista;
        $puedeDesignarTransportista = $puedeGestionarMayorista
            && PedidoDistribucionCatalogo::puedeDesignarTransportista($pedido);
        $transportistaDesignado = PedidoDistribucionCatalogo::tieneTransportistaDesignado($pedido);
        $puedeEmpezarRuta = $ruta !== null
            && SimulacionRutaCatalogo::usuarioPuedeEmpezarDistribucion($user, $ruta);
        $simulacionActiva = $ruta !== null
            && SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta);
        $esTransportistaAsignado = UsuarioRol::esTransportista($user)
            && (
                (int) $pedido->transportista_usuarioid === (int) $user->usuarioid
                || (int) $ruta?->transportista_usuarioid === (int) $user->usuarioid
            );
        $puedeEditarFlujo = PedidoDistribucionCatalogo::puedeEditarFlujoAntesDeRuta($pedido);
        // Solo el minorista dueño edita su solicitud y solo mientras está pendiente (MIN-04).
        $puedeEditarSolicitud = $esMinoristaDueño && PedidoDistribucionCatalogo::puedeEditarSolicitudMinorista($pedido);
        $puedeReabrirRevision = ($puedeGestionarMayorista ?? false)
            && PedidoDistribucionCatalogo::puedeReabrirRevision($pedido);
        $pasoActualFlujo = PedidoDistribucionCatalogo::pasoActualFlujo($pedido);
        $pasosFlujo = PedidoDistribucionCatalogo::pasosFlujoUi(
            $pedido,
            $esMinoristaDueño,
            $puedeEditarFlujo,
            $transportistaDesignado,
        );
        $mostrarPanelCapacidadVehiculo = PedidoDistribucionCatalogo::mostrarPanelCapacidadVehiculo(
            $pedido,
            $puedeGestionarMayorista,
            $puedeDesignarTransportista,
            $transportistaDesignado,
        );
        $pasoInicial = max(1, min(5, (int) request()->query('paso', 0) ?: $pasoActualFlujo));
        if (! $puedeEditarFlujo && $pasoInicial < $pasoActualFlujo) {
            $pasoInicial = $pasoActualFlujo;
        }

        $erroresStock = $puedeGestionarMayorista
            ? app(PedidoDistribucionMayoristaService::class)->verificarDisponibilidad($pedido)
            : [];

        $esAdmin = UsuarioRol::esAdminGlobal($user);
        $puedeVerRutaTiempoReal = $esAdmin
            || UsuarioRol::esJefeMayorista($user)
            || UsuarioRol::esJefePlanta($user);
        $urlTiempoRealPedido = $simulacionActiva && $ruta
            ? route('logistica.rutas-tiempo-real.show', ['tipo' => 'distribucion', 'id' => $ruta->rutadistribucionid])
            : null;

        $puedeSolicitarProduccionPlanta = false;
        $solicitudActivaPlanta = $pedido->solicitudesProduccionPlanta
            ->sortByDesc('solicitudproduccionplantaid')
            ->first();

        $puedeEliminarSolicitud = $pedido->estado === PedidoDistribucionCatalogo::ESTADO_PENDIENTE
            && $esMinoristaDueño;

        $esBandejaMayorista = PedidoDistribucionVista::esBandejaMayorista($request, $user);
        $ctxVolver = $esBandejaMayorista ? 'mayorista' : 'pdv';

        $capacidadSvc = app(TransporteCapacidadService::class);
        $resumenCargaPedido = [
            'peso_kg' => $capacidadSvc->pesoPedidosDistribucion([$pedido]),
            'volumen_m3' => $capacidadSvc->volumenPedidosDistribucion([$pedido]),
        ];

        return view('punto_venta.pedidos.show', compact(
            'pedido',
            'puedeGestionarMayorista',
            'esMinoristaDueño',
            'puedeAnunciarLlegada',
            'erroresStock',
            'ruta',
            'puedeDesignarTransportista',
            'transportistaDesignado',
            'puedeEmpezarRuta',
            'simulacionActiva',
            'esTransportistaAsignado',
            'puedeEditarFlujo',
            'puedeEditarSolicitud',
            'puedeReabrirRevision',
            'pasoInicial',
            'pasoActualFlujo',
            'esAdmin',
            'puedeVerRutaTiempoReal',
            'urlTiempoRealPedido',
            'puedeSolicitarProduccionPlanta',
            'solicitudActivaPlanta',
            'puedeEliminarSolicitud',
            'esBandejaMayorista',
            'ctxVolver',
            'estadoRecepcionPdv',
            'resumenCierrePdv',
            'resumenCargaPedido',
            'pendienteConfirmacionMinorista',
            'puedeConfirmarEnvioMayorista',
            'pasosFlujo',
            'mostrarPanelCapacidadVehiculo',
        ));
    }

    public function validarCapacidadVehiculo(Request $request, PedidoDistribucion $pedido, TransporteCapacidadService $capacidadSvc): JsonResponse
    {
        abort_unless(PuntoVentaAccess::puedeVerPedido(auth()->user(), $pedido), 403);

        $data = $request->validate([
            'vehiculoid' => 'required|integer|exists:vehiculo,vehiculoid',
        ]);

        $vehiculo = \App\Models\Vehiculo::query()->with('tipoVehiculo')->findOrFail((int) $data['vehiculoid']);
        $pesoKg = $capacidadSvc->pesoPedidosDistribucion([$pedido]);
        $volumenM3 = $capacidadSvc->volumenPedidosDistribucion([$pedido]);
        $cap = $capacidadSvc->capacidadEfectiva($vehiculo);
        $ok = true;
        $mensaje = '';

        try {
            $capacidadSvc->validarCarga($vehiculo, $pesoKg, $volumenM3);
        } catch (InvalidArgumentException $e) {
            $ok = false;
            $mensaje = $e->getMessage();
        }

        $pctKg = $cap['kg'] > 0 ? round(($pesoKg / $cap['kg']) * 100, 1) : null;
        $pctM3 = ($cap['m3'] > 0 && $volumenM3 !== null && $volumenM3 > 0)
            ? round(($volumenM3 / $cap['m3']) * 100, 1)
            : null;
        $pctUso = max($pctKg ?? 0, $pctM3 ?? 0);

        return response()->json([
            'ok' => $ok,
            'mensaje' => $mensaje,
            'capacidad_kg' => $cap['kg'],
            'capacidad_m3' => $cap['m3'],
            'peso_kg' => round($pesoKg, 2),
            'volumen_m3' => $volumenM3 !== null ? round($volumenM3, 3) : null,
            'porcentaje_uso' => $pctUso > 0 ? $pctUso : $pctKg,
            'porcentaje_peso' => $pctKg,
            'porcentaje_volumen' => $pctM3,
            'vehiculo' => $vehiculo->placa,
            'recomendacion' => $ok
                ? 'El vehículo seleccionado cubre peso y volumen de este pedido.'
                : 'Elija otro vehículo o reduzca la cantidad solicitada.',
        ]);
    }

    public function ubicacionPuntoVenta(Request $request, PedidoDistribucion $pedido): View
    {
        return $this->vistaUbicacionContexto($request, $pedido, 'punto-venta');
    }

    public function ubicacionAlmacen(Request $request, PedidoDistribucion $pedido): View
    {
        return $this->vistaUbicacionContexto($request, $pedido, 'almacen');
    }

    private function vistaUbicacionContexto(Request $request, PedidoDistribucion $pedido, string $tipo): View
    {
        abort_unless(PuntoVentaAccess::puedeVerPedido(auth()->user(), $pedido), 403);

        $pedido->load(['puntoVenta', 'almacenMayoristaOrigen', 'detalles.insumo.almacen']);

        $pasoRetorno = max(1, min(5, (int) $request->query('paso', 0) ?: 3));
        $volverUrl = route('punto-venta.pedidos.show', ['pedido' => $pedido, 'paso' => $pasoRetorno]);

        if ($tipo === 'punto-venta') {
            $punto = $pedido->puntoVenta;
            abort_unless($punto, 404);

            $ubicacion = $punto->ubicacionVisible();

            $contexto = [
                'titulo' => 'Punto de venta',
                'nombre' => $punto->nombre,
                'direccion' => $ubicacion['direccion'],
                'estimada' => $ubicacion['estimada'],
                'lat' => $punto->latitud,
                'lng' => $punto->longitud,
                'icono' => 'fa-store',
            ];
        } else {
            $almacen = $pedido->almacenMayoristaOrigen ?? $pedido->detalles->first()?->insumo?->almacen;
            abort_unless($almacen, 404);

            $resuelto = UbicacionGpsParser::resolverAlmacen(
                (int) $almacen->almacenid,
                $almacen->nombre,
                $almacen->ubicacion
            );

            $contexto = [
                'titulo' => 'Origen mayorista',
                'nombre' => $almacen->nombre,
                'direccion' => $resuelto['direccion'] ?: 'Sin ubicación registrada',
                'estimada' => $resuelto['estimada'] ?? false,
                'lat' => $resuelto['lat'],
                'lng' => $resuelto['lng'],
                'icono' => 'fa-warehouse',
            ];
        }

        return view('punto_venta.pedidos.contexto.ubicacion', compact('pedido', 'contexto', 'volverUrl', 'tipo'));
    }

    public function destroy(PedidoDistribucion $pedido): RedirectResponse
    {
        abort_unless(PuntoVentaAccess::puedeVerPedido(auth()->user(), $pedido), 403);

        $user = auth()->user();
        $esMinoristaDueño = UsuarioRol::esMinorista($user)
            && (int) $pedido->puntoVenta?->usuarioid === (int) $user->usuarioid;

        if ($pedido->estado !== PedidoDistribucionCatalogo::ESTADO_PENDIENTE) {
            return back()->with([
                'error' => 'Solo puede eliminar solicitudes que aún no fueron aceptadas por el mayorista.',
                'error_modal' => true,
                'error_modal_titulo' => 'No se puede eliminar',
            ]);
        }

        if (! $esMinoristaDueño) {
            abort(403);
        }

        $pedido->detalles()->delete();
        $pedido->delete();

        return redirect()
            ->route('punto-venta.pedidos.index', ['ctx' => 'pdv'])
            ->with('success', 'Solicitud eliminada.');
    }

    public function aceptar(PedidoDistribucion $pedido): RedirectResponse
    {
        abort_unless(UsuarioRol::puedeGestionarDistribucionMayorista(auth()->user()), 403);
        MayoristaAccess::asegurarPuedeVerPedido(auth()->user(), $pedido);

        if (! PedidoDistribucionCatalogo::puedeAceptarMayorista($pedido)) {
            return back()->with('warning', 'Este pedido ya fue procesado.');
        }

        try {
            app(PedidoDistribucionMayoristaService::class)->aceptarPedido($pedido, (int) auth()->id());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $pedido->refresh();
        $mensaje = $pedido->requiere_coordinacion_planta
            ? 'Pedido aceptado. Debe solicitar producción a planta antes de asignar transportista.'
            : 'Pedido aceptado. Designe el transportista cuando el producto esté listo para salir.';

        return back()->with('success', $mensaje);
    }

    public function confirmarEnvioIniciadoMayorista(PedidoDistribucion $pedido, NotificacionUsuarioService $notificaciones): RedirectResponse
    {
        $user = auth()->user();
        abort_unless(UsuarioRol::esMinorista($user), 403);
        abort_unless((int) $pedido->puntoVenta?->usuarioid === (int) $user->usuarioid, 403);

        if (! PedidoDistribucionCatalogo::pendienteConfirmacionMinorista($pedido)) {
            return back()->with('warning', 'Este envío ya fue confirmado o no requiere su aprobación.');
        }

        $pedido->update(['fecha_confirmacion_minorista' => now()]);
        $pedido->load(['transportista', 'puntoVenta', 'detalles.insumo']);
        $notificaciones->envioMayoristaConfirmadoPorMinorista($pedido);

        return back()->with('success', 'Envío confirmado. El transportista puede registrar las condiciones del vehículo y continuar con el cierre operativo.');
    }

    public function rechazar(Request $request, PedidoDistribucion $pedido): RedirectResponse
    {
        abort_unless(UsuarioRol::puedeGestionarDistribucionMayorista(auth()->user()), 403);
        MayoristaAccess::asegurarPuedeVerPedido(auth()->user(), $pedido);

        if (! PedidoDistribucionCatalogo::puedeAceptarMayorista($pedido)) {
            return back()->with('warning', 'Este pedido ya fue procesado.');
        }

        $data = $request->validate(['motivo_rechazo' => 'nullable|string|max:500']);

        $obs = trim(($pedido->observaciones ?? '')."\n[Rechazado mayorista] ".($data['motivo_rechazo'] ?? 'Sin motivo.'));

        $pedido->update([
            'estado' => PedidoDistribucionCatalogo::ESTADO_RECHAZADO,
            'observaciones' => $obs,
        ]);

        return redirect()
            ->route('punto-venta.pedidos.index')
            ->with('success', 'Solicitud rechazada.');
    }

    public function designarTransportista(Request $request, PedidoDistribucion $pedido): RedirectResponse
    {
        abort_unless(UsuarioRol::puedeGestionarDistribucionMayorista(auth()->user()), 403);
        MayoristaAccess::asegurarPuedeVerPedido(auth()->user(), $pedido);

        $redirectPedido = route('punto-venta.pedidos.show', [
            'pedido' => $pedido,
            'ctx' => 'mayorista',
            'paso' => 3,
        ]);

        try {
            $data = $request->validate([
                'transportista_usuarioid' => 'required|integer|exists:usuario,usuarioid',
                'vehiculoid' => 'required|integer|exists:vehiculo,vehiculoid',
            ]);
        } catch (ValidationException $e) {
            throw $e->redirectTo($redirectPedido);
        }

        try {
            app(PedidoDistribucionMayoristaService::class)->designarTransportista(
                $pedido,
                (int) $data['transportista_usuarioid'],
                (int) $data['vehiculoid'],
                (int) auth()->id()
            );
        } catch (\Throwable $e) {
            $pedido->refresh();
            if (PedidoDistribucionCatalogo::tieneTransportistaDesignado($pedido)) {
                return redirect()
                    ->route('punto-venta.pedidos.show', [
                        'pedido' => $pedido,
                        'ctx' => 'mayorista',
                        'paso' => 4,
                    ])
                    ->with('success', 'Transportista y vehículo ya estaban asignados.');
            }

            return redirect()
                ->route('punto-venta.pedidos.show', [
                    'pedido' => $pedido,
                    'ctx' => 'mayorista',
                    'paso' => 3,
                ])
                ->withInput()
                ->with([
                    'error' => $e->getMessage(),
                    'error_modal' => true,
                    'error_modal_titulo' => 'No se pudo designar transportista',
                ]);
        }

        return redirect()
            ->route('punto-venta.pedidos.show', [
                'pedido' => $pedido->fresh(),
                'ctx' => 'mayorista',
                'paso' => 4,
            ])
            ->with('success', 'Transportista y vehículo asignados. El chofer debe completar el cierre operativo (condiciones del vehículo, incidentes y firmas) antes de salir en ruta.');
    }

    /** @deprecated Alias de designarTransportista — conserva la ruta histórica. */
    public function marcarEnviado(Request $request, PedidoDistribucion $pedido): RedirectResponse
    {
        return $this->designarTransportista($request, $pedido);
    }

    public function empezarRuta(PedidoDistribucion $pedido, SimulacionRutaService $simulacion): RedirectResponse
    {
        $pedido->loadMissing('rutaDistribucion');
        $ruta = $pedido->rutaDistribucion;
        abort_unless($ruta !== null, 404);

        $user = auth()->user();
        if (! UsuarioRol::puedeMarcarEnRutaDistribucion($user, $ruta)) {
            abort(403);
        }

        try {
            $simulacion->empezarDistribucion($ruta);
        } catch (\InvalidArgumentException $e) {
            if (! app(\App\Services\CierreEnvioDistribucionPdvService::class)->tieneCondicionesVehiculo($ruta)) {
                return redirect()
                    ->route('punto-venta.rutas.cierre.panel', $ruta)
                    ->with('info', 'Registre las condiciones del vehículo en el cierre operativo antes de marcar en ruta.');
            }

            return back()->with('error', $e->getMessage());
        }

        $redirect = redirect()
            ->route('punto-venta.pedidos.show', ['pedido' => $pedido, 'paso' => 4])
            ->with('success', 'Pedido marcado en ruta. El recorrido simulado está en marcha hacia el punto de venta.');

        if (UsuarioRol::esAdminGlobal($user)) {
            $redirect->with(
                'info',
                'Puede seguir el vehículo en tiempo real desde Logística → Ruta en tiempo real.'
            );
        } elseif (UsuarioRol::esTransportista($user)) {
            $redirect->with(
                'info',
                'Recorrido iniciado. Puede seguir su ruta en el panel de transportista.'
            );
        }

        return $redirect;
    }

    public function update(Request $request, PedidoDistribucion $pedido): RedirectResponse
    {
        abort_unless(PuntoVentaAccess::puedeVerPedido(auth()->user(), $pedido), 403);

        $user = $request->user();
        $esAdmin = UsuarioRol::esAdminGlobal($user);

        $data = $request->validate([
            'puntoventaid' => [$esAdmin ? 'required' : 'nullable', 'integer', 'exists:punto_venta,puntoventaid'],
            'minorista_usuarioid' => [$esAdmin ? 'required' : 'nullable', 'integer', 'exists:usuario,usuarioid'],
            'almacen_mayorista_origenid' => [$esAdmin ? 'required' : 'nullable', 'integer', 'exists:almacen,almacenid'],
            'insumoid' => 'required|integer|exists:insumo,insumoid',
            'cantidad' => 'required|numeric|gt:0',
            'fecha_entrega_deseada' => 'nullable|date',
            'observaciones' => 'nullable|string|max:2000',
        ]);

        try {
            app(PedidoDistribucionMayoristaService::class)->actualizarSolicitud($pedido, $data, $user);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('punto-venta.pedidos.show', ['pedido' => $pedido, 'paso' => 1])
            ->with('success', 'Solicitud actualizada correctamente.');
    }

    public function reabrirRevision(PedidoDistribucion $pedido): RedirectResponse
    {
        abort_unless(UsuarioRol::puedeGestionarDistribucionMayorista(auth()->user()), 403);
        MayoristaAccess::asegurarPuedeVerPedido(auth()->user(), $pedido);

        try {
            app(PedidoDistribucionMayoristaService::class)->reabrirRevision($pedido);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('punto-venta.pedidos.show', ['pedido' => $pedido, 'paso' => 2])
            ->with('success', 'Pedido devuelto a revisión mayorista. Puede ajustar la solicitud y volver a aceptar.');
    }

    public function confirmarRecepcion(PedidoDistribucion $pedido): RedirectResponse
    {
        abort_unless(PuntoVentaAccess::puedeVerPedido(auth()->user(), $pedido), 403);
        abort_unless(
            UsuarioRol::esMinorista(auth()->user())
            && (int) $pedido->puntoVenta?->usuarioid === (int) auth()->id(),
            403
        );

        // Única vía canónica (MIN-01): la recepción contable ocurre en el cierre con llegada, incidentes
        // y firmas. Este endpoint histórico ya no acredita inventario; solo lleva al cierre.
        $pedido->loadMissing('rutaDistribucion');
        $ruta = $pedido->rutaDistribucion;
        if ($ruta !== null && ! $ruta->esTrasladoPlantaMayorista()) {
            return redirect()
                ->route('punto-venta.rutas.cierre.panel', $ruta)
                ->with('info', 'Use el cierre operativo con firmas para registrar la recepción en punto de venta.');
        }

        return back()->with('error', 'Este pedido no tiene una ruta de entrega: la recepción se registra en el cierre operativo de la ruta.');
    }
}
