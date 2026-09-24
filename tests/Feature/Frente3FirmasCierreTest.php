<?php

namespace Tests\Feature;

use App\Models\AlmacenMovimiento;
use App\Models\DetallePedidoDistribucion;
use App\Models\DetalleTrasladoPlantaMayorista;
use App\Models\EnvioAsignacionMultiple;
use App\Models\FirmaRecepcionEnvio;
use App\Models\Insumo;
use App\Models\PedidoDistribucion;
use App\Models\RutaDistribucion;
use App\Models\TipoIncidenteTransporte;
use App\Models\Usuario;
use App\Services\CierreEnvioAgricolaService;
use App\Services\CierreEnvioDistribucionPdvService;
use App\Services\CierreEnvioPlantaMayoristaService;
use App\Services\RecepcionPuntoVentaService;
use App\Services\SimulacionRutaService;
use App\Support\AlmacenAmbito;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\RutaDistribucionCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\EscenarioComercialLogistica;
use Tests\TestCase;

/**
 * Frente 3 — Fase B: doble control en el cierre (CROSS-A) y recepción.
 * TRA-01, TRA-03, TRA-12, MAY-08, MAY-09, MAY-15, MIN-01, MIN-03, MIN-07, MIN-08, MIN-10.
 */
class Frente3FirmasCierreTest extends TestCase
{
    use EscenarioComercialLogistica;
    use RefreshDatabase;

    private const FIRMA = 'data:image/png;base64,iVBORw0KGgo=';

    protected function setUp(): void
    {
        parent::setUp();
        TipoIncidenteTransporte::create(['codigo' => 'INC_F3', 'titulo' => 'Retraso', 'descripcion' => 'Test']);
        $this->tipoMovimiento('ingreso');
        $this->tipoMovimiento('salida');
    }

    /** @return array{0: RutaDistribucion, 1: Usuario, 2: Usuario, 3: Insumo, 4: \App\Models\Almacen} */
    private function trasladoEnDestino(): array
    {
        $conductor = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        $mayorista = $this->actor('mayorista');
        $destino = $this->almacenMayorista($mayorista, 'Mayorista receptor');
        $planta = $this->almacen(AlmacenAmbito::PLANTA, null, 'Planta emisora');
        $producto = $this->productoTerminado($planta, 'Mermelada', 100);

        $ruta = RutaDistribucion::create([
            'codigo' => 'TPM-F3-'.random_int(1000, 9999),
            'nombre' => 'Traslado F3',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA,
            'almacen_planta_origenid' => $planta->almacenid,
            'almacen_mayorista_destinoid' => $destino->almacenid,
            'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now()->subHour(),
            'simulacion_duracion_seg' => 60,
        ]);
        DetalleTrasladoPlantaMayorista::create([
            'rutadistribucionid' => $ruta->rutadistribucionid,
            'insumoid' => $producto->insumoid,
            'producto_nombre' => $producto->nombre,
            'cantidad' => 30,
        ]);

        $cierre = app(CierreEnvioPlantaMayoristaService::class);
        $cierre->confirmarLlegada($ruta->fresh(), $conductor);
        $cierre->registrarIncidentes($ruta->fresh(), $conductor, true);

        return [$ruta->fresh(), $conductor, $mayorista, $producto, $destino];
    }

    private function assertFirmaRechazada(callable $firmar, string $patron): void
    {
        try {
            $firmar();
            $this->fail('La firma debía rechazarse.');
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression($patron, $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- Planta → Mayorista

    public function test_planta_mayorista_transportista_firma_entrega_y_solo_el_mayorista_destino_recibe(): void
    {
        [$ruta, $conductor, $mayorista, $producto, $destino] = $this->trasladoEnDestino();
        $cierre = app(CierreEnvioPlantaMayoristaService::class);

        // Admin no firma como transportista (TRA-03) ni otro conductor.
        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaTransportista($ruta->fresh(), $this->actor('admin'), self::FIRMA), '/Solo el transportista asignado/');
        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaTransportista($ruta->fresh(), $this->conductor(), self::FIRMA), '/Solo el transportista asignado/');

        $cierre->guardarFirmaTransportista($ruta->fresh(), $conductor, self::FIRMA);

        // El conductor no firma la recepción (TRA-01, MAY-15); tampoco admin ni un mayorista ajeno.
        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaRecepcion($ruta->fresh(), $conductor, self::FIRMA), '/transportista no puede firmar la recepción/');
        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaRecepcion($ruta->fresh(), $this->actor('admin'), self::FIRMA), '/administrador/');
        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaRecepcion($ruta->fresh(), $this->actor('mayorista'), self::FIRMA), '/No tiene permiso/');

        // Sin recepción del destino, el conductor no puede cerrar ni acreditar (TRA-12, MAY-08).
        $this->assertFirmaRechazada(fn () => $cierre->finalizarEntrega($ruta->fresh(), $conductor), '/Complete condiciones|recepción/');
        $this->assertSame(0, AlmacenMovimiento::query()->where('almacenid', $destino->almacenid)->count());

        $firma = $cierre->guardarFirmaRecepcion($ruta->fresh(), $mayorista, self::FIRMA);
        $this->assertSame((int) $mayorista->usuarioid, (int) $firma->firmante_usuarioid);

        $cierre->finalizarEntrega($ruta->fresh(), $conductor);

        $this->assertSame(RutaDistribucionCatalogo::ESTADO_COMPLETADA, $ruta->fresh()->estado);
        $this->assertEqualsWithDelta(70.0, (float) $producto->fresh()->stock, 0.0001);
        $recibido = Insumo::query()->where('almacenid', $destino->almacenid)->firstOrFail();
        $this->assertEqualsWithDelta(30.0, (float) $recibido->stock, 0.0001);

        // Segunda finalización: no duplica la transferencia (TRA-15).
        $this->assertFirmaRechazada(fn () => $cierre->finalizarEntrega($ruta->fresh(), $conductor), '/ya fue completado|Complete condiciones/');
        $this->assertEqualsWithDelta(30.0, (float) $recibido->fresh()->stock, 0.0001);
        $this->assertSame(1, AlmacenMovimiento::query()->where('almacenid', $destino->almacenid)->count());
    }

    public function test_transferencia_fallida_no_marca_el_traslado_como_completado(): void
    {
        [$ruta, $conductor, $mayorista, $producto, $destino] = $this->trasladoEnDestino();
        $cierre = app(CierreEnvioPlantaMayoristaService::class);
        $cierre->guardarFirmaTransportista($ruta->fresh(), $conductor, self::FIRMA);
        $cierre->guardarFirmaRecepcion($ruta->fresh(), $mayorista, self::FIRMA);

        // La planta se quedó sin stock: la transferencia falla y todo se revierte (MAY-09).
        $producto->update(['stock' => 5]);

        $this->assertFirmaRechazada(fn () => $cierre->finalizarEntrega($ruta->fresh(), $conductor), '/Stock insuficiente/');

        $this->assertSame(RutaDistribucionCatalogo::ESTADO_EN_RUTA, $ruta->fresh()->estado);
        $this->assertSame(0, AlmacenMovimiento::query()->where('referencia', $ruta->codigo)->count());
        $this->assertEqualsWithDelta(5.0, (float) $producto->fresh()->stock, 0.0001);
        $this->assertFalse(Insumo::query()->where('almacenid', $destino->almacenid)->where('stock', '>', 0)->exists());
    }

    public function test_cierre_manual_solo_cierra_el_gps_y_no_recibe_ni_mueve_inventario(): void
    {
        $conductor = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        $mayorista = $this->actor('mayorista');
        $destino = $this->almacenMayorista($mayorista, 'Destino manual');
        $planta = $this->almacen(AlmacenAmbito::PLANTA, null, 'Planta manual');
        $producto = $this->productoTerminado($planta, 'Salsa', 50);
        $ruta = RutaDistribucion::create([
            'codigo' => 'TPM-F3-MANUAL', 'nombre' => 'Manual',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA,
            'almacen_planta_origenid' => $planta->almacenid,
            'almacen_mayorista_destinoid' => $destino->almacenid,
            'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now(),
            'simulacion_duracion_seg' => 3600,
        ]);
        DetalleTrasladoPlantaMayorista::create([
            'rutadistribucionid' => $ruta->rutadistribucionid, 'insumoid' => $producto->insumoid,
            'producto_nombre' => 'Salsa', 'cantidad' => 10,
        ]);

        app(SimulacionRutaService::class)->completarManualDistribucion($ruta->fresh());

        $ruta->refresh();
        $this->assertSame(RutaDistribucionCatalogo::ESTADO_EN_RUTA, $ruta->estado, 'el cierre manual no completa (MAY-08)');
        $this->assertSame(0, AlmacenMovimiento::query()->count());
        $this->assertEqualsWithDelta(50.0, (float) $producto->fresh()->stock, 0.0001);

        // Queda en 100 %: el conductor puede confirmar la llegada y seguir el cierre con firmas.
        app(CierreEnvioPlantaMayoristaService::class)->confirmarLlegada($ruta, $conductor);
        $this->assertNotNull($ruta->fresh()->llegada_confirmada_at);
    }

    public function test_firma_de_recepcion_anonima_no_permite_cerrar_y_el_receptor_la_reemplaza(): void
    {
        [$ruta, $conductor, $mayorista] = $this->trasladoEnDestino();
        $cierre = app(CierreEnvioPlantaMayoristaService::class);
        $cierre->guardarFirmaTransportista($ruta->fresh(), $conductor, self::FIRMA);

        // Firma histórica del QR sin sesión (sin firmante): no vale como recepción.
        FirmaRecepcionEnvio::create([
            'rutadistribucionid' => $ruta->rutadistribucionid,
            'imagenfirma' => self::FIRMA,
            'nombrefirmante' => 'Nombre escrito a mano',
            'fechafirma' => now(),
        ]);

        $this->assertFalse($cierre->resumenPasos($ruta->fresh())['puede_finalizar']);
        $this->assertFirmaRechazada(fn () => $cierre->finalizarEntrega($ruta->fresh(), $conductor), '/Complete condiciones|recepción/');

        $cierre->guardarFirmaRecepcion($ruta->fresh(), $mayorista, self::FIRMA);
        $this->assertSame(1, FirmaRecepcionEnvio::query()->where('rutadistribucionid', $ruta->rutadistribucionid)->count());
        $this->assertTrue($cierre->resumenPasos($ruta->fresh())['puede_finalizar']);
    }

    // ---------------------------------------------------------------- Mayorista → PDV

    /** @return array{0: RutaDistribucion, 1: PedidoDistribucion, 2: Usuario, 3: Usuario, 4: Insumo} */
    private function entregaPdvEnDestino(): array
    {
        $conductor = $this->conductor(TransportistaFlotaCatalogo::MAYORISTA);
        $mayorista = $this->actor('mayorista');
        $minorista = $this->actor('minorista');
        $almacen = $this->almacenMayorista($mayorista, 'Mayorista PDV');
        $producto = $this->productoTerminado($almacen, 'Galletas', 100);
        $pdv = $this->puntoVenta($minorista, 'Tienda A');

        $ruta = RutaDistribucion::create([
            'codigo' => 'RD-F3-'.random_int(1000, 9999),
            'nombre' => 'Entrega PDV F3',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_MAYORISTA_PDV,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now()->subHour(),
            'simulacion_duracion_seg' => 60,
        ]);
        $pedido = PedidoDistribucion::create([
            'numero_solicitud' => 'PDV-F3-'.random_int(1000, 9999),
            'puntoventaid' => $pdv->puntoventaid,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'rutadistribucionid' => $ruta->rutadistribucionid,
            'estado' => PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO,
            'fechapedido' => now(),
        ]);
        DetallePedidoDistribucion::create([
            'pedidodistribucionid' => $pedido->pedidodistribucionid,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'insumoid' => $producto->insumoid,
            'producto_nombre' => 'Galletas',
            'cantidad' => 10,
        ]);

        $cierre = app(CierreEnvioDistribucionPdvService::class);
        $cierre->confirmarLlegada($ruta->fresh(), $conductor);
        $cierre->registrarIncidentes($ruta->fresh(), $conductor, true);

        return [$ruta->fresh(), $pedido, $conductor, $minorista, $producto];
    }

    public function test_pdv_solo_el_minorista_dueno_firma_y_la_recepcion_no_se_duplica(): void
    {
        [$ruta, $pedido, $conductor, $minorista, $producto] = $this->entregaPdvEnDestino();
        $cierre = app(CierreEnvioDistribucionPdvService::class);
        $cierre->guardarFirmaTransportista($ruta->fresh(), $conductor, self::FIRMA);

        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaRecepcion($ruta->fresh(), $conductor, self::FIRMA), '/transportista no puede firmar la recepción/');
        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaRecepcion($ruta->fresh(), $this->actor('minorista'), self::FIRMA), '/No tiene permiso/');

        // Entrega logística sin recepción: no acredita inventario (MIN-10).
        $this->assertFirmaRechazada(fn () => $cierre->finalizarEntrega($ruta->fresh(), $conductor), '/Complete condiciones|recepción/');
        $this->assertSame(PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO, $pedido->fresh()->estado);

        $cierre->guardarFirmaRecepcion($ruta->fresh(), $minorista, self::FIRMA);
        $cierre->finalizarEntrega($ruta->fresh(), $conductor);

        $pedido->refresh();
        $this->assertSame(PedidoDistribucionCatalogo::ESTADO_RECIBIDO, $pedido->estado);
        $almacenPdv = $pedido->puntoVenta->fresh()->almacen;
        $stockPdv = (float) Insumo::query()->where('almacenid', $almacenPdv->almacenid)->sum('stock');
        $this->assertEqualsWithDelta(10.0, $stockPdv, 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $producto->fresh()->stock, 0.0001);

        // Un segundo intento (doble clic / request concurrente) no duplica inventario (MIN-03).
        $this->assertFirmaRechazada(
            fn () => app(RecepcionPuntoVentaService::class)->confirmar($pedido->fresh(), $minorista),
            '/ya fue recibido|no está en tránsito/'
        );
        $this->assertEqualsWithDelta($stockPdv, (float) Insumo::query()->where('almacenid', $almacenPdv->almacenid)->sum('stock'), 0.0001);
    }

    public function test_servicio_de_recepcion_pdv_revalida_el_receptor(): void
    {
        [, $pedido, $conductor] = $this->entregaPdvEnDestino();

        // Sin firma del minorista, ni el conductor ni otro minorista ni el admin acreditan (MIN-08).
        foreach ([$conductor, $this->actor('minorista'), $this->actor('admin')] as $actor) {
            $this->assertFirmaRechazada(
                fn () => app(RecepcionPuntoVentaService::class)->confirmar($pedido->fresh(), $actor),
                '/minorista dueño|administrador/'
            );
        }

        $this->assertSame(PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO, $pedido->fresh()->estado);
    }

    public function test_endpoint_legacy_confirmar_recepcion_no_acredita_inventario(): void
    {
        // Ruta en tránsito SIN condiciones ni llegada: antes el endpoint histórico acreditaba igual.
        $mayorista = $this->actor('mayorista');
        $minorista = $this->actor('minorista');
        $almacen = $this->almacenMayorista($mayorista, 'Mayorista legacy');
        $producto = $this->productoTerminado($almacen, 'Galletas legacy', 100);
        $ruta = RutaDistribucion::create([
            'codigo' => 'RD-LEGACY', 'nombre' => 'Legacy',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_MAYORISTA_PDV,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'transportista_usuarioid' => $this->conductor()->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now(),
        ]);
        $pedido = PedidoDistribucion::create([
            'numero_solicitud' => 'PDV-LEGACY',
            'puntoventaid' => $this->puntoVenta($minorista, 'Tienda legacy')->puntoventaid,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'rutadistribucionid' => $ruta->rutadistribucionid,
            'estado' => PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO,
            'fechapedido' => now(),
        ]);
        DetallePedidoDistribucion::create([
            'pedidodistribucionid' => $pedido->pedidodistribucionid,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'insumoid' => $producto->insumoid,
            'producto_nombre' => 'Galletas legacy',
            'cantidad' => 10,
        ]);

        $this->actingAs($minorista)
            ->post(route('punto-venta.pedidos.confirmar-recepcion', $pedido))
            ->assertRedirect(route('punto-venta.rutas.cierre.panel', $ruta));

        $this->assertSame(PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO, $pedido->fresh()->estado);
        $this->assertFalse(AlmacenMovimiento::query()->where('observaciones', 'like', '[Recepción PDV]%')->exists());
    }

    // ---------------------------------------------------------------- Agricultura → Planta

    public function test_agricola_transportista_no_firma_recepcion_en_planta(): void
    {
        $conductor = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);
        $envio = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-F3-AGR', 'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => 'en_transporte_planta', 'fecha_asignacion' => now(), 'llegada_confirmada_at' => now(),
        ]);
        $cierre = app(CierreEnvioAgricolaService::class);
        $cierre->registrarIncidentes($envio->fresh(), $conductor, true);
        $cierre->guardarFirmaTransportista($envio->fresh(), $conductor, self::FIRMA);

        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaRecepcion($envio->fresh(), $conductor, self::FIRMA), '/transportista no puede firmar la recepción/');
        $this->assertFirmaRechazada(fn () => $cierre->guardarFirmaRecepcion($envio->fresh(), $this->actor('admin'), self::FIRMA), '/administrador/');

        $planta = $this->actor('planta');
        $firma = $cierre->guardarFirmaRecepcion($envio->fresh(), $planta, self::FIRMA);
        $this->assertSame((int) $planta->usuarioid, (int) $firma->firmante_usuarioid);
    }
}
