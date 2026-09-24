<?php

namespace Tests\Feature;

use App\Models\DocumentoEntrega;
use App\Models\PerfilTransportista;
use App\Models\RutaDistribucion;
use App\Models\TipoVehiculo;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Services\TrasladoPlantaMayoristaService;
use App\Support\CuentaEstado;
use App\Support\RutaDistribucionCatalogo;
use App\Support\SimulacionRutaCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\EscenarioComercialLogistica;
use Tests\TestCase;

/**
 * Frente 3 — Fase E: pool y permisos del transportista.
 * MAY-16, MAY-17, TRA-04, TRA-05, TRA-08, TRA-09, TRA-10, TRA-11, TRA-13 (TRA-14 en los tests de acceso existentes).
 */
class Frente3PoolTransportistaTest extends TestCase
{
    use EscenarioComercialLogistica;
    use RefreshDatabase;

    /** @return list<int> */
    private function poolSelector(string $ambito): array
    {
        return collect($this->getJson(route('catalogo-selector.usuarios', [
            'roles' => 'transportista',
            'ambito_flota' => $ambito,
            'per_page' => 50,
        ]))->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_pool_por_ambito_excluye_inactivos_no_disponibles_sin_perfil_y_en_viaje(): void
    {
        $valido = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);
        $inactivo = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA, ['activo' => false]);
        $noDisponible = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);
        PerfilTransportista::query()->where('usuarioid', $noDisponible->usuarioid)->update(['disponible' => false]);
        $sinPerfil = $this->actor('transportista');
        $dePlanta = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        $enViaje = $this->conductor(TransportistaFlotaCatalogo::AGRICOLA);
        RutaDistribucion::create([
            'codigo' => 'RD-POOL', 'nombre' => 'En viaje',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_MAYORISTA_PDV,
            'transportista_usuarioid' => $enViaje->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
            'simulacion_inicio_at' => now(),
        ]);

        $this->actingAs($this->actor('jefe_agricultor'));
        $pool = $this->poolSelector(TransportistaFlotaCatalogo::AGRICOLA);

        $this->assertContains((int) $valido->usuarioid, $pool);
        foreach ([$inactivo, $noDisponible, $sinPerfil, $dePlanta, $enViaje] as $excluido) {
            $this->assertNotContains((int) $excluido->usuarioid, $pool, 'no debe estar en el pool agrícola: '.$excluido->apellido);
        }

        // Mismos criterios al asignar (backend), no solo en el selector.
        foreach ([$noDisponible, $sinPerfil, $dePlanta, $enViaje] as $excluido) {
            try {
                app(\App\Services\DistribucionRutaService::class)->asegurarTransportistaMayorista((int) $excluido->usuarioid);
                $this->fail('No debería ser asignable al pool mayorista: '.$excluido->apellido);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_alta_de_transportista_sincroniza_el_rol_spatie(): void
    {
        $usuario = Usuario::create([
            'nombre' => 'Legacy', 'apellido' => 'Chofer', 'email' => 'legacy.chofer@test.local',
            'nombreusuario' => 'legacy_chofer', 'passwordhash' => Hash::make('x'),
            'role' => 'transportista', 'fecharegistro' => now(), 'activo' => true,
        ]);

        $this->assertTrue($usuario->fresh()->hasRole('transportista'));
    }

    public function test_aprobar_transportista_exige_elegir_la_flota(): void
    {
        $admin = $this->actor('admin');
        $solicitante = Usuario::create([
            'nombre' => 'Nuevo', 'apellido' => 'Chofer', 'email' => 'nuevo.chofer@test.local', 'nombreusuario' => 'solicitud_chofer',
            'passwordhash' => Hash::make('x'), 'role' => 'pendiente', 'rol_solicitado' => 'transportista',
            'estado_cuenta' => CuentaEstado::PENDIENTE, 'fecharegistro' => now(), 'activo' => false,
            'tipo_licencia' => 'C',
        ]);

        $this->actingAs($admin);
        $this->post(route('gestion.solicitud.aprobar', $solicitante))->assertSessionHasErrors('ambito_flota');
        $this->assertSame(CuentaEstado::PENDIENTE, $solicitante->fresh()->estado_cuenta);

        $this->post(route('gestion.solicitud.aprobar', $solicitante), ['ambito_flota' => TransportistaFlotaCatalogo::PLANTA])
            ->assertRedirect();
        $this->assertSame(TransportistaFlotaCatalogo::PLANTA, $solicitante->fresh()->perfilTransportista->ambito_flota);
    }

    public function test_traslado_planta_mayorista_valida_licencia_del_conductor(): void
    {
        $conductor = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        PerfilTransportista::query()->where('usuarioid', $conductor->usuarioid)->update(['licencias_json' => ['A']]);
        $conductor->update(['licencias_json' => ['A'], 'tipo_licencia' => 'A']);
        $tipo = TipoVehiculo::create(['nombre' => 'Camión pesado', 'capacidad_kg' => 10000, 'licencia_requerida' => 'C']);
        $camion = Vehiculo::create([
            'placa' => 'TRA10-1', 'marca' => 'Volvo', 'modelo' => 'FH', 'activo' => true,
            'tipovehiculoid' => $tipo->tipovehiculoid, 'ambito_flota' => TransportistaFlotaCatalogo::PLANTA,
        ]);

        $validarFlota = new \ReflectionMethod(TrasladoPlantaMayoristaService::class, 'validarFlota');

        try {
            $validarFlota->invoke(app(TrasladoPlantaMayoristaService::class), (int) $conductor->usuarioid, (int) $camion->vehiculoid);
            $this->fail('Un conductor con licencia A no debe llevar un camión que exige licencia C.');
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/licencia/i', $e->getMessage());
        }
    }

    public function test_permisos_recortados_del_transportista(): void
    {
        $conductor = $this->conductor();

        foreach (['envios.update', 'pedidos_distribucion.update', 'monitoreo.view', 'incidentes.delete'] as $permiso) {
            $this->assertFalse($conductor->can($permiso), $permiso);
        }
        $this->assertTrue($conductor->can('incidentes.create'));
    }

    public function test_conductor_ve_documentos_de_sus_rutas_y_no_los_ajenos(): void
    {
        $conductorA = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        $conductorB = $this->conductor(TransportistaFlotaCatalogo::PLANTA);
        foreach ([[$conductorA, 'TPM-DOC-A'], [$conductorB, 'TPM-DOC-B']] as [$conductor, $codigo]) {
            RutaDistribucion::create([
                'codigo' => $codigo, 'nombre' => $codigo,
                'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA,
                'transportista_usuarioid' => $conductor->usuarioid,
                'estado' => RutaDistribucionCatalogo::ESTADO_COMPLETADA,
            ]);
        }
        $documento = fn (string $codigo) => DocumentoEntrega::create([
            'externo_envio_id' => $codigo, 'tipo_documento' => 'guia_transporte',
            'titulo' => 'Guía '.$codigo, 'archivo_path' => 'documentos/entrega/no-existe-'.$codigo.'.pdf',
            'usuarioid' => $this->actor('jefe_planta')->usuarioid,
        ]);
        $propio = $documento('TPM-DOC-A');
        $ajeno = $documento('TPM-DOC-B');

        Sanctum::actingAs($conductorA);
        // Propio: pasa la autorización y falla solo porque el PDF de prueba no existe (404).
        $this->get('/api/documentos-entrega/'.$propio->documentoentregaid.'/download')->assertNotFound();
        $this->get('/api/documentos-entrega/'.$ajeno->documentoentregaid.'/download')->assertForbidden();
    }

    public function test_solo_el_conductor_asignado_ve_empezar_ruta(): void
    {
        $mayorista = $this->actor('mayorista');
        $almacen = $this->almacenMayorista($mayorista, 'Mayorista MAY-16');
        $conductor = $this->conductor();
        $ruta = RutaDistribucion::create([
            'codigo' => 'RD-MAY16', 'nombre' => 'MAY-16',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_MAYORISTA_PDV,
            'almacen_mayorista_origenid' => $almacen->almacenid,
            'transportista_usuarioid' => $conductor->usuarioid,
            'estado' => RutaDistribucionCatalogo::ESTADO_PLANIFICADA,
        ]);

        $this->assertTrue(SimulacionRutaCatalogo::usuarioPuedeEmpezarDistribucion($conductor, $ruta));
        $this->assertFalse(SimulacionRutaCatalogo::usuarioPuedeEmpezarDistribucion($mayorista, $ruta), 'el mayorista no conduce');
        $this->assertFalse(SimulacionRutaCatalogo::usuarioPuedeEmpezarDistribucion($this->actor('admin'), $ruta));
    }
}
