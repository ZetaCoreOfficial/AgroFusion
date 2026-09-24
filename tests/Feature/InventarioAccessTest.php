<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventarioAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => ucfirst($roleName),
            'email' => $roleName . '.inventario@test.local',
            'nombreusuario' => $roleName . '_inventario',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);

        $user->syncRoles([$role->name]);
        return $user;
    }

    public function test_admin_consulta_inventario_pero_no_lo_modifica(): void
    {
        $admin = $this->createUser('admin');
        $this->actingAs($admin);

        $this->get(route('insumos.index'))->assertOk();
        $this->get(route('insumos.create'))->assertForbidden();
        $this->post(route('insumos.store'), [])->assertForbidden();
    }
}

