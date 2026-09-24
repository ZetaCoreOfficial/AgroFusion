<?php

namespace Tests\Feature;

use App\Models\AlmacenMovimiento;
use App\Models\DetallePedidoDistribucion;
use App\Models\EnvioAsignacionMultiple;
use App\Models\Insumo;
use App\Models\InsumoPresentacion;
use App\Models\InventarioPresentacionLote;
use App\Models\PedidoDistribucion;
use App\Models\RutaDistribucion;
use App\Models\Usuario;
use App\Services\CierreEnvioDistribucionPdvService;
use App\Services\PedidoDistribucionReservaService;
use App\Services\PedidoDistribucionSalidaMayoristaService;
use App\Services\SimulacionRutaService;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\RutaDistribucionCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\EscenarioComercialLogistica;
use Tests\TestCase;

/**
 * Frente 3 — Fase C: concurrencia e inventario.
 * MAY-10, MAY-19, TRA-06, TRA-15, MIN-06, MIN-11 (MAY-07 y MIN-03 en Fases A/B).
 */
class Frente3ConcurrenciaInventarioTest extends TestCase
{
    use EscenarioComercialLogistica;
    use RefreshDatabase;

    private function presentacion(Insumo $insumo, string $nombre, float $pesoKg): InsumoPresentacion
    {
        return InsumoPresentacion::create([
            'insumoid' => $insumo->insumoid,
            'nombre' => $nombre,
            'tipo_envase' => 'bolsa',
            'peso_neto_kg' => $pesoKg,
            'orden' => 1,
            'activo' => true,
        ]);
    }

    private function lote(Insumo $insumo, InsumoPresentacion $presentacion, float $unidades): InventarioPresentacionLote
    {
        return InventarioPresentacionLote::create([
            'almacenid' => $insumo->almacenid,
            'insumoid' => $insumo->insumoid,
            'insumo_presentacionid' => $presentacion->insumo_presentacionid,
            'referencia_lote' => 'LOTE-'.$presentacion->insumo_presentacionid,
            'cantidad_unidades' => $unidades,
            'cantidad_kg' => $unidades * (float) $presentacion->peso_neto_kg,
        ]);
    }

    private function pedido(\App\Models\Almacen $almacen, Usuario $minorista, string $estado, array $lineas): PedidoDistribucion
    {
        $pedido = PedidoDistribucion::create([
            'numero_solicitud' => 'PDV-F3C-'.random_int(10000, 99999),
            'puntoventaid' => $this->puntoVenta($minorista, 'PDV '.random_int(1, 999))->puntoventaid,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'estado' => $estado,
            'fechapedido' => now(),
        ]);

        foreach ($lineas as [$insumo, $presentacion, $cantidad]) {
            DetallePedidoDistribucion::create([
                'pedidodistribucionid' => $pedido->pedidodistribucionid,
                'almacen_mayorista_origenid' => $almacen->almacenid,
                'insumoid' => $insumo->insumoid,
                'insumo_presentacionid' => $presentacion?->insumo_presentacionid,
                'producto_nombre' => $insumo->nombre,
                'cantidad' => $cantidad,
            ]);
        }

        return $pedido->fresh('detalles');
    }

    // ---------------------------------------------------------------- MAY-19

    public function test_mismo_producto_en_dos_presentaciones_descuenta_ambas_lineas_una_sola_vez(): void
    {
        $this->tipoMovimiento('salida');
        $mayorista = $this->actor('mayorista');
        $almacen = $this->almacenMayorista($mayorista, 'Mayorista MAY-19');
        $producto = $this->productoTerminado($almacen, 'Frejol', 100);
        $bolsaChica = $this->presentacion($producto, 'Bolsa 1 kg', 1);
        $bolsaGrande = $this->presentacion($producto, 'Bolsa 5 kg', 5);
        $loteChico = $this->lote($producto, $bolsaChica, 50);
        $loteGrande = $this->lote($producto, $bolsaGrande, 10);

        $pedido = $this->pedido($almacen, $this->actor('minorista'), PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO, [
            [$producto, $bolsaChica, 4],
            [$producto, $bolsaGrande, 2],
        ]);
        $ruta = RutaDistribucion::create([
            'codigo' => 'RD-MAY19', 'nombre' => 'MAY-19',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_MAYORISTA_PDV,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'transportista_usuarioid' => $this->conductor()->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
        ]);
        $pedido->update(['rutadistribucionid' => $ruta->rutadistribucionid]);

        $salida = app(PedidoDistribucionSalidaMayoristaService::class);
        $salida->descontarPedidosDeRuta($ruta->fresh(), $mayorista);

        // Antes: la 2.ª línea (mismo insumo y pedido) se daba por descontada y no bajaba su stock.
        $this->assertEqualsWithDelta(46.0, (float) $loteChico->fresh()->cantidad_unidades, 0.0001);
        $this->assertEqualsWithDelta(8.0, (float) $loteGrande->fresh()->cantidad_unidades, 0.0001);
        $this->assertSame(2, AlmacenMovimiento::query()->where('referencia', $pedido->numero_solicitud)->count());

        // Reintento: idempotente por línea.
        $salida->descontarPedidosDeRuta($ruta->fresh(), $mayorista);
        $this->assertSame(2, AlmacenMovimiento::query()->where('referencia', $pedido->numero_solicitud)->count());
        $this->assertEqualsWithDelta(46.0, (float) $loteChico->fresh()->cantidad_unidades, 0.0001);

        // La base de datos tampoco admite dos salidas para la misma línea.
        $this->expectException(QueryException::class);
        AlmacenMovimiento::create([
            'almacenid' => $almacen->almacenid,
            'insumoid' => $producto->insumoid,
            'tipo_movimiento_almacenid' => $this->tipoMovimiento('salida')->tipo_movimiento_almacenid,
            'usuarioid' => $mayorista->usuarioid,
            'fecha' => now()->toDateString(),
            'cantidad' => 1,
            'referencia' => $pedido->numero_solicitud,
            'detallepedidodistribucionid' => $pedido->detalles->first()->detallepedidodistribucionid,
        ]);
    }

    // ---------------------------------------------------------------- MAY-10

    public function test_reserva_impide_que_dos_pedidos_confirmados_consuman_el_mismo_stock(): void
    {
        $mayorista = $this->actor('mayorista');
        $almacen = $this->almacenMayorista($mayorista, 'Mayorista MAY-10');
        $producto = $this->productoTerminado($almacen, 'Arroz', 10);
        $bolsa = $this->presentacion($producto, 'Bolsa 1 kg', 1);
        $this->lote($producto, $bolsa, 10);
        $minorista = $this->actor('minorista');

        $pedidoA = $this->pedido($almacen, $minorista, PedidoDistribucionCatalogo::ESTADO_CONFIRMADO, [[$producto, $bolsa, 7]]);
        $pedidoB = $this->pedido($almacen, $minorista, PedidoDistribucionCatalogo::ESTADO_PENDIENTE, [[$producto, $bolsa, 7]]);
        $reservas = app(PedidoDistribucionReservaService::class);

        $this->assertEqualsWithDelta(7.0, $reservas->reservado($almacen->almacenid, $producto->insumoid, $bolsa->insumo_presentacionid), 0.0001);

        try {
            DB::transaction(fn () => $reservas->reservar($pedidoB));
            $this->fail('B no debe poder reservar stock ya comprometido por A.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('disponible sin reservar 3', $e->getMessage());
        }

        // De punta a punta: el mayorista no puede aceptar B mientras A tiene el stock reservado.
        $this->actingAs($mayorista)
            ->post(route('punto-venta.pedidos.aceptar', $pedidoB))
            ->assertSessionHas('error');
        $this->assertSame(PedidoDistribucionCatalogo::ESTADO_PENDIENTE, $pedidoB->fresh()->estado);

        // Rechazar/cancelar A libera la reserva y B ya puede aceptarse.
        $pedidoA->update(['estado' => PedidoDistribucionCatalogo::ESTADO_RECHAZADO]);
        $this->actingAs($mayorista)
            ->post(route('punto-venta.pedidos.aceptar', $pedidoB))
            ->assertSessionMissing('error');
        $this->assertSame(PedidoDistribucionCatalogo::ESTADO_CONFIRMADO, $pedidoB->fresh()->estado);
    }

    // ---------------------------------------------------------------- TRA-06 / TRA-15

    public function test_conductor_no_inicia_un_segundo_viaje_mientras_otro_esta_en_curso(): void
    {
        $conductor = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);
        RutaDistribucion::create([
            'codigo' => 'RD-EN-CURSO', 'nombre' => 'En curso',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_MAYORISTA_PDV,
            'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now(),
        ]);
        $envio = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-SEGUNDO', 'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => 'asignado', 'fecha_asignacion' => now(),
        ]);

        try {
            app(SimulacionRutaService::class)->empezarAgricola($envio);
            $this->fail('No debe iniciar un segundo viaje simultáneo.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ya tiene un viaje en curso (RD-EN-CURSO)', $e->getMessage());
        }

        $this->assertNull($envio->fresh()->simulacion_inicio_at);
    }

    public function test_llegada_no_se_confirma_dos_veces(): void
    {
        $conductor = $this->conductor(TransportistaFlotaCatalogo::MAYORISTA);
        $ruta = RutaDistribucion::create([
            'codigo' => 'RD-LLEGADA', 'nombre' => 'Llegada',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_MAYORISTA_PDV,
            'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now()->subHour(),
            'simulacion_duracion_seg' => 60,
        ]);
        $cierre = app(CierreEnvioDistribucionPdvService::class);
        $copiaVieja = $ruta->fresh();

        $cierre->confirmarLlegada($ruta->fresh(), $conductor);
        $primera = $ruta->fresh()->llegada_confirmada_at;

        // Un segundo request con una copia leída antes de la primera confirmación no la pisa.
        try {
            $cierre->confirmarLlegada($copiaVieja, $conductor);
            $this->fail('La llegada no debe confirmarse dos veces.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ya fue confirmada', $e->getMessage());
        }
        $this->assertEquals($primera, $ruta->fresh()->llegada_confirmada_at);
    }

    // ---------------------------------------------------------------- MIN-06 / MIN-11

    public function test_ajuste_de_stock_pdv_exige_motivo_permiso_y_deja_movimiento(): void
    {
        $this->tipoMovimiento('ingreso');
        $this->tipoMovimiento('salida');
        $minorista = $this->actor('minorista');
        $pdv = $this->puntoVenta($minorista, 'Tienda ajuste');
        $almacenPdv = $this->almacen(\App\Support\AlmacenAmbito::PUNTO_VENTA, $minorista, 'Almacén — Tienda ajuste');
        $pdv->update(['almacenid' => $almacenPdv->almacenid]);
        $producto = $this->productoTerminado($almacenPdv, 'Leche', 20);
        $ruta = route('punto-venta.puntos.inventario.update', [$pdv, $producto]);

        $this->actingAs($minorista);

        // Sin motivo: no hay edición «mágica» de cantidad.
        $this->put($ruta, ['nombre' => 'Leche', 'stock' => 5])->assertSessionHasErrors('motivo_ajuste');
        $this->assertEqualsWithDelta(20.0, (float) $producto->fresh()->stock, 0.0001);

        // Cambiar solo el nombre no exige motivo ni crea movimiento.
        $this->put($ruta, ['nombre' => 'Leche entera', 'stock' => 20])->assertRedirect();
        $this->assertSame(0, AlmacenMovimiento::query()->where('insumoid', $producto->insumoid)->count());

        $this->put($ruta, ['nombre' => 'Leche entera', 'stock' => 17, 'motivo_ajuste' => 'Merma por vencimiento'])->assertRedirect();
        $this->assertEqualsWithDelta(17.0, (float) $producto->fresh()->stock, 0.0001);
        $mov = AlmacenMovimiento::query()->where('insumoid', $producto->insumoid)->firstOrFail();
        $this->assertSame((int) $minorista->usuarioid, (int) $mov->usuarioid);
        $this->assertEqualsWithDelta(3.0, (float) $mov->cantidad, 0.0001);
        $this->assertSame('salida', $mov->tipo->naturaleza);
        $this->assertStringContainsString('20.00 → 17.00', $mov->observaciones);
        $this->assertStringContainsString('Merma por vencimiento', $mov->observaciones);

        // Sin el permiso especial no se ajusta.
        $minorista->revokePermissionTo('punto_venta.ajuste_stock');
        \Spatie\Permission\Models\Role::findByName('minorista', 'web')->revokePermissionTo('punto_venta.ajuste_stock');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($minorista->fresh())
            ->put($ruta, ['nombre' => 'Leche entera', 'stock' => 30, 'motivo_ajuste' => 'Conteo'])
            ->assertForbidden();
        $this->assertEqualsWithDelta(17.0, (float) $producto->fresh()->stock, 0.0001);
    }
}
