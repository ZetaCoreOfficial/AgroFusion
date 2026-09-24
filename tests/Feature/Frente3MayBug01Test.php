<?php

namespace Tests\Feature;

use App\Models\DetalleTrasladoPlantaMayorista;
use App\Models\Insumo;
use App\Models\RutaDistribucion;
use App\Models\TipoIncidenteTransporte;
use App\Services\CierreEnvioPlantaMayoristaService;
use App\Services\TrasladoPlantaMayoristaService;
use App\Support\AlmacenAmbito;
use App\Support\MayoristaAccess;
use App\Support\RutaDistribucionCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\EscenarioComercialLogistica;
use Tests\TestCase;

/**
 * MAY-BUG-01 — «después de un envío planta → mayorista el movimiento figura, pero el almacén no
 * aparece para el mayorista».
 *
 * Causa raíz (no era MAY-02): dos definiciones de «almacén propio» y un destino sin dueño.
 *  1. El módulo de movimientos filtraba solo por ámbito: el mayorista veía la entrega a CUALQUIER
 *     almacén mayorista, incluido uno que no era suyo.
 *  2. El listado de almacenes filtraba por responsable_usuarioid, y otras vistas sumaban
 *     usuario.almacenid sin filtrar; un almacén asignado solo por usuario.almacenid no aparecía.
 *  3. Planta podía enviar a un almacén mayorista sin responsable: nadie lo veía ni lo recibía.
 * También MAY-18 (el menú «Recepciones de planta» visible con inventario.view).
 */
class Frente3MayBug01Test extends TestCase
{
    use EscenarioComercialLogistica;
    use RefreshDatabase;

    public function test_movimiento_visible_implica_almacen_visible_para_el_mismo_mayorista(): void
    {
        $a = $this->actor('mayorista');
        $otro = $this->actor('mayorista');
        $ajeno = $this->almacenMayorista($otro, 'Almacén de otro mayorista');
        $this->movimiento($ajeno, $this->productoTerminado($ajeno, 'Entrega ajena'), $otro, 'TPM-AJENO');

        // Almacén asignado a A por usuario.almacenid (dato legacy, sin responsable).
        $legacy = $this->almacenMayorista(null, 'Almacén asignado legacy');
        $a->update(['almacenid' => $legacy->almacenid]);
        $this->movimiento($legacy, $this->productoTerminado($legacy, 'Entrega propia'), $a, 'TPM-PROPIO');

        $this->actingAs($a->fresh());

        // Antes: la entrega ajena figuraba en movimientos y el almacén propio legacy no se listaba.
        $this->get(route('almacen-mayorista.movimientos.index'))
            ->assertOk()
            ->assertSee('TPM-PROPIO')
            ->assertDontSee('TPM-AJENO');
        $this->get(route('almacen-mayorista.index'))
            ->assertOk()
            ->assertSee('Almacén asignado legacy')
            ->assertDontSee('Almacén de otro mayorista');

        // La misma regla para operar: A gestiona su almacén legacy, no el de otro.
        $this->assertTrue(MayoristaAccess::puedeGestionarAlmacen($a->fresh(), $legacy));
        $this->assertFalse(MayoristaAccess::puedeGestionarAlmacen($a->fresh(), $ajeno));
    }

    public function test_planta_no_envia_a_un_almacen_mayorista_sin_responsable(): void
    {
        $planta = $this->almacen(AlmacenAmbito::PLANTA, null, 'Planta origen');
        $sinDueno = $this->almacenMayorista(null, 'Mayorista sin responsable');

        try {
            app(TrasladoPlantaMayoristaService::class)->crear(
                $planta,
                $sinDueno,
                (int) $this->conductor(TransportistaFlotaCatalogo::PLANTA)->usuarioid,
                null,
                (int) $this->actor('jefe_planta')->usuarioid,
                [['insumoid' => $this->productoTerminado($planta)->insumoid, 'cantidad' => 1]],
            );
            $this->fail('No debe crearse un traslado hacia un almacén sin mayorista responsable.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no tiene un mayorista responsable', $e->getMessage());
        }

        $this->assertSame(0, RutaDistribucion::query()->count());
    }

    public function test_tras_recepcion_valida_el_almacen_del_dueno_queda_visible_con_stock(): void
    {
        TipoIncidenteTransporte::create(['codigo' => 'INC_BUG01', 'titulo' => 'Retraso', 'descripcion' => 'Test']);
        $this->tipoMovimiento('ingreso');
        $this->tipoMovimiento('salida');
        $conductor = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        $mayorista = $this->actor('mayorista');
        $destino = $this->almacenMayorista($mayorista, 'Almacén del dueño');
        $planta = $this->almacen(AlmacenAmbito::PLANTA, null, 'Planta');
        $producto = $this->productoTerminado($planta, 'Mermelada de frutilla', 40);
        $ruta = RutaDistribucion::create([
            'codigo' => 'TPM-BUG01', 'nombre' => 'Traslado',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA,
            'almacen_planta_origenid' => $planta->almacenid,
            'almacen_mayorista_destinoid' => $destino->almacenid,
            'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now()->subHour(),
            'simulacion_duracion_seg' => 60,
        ]);
        DetalleTrasladoPlantaMayorista::create([
            'rutadistribucionid' => $ruta->rutadistribucionid, 'insumoid' => $producto->insumoid,
            'producto_nombre' => 'Mermelada de frutilla', 'cantidad' => 12,
        ]);

        $cierre = app(CierreEnvioPlantaMayoristaService::class);
        $cierre->confirmarLlegada($ruta->fresh(), $conductor);
        $cierre->registrarIncidentes($ruta->fresh(), $conductor, true);
        $cierre->guardarFirmaTransportista($ruta->fresh(), $conductor, 'data:image/png;base64,iVBORw0KGgo=');
        $cierre->guardarFirmaRecepcion($ruta->fresh(), $mayorista, 'data:image/png;base64,iVBORw0KGgo=');
        $cierre->finalizarEntrega($ruta->fresh(), $conductor);

        $recibido = Insumo::query()->where('almacenid', $destino->almacenid)->firstOrFail();
        $this->assertEqualsWithDelta(12.0, (float) $recibido->stock, 0.0001);

        $this->actingAs($mayorista);
        $this->get(route('almacen-mayorista.index'))->assertOk()->assertSee('Almacén del dueño');
        $this->get(route('almacen-mayorista.show', $destino))->assertOk()->assertSee('Mermelada de frutilla');
        $this->get(route('almacen-mayorista.movimientos.index'))->assertOk()->assertSee('TPM-BUG01');
        // MAY-18: el acceso a «Recepciones de planta» usa un permiso existente (inventario.view).
        $this->get(route('almacen-mayorista.index'))->assertSee('Recepciones de planta');
    }
}
