<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransportistaLogisticaAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createTransportista(): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate('transportista', 'web');

        $user = Usuario::create([
            'nombre' => 'Carlos',
            'apellido' => 'Transportista',
            'email' => 'transportista.menu@test.local',
            'nombreusuario' => 'transportista_menu',
            'passwordhash' => Hash::make('secret123'),
            'role' => 'transportista',
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);

        $user->syncRoles([$role->name]);

        return $user;
    }

    public function test_transportista_ve_mis_envios_y_reportes_operativos(): void
    {
        $transportista = $this->createTransportista();
        $this->actingAs($transportista);

        $this->get(route('logistica.asignaciones.listado'))->assertOk();
        $this->get(route('logistica.documentos.index'))->assertOk();
        $this->get(route('logistica.incidentes.index'))->assertOk();
        $this->get(route('dashboard.panel-transportista'))->assertOk();
    }

    public function test_transportista_no_crea_envios_ni_gestiona_catalogos(): void
    {
        $transportista = $this->createTransportista();
        $this->actingAs($transportista);

        $this->get(route('pedidos.create'))->assertForbidden();
        $this->get(route('envios.transportistas'))->assertForbidden();
        $this->get(route('envios.vehiculos'))->assertForbidden();
        // Reporte global de distribución: no es de su producto → 403 real, no solo ocultar el menú (TRA-14).
        $this->get(route('envios.reportes-distribucion'))->assertForbidden();
    }
}
