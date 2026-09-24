<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AlmacenMovimientosAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => ucfirst($roleName),
            'email' => $roleName . '.mov@test.local',
            'nombreusuario' => $roleName . '_mov',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);

        $user->syncRoles([$role->name]);

        return $user;
    }

    public function test_admin_consulta_movimientos_pero_no_registra_ingresos_ni_salidas(): void
    {
        $admin = $this->createUser('admin');
        $this->actingAs($admin);

        $this->get(route('almacen-agricola.movimientos.index'))->assertOk();
        foreach (['ingreso', 'salida'] as $naturaleza) {
            $this->get(route('almacen-agricola.movimientos.create', ['naturaleza' => $naturaleza]))->assertForbidden();
            $this->post(route('almacen-agricola.movimientos.store', ['naturaleza' => $naturaleza]), [])->assertForbidden();
        }
    }

    public function test_transportista_no_accede_a_movimientos_internos(): void
    {
        $transportista = $this->createUser('transportista');
        $this->actingAs($transportista);

        $this->get(route('almacen-agricola.movimientos.index'))->assertForbidden();
    }
}
