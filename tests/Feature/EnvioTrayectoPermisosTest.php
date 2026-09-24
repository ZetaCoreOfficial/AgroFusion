<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\EnvioTrayectoCatalogo;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnvioTrayectoPermisosTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => ucfirst($roleName),
            'email' => $roleName.'.trayecto@test.local',
            'nombreusuario' => $roleName.'_trayecto',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);

        $user->syncRoles([$role->name]);

        return $user;
    }

    public function test_admin_supervisor_no_registra_envios_en_ningun_trayecto(): void
    {
        $admin = $this->createUser('admin');

        $this->assertSame([], EnvioTrayectoCatalogo::trayectosPermitidos($admin));
        $this->assertFalse(EnvioTrayectoCatalogo::puedeCrearAlguno($admin));
        $this->actingAs($admin)->get(route('pedidos.create'))->assertForbidden();
    }

    public function test_jefe_agricultor_solo_trayecto_planta(): void
    {
        $user = $this->createUser('jefe_agricultor');

        $this->assertSame(['planta'], EnvioTrayectoCatalogo::trayectosPermitidos($user));
        $this->actingAs($user)->get(route('pedidos.create'))->assertOk();
        $this->actingAs($user)->get(route('pedidos.create', ['destino' => 'mayorista']))->assertForbidden();
    }

    public function test_jefe_planta_solo_trayecto_mayorista(): void
    {
        $user = $this->createUser('jefe_planta');

        $this->assertSame(['mayorista'], EnvioTrayectoCatalogo::trayectosPermitidos($user));
        $this->actingAs($user)->get(route('pedidos.create', ['destino' => 'mayorista']))->assertOk();
        $this->actingAs($user)->get(route('pedidos.create', ['destino' => 'planta']))->assertForbidden();
    }

    public function test_mayorista_puede_crear_envio_pdv_en_wizard(): void
    {
        $user = $this->createUser('mayorista');

        $this->assertSame(['punto-venta'], EnvioTrayectoCatalogo::trayectosPermitidos($user));
        $this->assertTrue(EnvioTrayectoCatalogo::puedeCrearAlguno($user));
        $this->actingAs($user)->get(route('pedidos.create', ['destino' => 'punto-venta']))->assertOk();
        $this->actingAs($user)->get(route('pedidos.create', ['destino' => 'mayorista']))->assertForbidden();
        $this->actingAs($user)->get(route('punto-venta.pedidos.index', ['ctx' => 'mayorista']))->assertOk();

        $request = \Illuminate\Http\Request::create('/punto-venta/pedidos', 'POST');
        $request->setUserResolver(fn () => $user);
        $this->assertTrue(EnvioTrayectoCatalogo::puedeRegistrarStorePdv($request, $user));
    }
}
