<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\Pedido;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\RecepcionPlantaEnvioService;
use App\Support\AlmacenAmbito;
use App\Support\PlantaAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TEST-01 — Jefe/Operario Planta A ≠ B (JPL-01, JPL-02, OPP-02).
 */
class PlantaOwnershipAislamientoTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName, string $suffix, ?int $supervisorId = null): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $payload = [
            'nombre' => 'Test',
            'apellido' => $suffix,
            'email' => "{$roleName}.{$suffix}@test.local",
            'nombreusuario' => "{$roleName}_{$suffix}",
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ];

        if ($supervisorId !== null && Schema::hasColumn('usuario', 'supervisor_usuarioid')) {
            $payload['supervisor_usuarioid'] = $supervisorId;
        }

        $user = Usuario::create($payload);
        $user->syncRoles([$role->name]);

        return $user;
    }

    private function crearAlmacenPlanta(Usuario $responsable, string $nombre): Almacen
    {
        $um = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'kg'],
            ['nombre' => 'Kilogramo', 'categoria' => 'peso']
        );

        $payload = [
            'nombre' => $nombre,
            'descripcion' => 'Test planta',
            'ubicacion' => 'Santa Cruz',
            'capacidad' => 1000,
            'unidadmedidaid' => $um->unidadmedidaid,
            'activo' => true,
        ];

        if (Schema::hasColumn('almacen', 'ambito')) {
            $payload['ambito'] = AlmacenAmbito::PLANTA;
        }
        if (Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            $payload['responsable_usuarioid'] = $responsable->usuarioid;
        }

        return Almacen::create($payload);
    }

    public function test_jefe_a_no_ve_almacen_planta_de_jefe_b(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'A');
        $jefeB = $this->createUser('jefe_planta', 'B');
        $almacenB = $this->crearAlmacenPlanta($jefeB, 'Planta Jefe B');

        $this->assertFalse(PlantaAccess::puedeVerAlmacen($jefeA, $almacenB));
        $this->assertTrue(PlantaAccess::puedeVerAlmacen($jefeB, $almacenB));

        $idsA = PlantaAccess::idsAlmacenesPlanta($jefeA);
        $this->assertNotContains((int) $almacenB->almacenid, $idsA);

        $this->actingAs($jefeA)
            ->get(route('almacen-planta.show', $almacenB))
            ->assertForbidden();
    }

    public function test_jefe_a_si_abre_su_almacen_planta(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'A');
        $almacenA = $this->crearAlmacenPlanta($jefeA, 'Planta Jefe A');

        $this->assertTrue(PlantaAccess::puedeVerAlmacen($jefeA, $almacenA));
        $this->assertTrue(PlantaAccess::puedeGestionarAlmacen($jefeA, $almacenA));

        $this->actingAs($jefeA)
            ->get(route('almacen-planta.show', $almacenA))
            ->assertOk();
    }

    public function test_operario_solo_ve_almacen_de_su_jefe(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'JA');
        $jefeB = $this->createUser('jefe_planta', 'JB');
        $opA = $this->createUser('planta', 'OPA', $jefeA->usuarioid);
        $almacenA = $this->crearAlmacenPlanta($jefeA, 'Planta Equipo A');
        $almacenB = $this->crearAlmacenPlanta($jefeB, 'Planta Equipo B');

        $this->assertTrue(PlantaAccess::puedeVerAlmacen($opA, $almacenA));
        $this->assertFalse(PlantaAccess::puedeVerAlmacen($opA, $almacenB));
        $this->assertFalse(PlantaAccess::puedeGestionarAlmacen($opA, $almacenA));

        $this->actingAs($opA)
            ->get(route('almacen-planta.show', $almacenB))
            ->assertForbidden();
    }

    public function test_jefe_a_solo_asigna_operarios_de_su_equipo(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'AsigA');
        $jefeB = $this->createUser('jefe_planta', 'AsigB');
        $opA = $this->createUser('planta', 'EqA', $jefeA->usuarioid);
        $opB = $this->createUser('planta', 'EqB', $jefeB->usuarioid);

        $ids = PlantaAccess::queryOperariosAsignables($jefeA)->pluck('usuarioid')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $opA->usuarioid, $ids);
        $this->assertNotContains((int) $opB->usuarioid, $ids);
        $this->assertTrue(PlantaAccess::puedeAsignarOperario($jefeA, $opA));
        $this->assertFalse(PlantaAccess::puedeAsignarOperario($jefeA, $opB));
    }

    public function test_recepcion_no_usa_fallback_arbitrario_de_almacen(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'RecA');
        $jefeB = $this->createUser('jefe_planta', 'RecB');
        $this->crearAlmacenPlanta($jefeA, 'Planta Norte Alpha');
        $this->crearAlmacenPlanta($jefeB, 'Planta Sur Beta');

        $pedidoSinDestino = Pedido::create([
            'numero_solicitud' => 'REC-NO-DEST',
            'nombre_planta' => 'Sin destino',
            'direccion_texto' => '',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en_transito',
        ]);

        $pedidoDesconocido = Pedido::create([
            'numero_solicitud' => 'REC-UNK',
            'nombre_planta' => 'Desconocido',
            'direccion_texto' => 'Almacen Fantasma XYZ · GPS',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en_transito',
        ]);

        $service = app(RecepcionPlantaEnvioService::class);
        $method = new ReflectionMethod(RecepcionPlantaEnvioService::class, 'resolverAlmacenPlantaDesdePedido');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($service, $pedidoSinDestino);
    }

    public function test_recepcion_falla_si_nombre_no_coincide_con_ningun_almacen(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'Rec2A');
        $this->crearAlmacenPlanta($jefeA, 'Planta Norte Alpha');

        $pedido = Pedido::create([
            'numero_solicitud' => 'REC-MISS',
            'nombre_planta' => 'Desconocido',
            'direccion_texto' => 'Almacen Fantasma XYZ · GPS',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en_transito',
        ]);

        $service = app(RecepcionPlantaEnvioService::class);
        $method = new ReflectionMethod(RecepcionPlantaEnvioService::class, 'resolverAlmacenPlantaDesdePedido');
        $method->setAccessible(true);

        try {
            $method->invoke($service, $pedido);
            $this->fail('Debía lanzar InvalidArgumentException sin elegir un almacén arbitrario.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('No se encontró', $e->getMessage());
        }
    }
}
