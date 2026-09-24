<?php



namespace App\Services;



use App\Models\Almacen;

use App\Models\PedidoDistribucion;

use App\Models\Usuario;

use App\Models\Vehiculo;

use App\Support\PedidoDistribucionCatalogo;

use App\Support\TransportistaFlotaCatalogo;

use App\Support\UbicacionGpsParser;

use Illuminate\Support\Facades\DB;

use InvalidArgumentException;



class PedidoDistribucionMayoristaService

{

    /** @return list<string> */

    public function verificarDisponibilidad(PedidoDistribucion $pedido): array

    {

        $pedido->loadMissing('detalles.insumo.unidadMedida', 'almacenMayoristaOrigen');

        $errores = [];



        foreach ($pedido->detalles as $detalle) {

            $nombre = $detalle->producto_nombre ?: 'Producto';

            $cantidad = (float) $detalle->cantidad;

            if ($pedido->espera_stock) {

                continue;

            }

            if ($detalle->es_solicitud_custom || $pedido->requiere_coordinacion_planta) {

                continue;

            }

            $insumo = $detalle->insumo;



            if ($insumo === null) {

                $errores[] = "«{$nombre}» no está disponible en el almacén mayorista.";



                continue;

            }



            if (! $insumo->tieneStockSuficiente($cantidad)) {

                $unidad = $insumo->unidadMedida?->abreviatura ?? '';

                $errores[] = "Stock insuficiente para «{$nombre}»: solicitado {$cantidad} {$unidad}, disponible ".number_format((float) $insumo->stock, 2)." {$unidad}.";

            }

        }



        return $errores;

    }

    public function aceptarPedido(PedidoDistribucion $pedido, int $aceptadoPorId): PedidoDistribucion

    {

        if (! PedidoDistribucionCatalogo::puedeAceptarMayorista($pedido)) {

            throw new InvalidArgumentException('Este pedido ya fue procesado.');

        }

        $pedido->loadMissing(['detalles.presentacion', 'detalles.insumoPlantaReferencia', 'puntoVenta']);

        $detalle = $pedido->detalles->first();

        $esCustom = $detalle !== null && ($detalle->es_solicitud_custom || $pedido->tipo_solicitud === PedidoDistribucionCatalogo::TIPO_SOLICITUD_CUSTOM);

        $resolucion = $esCustom ? null : app(AlmacenMayoristaProximoService::class)->resolverParaPedido($pedido);

        $requierePlanta = $esCustom || $resolucion === null;

        return DB::transaction(function () use ($pedido, $aceptadoPorId, $detalle, $resolucion, $requierePlanta) {

            $updates = [

                'estado' => PedidoDistribucionCatalogo::ESTADO_CONFIRMADO,

                'fecha_aceptacion' => now(),

                'aceptado_por_usuarioid' => $aceptadoPorId,

                'espera_stock' => false,

                'requiere_coordinacion_planta' => $requierePlanta,

                'coordinacion_planta_resuelta' => ! $requierePlanta,

            ];

            if ($resolucion !== null) {

                if (! $pedido->almacen_mayorista_origenid) {
                    $updates['almacen_mayorista_origenid'] = $resolucion['almacen']->almacenid;
                }

                if ($detalle !== null) {

                    $detalle->update([

                        'insumoid' => $resolucion['insumo']->insumoid,

                        'producto_nombre' => $resolucion['insumo']->nombre,

                    ]);

                }

            }

            // El origen ya no se borra cuando falta stock: el pedido sigue asignado a este mayorista
            // (MAY-11) y pasa por coordinación con planta.
            $pedido->update($updates);

            // Reserva: dos pedidos confirmados no consumen el mismo stock (MAY-10).
            if (! $requierePlanta) {
                app(PedidoDistribucionReservaService::class)->reservar($pedido->fresh(['detalles.presentacion', 'detalles.insumo']));
            }

            return $pedido->fresh([

                'detalles.insumo.unidadMedida',

                'detalles.presentacion',

                'puntoVenta.minorista',

                'almacenMayoristaOrigen',

            ]);

        });

    }



    public function designarTransportista(

        PedidoDistribucion $pedido,

        int $transportistaId,

        int $vehiculoId,

        int $creadoPorId,

        ?array $almacenesRecogidaOrden = null

    ): PedidoDistribucion {

        if (! PedidoDistribucionCatalogo::puedeDesignarTransportista($pedido)) {

            if (PedidoDistribucionCatalogo::tieneTransportistaDesignado($pedido)) {

                return $pedido->fresh([

                    'detalles.insumo.unidadMedida',

                    'detalles.presentacion',

                    'puntoVenta.minorista',

                    'almacenMayoristaOrigen',

                    'transportista',

                    'vehiculo',

                    'rutaDistribucion',

                ]);

            }

            throw new InvalidArgumentException('El pedido no está listo para asignar transportista.');

        }



        [$transportista, $vehiculo] = $this->validarFlotaMayorista($pedido, $transportistaId, $vehiculoId);



        $pedido->loadMissing('almacenMayoristaOrigen', 'puntoVenta');

        $almacen = $pedido->almacenMayoristaOrigen;

        if ($almacen === null) {

            throw new InvalidArgumentException('El pedido no tiene almacén mayorista de origen.');

        }



        $pdv = $pedido->puntoVenta;

        if ($pdv === null) {

            throw new InvalidArgumentException('El pedido no tiene punto de venta asociado.');

        }



        if ($pdv->latitud === null || $pdv->longitud === null) {

            throw new InvalidArgumentException("El punto «{$pdv->nombre}» no tiene ubicación GPS para planificar la ruta.");

        }



        return DB::transaction(function () use ($pedido, $transportistaId, $vehiculoId, $creadoPorId, $almacen, $pdv, $almacenesRecogidaOrden) {

            $almacenesOrigen = $this->almacenesRecogidaOrdenados($pedido, $almacenesRecogidaOrden);
            if ($almacenesOrigen === []) {
                $almacenesOrigen = [$almacen];
            }

            $waypoints = [];
            foreach ($almacenesOrigen as $alm) {
                $coords = UbicacionGpsParser::resolverAlmacen(
                    (int) $alm->almacenid,
                    $alm->nombre,
                    $alm->ubicacion
                );
                if ($coords['lat'] !== null && $coords['lng'] !== null) {
                    $waypoints[] = ['lat' => (float) $coords['lat'], 'lng' => (float) $coords['lng']];
                }
            }
            $waypoints[] = ['lat' => (float) $pdv->latitud, 'lng' => (float) $pdv->longitud];

            $costoBs = null;
            if (count($waypoints) >= 2) {
                $estimacion = app(CostoEnvioRutaService::class)->calcular($waypoints);
                if (($estimacion['costo_bs'] ?? 0) > 0) {
                    $costoBs = (float) $estimacion['costo_bs'];
                }
            }

            app(DistribucionRutaService::class)->crearEnvioDirectoPedido(
                $almacenesOrigen,
                $pedido,
                $transportistaId,
                $vehiculoId,
                $creadoPorId,
                'Envío directo '.$pedido->numero_solicitud,
                $costoBs
            );



            $pedido->update([

                'transportista_usuarioid' => $transportistaId,

                'vehiculoid' => $vehiculoId,

            ]);



            return $pedido->fresh(['transportista', 'vehiculo.tipoVehiculo', 'rutaDistribucion.transportista', 'rutaDistribucion.vehiculo']);

        });

    }



    /** @deprecated Use designarTransportista(); la salida en ruta se inicia desde la ruta asignada. */

    public function marcarEnTransito(PedidoDistribucion $pedido, int $transportistaId, int $vehiculoId): PedidoDistribucion

    {

        return $this->designarTransportista($pedido, $transportistaId, $vehiculoId, (int) auth()->id());

    }



    /**

     * @return array{0: Usuario, 1: Vehiculo}

     */

    private function validarFlotaMayorista(PedidoDistribucion $pedido, int $transportistaId, int $vehiculoId): array

    {

        app(DistribucionRutaService::class)->asegurarTransportistaMayorista($transportistaId);



        $transportista = Usuario::query()

            ->where('usuarioid', $transportistaId)

            ->where('role', 'transportista')

            ->where('activo', true)

            ->first();



        if ($transportista === null) {

            throw new InvalidArgumentException('El transportista seleccionado no está disponible.');

        }



        $vehiculo = Vehiculo::query()

            ->with('tipoVehiculo')

            ->where('vehiculoid', $vehiculoId)

            ->where('activo', true)

            ->first();



        if ($vehiculo === null) {

            throw new InvalidArgumentException('El vehículo seleccionado no está disponible.');

        }



        if ($vehiculo->ambito_flota !== null && $vehiculo->ambito_flota !== TransportistaFlotaCatalogo::MAYORISTA) {

            throw new InvalidArgumentException('Seleccione un vehículo de flota mayorista.');

        }



        $pedido->loadMissing('detalles');

        $capacidad = app(TransporteCapacidadService::class);

        $capacidad->validarAsignacionYCarga(
            $transportista,
            $vehiculo,
            $capacidad->pesoPedidosDistribucion([$pedido]),
            $capacidad->volumenPedidosDistribucion([$pedido])
        );



        return [$transportista, $vehiculo];
    }

    public function liberarAsignacionLogistica(PedidoDistribucion $pedido): void
    {
        if ($pedido->rutadistribucionid === null) {
            $pedido->update([
                'transportista_usuarioid' => null,
                'vehiculoid' => null,
            ]);

            return;
        }

        $ruta = \App\Models\RutaDistribucion::query()
            ->with('pedidos')
            ->find($pedido->rutadistribucionid);

        if ($ruta === null) {
            $pedido->update([
                'rutadistribucionid' => null,
                'transportista_usuarioid' => null,
                'vehiculoid' => null,
            ]);

            return;
        }

        if ($ruta->estado !== \App\Support\RutaDistribucionCatalogo::ESTADO_PLANIFICADA) {
            throw new InvalidArgumentException('No se puede modificar el pedido: la ruta ya salió o finalizó.');
        }

        DB::transaction(function () use ($pedido, $ruta) {
            $pedido->update([
                'rutadistribucionid' => null,
                'transportista_usuarioid' => null,
                'vehiculoid' => null,
            ]);

            $otrosPedidos = $ruta->pedidos
                ->filter(fn ($p) => (int) $p->pedidodistribucionid !== (int) $pedido->pedidodistribucionid);

            if ($otrosPedidos->isEmpty()) {
                $ruta->paradas()->delete();
                $ruta->delete();
            }
        });
    }

    public function reabrirRevision(PedidoDistribucion $pedido): PedidoDistribucion
    {
        if (! PedidoDistribucionCatalogo::puedeReabrirRevision($pedido)) {
            throw new InvalidArgumentException('Este pedido no puede volver a revisión mayorista.');
        }

        return DB::transaction(function () use ($pedido) {
            $this->liberarAsignacionLogistica($pedido);

            $pedido->update([
                'estado' => PedidoDistribucionCatalogo::ESTADO_PENDIENTE,
                'fecha_aceptacion' => null,
                'aceptado_por_usuarioid' => null,
            ]);

            return $pedido->fresh(['detalles.insumo.unidadMedida', 'puntoVenta.minorista', 'almacenMayoristaOrigen']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function actualizarSolicitud(PedidoDistribucion $pedido, array $data, Usuario $usuario): PedidoDistribucion
    {
        // Pedido confirmado/en tránsito/recibido: contenido comercial congelado (MIN-04).
        if (! PedidoDistribucionCatalogo::puedeEditarSolicitudMinorista($pedido)) {
            throw new InvalidArgumentException('El pedido ya fue aceptado por el mayorista: su contenido no puede editarse. Pida que lo devuelva a revisión.');
        }

        $pedido->loadMissing(['detalles.insumo', 'detalles.presentacion', 'puntoVenta']);

        // Solo el minorista dueño edita su solicitud (el admin supervisa).
        $esDueño = \App\Support\UsuarioRol::esMinorista($usuario)
            && (int) $pedido->puntoVenta?->usuarioid === (int) $usuario->usuarioid;

        if (! $esDueño) {
            throw new InvalidArgumentException('No tiene permiso para editar esta solicitud.');
        }

        $cantidad = (float) $data['cantidad'];
        if ($cantidad <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }

        $detalleActual = $pedido->detalles->first();
        $insumoId = (int) ($data['insumoid'] ?? $detalleActual?->insumoid);
        $insumo = \App\Models\Insumo::query()->with('almacen')->findOrFail($insumoId);

        // La presentación de la línea es parte de la cantidad: cambiar de producto es otra solicitud.
        $presentacion = $detalleActual?->presentacion;
        if ($presentacion !== null && (int) $presentacion->insumoid !== $insumoId) {
            throw new InvalidArgumentException('Para cambiar de producto cree una nueva solicitud.');
        }

        $almacenId = (int) ($data['almacen_mayorista_origenid'] ?? $pedido->almacen_mayorista_origenid ?? $insumo->almacenid);
        $almacen = \App\Models\Almacen::query()->findOrFail($almacenId);

        if ($almacen->ambito !== \App\Support\AlmacenAmbito::MAYORISTA) {
            throw new InvalidArgumentException('El almacén debe ser de tipo mayorista.');
        }

        if ((int) $insumo->almacenid !== $almacenId) {
            throw new InvalidArgumentException('El producto no pertenece al almacén mayorista indicado.');
        }

        // Misma semántica que al crear (MIN-05): unidades de la presentación contra el stock por
        // presentación; antes la edición comparaba esas unidades contra el stock agregado en kg.
        if ($presentacion !== null) {
            $inventario = app(InventarioPresentacionService::class);
            $inventario->asegurarInventarioDesdeStock($almacenId, $insumoId);
            $disponible = $inventario->stockTotalUnidades($almacenId, (int) $presentacion->insumo_presentacionid);
            if ($cantidad > $disponible + 0.0001) {
                throw new InvalidArgumentException(
                    'La cantidad supera el stock disponible: '.number_format($disponible, 0).' '.$presentacion->etiquetaUnidad().'.'
                );
            }
        } elseif ($cantidad > (float) $insumo->stock) {
            throw new InvalidArgumentException('La cantidad supera el stock disponible en el almacén mayorista.');
        }

        $puntoId = (int) ($data['puntoventaid'] ?? $pedido->puntoventaid);
        $punto = \App\Models\PuntoVenta::query()->findOrFail($puntoId);

        if ($esDueño && (int) $punto->usuarioid !== (int) $usuario->usuarioid) {
            throw new InvalidArgumentException('Solo puede solicitar para sus propios puntos de venta.');
        }

        return DB::transaction(function () use ($pedido, $data, $cantidad, $insumo, $almacenId, $puntoId) {
            if (PedidoDistribucionCatalogo::tieneTransportistaDesignado($pedido)) {
                $this->liberarAsignacionLogistica($pedido);
            }

            $pedido->update([
                'puntoventaid' => $puntoId,
                'almacen_mayorista_origenid' => $almacenId,
                'fecha_entrega_deseada' => $data['fecha_entrega_deseada'] ?? $pedido->fecha_entrega_deseada,
                'observaciones' => $data['observaciones'] ?? $pedido->observaciones,
            ]);

            $detalle = $pedido->detalles->first();
            if ($detalle) {
                $detalle->update([
                    'insumoid' => $insumo->insumoid,
                    'producto_nombre' => $insumo->nombre,
                    'cantidad' => $cantidad,
                ]);
            }

            return $pedido->fresh(['detalles.insumo.unidadMedida', 'puntoVenta.minorista', 'almacenMayoristaOrigen']);
        });
    }

    /** @return array<int, Almacen> */
    private function almacenesRecogidaOrdenados(PedidoDistribucion $pedido, ?array $ordenPreferido = null): array
    {
        $pedido->loadMissing('detalles');

        // Solo almacenes de las líneas del pedido (o su cabecera): un orden de recogida no puede
        // incorporar almacenes ajenos (MAY-04).
        $idsDelPedido = $pedido->detalles
            ->pluck('almacen_mayorista_origenid')
            ->push($pedido->almacen_mayorista_origenid)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $idsOrdenados = array_values(array_intersect(
            array_filter(array_map('intval', $ordenPreferido ?? [])),
            $idsDelPedido
        ));

        if ($idsOrdenados === []) {
            foreach ($pedido->detalles as $detalle) {
                $id = (int) ($detalle->almacen_mayorista_origenid ?? 0);
                if ($id > 0 && ! in_array($id, $idsOrdenados, true)) {
                    $idsOrdenados[] = $id;
                }
            }
        } else {
            foreach ($pedido->detalles as $detalle) {
                $id = (int) ($detalle->almacen_mayorista_origenid ?? 0);
                if ($id > 0 && ! in_array($id, $idsOrdenados, true)) {
                    $idsOrdenados[] = $id;
                }
            }
        }

        if ($idsOrdenados === [] && $pedido->almacen_mayorista_origenid) {
            $idsOrdenados[] = (int) $pedido->almacen_mayorista_origenid;
        }

        if ($idsOrdenados === []) {
            return [];
        }

        $posicion = array_flip($idsOrdenados);

        return Almacen::query()
            ->whereIn('almacenid', $idsOrdenados)
            ->get()
            ->sortBy(fn (Almacen $a) => $posicion[(int) $a->almacenid] ?? 999)
            ->values()
            ->all();
    }
}


