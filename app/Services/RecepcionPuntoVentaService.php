<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\AlmacenMovimiento;
use App\Models\DetallePedidoDistribucion;
use App\Models\Insumo;
use App\Models\InsumoPresentacion;
use App\Models\PedidoDistribucion;
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
use Illuminate\Support\Str;

class RecepcionPuntoVentaService
{
    public function __construct(
        private readonly DistribucionRutaService $rutas,
        private readonly PedidoDistribucionSalidaMayoristaService $salidaMayorista,
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

            foreach ($this->gruposConsolidadosConDetalle($pedido->detalles) as $grupo) {
                $this->ingresarGrupoConsolidado($grupo, $pedido, $usuario, $almacenPdv, $tipoIngreso);
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

    /** @param  array{grupo: array<string, mixed>, detalle: DetallePedidoDistribucion}  $item */
    private function ingresarGrupoConsolidado(
        array $item,
        PedidoDistribucion $pedido,
        Usuario $usuario,
        Almacen $almacenPdv,
        TipoMovimientoAlmacen $tipoIngreso
    ): void {
        $grupo = $item['grupo'];
        $detalle = $item['detalle'];
        $cantidad = (float) $grupo['cantidad'];
        $kgMovimiento = (float) $grupo['cantidad_kg'];

        if ($cantidad <= 0 || $kgMovimiento <= 0) {
            return;
        }

        $detalle->loadMissing('presentacion', 'insumo.unidadMedida');
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

        $ref = $pedido->numero_solicitud;
        $unidad = $presentacion instanceof InsumoPresentacion
            ? $presentacion->etiquetaUnidad()
            : ($grupo['unidad'] ?? 'unidades');
        $obsUnidades = number_format($cantidad, 0).' '.$unidad.' ('.number_format($kgMovimiento, 2).' kg)';

        AlmacenMovimiento::create([
            'almacenid' => $almacenPdv->almacenid,
            'insumoid' => $insumoDestino->insumoid,
            'tipo_movimiento_almacenid' => $tipoIngreso->tipo_movimiento_almacenid,
            'usuarioid' => $usuario->usuarioid,
            'fecha' => now()->toDateString(),
            'cantidad' => $kgMovimiento,
            'referencia' => $ref,
            'destino_motivo' => $almacenPdv->nombre,
            'observaciones' => '[Recepción PDV] '.$ref.' · '.$obsUnidades,
        ]);

        $insumoDestino->incrementarStock($kgMovimiento);
    }
}
