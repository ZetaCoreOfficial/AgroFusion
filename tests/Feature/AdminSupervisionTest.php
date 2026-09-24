<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\CuentaEstado;
use App\Support\UsuarioRol;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SEC-01/SEC-02 (API protegida) y ADM-01…ADM-06 (admin = supervisión, sin bypass).
 */
class AdminSupervisionTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName, array $overrides = []): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create(array_merge([
            'nombre' => 'Test',
            'apellido' => ucfirst($roleName),
            'email' => strtolower($roleName).'.supervision@test.local',
            'nombreusuario' => strtolower($roleName).'_supervision',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ], $overrides));

        $user->syncRoles([$role->name]);

        return $user;
    }

    // ---------------------------------------------------------------- SEC-01

    public function test_recursos_api_exigen_autenticacion(): void
    {
        $usuario = $this->createUser('agricultor');

        foreach ([
            '/api/usuarios',
            '/api/roles',
            '/api/usuario-roles',
            '/api/tipo-almacenes',
            '/api/almacenes',
            '/api/producciones',
            '/api/producciones-almacenamiento',
            '/api/estadolotes',
            '/api/historial-estados-lote',
            '/api/lote-insumos',
            '/api/actividades',
            '/api/climas',
        ] as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }

        $this->putJson('/api/usuarios/'.$usuario->usuarioid, ['password' => 'hackeado123'])
            ->assertUnauthorized();
        $this->assertTrue(Hash::check('secret123', $usuario->fresh()->passwordhash));
    }

    public function test_api_usuarios_solo_para_administracion_de_usuarios(): void
    {
        $jefe = $this->createUser('jefe_agricultor');
        $otro = $this->createUser('agricultor');

        // jefe_agricultor tiene usuarios.update en web (acotado a su equipo), pero la API no acota: 403.
        Sanctum::actingAs($jefe);
        $this->putJson('/api/usuarios/'.$otro->usuarioid, ['password' => 'hackeado123'])->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
        $this->assertTrue(Hash::check('secret123', $otro->fresh()->passwordhash));

        Sanctum::actingAs($this->createUser('admin'));
        $this->getJson('/api/usuarios')->assertOk();
    }

    public function test_api_catalogos_escritura_requiere_permiso_de_escritura(): void
    {
        // Antes, escribir catálogos por API solo pedía catalogos,read.
        Sanctum::actingAs($this->createUser('agricultor'));

        $this->postJson('/api/cultivos', ['nombre' => 'Intruso'])->assertForbidden();
        $this->postJson('/api/tipo-almacenes', ['nombre' => 'Intruso'])->assertForbidden();
    }

    // ---------------------------------------------------------------- SEC-02

    public function test_register_admin_ya_no_existe(): void
    {
        $this->postJson('/api/register-admin', [
            'nombre' => 'X',
            'apellido' => 'Y',
            'email' => 'nuevo.admin@test.local',
            'nombreusuario' => 'nuevo_admin',
            'password' => 'secret123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('usuario', ['email' => 'nuevo.admin@test.local']);
    }

    public function test_registro_publico_api_queda_pendiente_y_no_inicia_sesion(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate('agricultor', 'web');

        $this->postJson('/api/register', [
            'nombre' => 'Movil',
            'apellido' => 'Test',
            'email' => 'movil@test.local',
            'nombreusuario' => 'movil_test',
            'password' => 'secret123',
        ])->assertCreated()->assertJsonMissingPath('token');

        $usuario = Usuario::query()->where('email', 'movil@test.local')->firstOrFail();
        $this->assertSame(CuentaEstado::PENDIENTE, $usuario->estado_cuenta);
        $this->assertTrue($usuario->hasRole('agricultor'));
        $this->assertFalse(UsuarioRol::esAdminGlobal($usuario));

        $this->postJson('/api/login', ['email' => 'movil@test.local', 'password' => 'secret123'])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- ADM-01 / ADM-05

    public function test_admin_sin_bypass_solo_tiene_permisos_de_supervision(): void
    {
        $admin = $this->createUser('admin');

        foreach (['lotes.view', 'inventario.view', 'pedidos_distribucion.view', 'envios.view', 'usuarios.create', 'solicitudes.approve', 'catalogos.create'] as $permiso) {
            $this->assertTrue($admin->can($permiso), "El admin debería tener {$permiso}");
        }

        foreach (['lotes.create', 'lotes.update', 'lote_produccion.create', 'pedidos.create', 'pedidos_distribucion.create', 'pedidos_distribucion.update', 'asignaciones.update', 'recepcion_planta.confirm', 'documentos.delete', 'almacen.ingresos.create'] as $permiso) {
            $this->assertFalse($admin->can($permiso), "El admin NO debería tener {$permiso}");
        }
    }

    public function test_admin_consulta_pero_no_escribe_en_flujos_de_negocio(): void
    {
        $this->actingAs($this->createUser('admin'));

        $this->get(route('lotes.index'))->assertOk();
        $this->get(route('procesamiento.index'))->assertOk();
        $this->get(route('punto-venta.pedidos.index'))->assertOk();

        $this->post(route('producciones.store'), [])->assertForbidden();
        $this->post(route('actividades.store'), [])->assertForbidden();
        $this->post(route('procesamiento.store'), [])->assertForbidden();
        $this->post(route('punto-venta.pedidos.store'), [])->assertForbidden();
    }

    public function test_admin_conserva_funciones_de_administracion(): void
    {
        $this->actingAs($this->createUser('admin'));

        // Validación fallida (302), no 403: la escritura de administración sigue permitida.
        $this->post(route('gestion.usuario.store'), [])->assertRedirect();
        $this->post(route('cultivos.store'), [])->assertRedirect();
    }

    // ---------------------------------------------------------------- ADM-02 / ADM-03 / ADM-04

    public function test_admin_no_gestiona_campo_planta_ni_distribucion(): void
    {
        $admin = $this->createUser('admin');

        $this->assertFalse(UsuarioRol::puedeOperar($admin));
        $this->assertFalse(UsuarioRol::gestionaCampo($admin));
        $this->assertFalse(UsuarioRol::gestionaPlanta($admin));
        $this->assertFalse(UsuarioRol::puedeConfirmarRecepcionPlanta($admin));
        $this->assertFalse(UsuarioRol::puedeGestionarDistribucionMayorista($admin));
    }

    public function test_roles_operativos_conservan_su_gestion(): void
    {
        $this->assertTrue(UsuarioRol::gestionaCampo($this->createUser('jefe_agricultor')));
        $this->assertTrue(UsuarioRol::gestionaPlanta($this->createUser('jefe_planta')));
        $this->assertTrue(UsuarioRol::puedeGestionarDistribucionMayorista($this->createUser('mayorista')));
    }

    // ---------------------------------------------------------------- ADM-06

    public function test_rol_legacy_Admin_se_reconoce_como_admin(): void
    {
        $legacy = $this->createUser('Admin', ['role' => 'Admin']);

        $this->assertTrue(UsuarioRol::esAdminGlobal($legacy));
        $this->assertFalse(UsuarioRol::puedeOperar($legacy));

        $this->actingAs($legacy)->post(route('producciones.store'), [])->assertForbidden();
    }
}
