<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AsignacionEtapaPlanta;
use App\Models\LoteProduccionPedido;
use App\Models\MaquinaPlanta;
use App\Models\MaquinaVariablePlanta;
use App\Models\Pedido;
use App\Models\ProcesoPlanta;
use App\Models\RegistroProcesoMaquinaPlanta;
use App\Models\RutaDistribucion;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\UsuarioNotificacion;
use App\Models\VariableEstandar;
use App\Services\NotificacionUsuarioService;
use App\Support\AlmacenAmbito;
use App\Support\AlmacenResponsableCatalogo;
use App\Support\AsignacionEtapaPlantaService;
use App\Support\LoteProduccionParametrosService;
use App\Support\RutaDistribucionCatalogo;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase E1 — integridad funcional (AGR-07, JPL-09, JPL-10, OPP-06).
 */
class FaseE1IntegridadFuncionalTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName, string $suffix): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => $suffix,
            'email' => "{$roleName}.{$suffix}@fasee1.test",
            'nombreusuario' => "{$roleName}_{$suffix}",
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);
        $user->syncRoles([$role->name]);

        return $user;
    }

    private function crearAlmacen(string $ambito, Usuario $responsable, string $nombre): Almacen
    {
        $um = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'kg'],
            ['nombre' => 'Kilogramo', 'categoria' => 'peso']
        );

        $payload = [
            'nombre' => $nombre,
            'descripcion' => 'E1',
            'ubicacion' => 'Santa Cruz GPS:-17.78,-63.18',
            'capacidad' => 1000,
            'unidadmedidaid' => $um->unidadmedidaid,
            'activo' => true,
        ];
        if (Schema::hasColumn('almacen', 'ambito')) {
            $payload['ambito'] = $ambito;
        }
        if (Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            $payload['responsable_usuarioid'] = $responsable->usuarioid;
        }

        return Almacen::create($payload);
    }

    public function test_agr07_jefe_a_no_envia_desde_almacen_agricola_b(): void
    {
        $jefeA = $this->createUser('jefe_agricultor', 'AgrA');
        $jefeB = $this->createUser('jefe_agricultor', 'AgrB');
        $this->crearAlmacen(AlmacenAmbito::AGRICOLA, $jefeA, 'Agricola A');
        $almB = $this->crearAlmacen(AlmacenAmbito::AGRICOLA, $jefeB, 'Agricola B');
        $planta = $this->crearAlmacen(
            AlmacenAmbito::PLANTA,
            $this->createUser('jefe_planta', 'Dest'),
            'Planta Dest'
        );

        $vehiculo = \App\Models\Vehiculo::create([
            'placa' => 'E1-AGR-01',
            'marca' => 'Test',
            'modelo' => 'Van',
            'anio' => 2024,
            'activo' => true,
        ]);

        $response = $this->actingAs($jefeA)->post(route('pedidos.store'), [
            'origen_latitud' => -17.78,
            'origen_longitud' => -63.18,
            'origen_direccion' => $almB->nombre,
            'origen_almacenid' => $almB->almacenid,
            'latitud' => -17.79,
            'longitud' => -63.19,
            'direccion_texto' => $planta->nombre,
            'fechaEntregaDeseada' => now()->addDay()->toDateString(),
            'transportista_usuarioid' => $this->createUser('transportista', 'Chofer')->usuarioid,
            'vehiculoid' => $vehiculo->vehiculoid,
            'costo_bs' => 100,
            'detalles' => [
                ['producto_ref' => 'cultivo:1', 'cantidad' => 10],
            ],
        ]);

        $response->assertSessionHasErrors('origen_almacenid');
        $this->assertSame(0, Pedido::query()->count());
    }

    public function test_agr07_no_aplica_a_pedidos_distribucion_pdv(): void
    {
        // Minorista/mayorista/PDV no pasan por PedidoController::store (TRAYECTO_PLANTA).
        $this->assertSame(
            \App\Http\Controllers\Web\PedidoController::class,
            app('router')->getRoutes()->getByName('pedidos.store')->getControllerClass()
        );
        $this->assertSame(
            \App\Http\Controllers\Web\PedidoDistribucionController::class,
            app('router')->getRoutes()->getByName('punto-venta.pedidos.store')->getControllerClass()
        );

        $source = file_get_contents(app_path('Http/Controllers/Web/PedidoDistribucionController.php'));
        $this->assertStringNotContainsString('assertAlmacenesAgricolasPropios', $source);
        $this->assertStringNotContainsString('CampoAccess::', $source);
    }

    public function test_jpl09_notificacion_planta_a_no_llega_a_jefe_b(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'NotifA');
        $jefeB = $this->createUser('jefe_planta', 'NotifB');
        $almA = $this->crearAlmacen(AlmacenAmbito::PLANTA, $jefeA, 'Planta Notif A');
        $almMay = $this->crearAlmacen(
            AlmacenAmbito::MAYORISTA,
            $this->createUser('mayorista', 'May'),
            'May E1'
        );

        $transportista = $this->createUser('transportista', 'TrNotif');
        $ruta = RutaDistribucion::create([
            'codigo' => 'TR-E1-001',
            'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_PLANTA_MAYORISTA,
            'estado' => RutaDistribucionCatalogo::ESTADO_PENDIENTE_APROBACION,
            'almacen_planta_origenid' => $almA->almacenid,
            'almacen_mayorista_destinoid' => $almMay->almacenid,
            'creado_por_usuarioid' => $jefeA->usuarioid,
            'transportista_usuarioid' => $transportista->usuarioid,
        ]);

        $svc = app(NotificacionUsuarioService::class);
        $method = new ReflectionMethod($svc, 'destinatariosPlantaTraslado');
        $method->setAccessible(true);
        /** @var \Illuminate\Support\Collection<int, Usuario> $destinatarios */
        $destinatarios = $method->invoke($svc, $ruta->fresh(['almacenPlantaOrigen']));

        $ids = $destinatarios->pluck('usuarioid')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([(int) $jefeA->usuarioid], $ids);

        $admin = $this->createUser('admin', 'NotifAdmin');
        $svc->trasladoPlantaPendienteAprobacion($ruta->fresh());
        $this->assertSame(
            0,
            UsuarioNotificacion::query()->where('usuarioid', $jefeB->usuarioid)->count()
        );
        $this->assertSame(
            0,
            UsuarioNotificacion::query()->where('usuarioid', $admin->usuarioid)->count()
        );
        $this->assertGreaterThan(
            0,
            UsuarioNotificacion::query()->where('usuarioid', $jefeA->usuarioid)->count()
        );
    }

    public function test_jpl10_solo_jefe_planta_como_responsable_almacen(): void
    {
        $this->assertSame(
            ['jefe_planta'],
            AlmacenResponsableCatalogo::rolesSpatie(AlmacenAmbito::PLANTA)
        );

        $operario = new Usuario(['role' => 'planta', 'usuarioid' => 99]);
        $operario->setRelation('roles', collect());
        $this->assertFalse(
            AlmacenResponsableCatalogo::usuarioValidoParaAmbito($operario, AlmacenAmbito::PLANTA)
        );

        $jefe = new Usuario(['role' => 'jefe_planta', 'usuarioid' => 100]);
        $jefe->setRelation('roles', collect());
        $this->assertTrue(
            AlmacenResponsableCatalogo::usuarioValidoParaAmbito($jefe, AlmacenAmbito::PLANTA)
        );
    }

    public function test_opp06_no_inventa_midpoint_al_completar_sin_parametros(): void
    {
        $jefe = $this->createUser('jefe_planta', 'VarJefe');
        $op = $this->createUser('planta', 'VarOp');
        if (Schema::hasColumn('usuario', 'supervisor_usuarioid')) {
            $op->update(['supervisor_usuarioid' => $jefe->usuarioid]);
        }

        $pedido = Pedido::create([
            'numero_solicitud' => 'E1-VAR-1',
            'nombre_planta' => 'P',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-E1-VAR',
            'nombre' => 'Lote',
            'producto' => 'X',
            'fecha_creacion' => now()->toDateString(),
        ]);
        $proceso = ProcesoPlanta::create(['nombre' => 'Preparación de Materias Primas', 'activo' => true]);
        $maquina = MaquinaPlanta::create(['nombre' => 'L-100', 'codigo' => 'L-E1', 'activo' => true]);
        $variable = VariableEstandar::create([
            'codigo' => 'TEMP-E1',
            'nombre' => 'Temperatura',
            'unidad' => 'C',
            'activo' => true,
        ]);
        MaquinaVariablePlanta::create([
            'maquinaplantaid' => $maquina->maquinaplantaid,
            'variableestandarid' => $variable->variableestandarid,
            'valor_minimo' => 10,
            'valor_maximo' => 20,
            'obligatorio' => true,
        ]);

        $asignacion = AsignacionEtapaPlanta::create([
            'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
            'procesoplantaid' => $proceso->procesoplantaid,
            'maquinaplantaid' => $maquina->maquinaplantaid,
            'operador_usuarioid' => $op->usuarioid,
            'asignado_por_usuarioid' => $jefe->usuarioid,
            'orden' => 1,
            'estado' => AsignacionEtapaPlanta::ESTADO_PENDIENTE,
            'creado_en' => now(),
        ]);

        try {
            app(AsignacionEtapaPlantaService::class)->completar($asignacion, [
                'hora_inicio' => now()->subHour()->toDateTimeString(),
                'hora_fin' => now()->toDateTimeString(),
                'parametros' => [],
            ], $op);
            $this->fail('Debía rechazar completar sin mediciones reales');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('automáticamente', $e->getMessage());
        }

        $this->assertSame(0, RegistroProcesoMaquinaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->count());
    }

    public function test_opp06_parametros_registrados_desde_plan_no_usa_midpoint(): void
    {
        $jefe = $this->createUser('jefe_planta', 'MidJefe');
        $op = $this->createUser('planta', 'MidOp');
        $pedido = Pedido::create([
            'numero_solicitud' => 'E1-MID-1',
            'nombre_planta' => 'P',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-E1-MID',
            'nombre' => 'Lote',
            'producto' => 'X',
            'fecha_creacion' => now()->toDateString(),
        ]);
        $proceso = ProcesoPlanta::create(['nombre' => 'Preparación de Materias Primas', 'activo' => true]);
        $maquina = MaquinaPlanta::create(['nombre' => 'L-100', 'codigo' => 'L-MID', 'activo' => true]);
        $variable = VariableEstandar::create([
            'codigo' => 'PRES-E1',
            'nombre' => 'Presion',
            'unidad' => 'bar',
            'activo' => true,
        ]);
        MaquinaVariablePlanta::create([
            'maquinaplantaid' => $maquina->maquinaplantaid,
            'variableestandarid' => $variable->variableestandarid,
            'valor_minimo' => 1,
            'valor_maximo' => 5,
            'obligatorio' => true,
        ]);

        $asignacion = AsignacionEtapaPlanta::create([
            'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
            'procesoplantaid' => $proceso->procesoplantaid,
            'maquinaplantaid' => $maquina->maquinaplantaid,
            'operador_usuarioid' => $op->usuarioid,
            'asignado_por_usuarioid' => $jefe->usuarioid,
            'orden' => 1,
            'estado' => AsignacionEtapaPlanta::ESTADO_PENDIENTE,
            'creado_en' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(LoteProduccionParametrosService::class)->parametrosRegistradosDesdePlan($asignacion);
    }
}
