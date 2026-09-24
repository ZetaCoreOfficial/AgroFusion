<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PedidosAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => ucfirst($roleName),
            'email' => $roleName . '.pedidos@test.local',
            'nombreusuario' => $roleName . '_pedidos',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);

        $user->syncRoles([$role->name]);
        return $user;
    }

    public function test_admin_consulta_pedidos_pero_no_crea_envios(): void
    {
        $admin = $this->createUser('admin');
        $this->actingAs($admin);

        $this->get(route('pedidos.index'))->assertRedirect(route('logistica.asignaciones.listado'));
        $this->get(route('logistica.asignaciones.listado'))->assertOk();
        $this->get(route('pedidos.create'))->assertForbidden();
        $this->post(route('pedidos.store'), [])->assertForbidden();
    }
}

