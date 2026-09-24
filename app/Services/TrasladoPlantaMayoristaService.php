<?php



namespace App\Services;



use App\Models\Almacen;

use App\Models\AlmacenajeLoteProduccion;

use App\Models\AlmacenMovimiento;

use App\Models\DetalleTrasladoPlantaMayorista;

use App\Models\Insumo;

use App\Models\InsumoPresentacion;

use App\Models\InventarioPresentacionLote;
use App\Models\RutaDistribucion;

use App\Models\RutaDistribucionParada;

use App\Models\TipoMovimientoAlmacen;

use App\Models\Usuario;

use App\Models\Vehiculo;

use App\Services\NotificacionUsuarioService;

use App\Support\AlmacenAmbito;

use App\Support\InsumoCatalogo;

use App\Support\PlantaAccess;

use App\Support\RutaDistribucionCatalogo;

use App\Support\TrasladoPlantaMayoristaPresentacion;

use App\Support\TransportistaFlotaCatalogo;

use App\Support\UbicacionGpsParser;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Str;

use InvalidArgumentException;



class TrasladoPlantaMayoristaService

{

    public function __construct(
        private readonly NotificacionUsuarioService $notificaciones,
        private readonly InventarioPresentacionService $inventarioPresentacion,
        private readonly ProductoPlantaInventarioService $inventarioPlanta,
        private readonly TransporteCapacidadService $capacidadTransporte,
    ) {}

    /**
     * @param  list<int>  $recogidasPlantasExtraIds  Almacenes de planta adicionales (recogida 2, 3…)
     */
    public function crear(

        Almacen $plantaOrigen,

        Almacen $mayoristaDestino,

        int $transportistaId,

        ?int $vehiculoId,

        int $creadoPorId,

        array $detalles,

        ?string $nombre = null,

        ?float $costoBs = null,

        array $recogidasPlantasExtraIds = [],

    ): RutaDistribucion {

        if ($plantaOrigen->ambito !== AlmacenAmbito::PLANTA) {

            throw new InvalidArgumentException('El origen debe ser un almacén de planta.');

        }



        if ($mayoristaDestino->ambito !== AlmacenAmbito::MAYORISTA) {

            throw new InvalidArgumentException('El destino debe ser un almacén mayorista.');

        }

        // MAY-BUG-01: un traslado a un almacén sin mayorista responsable activo quedaba entregado
        // (se veía en movimientos) pero ningún mayorista lo veía ni podía recibirlo.
        if (\App\Support\MayoristaAccess::responsableMayorista($mayoristaDestino) === null) {

            throw new InvalidArgumentException('El almacén mayorista destino no tiene un mayorista responsable activo. Asígnelo antes de enviar el traslado.');

        }



        $plantasRecogida = $this->resolverPlantasRecogida($plantaOrigen, $recogidasPlantasExtraIds);

        $coordsMayorista = UbicacionGpsParser::resolverAlmacen(

            (int) $mayoristaDestino->almacenid,

            $mayoristaDestino->nombre,

            $mayoristaDestino->ubicacion

        );



        if ($coordsMayorista === null) {

            throw new InvalidArgumentException('El destino debe tener ubicación GPS para planificar la ruta.');

        }



        $almacenesPlantaIds = array_map(fn (Almacen $a) => (int) $a->almacenid, $plantasRecogida);

        $detallesNormalizados = $this->normalizarDetalles($detalles, $almacenesPlantaIds, (int) $plantaOrigen->almacenid);

        $this->validarFlota($transportistaId, $vehiculoId);

        $this->validarCapacidadVehiculo($vehiculoId, $detallesNormalizados);



        return DB::transaction(function () use (

            $plantaOrigen,

            $mayoristaDestino,

            $transportistaId,

            $vehiculoId,

            $creadoPorId,

            $nombre,

            $costoBs,

            $plantasRecogida,

            $coordsMayorista,

            $detallesNormalizados

        ) {

            $etiquetaOrigenes = collect($plantasRecogida)->pluck('nombre')->implode(' + ');

            $ruta = RutaDistribucion::create([

                'codigo' => RutaDistribucionCatalogo::generarCodigoTraslado(),

                'nombre' => $nombre ?: 'Traslado '.$etiquetaOrigenes.' → '.$mayoristaDestino->nombre,

                'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA,

                'almacen_planta_origenid' => $plantaOrigen->almacenid,

                'almacen_mayorista_origenid' => null,

                'almacen_mayorista_destinoid' => $mayoristaDestino->almacenid,

                'transportista_usuarioid' => $transportistaId,

                'vehiculoid' => $vehiculoId,

                'costo_bs' => $costoBs !== null ? round($costoBs, 2) : null,

                'creado_por_usuarioid' => $creadoPorId,

                'estado' => RutaDistribucionCatalogo::ESTADO_PENDIENTE_APROBACION,

                'fecha_salida' => null,

            ]);



            $orden = 1;

            foreach ($plantasRecogida as $planta) {

                $coordsPlanta = UbicacionGpsParser::resolverAlmacen(

                    (int) $planta->almacenid,

                    $planta->nombre,

                    $planta->ubicacion

                );

                if ($coordsPlanta === null) {

                    throw new InvalidArgumentException('Cada almacén de planta debe tener ubicación GPS: '.$planta->nombre);

                }

                RutaDistribucionParada::create([

                    'rutadistribucionid' => $ruta->rutadistribucionid,

                    'orden' => $orden,

                    'tipo' => RutaDistribucionCatalogo::PARADA_CARGA_PLANTA,

                    'almacenid' => $planta->almacenid,

                    'destino' => 'Carga: '.$planta->nombre,

                    'latitud' => $coordsPlanta['lat'],

                    'longitud' => $coordsPlanta['lng'],

                    'estado' => 'completada',

                ]);

                $orden++;

            }



            RutaDistribucionParada::create([

                'rutadistribucionid' => $ruta->rutadistribucionid,

                'orden' => $orden,

                'tipo' => RutaDistribucionCatalogo::PARADA_ENTREGA_MAYORISTA,

                'almacenid' => $mayoristaDestino->almacenid,

                'destino' => 'Entrega: '.$mayoristaDestino->nombre,

                'latitud' => $coordsMayorista['lat'],

                'longitud' => $coordsMayorista['lng'],

                'estado' => 'pendiente',

            ]);



            foreach ($detallesNormalizados as $detalle) {

                DetalleTrasladoPlantaMayorista::create([

                    'rutadistribucionid' => $ruta->rutadistribucionid,

                    'insumoid' => $detalle['insumoid'],

                    'insumo_presentacionid' => $detalle['insumo_presentacionid'] ?? null,

                    'inventario_presentacion_loteid' => $detalle['inventario_presentacion_loteid'] ?? null,

                    'loteproduccionpedidoid' => $detalle['loteproduccionpedidoid'] ?? null,

                    'presentacion_nombre' => $detalle['presentacion_nombre'] ?? null,

                    'producto_nombre' => $detalle['producto_nombre'],

                    'cantidad' => $detalle['cantidad'],

                    'cantidad_unidades' => $detalle['cantidad_unidades'] ?? null,

                    'observaciones' => $detalle['observaciones'] ?? null,

                ]);

            }



            $ruta = $ruta->load([

                'paradas',

                'transportista',

                'vehiculo',

                'almacenPlantaOrigen',

                'almacenMayoristaDestino',

                'detallesTraslado.insumo.unidadMedida',

            ]);

            $this->notificaciones->trasladoPlantaPendienteAprobacion($ruta);

            return $ruta;

        });

    }



    public function aceptar(RutaDistribucion $ruta, Usuario $usuario): RutaDistribucion
    {
        if (! RutaDistribucionCatalogo::puedeAceptarPlanta($ruta)) {
            throw new InvalidArgumentException('Este traslado ya fue procesado o no está pendiente de aprobación.');
        }

        if (! PlantaAccess::puedeAprobarTraslado($usuario, $ruta)) {
            throw new InvalidArgumentException('No tiene permiso para aprobar este traslado desde planta.');
        }

        $ruta->update([
            'estado' => RutaDistribucionCatalogo::ESTADO_PLANIFICADA,
            'fecha_aprobacion_mayorista' => now(),
            'aprobado_por_usuarioid' => $usuario->usuarioid,
            'motivo_rechazo_mayorista' => null,
        ]);

        $ruta = $ruta->fresh([
            'transportista',
            'almacenPlantaOrigen',
            'almacenMayoristaDestino',
            'detallesTraslado',
        ]);

        $this->notificaciones->trasladoPlantaAceptado($ruta);
        $this->notificaciones->trasladoPlantaListoParaRecoger($ruta);

        return $ruta;
    }



    public function rechazar(RutaDistribucion $ruta, Usuario $usuario, ?string $motivo = null): RutaDistribucion
    {
        if (! RutaDistribucionCatalogo::puedeAceptarPlanta($ruta)) {
            throw new InvalidArgumentException('Este traslado ya fue procesado o no está pendiente de aprobación.');
        }

        if (! PlantaAccess::puedeAprobarTraslado($usuario, $ruta)) {
            throw new InvalidArgumentException('No tiene permiso para rechazar este traslado desde planta.');
        }

        $ruta->update([
            'estado' => RutaDistribucionCatalogo::ESTADO_RECHAZADA,
            'motivo_rechazo_mayorista' => $motivo ? trim($motivo) : null,
        ]);

        $ruta = $ruta->fresh(['almacenPlantaOrigen', 'almacenMayoristaDestino', 'creadoPor']);

        $this->notificaciones->trasladoPlantaRechazado($ruta);

        return $ruta;
    }



    public function transferirInventarioAlCompletar(RutaDistribucion $ruta, Usuario $usuario): void

    {

        // load (no loadMissing): las cantidades recibidas se registran al firmar y deben leerse frescas.
        $ruta->load([

            'detallesTraslado.insumo.unidadMedida',

            'almacenPlantaOrigen',

            'almacenMayoristaDestino',

        ]);



        if (! $ruta->esTrasladoPlantaMayorista()) {

            throw new InvalidArgumentException('La ruta no es un traslado planta → mayorista.');

        }



        $almacenMayorista = $ruta->almacenMayoristaDestino;

        if ($almacenMayorista === null) {

            throw new InvalidArgumentException('El traslado no tiene almacén mayorista destino.');

        }



        if ($ruta->detallesTraslado->isEmpty()) {

            throw new InvalidArgumentException('El traslado no tiene productos registrados.');

        }



        // Receptor real (MAY-08): el inventario solo se acredita si el mayorista dueño del destino
        // firmó la recepción con su cuenta; un cierre manual o un tercero no la sustituyen.
        $ruta->loadMissing('firmaRecepcion');
        $firma = $ruta->firmaRecepcion;
        $firmante = $firma !== null && $firma->firmante_usuarioid ? Usuario::query()->find($firma->firmante_usuarioid) : null;
        if ($firmante === null
            || ! \App\Support\FirmaCierreReglas::recepcionValida($firma, $ruta->transportista_usuarioid)
            || ! \App\Support\MayoristaAccess::puedeGestionarTraslado($firmante, $ruta)) {
            throw new InvalidArgumentException('El mayorista del almacén destino debe firmar la recepción antes de acreditar el inventario.');
        }

        // Idempotencia: la transferencia de un traslado se aplica una sola vez.
        if (AlmacenMovimiento::query()
            ->where('almacenid', $almacenMayorista->almacenid)
            ->where('referencia', $ruta->codigo)
            ->where('observaciones', 'like', '[Traslado planta → mayorista — ingreso]%')
            ->exists()) {
            throw new InvalidArgumentException('El inventario de este traslado ya fue transferido.');
        }

        $tipoIngreso = TipoMovimientoAlmacen::activosPorNaturaleza('ingreso')->firstOrFail();

        $tipoSalida = TipoMovimientoAlmacen::activosPorNaturaleza('salida')->firstOrFail();

        $ref = $ruta->codigo;



        DB::transaction(function () use ($ruta, $usuario, $almacenMayorista, $tipoIngreso, $tipoSalida, $ref) {

            foreach ($ruta->detallesTraslado as $detalle) {

                $this->transferirDetalle(

                    $detalle,

                    $almacenMayorista,

                    $usuario,

                    $tipoIngreso,

                    $tipoSalida,

                    $ref

                );

            }

        });

    }



    public function trayectoTexto(RutaDistribucion $ruta): ?string

    {

        $ruta->loadMissing(['almacenPlantaOrigen', 'almacenMayoristaDestino']);



        if (! $ruta->esTrasladoPlantaMayorista()) {

            return null;

        }



        $ruta->loadMissing('paradas.almacen');

        $origenes = $ruta->paradas

            ->where('tipo', RutaDistribucionCatalogo::PARADA_CARGA_PLANTA)

            ->sortBy('orden')

            ->map(fn ($p) => $p->almacen?->nombre ?? trim(str_replace('Carga:', '', (string) $p->destino)))

            ->filter()

            ->values();

        $origen = $origenes->isNotEmpty()

            ? $origenes->implode(' + ')

            : ($ruta->almacenPlantaOrigen?->nombre ?? 'Planta');

        $destino = TrasladoPlantaMayoristaPresentacion::nombreDestinoMayorista($ruta) ?? 'Almacén mayorista';



        return $origen.' → '.$destino;

    }



    /** @return list<array{insumoid: int, insumo_presentacionid: ?int, inventario_presentacion_loteid: ?int, loteproduccionpedidoid: ?int, presentacion_nombre: ?string, producto_nombre: string, cantidad: float, cantidad_unidades: ?float, observaciones: ?string}> */

    /** @param  list<int>  $almacenesPlantaIds */
    private function normalizarDetalles(array $detalles, array $almacenesPlantaIds, int $almacenPlantaPrincipalId): array

    {

        if ($detalles === []) {

            throw new InvalidArgumentException('Indique al menos un producto a trasladar desde planta.');

        }



        $normalizados = [];

        $vistos = [];

        $almacenesPermitidos = array_fill_keys($almacenesPlantaIds, true);



        foreach ($detalles as $detalle) {

            $insumoId = (int) ($detalle['insumoid'] ?? 0);

            $presentacionId = (int) ($detalle['insumo_presentacionid'] ?? 0);

            $inventarioId = (int) ($detalle['inventario_presentacion_loteid'] ?? 0);

            $cantidadUnidades = (float) ($detalle['cantidad_unidades'] ?? 0);

            $cantidad = (float) ($detalle['cantidad'] ?? 0);

            $almacenPlantaId = (int) ($detalle['almacen_plantaid'] ?? $almacenPlantaPrincipalId);



            if ($insumoId <= 0) {

                throw new InvalidArgumentException('Cada línea debe incluir un producto válido.');

            }



            if ($almacenPlantaId <= 0 || ! isset($almacenesPermitidos[$almacenPlantaId])) {

                throw new InvalidArgumentException('Cada producto debe pertenecer a uno de los almacenes de planta de la ruta.');

            }



            $claveLinea = $almacenPlantaId.'-'.$insumoId.'-'.$presentacionId.'-'.$inventarioId;

            if (isset($vistos[$claveLinea])) {

                throw new InvalidArgumentException('No repita la misma presentación y lote del mismo almacén de planta en el traslado.');

            }



            $insumo = Insumo::query()

                ->with('unidadMedida')

                ->where('insumoid', $insumoId)

                ->where('almacenid', $almacenPlantaId)

                ->first();



            if ($insumo === null) {

                throw new InvalidArgumentException('Uno de los productos no pertenece al almacén de planta seleccionado.');

            }

            if ((int) $insumo->tipoinsumoid !== InsumoCatalogo::tipoProductoTerminadoId()) {

                throw new InvalidArgumentException(

                    '«'.$insumo->nombre.'» no es un producto terminado. Solo puede enviar productos ya procesados en planta al mayorista (no cosecha cruda).'

                );

            }



            $presentacion = null;

            $presentacionNombre = null;

            $inventarioLote = null;

            $loteProduccionId = null;

            $tienePresentaciones = InsumoPresentacion::query()

                ->where('insumoid', $insumoId)

                ->where('activo', true)

                ->exists();



            if ($presentacionId > 0) {

                $presentacion = InsumoPresentacion::query()

                    ->where('insumo_presentacionid', $presentacionId)

                    ->where('insumoid', $insumoId)

                    ->where('activo', true)

                    ->first();



                if ($presentacion === null) {

                    throw new InvalidArgumentException('La presentación seleccionada no es válida para «'.$insumo->nombre.'».');

                }



                if ($cantidadUnidades <= 0) {

                    throw new InvalidArgumentException('Indique cuántas unidades envía de «'.$presentacion->nombre.'».');

                }



                $cantidad = round($cantidadUnidades * $presentacion->pesoNetoKg(), 4);

                $presentacionNombre = $presentacion->nombre;



                $lotesDisponibles = $this->inventarioPresentacion->lotesDisponibles($almacenPlantaId, $presentacionId);

                if ($lotesDisponibles->isEmpty()) {
                    $this->inventarioPresentacion->asegurarInventarioDesdeStock($almacenPlantaId, $insumoId);
                    $lotesDisponibles = $this->inventarioPresentacion->lotesDisponibles($almacenPlantaId, $presentacionId);
                }

                if ($lotesDisponibles->isEmpty()) {
                    throw new InvalidArgumentException(
                        'No hay stock por lote para «'.$presentacion->nombre.'». Registre inventario envasado en planta.'
                    );
                }

                if ($inventarioId <= 0) {
                    $inventarioLote = $lotesDisponibles->first();
                    if ($inventarioLote === null) {
                        throw new InvalidArgumentException('No hay stock por lote para «'.$presentacion->nombre.'».');
                    }
                } else {
                    $inventarioLote = $this->inventarioPresentacion->obtenerLote($inventarioId, $almacenPlantaId, $presentacionId);
                }
                $this->inventarioPresentacion->validarDisponibilidad($inventarioLote, $cantidadUnidades, $cantidad);
                $loteProduccionId = $inventarioLote->loteproduccionpedidoid;

            } elseif ($tienePresentaciones) {

                throw new InvalidArgumentException('Seleccione la presentación comercial de «'.$insumo->nombre.'».');

            } elseif ($cantidad <= 0) {

                throw new InvalidArgumentException('Cada producto debe tener cantidad mayor a cero.');

            } elseif (! $insumo->tieneStockSuficiente($cantidad)) {

                $unidad = $insumo->unidadMedida?->abreviatura ?? '';

                throw new InvalidArgumentException(

                    'Stock insuficiente para «'.$insumo->nombre.'»: solicitado '.$cantidad.' '.$unidad

                    .', disponible '.number_format((float) $insumo->stock, 2).' '.$unidad.'.'

                );

            }



            $vistos[$claveLinea] = true;

            $normalizados[] = [

                'insumoid' => $insumoId,

                'insumo_presentacionid' => $presentacion?->insumo_presentacionid,

                'inventario_presentacion_loteid' => $inventarioLote?->inventario_presentacion_loteid,

                'loteproduccionpedidoid' => $loteProduccionId,

                'presentacion_nombre' => $presentacionNombre,

                'producto_nombre' => $insumo->nombre,

                'cantidad' => $cantidad,

                'cantidad_unidades' => $presentacion !== null ? $cantidadUnidades : null,

                'observaciones' => isset($detalle['observaciones']) ? trim((string) $detalle['observaciones']) : null,

            ];

        }



        return $normalizados;

    }



    private function transferirDetalle(

        DetalleTrasladoPlantaMayorista $detalle,

        Almacen $almacenMayorista,

        Usuario $usuario,

        TipoMovimientoAlmacen $tipoIngreso,

        TipoMovimientoAlmacen $tipoSalida,

        string $ref

    ): void {

        $cantidad = (float) $detalle->cantidad;

        $cantidadUnidades = (float) ($detalle->cantidad_unidades ?? 0);

        // MAY-14: sale de planta lo despachado; al mayorista se acredita solo lo recibido.
        $factorRecibido = $detalle->factorRecibido();
        $kgRecibidos = round($cantidad * $factorRecibido, 4);
        $unidadesRecibidas = round($cantidadUnidades * $factorRecibido, 4);
        $notaDiferencia = $factorRecibido < 0.99999
            ? ' · Recibido '.number_format((float) $detalle->cantidad_recibida, 2).' de '
                .number_format($detalle->cantidadDespachada(), 2).' (diferencia: '.($detalle->motivo_diferencia ?: 'sin motivo').')'
            : '';

        $insumoOrigen = $detalle->insumo;

        $detalle->loadMissing(['presentacion', 'inventarioLote']);



        if ($insumoOrigen === null) {

            throw new InvalidArgumentException('Producto de planta no encontrado en el traslado.');

        }



        if ($detalle->inventario_presentacion_loteid && $detalle->inventarioLote) {

            $this->inventarioPresentacion->validarDisponibilidad(

                $detalle->inventarioLote,

                $cantidadUnidades,

                $cantidad

            );

        } elseif (! $insumoOrigen->tieneStockSuficiente($cantidad)) {

            throw new InvalidArgumentException(

                "Stock insuficiente en planta para «{$insumoOrigen->nombre}». Disponible: {$insumoOrigen->stock}."

            );

        }



        $insumoDestino = Insumo::query()

            ->where('almacenid', $almacenMayorista->almacenid)

            ->whereRaw('LOWER(TRIM(nombre)) = ?', [Str::lower(trim($insumoOrigen->nombre))])

            ->first();



        if ($insumoDestino === null) {

            $insumoDestino = Insumo::create([

                'nombre' => $insumoOrigen->nombre,

                'codigo_trazabilidad' => 'TRZ-MAY-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -6)),

                'tipoinsumoid' => $insumoOrigen->tipoinsumoid,

                'unidadmedidaid' => $insumoOrigen->unidadmedidaid,

                'stock' => 0,

                'stockminimo' => InsumoCatalogo::UMBRAL_ALERTA_STOCK,

                'descripcion' => 'Producto recibido desde planta — '.$ref,

                'almacenid' => $almacenMayorista->almacenid,

            ]);

        } else {

            $this->marcarRecepcionPlantaEnInsumo($insumoDestino, $ref);

        }



        AlmacenMovimiento::create([

            'almacenid' => $insumoOrigen->almacenid,

            'insumoid' => $insumoOrigen->insumoid,

            'insumo_presentacionid' => $detalle->insumo_presentacionid,

            'loteproduccionpedidoid' => $detalle->loteproduccionpedidoid,

            'tipo_movimiento_almacenid' => $tipoSalida->tipo_movimiento_almacenid,

            'usuarioid' => $usuario->usuarioid,

            'fecha' => now()->toDateString(),

            'cantidad' => $cantidad,

            'cantidad_unidades' => $cantidadUnidades > 0 ? $cantidadUnidades : null,

            'referencia' => $ref,

            'destino_motivo' => $almacenMayorista->nombre,

            'observaciones' => '[Traslado planta → mayorista — salida] '.$ref,

        ]);



        AlmacenMovimiento::create([

            'almacenid' => $almacenMayorista->almacenid,

            'insumoid' => $insumoDestino->insumoid,

            'insumo_presentacionid' => $detalle->insumo_presentacionid,

            'loteproduccionpedidoid' => $detalle->loteproduccionpedidoid,

            'tipo_movimiento_almacenid' => $tipoIngreso->tipo_movimiento_almacenid,

            'usuarioid' => $usuario->usuarioid,

            'fecha' => now()->toDateString(),

            'cantidad' => $kgRecibidos,

            'cantidad_unidades' => $unidadesRecibidas > 0 ? $unidadesRecibidas : null,

            'referencia' => $ref,

            'destino_motivo' => $almacenMayorista->nombre,

            'observaciones' => '[Traslado planta → mayorista — ingreso] '.$ref.$notaDiferencia,

        ]);



        if ($detalle->inventarioLote && $cantidadUnidades > 0) {

            $this->inventarioPresentacion->descontar($detalle->inventarioLote, $cantidadUnidades, $cantidad);

            if ($detalle->presentacion && $unidadesRecibidas > 0) {

                $presentacionDestino = $this->inventarioPresentacion->replicarPresentacionEnInsumo(

                    $detalle->presentacion,

                    $insumoDestino

                );

                $this->inventarioPresentacion->ingresar(

                    (int) $almacenMayorista->almacenid,

                    (int) $insumoDestino->insumoid,

                    (int) $presentacionDestino->insumo_presentacionid,

                    $detalle->loteproduccionpedidoid,

                    $detalle->inventarioLote->referencia_lote,

                    $unidadesRecibidas,

                    $kgRecibidos

                );

            }

            $this->descontarAlmacenajePlanta(
                (int) $insumoOrigen->almacenid,
                $detalle->loteproduccionpedidoid,
                $cantidadUnidades,
                $cantidad
            );

        } else {

            $insumoOrigen->decrementarStock($cantidad);

            if ($kgRecibidos > 0) {
                $insumoDestino->incrementarStock($kgRecibidos);
            }

            $this->descontarAlmacenajePlanta(
                (int) $insumoOrigen->almacenid,
                $detalle->loteproduccionpedidoid,
                0,
                $cantidad
            );

        }

    }

    private function descontarAlmacenajePlanta(
        int $almacenPlantaId,
        ?int $loteProduccionPedidoId,
        float $cantidadUnidades,
        float $cantidadKg
    ): void {
        if ($loteProduccionPedidoId === null) {
            return;
        }

        $restanteUnidades = $cantidadUnidades > 0 ? $cantidadUnidades : 0.0;
        $restanteKg = $cantidadKg > 0 ? $cantidadKg : 0.0;

        if ($restanteUnidades <= 0 && $restanteKg <= 0) {
            return;
        }

        $almacenajes = AlmacenajeLoteProduccion::query()
            ->where('almacenid', $almacenPlantaId)
            ->where('loteproduccionpedidoid', $loteProduccionPedidoId)
            ->whereNull('fecha_retiro')
            ->orderBy('fecha_almacenaje')
            ->get();

        foreach ($almacenajes as $alm) {
            if ($restanteUnidades <= 0 && $restanteKg <= 0) {
                break;
            }

            $actual = (float) $alm->cantidad;
            if ($actual <= 0) {
                continue;
            }

            $reduccion = $restanteUnidades > 0
                ? min($actual, $restanteUnidades)
                : min($actual, $restanteKg);

            if ($reduccion <= 0) {
                continue;
            }

            $nueva = max(0.0, $actual - $reduccion);
            if ($nueva <= 0.0001) {
                $alm->update([
                    'cantidad' => 0,
                    'observaciones' => trim(($alm->observaciones ?? '').' · Salida por traslado a mayorista.'),
                ]);
            } else {
                $alm->update(['cantidad' => $nueva]);
            }

            if ($restanteUnidades > 0) {
                $restanteUnidades -= $reduccion;
            } else {
                $restanteKg -= $reduccion;
            }
        }

        $this->inventarioPlanta->sincronizarDesdeAlmacenajes($almacenPlantaId);
    }



    private function validarFlota(int $transportistaId, ?int $vehiculoId): void

    {

        $transportista = Usuario::query()->with('perfilTransportista')->find($transportistaId);

        \App\Support\TransportistaPool::asegurarAsignable($transportista, TransportistaFlotaCatalogo::PLANTA);



        if ($vehiculoId === null) {

            return;

        }



        $vehiculo = Vehiculo::query()

            ->where('vehiculoid', $vehiculoId)

            ->where('activo', true)

            ->first();



        if ($vehiculo === null) {

            throw new InvalidArgumentException('El vehículo seleccionado no está disponible.');

        }



        if ($vehiculo->ambito_flota !== null && $vehiculo->ambito_flota !== TransportistaFlotaCatalogo::PLANTA) {

            throw new InvalidArgumentException('Seleccione un vehículo de flota planta.');

        }

        // Licencia compatible, vehículo operativo y no en ruta (TRA-10): antes solo se validaba en M → PDV.
        $this->capacidadTransporte->validarAsignacion($transportista, $vehiculo->loadMissing('tipoVehiculo'));

    }



    /** @param  list<array{cantidad: float}>  $detalles */

    private function validarCapacidadVehiculo(?int $vehiculoId, array $detalles): void

    {

        if ($vehiculoId === null) {

            throw new InvalidArgumentException('Seleccione un vehículo para validar la capacidad de carga.');

        }



        $pesoTotal = array_sum(array_map(fn (array $d) => (float) $d['cantidad'], $detalles));

        if ($pesoTotal <= 0) {

            return;

        }



        $vehiculo = Vehiculo::query()

            ->where('vehiculoid', $vehiculoId)

            ->where('activo', true)

            ->first();



        if ($vehiculo === null) {

            throw new InvalidArgumentException('El vehículo seleccionado no está disponible.');

        }



        $this->capacidadTransporte->validarCarga($vehiculo, $pesoTotal);

    }

    private function marcarRecepcionPlantaEnInsumo(Insumo $insumo, string $ref): void
    {
        $descripcion = trim((string) $insumo->descripcion);
        if ($descripcion !== '' && str_contains($descripcion, $ref)) {
            return;
        }

        $marca = 'Producto recibido desde planta — '.$ref;
        $esGenerica = $descripcion === ''
            || str_contains(mb_strtolower($descripcion), 'producto terminado de planta');

        $insumo->update([
            'descripcion' => $esGenerica ? $marca : $descripcion.' | '.$marca,
        ]);
    }

    /** @param  list<int>  $recogidasPlantasExtraIds  @return list<Almacen> */
    private function resolverPlantasRecogida(Almacen $plantaPrincipal, array $recogidasPlantasExtraIds): array
    {
        $plantas = [$plantaPrincipal];
        $vistos = [(int) $plantaPrincipal->almacenid => true];

        foreach ($recogidasPlantasExtraIds as $almacenId) {
            $almacenId = (int) $almacenId;
            if ($almacenId <= 0 || isset($vistos[$almacenId])) {
                continue;
            }

            $planta = Almacen::query()->where('almacenid', $almacenId)->first();
            if ($planta === null) {
                throw new InvalidArgumentException('Uno de los almacenes de planta adicionales no existe.');
            }
            if ($planta->ambito !== AlmacenAmbito::PLANTA) {
                throw new InvalidArgumentException('Las recogidas adicionales deben ser almacenes de planta.');
            }

            $plantas[] = $planta;
            $vistos[$almacenId] = true;
        }

        return $plantas;
    }

}


