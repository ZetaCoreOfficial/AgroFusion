<?php

namespace Tests\Feature;

use App\Models\AlmacenMovimiento;
use App\Models\EnvioAsignacionMultiple;
use App\Models\IncidenteEnvio;
use App\Models\RutaDistribucion;
use App\Support\AlmacenAmbito;
use App\Support\RutaDistribucionCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use App\Support\UsuarioRol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\EscenarioComercialLogistica;
use Tests\TestCase;

/**
 * Frente 3 — Fase A: ownership crítico.
 * MAY-01, MAY-02, MAY-03, MAY-05, MAY-06, MAY-07, TRA-02, TRA-07 (parte de TEST-MAY-01 y TEST-TRA-01).
 */
class Frente3OwnershipTest extends TestCase
{
    use EscenarioComercialLogistica;
    use RefreshDatabase;

    /** @return array{0: \App\Models\Usuario, 1: \App\Models\Usuario, 2: \App\Models\Almacen, 3: \App\Models\Almacen} */
    private function dosMayoristas(): array
    {
        $a = $this->actor('mayorista');
        $b = $this->actor('mayorista');

        return [$a, $b, $this->almacenMayorista($a, 'Almacén Mayorista A'), $this->almacenMayorista($b, 'Almacén Mayorista B')];
    }

    // ---------------------------------------------------------------- MAY-02

    public function test_mayorista_ve_su_almacen_y_no_abre_edita_ni_elimina_el_ajeno(): void
    {
        [$a, $b, $almA, $almB] = $this->dosMayoristas();

        $this->actingAs($a);
        $this->get(route('almacen-mayorista.index'))->assertOk()->assertSee('Almacén Mayorista A')->assertDontSee('Almacén Mayorista B');
        $this->get(route('almacen-mayorista.show', $almA))->assertOk();

        $this->get(route('almacen-mayorista.show', $almB))->assertForbidden();
        $this->get(route('almacen-mayorista.edit', $almB))->assertForbidden();
        $this->put(route('almacen-mayorista.update', $almB), [
            'nombre' => 'Robado por A',
            'capacidad' => 10,
        ])->assertForbidden();
        $this->delete(route('almacen-mayorista.destroy', $almB))->assertForbidden();

        $almB->refresh();
        $this->assertSame('Almacén Mayorista B', $almB->nombre);
        $this->assertSame((int) $b->usuarioid, (int) $almB->responsable_usuarioid, 'A no se vuelve responsable de B');
    }

    public function test_admin_supervisa_almacen_mayorista_sin_operarlo(): void
    {
        [, , , $almB] = $this->dosMayoristas();

        $this->actingAs($this->actor('admin'));
        $this->get(route('almacen-mayorista.show', $almB))->assertOk();
        $this->get(route('almacen-mayorista.edit', $almB))->assertForbidden();
    }

    // ---------------------------------------------------------------- MAY-07

    public function test_ver_almacen_no_descuenta_stock_ni_crea_movimientos(): void
    {
        [$a, , $almA] = $this->dosMayoristas();
        $producto = $this->productoTerminado($almA, 'Producto intacto', 40);
        $this->tipoMovimiento('salida');

        // Pedido confirmado que aún no salió: antes, abrir el almacén «reconciliaba» y lo descontaba.
        $pedido = \App\Models\PedidoDistribucion::create([
            'numero_solicitud' => 'PDV-GET-SIN-EFECTOS',
            'puntoventaid' => $this->puntoVenta($this->actor('minorista'), 'Tienda GET')->puntoventaid,
            'almacen_mayorista_origenid' => $almA->almacenid,
            'estado' => \App\Support\PedidoDistribucionCatalogo::ESTADO_CONFIRMADO,
            'fechapedido' => now(),
        ]);
        \App\Models\DetallePedidoDistribucion::create([
            'pedidodistribucionid' => $pedido->pedidodistribucionid,
            'almacen_mayorista_origenid' => $almA->almacenid,
            'insumoid' => $producto->insumoid,
            'producto_nombre' => $producto->nombre,
            'cantidad' => 5,
        ]);
        $movimientosAntes = AlmacenMovimiento::query()->count();

        $this->actingAs($a)->get(route('almacen-mayorista.show', $almA))->assertOk();

        $this->assertSame($movimientosAntes, AlmacenMovimiento::query()->count());
        $this->assertEqualsWithDelta(40.0, (float) $producto->fresh()->stock, 0.0001);
    }

    // ---------------------------------------------------------------- MAY-06

    public function test_mayorista_no_ve_edita_ni_elimina_inventario_ajeno(): void
    {
        [$a, , , $almB] = $this->dosMayoristas();
        $productoB = $this->productoTerminado($almB, 'Producto de B', 50);

        $this->actingAs($a);
        $params = ['almacen' => $almB->almacenid, 'insumo' => $productoB->insumoid];
        $this->get(route('almacen-mayorista.inventario.show', $params))->assertForbidden();
        $this->get(route('almacen-mayorista.inventario.edit', $params))->assertForbidden();
        $this->put(route('almacen-mayorista.inventario.update', $params), [
            'nombre' => 'Ajustado por A',
            'unidadmedidaid' => $this->unidadKg()->unidadmedidaid,
            'stock' => 0,
        ])->assertForbidden();
        $this->delete(route('almacen-mayorista.inventario.destroy', $params))->assertForbidden();

        $productoB->refresh();
        $this->assertSame('Producto de B', $productoB->nombre);
        $this->assertEqualsWithDelta(50.0, (float) $productoB->stock, 0.0001);
    }

    // ---------------------------------------------------------------- MAY-03

    public function test_movimientos_mayoristas_solo_en_almacenes_propios(): void
    {
        [$a, $b, $almA, $almB] = $this->dosMayoristas();
        $productoA = $this->productoTerminado($almA, 'Producto A');
        $productoB = $this->productoTerminado($almB, 'Producto B');
        $this->movimiento($almA, $productoA, $a, 'REF-PROPIO-A');
        $movB = $this->movimiento($almB, $productoB, $b, 'REF-AJENO-B');

        $planta = $this->almacen(AlmacenAmbito::PLANTA, null, 'Almacén Planta X');
        $productoPlanta = $this->productoTerminado($planta, 'Producto planta', 80);
        $tipoSalida = $this->tipoMovimiento('salida');

        $this->actingAs($a);
        $this->get(route('almacen-mayorista.movimientos.index'))
            ->assertOk()
            ->assertSee('REF-PROPIO-A')
            ->assertDontSee('REF-AJENO-B');
        $this->get(route('almacen-mayorista.movimientos.show', ['almacenMovimiento' => $movB->almacen_movimientoid]))->assertNotFound();

        $payload = fn ($almacen, $insumo) => [
            'almacenid' => $almacen->almacenid,
            'insumoid' => $insumo->insumoid,
            'tipo_movimiento_almacenid' => $tipoSalida->tipo_movimiento_almacenid,
            'fecha' => now()->toDateString(),
            'cantidad' => 1,
        ];

        $this->post(route('almacen-mayorista.movimientos.store', 'salida'), $payload($almB, $productoB))->assertForbidden();
        $this->post(route('almacen-mayorista.movimientos.store', 'salida'), $payload($planta, $productoPlanta))->assertForbidden();
        $this->assertEqualsWithDelta(100.0, (float) $productoB->fresh()->stock, 0.0001);
        $this->assertEqualsWithDelta(80.0, (float) $productoPlanta->fresh()->stock, 0.0001);

        $this->post(route('almacen-mayorista.movimientos.store', 'salida'), $payload($almA, $productoA))->assertRedirect();
        $this->assertEqualsWithDelta(99.0, (float) $productoA->fresh()->stock, 0.0001);
    }

    // ---------------------------------------------------------------- MAY-05

    public function test_api_mayorista_respeta_ownership(): void
    {
        [$a, $b, $almA, $almB] = $this->dosMayoristas();
        $productoB = $this->productoTerminado($almB, 'Producto B');
        $this->movimiento($almB, $productoB, $b, 'REF-API-B');
        $tipoIngreso = $this->tipoMovimiento('ingreso');

        Sanctum::actingAs($a);

        $ids = collect($this->getJson('/api/almacenes')->assertOk()->json())->pluck('almacenid')->map(fn ($id) => (int) $id);
        $this->assertTrue($ids->contains((int) $almA->almacenid));
        $this->assertFalse($ids->contains((int) $almB->almacenid));

        $this->getJson('/api/almacenes/'.$almB->almacenid)->assertNotFound();
        $this->putJson('/api/almacenes/'.$almB->almacenid, ['capacidad' => 1])->assertForbidden();
        $this->deleteJson('/api/almacenes/'.$almB->almacenid)->assertForbidden();

        $refs = collect($this->getJson('/api/almacen-movimientos')->assertOk()->json('data'))->pluck('referencia');
        $this->assertNotContains('REF-API-B', $refs->all());

        $this->postJson('/api/almacen-movimientos/ingreso', [
            'almacenid' => $almB->almacenid,
            'insumoid' => $productoB->insumoid,
            'tipo_movimiento_almacenid' => $tipoIngreso->tipo_movimiento_almacenid,
            'fecha' => now()->toDateString(),
            'cantidad' => 10,
        ])->assertForbidden();

        $nombresInsumos = collect($this->getJson('/api/insumos')->assertOk()->json())->pluck('nombre');
        $this->assertNotContains('Producto B', $nombresInsumos->all());
        $this->assertEqualsWithDelta(100.0, (float) $productoB->fresh()->stock, 0.0001);
        $this->assertNotNull($almB->fresh());
    }

    // ---------------------------------------------------------------- MAY-01

    public function test_jefe_mayorista_no_administra_usuarios_globales(): void
    {
        $admin = $this->actor('admin');
        $jefe = $this->actor('jefe_mayorista');

        $this->assertFalse(UsuarioRol::puedeGestionarUsuarios($jefe));
        $this->assertFalse($jefe->can('usuarios.create'), 'jefe_mayorista ya no hereda usuarios.* de la matriz');

        $this->actingAs($jefe);
        $this->get(route('gestion.index'))->assertForbidden();
        $this->get(route('gestion.edit', $admin))->assertForbidden();
        $this->delete(route('gestion.usuario.destroy', $admin))->assertForbidden();
    }

    // ---------------------------------------------------------------- TRA-02

    public function test_conductor_solo_ve_sus_viajes_en_web_y_api(): void
    {
        $conductorA = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);
        $conductorB = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);

        $envioA = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-F3-A', 'transportista_usuarioid' => $conductorA->usuarioid,
            'estado' => 'asignado', 'fecha_asignacion' => now(),
        ]);
        $envioB = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-F3-B', 'transportista_usuarioid' => $conductorB->usuarioid,
            'estado' => 'asignado', 'fecha_asignacion' => now(),
        ]);

        $this->assertTrue($conductorA->can('asignaciones.view'), 'el permiso amplio existe, pero no basta');

        $this->actingAs($conductorA);
        $this->get(route('logistica.asignaciones.show', $envioB))->assertForbidden();
        $this->patch(route('logistica.asignaciones.empezar-ruta', $envioB))->assertForbidden();

        Sanctum::actingAs($conductorA);
        $codigos = collect($this->getJson('/api/asignaciones-multiples')->assertOk()->json('data'))->pluck('externo_envio_id');
        $this->assertContains('ENV-F3-A', $codigos->all());
        $this->assertNotContains('ENV-F3-B', $codigos->all());
        $this->assertNotNull($envioA);
    }

    public function test_conductor_no_abre_cierre_ni_ruta_de_otro_conductor(): void
    {
        $conductorA = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        $conductorB = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        $mayorista = $this->actor('mayorista');
        $destino = $this->almacenMayorista($mayorista, 'Destino traslado');
        $planta = $this->almacen(AlmacenAmbito::PLANTA, null, 'Planta origen');

        $trasladoB = RutaDistribucion::create([
            'codigo' => 'TPM-F3-B',
            'nombre' => 'Traslado de B',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA,
            'almacen_planta_origenid' => $planta->almacenid,
            'almacen_mayorista_destinoid' => $destino->almacenid,
            'transportista_usuarioid' => $conductorB->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_PLANIFICADA,
        ]);

        $this->actingAs($conductorA);
        $this->get(route('logistica.traslados-planta.cierre.panel', $trasladoB))->assertForbidden();
        $this->get(route('logistica.traslados-planta.show', $trasladoB))->assertForbidden();

        $this->actingAs($conductorB);
        $this->get(route('logistica.traslados-planta.cierre.panel', $trasladoB))->assertOk();

        // Otro mayorista (no destino) tampoco abre el traslado.
        $this->actingAs($this->actor('mayorista'));
        $this->get(route('logistica.traslados-planta.cierre.panel', $trasladoB))->assertForbidden();
    }

    // ---------------------------------------------------------------- TRA-07

    public function test_conductor_solo_ve_y_crea_incidentes_de_sus_viajes(): void
    {
        $conductorA = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);
        $conductorB = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);
        EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-INC-A', 'transportista_usuarioid' => $conductorA->usuarioid,
            'estado' => 'asignado', 'fecha_asignacion' => now(),
        ]);
        EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-INC-B', 'transportista_usuarioid' => $conductorB->usuarioid,
            'estado' => 'asignado', 'fecha_asignacion' => now(),
        ]);
        $incidenteB = IncidenteEnvio::create([
            'externo_envio_id' => 'ENV-INC-B', 'reportadopor_usuarioid' => $conductorB->usuarioid,
            'tipo' => 'Retraso', 'descripcion' => 'Incidente privado de B', 'estado' => 'abierto',
        ]);

        $this->actingAs($conductorA);
        $this->get(route('logistica.incidentes.index'))->assertOk()->assertDontSee('Incidente privado de B');
        $this->get(route('logistica.incidentes.show', $incidenteB))->assertForbidden();
        $this->get(route('logistica.incidentes.edit', $incidenteB))->assertForbidden();
        $this->delete(route('logistica.incidentes.destroy', $incidenteB))->assertForbidden();

        $this->post(route('logistica.incidentes.store'), [
            'externo_envio_id' => 'ENV-INC-B', 'tipo' => 'Falso', 'descripcion' => 'En viaje ajeno',
        ])->assertSessionHasErrors('externo_envio_id');

        $this->post(route('logistica.incidentes.store'), [
            'externo_envio_id' => 'ENV-INC-A', 'tipo' => 'Pinchazo', 'descripcion' => 'En mi viaje',
        ])->assertRedirect();

        $this->assertDatabaseHas('incidente_envio', ['externo_envio_id' => 'ENV-INC-A', 'reportadopor_usuarioid' => $conductorA->usuarioid]);
        $this->assertDatabaseMissing('incidente_envio', ['descripcion' => 'En viaje ajeno']);
        $this->assertNotNull(IncidenteEnvio::find($incidenteB->incidenteenvioid));

        Sanctum::actingAs($conductorA);
        $descripciones = collect($this->getJson('/api/incidentes')->assertOk()->json('data'))->pluck('descripcion');
        $this->assertNotContains('Incidente privado de B', $descripciones->all());
    }
}
