<?php

namespace Tests\Feature;

use App\Models\AlmacenMovimiento;
use App\Models\DetallePedidoDistribucion;
use App\Models\DetalleTrasladoPlantaMayorista;
use App\Models\Insumo;
use App\Models\InsumoPresentacion;
use App\Models\PedidoDistribucion;
use App\Models\RutaDistribucion;
use App\Models\TipoIncidenteTransporte;
use App\Services\CierreEnvioPlantaMayoristaService;
use App\Services\InventarioPresentacionService;
use App\Services\PedidoDistribucionMayoristaService;
use App\Support\AlmacenAmbito;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\RutaDistribucionCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\EscenarioComercialLogistica;
use Tests\TestCase;

/**
 * Frente 3 — Fase D: pedidos.
 * MAY-04, MAY-11, MAY-12, MAY-13, MAY-14, MIN-04, MIN-05, MIN-09.
 */
class Frente3PedidosTest extends TestCase
{
    use EscenarioComercialLogistica;
    use RefreshDatabase;

    private function pedidoPendiente(\App\Models\Almacen $almacen, \App\Models\Usuario $minorista, Insumo $insumo, ?InsumoPresentacion $presentacion, float $cantidad, string $estado = PedidoDistribucionCatalogo::ESTADO_PENDIENTE): PedidoDistribucion
    {
        $pedido = PedidoDistribucion::create([
            'numero_solicitud' => 'PDV-F3D-'.random_int(10000, 99999),
            'puntoventaid' => $this->puntoVenta($minorista, 'PDV '.random_int(1, 999))->puntoventaid,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'estado' => $estado,
            'tipo_solicitud' => PedidoDistribucionCatalogo::TIPO_SOLICITUD_STOCK,
            'fechapedido' => now(),
            'fecha_entrega_deseada' => now()->addDay(),
            'creado_por_usuarioid' => $minorista->usuarioid,
        ]);
        DetallePedidoDistribucion::create([
            'pedidodistribucionid' => $pedido->pedidodistribucionid,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'insumoid' => $insumo->insumoid,
            'insumo_presentacionid' => $presentacion?->insumo_presentacionid,
            'producto_nombre' => $insumo->nombre,
            'cantidad' => $cantidad,
        ]);

        return $pedido->fresh('detalles');
    }

    // ---------------------------------------------------------------- MAY-04 / MAY-12

    public function test_mayorista_no_usa_productos_ni_almacenes_ajenos_en_su_envio(): void
    {
        $a = $this->actor('mayorista');
        $b = $this->actor('mayorista');
        $almA = $this->almacenMayorista($a, 'Mayorista A');
        $almB = $this->almacenMayorista($b, 'Mayorista B');
        $productoB = $this->productoTerminado($almB, 'Producto de B');
        $minorista = $this->actor('minorista');
        $pdv = $this->puntoVenta($minorista, 'Tienda');

        $this->actingAs($a)->postJson(route('punto-venta.pedidos.store'), [
            'puntoventaid' => $pdv->puntoventaid,
            'almacen_mayorista_origenid' => $almA->almacenid,
            'fecha_entrega_deseada' => now()->addDay()->toDateString(),
            'canal_origen' => 'whatsapp',
            'transportista_usuarioid' => $this->conductor()->usuarioid,
            'vehiculoid' => \App\Models\Vehiculo::create(['placa' => 'F3D-1', 'marca' => 'X', 'modelo' => 'Y', 'activo' => true])->vehiculoid,
            'detalles' => [[
                'insumoid' => $productoB->insumoid,
                'insumo_presentacionid' => $this->presentacion($productoB)->insumo_presentacionid,
                'almacen_mayorista_origenid' => $almB->almacenid,
                'cantidad' => 1,
            ]],
        ])->assertForbidden()->assertJsonFragment(['message' => 'El producto «Producto de B» pertenece a un almacén mayorista que no es suyo.']);

        $this->assertSame(0, PedidoDistribucion::query()->count());
    }

    public function test_orden_de_recogida_ignora_almacenes_ajenos_al_pedido(): void
    {
        $a = $this->actor('mayorista');
        $almA = $this->almacenMayorista($a, 'Mayorista A');
        $almAjeno = $this->almacenMayorista($this->actor('mayorista'), 'Mayorista ajeno');
        $pedido = $this->pedidoPendiente($almA, $this->actor('minorista'), $this->productoTerminado($almA), null, 1);

        $metodo = new \ReflectionMethod(PedidoDistribucionMayoristaService::class, 'almacenesRecogidaOrdenados');
        $almacenes = $metodo->invoke(app(PedidoDistribucionMayoristaService::class), $pedido, [$almAjeno->almacenid, $almA->almacenid]);

        $this->assertSame([(int) $almA->almacenid], array_map(fn ($al) => (int) $al->almacenid, $almacenes));
    }

    // ---------------------------------------------------------------- MAY-11

    public function test_aceptar_sin_stock_conserva_el_origen_y_pedido_sin_origen_no_es_de_cualquiera(): void
    {
        $a = $this->actor('mayorista');
        $almA = $this->almacenMayorista($a, 'Mayorista A');
        $producto = $this->productoTerminado($almA, 'Sin stock', 0);
        $minorista = $this->actor('minorista');
        $pedido = $this->pedidoPendiente($almA, $minorista, $producto, null, 5);

        $this->actingAs($a)->post(route('punto-venta.pedidos.aceptar', $pedido))->assertRedirect();
        $pedido->refresh();
        $this->assertTrue((bool) $pedido->requiere_coordinacion_planta);
        $this->assertSame((int) $almA->almacenid, (int) $pedido->almacen_mayorista_origenid, 'antes se borraba el origen');

        $huerfano = PedidoDistribucion::create([
            'numero_solicitud' => 'PDV-HUERFANO',
            'puntoventaid' => $this->puntoVenta($minorista, 'Tienda H')->puntoventaid,
            'estado' => PedidoDistribucionCatalogo::ESTADO_PENDIENTE,
            'tipo_solicitud' => PedidoDistribucionCatalogo::TIPO_SOLICITUD_CUSTOM,
            'fechapedido' => now(),
        ]);

        $this->actingAs($this->actor('mayorista'));
        $this->get(route('punto-venta.pedidos.show', $huerfano))->assertForbidden();
        $this->post(route('punto-venta.pedidos.aceptar', $huerfano))->assertForbidden();
        $this->get(route('punto-venta.pedidos.index', ['ctx' => 'mayorista']))->assertOk()->assertDontSee('PDV-HUERFANO');
    }

    // ---------------------------------------------------------------- MIN-04 / MIN-05

    private function presentacion(Insumo $insumo, float $pesoKg = 0.15): InsumoPresentacion
    {
        return InsumoPresentacion::create([
            'insumoid' => $insumo->insumoid,
            'nombre' => 'Bolsa '.($pesoKg * 1000).' g',
            'tipo_envase' => 'bolsa',
            'peso_neto_kg' => $pesoKg,
            'orden' => 1,
            'activo' => true,
        ]);
    }

    public function test_pedido_confirmado_no_se_edita_y_la_edicion_usa_las_mismas_unidades_que_la_creacion(): void
    {
        $a = $this->actor('mayorista');
        $almA = $this->almacenMayorista($a, 'Mayorista A');
        // 20 bolsas de 150 g = 3 kg de stock agregado.
        $producto = $this->productoTerminado($almA, 'Chips', 3);
        $bolsa = $this->presentacion($producto);
        app(InventarioPresentacionService::class)->asegurarInventarioDesdeStock($almA->almacenid, $producto->insumoid);
        $minorista = $this->actor('minorista');
        $pendiente = $this->pedidoPendiente($almA, $minorista, $producto, $bolsa, 5);

        $this->actingAs($minorista);
        $payload = fn (float $cantidad) => ['insumoid' => $producto->insumoid, 'cantidad' => $cantidad];

        // 15 bolsas ≤ 20 disponibles: antes se rechazaba al comparar 15 contra 3 kg (MIN-05).
        $this->put(route('punto-venta.pedidos.update', $pendiente), $payload(15))->assertSessionMissing('error');
        $this->assertEqualsWithDelta(15.0, (float) $pendiente->fresh()->detalles->first()->cantidad, 0.0001);

        $this->put(route('punto-venta.pedidos.update', $pendiente), $payload(25))->assertSessionHas('error');
        $this->assertEqualsWithDelta(15.0, (float) $pendiente->fresh()->detalles->first()->cantidad, 0.0001);

        // Confirmado: contenido congelado (MIN-04).
        $confirmado = $this->pedidoPendiente($almA, $minorista, $producto, $bolsa, 5, PedidoDistribucionCatalogo::ESTADO_CONFIRMADO);
        $this->put(route('punto-venta.pedidos.update', $confirmado), $payload(2))->assertSessionHas('error');
        $this->assertEqualsWithDelta(5.0, (float) $confirmado->fresh()->detalles->first()->cantidad, 0.0001);
    }

    // ---------------------------------------------------------------- MAY-13 / MIN-09

    public function test_envio_del_mayorista_exige_canal_y_el_minorista_lo_ve_como_pedido_externo(): void
    {
        $a = $this->actor('mayorista');
        $almA = $this->almacenMayorista($a, 'Mayorista A');
        $minorista = $this->actor('minorista');
        $pdv = $this->puntoVenta($minorista, 'Tienda');

        $this->actingAs($a)->post(route('punto-venta.pedidos.store'), [
            'puntoventaid' => $pdv->puntoventaid,
            'fecha_entrega_deseada' => now()->addDay()->toDateString(),
            'transportista_usuarioid' => $this->conductor()->usuarioid,
            'vehiculoid' => 1,
        ])->assertSessionHasErrors('canal_origen');

        $producto = $this->productoTerminado($almA, 'Galletas');
        $pedido = $this->pedidoPendiente($almA, $minorista, $producto, null, 2, PedidoDistribucionCatalogo::ESTADO_CONFIRMADO);
        $pedido->update([
            'puntoventaid' => $pdv->puntoventaid,
            'envio_iniciado_mayorista' => true,
            'creado_por_usuarioid' => $a->usuarioid,
            'canal_origen' => 'whatsapp',
            'registrado_manual_por_usuarioid' => $a->usuarioid,
        ]);

        $this->assertTrue(PedidoDistribucionCatalogo::esPedidoExterno($pedido->fresh()));
        $this->actingAs($minorista)
            ->get(route('punto-venta.pedidos.show', $pedido))
            ->assertOk()
            ->assertSee('Pedido externo')
            ->assertSee('WhatsApp')
            ->assertSee('Registrado por (mayorista)');
    }

    // ---------------------------------------------------------------- MAY-14

    public function test_recepcion_planta_mayorista_registra_cantidad_recibida_y_acredita_solo_lo_recibido(): void
    {
        TipoIncidenteTransporte::create(['codigo' => 'INC_F3D', 'titulo' => 'Retraso', 'descripcion' => 'Test']);
        $this->tipoMovimiento('ingreso');
        $this->tipoMovimiento('salida');
        $conductor = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        $mayorista = $this->actor('mayorista');
        $destino = $this->almacenMayorista($mayorista, 'Destino MAY-14');
        $planta = $this->almacen(AlmacenAmbito::PLANTA, null, 'Planta MAY-14');
        $producto = $this->productoTerminado($planta, 'Harina', 100);
        $ruta = RutaDistribucion::create([
            'codigo' => 'TPM-MAY14', 'nombre' => 'MAY-14',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA,
            'almacen_planta_origenid' => $planta->almacenid,
            'almacen_mayorista_destinoid' => $destino->almacenid,
            'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now()->subHour(),
            'simulacion_duracion_seg' => 60,
        ]);
        $linea = DetalleTrasladoPlantaMayorista::create([
            'rutadistribucionid' => $ruta->rutadistribucionid, 'insumoid' => $producto->insumoid,
            'producto_nombre' => 'Harina', 'cantidad' => 30,
        ]);
        $cierre = app(CierreEnvioPlantaMayoristaService::class);
        $cierre->confirmarLlegada($ruta->fresh(), $conductor);
        $cierre->registrarIncidentes($ruta->fresh(), $conductor, true);
        $cierre->guardarFirmaTransportista($ruta->fresh(), $conductor, 'data:image/png;base64,iVBORw0KGgo=');
        $firma = 'data:image/png;base64,iVBORw0KGgo=';

        foreach ([
            [[$linea->detalletrasladoid => ['recibido' => 20]], '/motivo/'],
            [[$linea->detalletrasladoid => ['recibido' => 31, 'motivo' => 'x']], '/entre 0 y lo despachado/'],
        ] as [$recepcion, $patron]) {
            try {
                $cierre->guardarFirmaRecepcion($ruta->fresh(), $mayorista, $firma, $recepcion);
                $this->fail('La recepción inválida debía rechazarse.');
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression($patron, $e->getMessage());
            }
        }

        $cierre->guardarFirmaRecepcion($ruta->fresh(), $mayorista, $firma, [
            $linea->detalletrasladoid => ['recibido' => 20, 'motivo' => 'Dos bolsas rotas en el viaje'],
        ]);
        $cierre->finalizarEntrega($ruta->fresh(), $conductor);

        $this->assertEqualsWithDelta(70.0, (float) $producto->fresh()->stock, 0.0001, 'sale de planta lo despachado');
        $recibido = Insumo::query()->where('almacenid', $destino->almacenid)->firstOrFail();
        $this->assertEqualsWithDelta(20.0, (float) $recibido->stock, 0.0001, 'al mayorista solo lo recibido');
        $ingreso = AlmacenMovimiento::query()->where('almacenid', $destino->almacenid)->firstOrFail();
        $this->assertEqualsWithDelta(20.0, (float) $ingreso->cantidad, 0.0001);
        $this->assertStringContainsString('Recibido 20.00 de 30.00', $ingreso->observaciones);
        $this->assertStringContainsString('Dos bolsas rotas', $ingreso->observaciones);
        $this->assertSame('Dos bolsas rotas en el viaje', $linea->fresh()->motivo_diferencia);
    }
}
