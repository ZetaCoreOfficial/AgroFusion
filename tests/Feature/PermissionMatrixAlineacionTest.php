<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Alineación permission_matrix ↔ reglas Fase B (UI @can / Spatie).
 */
class PermissionMatrixAlineacionTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => $roleName,
            'email' => $roleName.'.matrix@test.local',
            'nombreusuario' => $roleName.'_matrix',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);
        $user->syncRoles([$role->name]);

        return $user;
    }

    public function test_operario_planta_no_tiene_confirmacion_ni_movimientos_libres(): void
    {
        $op = $this->createUser('planta');

        $this->assertFalse($op->can('recepcion_planta.confirm'));
        $this->assertFalse($op->can('almacen.ingresos.create'));
        $this->assertFalse($op->can('almacen.salidas.create'));
        $this->assertFalse($op->can('inventario.create'));
        $this->assertFalse($op->can('inventario.update'));

        $this->assertTrue($op->can('panel_planta.view'));
        $this->assertTrue($op->can('lote_produccion.view'));
        $this->assertTrue($op->can('inventario.view'));
        $this->assertFalse($op->can('pedidos_distribucion.view'));
        $this->assertFalse($op->can('pedidos_distribucion.update'));
    }

    public function test_jefe_planta_conserva_recepcion_y_movimientos(): void
    {
        $jefe = $this->createUser('jefe_planta');

        $this->assertTrue($jefe->can('recepcion_planta.confirm'));
        $this->assertTrue($jefe->can('almacen.ingresos.create'));
        $this->assertTrue($jefe->can('almacen.salidas.create'));
        $this->assertTrue($jefe->can('inventario.create'));
    }

    public function test_operario_agricultor_solo_lectura_sin_admin_almacen(): void
    {
        $op = $this->createUser('agricultor');

        $this->assertTrue($op->can('panel_agricultor.view'));
        $this->assertTrue($op->can('lotes.view'));
        $this->assertTrue($op->can('inventario.view'));

        $this->assertFalse($op->can('inventario.create'));
        $this->assertFalse($op->can('inventario.update'));
        $this->assertFalse($op->can('inventario.delete'));
        $this->assertFalse($op->can('almacen.ingresos.create'));
        $this->assertFalse($op->can('almacen.salidas.create'));
        $this->assertFalse($op->can('lotes.create'));
        $this->assertFalse($op->can('lotes.update'));
    }

    public function test_jefe_agricultor_conserva_gestion_campo(): void
    {
        $jefe = $this->createUser('jefe_agricultor');

        $this->assertTrue($jefe->can('lotes.create'));
        $this->assertTrue($jefe->can('lotes.update'));
        $this->assertTrue($jefe->can('inventario.create'));
        $this->assertTrue($jefe->can('almacen.ingresos.create'));
    }
}
