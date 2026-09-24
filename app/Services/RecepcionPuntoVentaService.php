<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\AlmacenMovimiento;
use App\Models\DetallePedidoDistribucion;
use App\Models\Insumo;
use App\Models\InsumoPresentacion;
use App\Models\InventarioPresentacionLote;
use App\Models\PedidoDistribucion;
use App\Models\PuntoVenta;
use App\Models\RutaDistribucion;
use App\Models\TipoInsumo;
use App\Models\TipoMovimientoAlmacen;
use App\Models\Usuario;
use App\Support\FirmaCierreReglas;
use App\Support\InsumoCatalogo;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\PedidoDistribucionConsolidacion;
use App\Support\UsuarioRol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RecepcionPuntoVentaService
{
    public function __construct(
        private readonly DistribucionRutaService $rutas,
        private readonly PedidoDistribucionSalidaMayoristaService $salidaMayorista,
        private readonly InventarioPresentacionService $inventarioPresentacion,
    ) {}

    public function confirmar(PedidoDistribucion $pedido, Usuario $usuario): void
    {
        if (! PedidoDistribucionCatalogo::puedeConfirmarRecepcion($pedido)) {
            throw new \InvalidArgumentException('El pedido no está en tránsito o ya fue recibido.');
        }

        // Defensa en profundidad (MIN-08): el servicio no confía en que el controller ya autorizó.
        $this->asegurarReceptorValido($pedido, $usuario);

        $pedido->load([
            'detalles.insumo.unidadMedida',
            'detalles.presentacion.tipoEmpaque',
            'detalles.inventarioPresentacionLote',
            'puntoVenta.almacen',
        ]);

        $puntoVenta = $pedido->puntoVenta;
        if ($puntoVenta === null) {
            throw new \InvalidArgumentException('Pedido sin punto de venta asociado.');
        }

        app(PuntoVentaAlmacenService::class)->crearAlmacenParaPuntoVenta($puntoVenta);
        $puntoVenta->refresh();

        $almacenPdv = $puntoVenta->almacen;
        if ($almacenPdv === null) {
            throw new \InvalidArgumentException('No se pudo vincular el almacén del punto de venta.');
        }

        $kgRecepcion = 0.0;
        foreach ($this->gruposConsolidadosConDetalle($pedido->detalles) as $item) {
            $kgRecepcion += (float) ($item['grupo']['cantidad_kg'] ?? 0);
        }
        app(PuntoVentaAlmacenService::class)->validarIngresoPedido($puntoVenta, $kgRecepcion);

        $tipoIngreso = TipoMovimientoAlmacen::activosPorNaturaleza('ingreso')->firstOrFail();
        $tipoSalida = TipoMovimientoAlmacen::activosPorNaturaleza('salida')->firstOrFail();

        DB::transaction(function () use ($pedido, $usuario, $almacenPdv, $tipoIngreso, $tipoSalida) {
            // Lock del pedido y revalidación del estado (MIN-03): un segundo request concurrente
            // espera el lock, ve «recibido» y no acredita inventario otra vez.
            $bloqueado = PedidoDistribucion::query()->whereKey($pedido->pedidodistribucionid)->lockForUpdate()->firstOrFail();
            if (! PedidoDistribucionCatalogo::puedeConfirmarRecepcion($bloqueado)) {
                throw new \InvalidArgumentException('El pedido ya fue recibido.');
            }

            foreach ($pedido->detalles as $detalle) {
                if ($this->salidaMayorista->yaDescontado($pedido, $detalle)) {
                    $this->salidaMayorista->descontarSoloInventarioSiPendiente($detalle, $pedido);

                    continue;
                }

                $this->salidaMayorista->descontarDetalle(
                    $detalle,
                    $pedido,
                    $usuario,
                    $tipoSalida,
                    $almacenPdv->nombre
                );
            }

            $kgAcreditado = 0.0;
            foreach ($this->gruposConsolidadosConDetalle($pedido->detalles) as $grupo) {
                $kgAcreditado += $this->ingresarGrupoConsolidado($grupo, $pedido, $usuario, $almacenPdv, $tipoIngreso);
            }

            if ($kgAcreditado <= 0) {
                throw new \InvalidArgumentException(
                    'No se pudo acreditar inventario en el punto de venta (cantidad/kg inválidos). '
                    .'El pedido no se marca como recibido.'
                );
            }

            $pedido->update([
                'estado' => PedidoDistribucionCatalogo::ESTADO_RECIBIDO,
                'fecha_recepcion' => now(),
            ]);
        });

        $pedido->refresh();
        if ($pedido->rutadistribucionid) {
            $ruta = RutaDistribucion::query()->find($pedido->rutadistribucionid);
            if ($ruta) {
                $this->rutas->sincronizarEstadoRuta($ruta);
            }
        }
    }

    /**
     * Repara pedidos ya «recibidos» cuyo almacén PDV quedó sin stock visible
     * (p. ej. ingreso solo en Insumo.stock que luego se sincronizó a 0 por presentaciones sin lote).
     * Idempotente: no vuelve a descontar mayorista ni duplica crédito si ya hay stock/lotes.
     *
     * @param  Collection<int, PuntoVenta>  $puntos
     */
    public function repararInventarioPedidosRecibidos(Collection $puntos): void
    {
        $puntoIds = $puntos->pluck('puntoventaid')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($puntoIds === []) {
            return;
        }

        $pedidos = PedidoDistribucion::query()
            ->whereIn('puntoventaid', $puntoIds)
            ->where('estado', PedidoDistribucionCatalogo::ESTADO_RECIBIDO)
            ->with([
                'detalles.insumo.unidadMedida',
                'detalles.presentacion.tipoEmpaque',
                'detalles.inventarioPresentacionLote',
                'puntoVenta.almacen',
            ])
            ->orderBy('pedidodistribucionid')
            ->get();

        $tipoIngreso = TipoMovimientoAlmacen::activosPorNaturaleza('ingreso')->first();
        if ($tipoIngreso === null) {
            return;
        }

        foreach ($pedidos as $pedido) {
            $this->repararCreditoInventarioPedido($pedido, $tipoIngreso);
        }
    }

    private function repararCreditoInventarioPedido(PedidoDistribucion $pedido, TipoMovimientoAlmacen $tipoIngreso): void
    {
        $puntoVenta = $pedido->puntoVenta;
        if ($puntoVenta === null) {
            return;
        }

        app(PuntoVentaAlmacenService::class)->crearAlmacenParaPuntoVenta($puntoVenta);
        $puntoVenta->refresh();
        $almacenPdv = $puntoVenta->almacen;
        if ($almacenPdv === null) {
            return;
        }

        if ($this->pdvYaTieneCreditoVisible($pedido, $almacenPdv)) {
            return;
        }

        $usuarioId = (int) ($pedido->aceptado_por_usuarioid
            ?? $puntoVenta->usuarioid
            ?? 0);
        $usuario = $usuarioId > 0 ? Usuario::query()->find($usuarioId) : null;
        if ($usuario === null) {
            return;
        }

        DB::transaction(function () use ($pedido, $usuario, $almacenPdv, $tipoIngreso) {
            foreach ($this->gruposConsolidadosConDetalle($pedido->detalles) as $grupo) {
                $this->ingresarGrupoConsolidado($grupo, $pedido, $usuario, $almacenPdv, $tipoIngreso);
            }
        });
    }

    private function pdvYaTieneCreditoVisible(PedidoDistribucion $pedido, Almacen $almacenPdv): bool
    {
        $movIds = AlmacenMovimiento::query()
            ->where('almacenid', $almacenPdv->almacenid)
            ->where('referencia', $pedido->numero_solicitud)
            ->where('observaciones', 'like', '[Recepción PDV]%')
            ->pluck('insumoid')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($movIds !== []) {
            $stockOk = Insumo::query()
                ->whereIn('insumoid', $movIds)
                ->where('almacenid', $almacenPdv->almacenid)
                ->where('stock', '>', 0)
                ->exists();
            if ($stockOk) {
                return true;
            }

            if (Schema::hasTable('inventario_presentacion_lote')) {
                $lotesOk = InventarioPresentacionLote::query()
                    ->where('almacenid', $almacenPdv->almacenid)
                    ->whereIn('insumoid', $movIds)
                    ->where('cantidad_kg', '>', 0)
                    ->exists();
                if ($lotesOk) {
                    return true;
                }
            }

            // Hubo movimiento pero el stock quedó en 0 → hay que reparar.
            return false;
        }

        // Sin movimiento de recepción para este pedido: hay que acreditar.
        return false;
    }

    /**
     * Receptor real: el minorista dueño del PDV, o el cierre de su ruta cuando ese minorista ya firmó
     * la recepción con su cuenta (el conductor puede cerrar la parte logística, no recibir por él).
     */
    private function asegurarReceptorValido(PedidoDistribucion $pedido, Usuario $usuario): void
    {
        $pedido->loadMissing(['puntoVenta', 'rutaDistribucion.firmaRecepcion']);
        $duenoPdv = (int) ($pedido->puntoVenta?->usuarioid ?? 0);

        if ($duenoPdv <= 0) {
            throw new \InvalidArgumentException('Pedido sin punto de venta asociado.');
        }

        if (! UsuarioRol::puedeOperar($usuario)) {
            throw new \InvalidArgumentException('El administrador supervisa la recepción pero no la registra.');
        }

        if ((int) $usuario->usuarioid === $duenoPdv && UsuarioRol::esMinorista($usuario)) {
            return;
        }

        $ruta = $pedido->rutaDistribucion;
        $firma = $ruta?->firmaRecepcion;
        if ($ruta !== null
            && FirmaCierreReglas::recepcionValida($firma, $ruta->transportista_usuarioid)
            && (int) $firma->firmante_usuarioid === $duenoPdv) {
            return;
        }

        throw new \InvalidArgumentException('La recepción del punto de venta debe confirmarla el minorista dueño del punto.');
    }

    /** @return array<int, array{grupo: array<string, mixed>, detalle: DetallePedidoDistribucion}> */
    private function gruposConsolidadosConDetalle(Collection $detalles): array
    {
        $resultado = [];
        foreach (PedidoDistribucionConsolidacion::consolidar($detalles) as $grupo) {
            $representante = $detalles->first(
                fn (DetallePedidoDistribucion $d) => in_array(
                    (int) $d->detallepedidodistribucionid,
                    $grupo['detalle_ids'],
                    true
                )
            );
            if ($representante) {
                $resultado[] = ['grupo' => $grupo, 'detalle' => $representante];
            }
        }

        return $resultado;
    }

    /**
     * @param  array{grupo: array<string, mixed>, detalle: DetallePedidoDistribucion}  $item
     * @return float kg acreditados en esta llamada (0 si se omite o ya estaba acreditado)
     */
    private function ingresarGrupoConsolidado(
        array $item,
        PedidoDistribucion $pedido,
        Usuario $usuario,
        Almacen $almacenPdv,
        TipoMovimientoAlmacen $tipoIngreso
    ): float {
        $grupo = $item['grupo'];
        $detalle = $item['detalle'];
        $cantidad = (float) $grupo['cantidad'];
        $kgMovimiento = (float) $grupo['cantidad_kg'];

        if ($cantidad <= 0 || $kgMovimiento <= 0) {
            return 0.0;
        }

        $detalle->loadMissing('presentacion', 'insumo.unidadMedida', 'inventarioPresentacionLote');
        $presentacion = $detalle->presentacion;

        $nombrePdv = PedidoDistribucionConsolidacion::nombreProducto($detalle);
        $lote = trim((string) ($grupo['lote'] ?? ''));
        if ($lote !== '') {
            $nombrePdv .= ' - '.$lote;
        }

        $insumoOrigen = $detalle->insumo;
        if ($insumoOrigen === null) {
            throw new \InvalidArgumentException('Producto de origen no encontrado.');
        }

        $insumoDestino = Insumo::query()
            ->where('almacenid', $almacenPdv->almacenid)
            ->where(function ($q) use ($nombrePdv, $insumoOrigen) {
                $q->whereRaw('LOWER(TRIM(nombre)) = ?', [Str::lower(trim($nombrePdv))])
                    ->orWhereRaw('LOWER(TRIM(nombre)) = ?', [Str::lower(trim($insumoOrigen->nombre))]);
            })
            ->first();

        if ($insumoDestino === null) {
            $codigo = 'TRZ-PDV-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -6));
            $insumoDestino = Insumo::create([
                'nombre' => $nombrePdv,
                'codigo_trazabilidad' => $codigo,
                'tipoinsumoid' => $insumoOrigen->tipoinsumoid ?? TipoInsumo::query()->value('tipoinsumoid'),
                'unidadmedidaid' => $insumoOrigen->unidadmedidaid,
                'stock' => 0,
                'stockminimo' => InsumoCatalogo::UMBRAL_ALERTA_STOCK,
                'descripcion' => 'Producto recibido desde mayorista — '.$pedido->numero_solicitud,
                'almacenid' => $almacenPdv->almacenid,
            ]);
        }

        // Idempotencia: no duplicar crédito si el PDV ya muestra stock (o lotes) para este producto.
        if ((float) $insumoDestino->stock > 0) {
            if ($presentacion instanceof InsumoPresentacion && Schema::hasTable('inventario_presentacion_lote')) {
                $tieneLote = InventarioPresentacionLote::query()
                    ->where('almacenid', $almacenPdv->almacenid)
                    ->where('insumoid', $insumoDestino->insumoid)
                    ->where('cantidad_kg', '>', 0)
                    ->exists();
                if (! $tieneLote) {
                    // Stock legado sin filas de presentación: materializar lotes sin sumar otra vez.
                    $this->inventarioPresentacion->asegurarInventarioDesdeStock(
                        (int) $almacenPdv->almacenid,
                        (int) $insumoDestino->insumoid
                    );
                }
            }

            return 0.0;
        }

        $ref = $pedido->numero_solicitud;
        $unidad = $presentacion instanceof InsumoPresentacion
            ? $presentacion->etiquetaUnidad()
            : ($grupo['unidad'] ?? 'unidades');
        $obsUnidades = number_format($cantidad, 0).' '.$unidad.' ('.number_format($kgMovimiento, 2).' kg)';

        $yaMovimiento = AlmacenMovimiento::query()
            ->where('almacenid', $almacenPdv->almacenid)
            ->where('insumoid', $insumoDestino->insumoid)
            ->where('referencia', $ref)
            ->where('observaciones', 'like', '[Recepción PDV]%')
            ->exists();

        if (! $yaMovimiento) {
            AlmacenMovimiento::create([
                'almacenid' => $almacenPdv->almacenid,
                'insumoid' => $insumoDestino->insumoid,
                'insumo_presentacionid' => $presentacion instanceof InsumoPresentacion
                    ? $presentacion->insumo_presentacionid
                    : null,
                'tipo_movimiento_almacenid' => $tipoIngreso->tipo_movimiento_almacenid,
                'usuarioid' => $usuario->usuarioid,
                'fecha' => now()->toDateString(),
                'cantidad' => $kgMovimiento,
                'cantidad_unidades' => $cantidad,
                'referencia' => $ref,
                'destino_motivo' => $almacenPdv->nombre,
                'observaciones' => '[Recepción PDV] '.$ref.' · '.$obsUnidades,
            ]);
        }

        if ($presentacion instanceof InsumoPresentacion && Schema::hasTable('inventario_presentacion_lote')) {
            $presentacionDestino = $this->inventarioPresentacion->replicarPresentacionEnInsumo(
                $presentacion,
                $insumoDestino
            );

            $loteOrigen = $detalle->inventarioPresentacionLote;
            $loteProduccionId = $loteOrigen?->loteproduccionpedidoid;
            $referenciaLote = $loteOrigen?->referencia_lote
                ?? (trim((string) ($detalle->referencia_lote ?? '')) !== '' ? (string) $detalle->referencia_lote : null)
                ?? ($lote !== '' ? $lote : null);

            $loteExistente = InventarioPresentacionLote::query()
                ->where('almacenid', $almacenPdv->almacenid)
                ->where('insumo_presentacionid', $presentacionDestino->insumo_presentacionid)
                ->when(
                    $loteProduccionId !== null,
                    fn ($q) => $q->where('loteproduccionpedidoid', $loteProduccionId),
                    function ($q) use ($referenciaLote) {
                        if ($referenciaLote !== null && $referenciaLote !== '') {
                            $q->where('referencia_lote', $referenciaLote)->whereNull('loteproduccionpedidoid');
                        } else {
                            $q->whereNull('loteproduccionpedidoid')->whereNull('referencia_lote');
                        }
                    }
                )
                ->first();

            if ($loteExistente === null || (float) $loteExistente->cantidad_kg <= 0) {
                $this->inventarioPresentacion->ingresar(
                    (int) $almacenPdv->almacenid,
                    (int) $insumoDestino->insumoid,
                    (int) $presentacionDestino->insumo_presentacionid,
                    $loteProduccionId !== null ? (int) $loteProduccionId : null,
                    $referenciaLote,
                    $cantidad,
                    $kgMovimiento
                );
            } else {
                $this->inventarioPresentacion->sincronizarStockAgregadoInsumo((int) $insumoDestino->insumoid);
            }
        } else {
            if ((float) $insumoDestino->fresh()->stock <= 0) {
                $insumoDestino->incrementarStock($kgMovimiento);
            }
        }

        return $kgMovimiento;
    }
}
