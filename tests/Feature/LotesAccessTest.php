<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LotesAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => ucfirst($roleName),
            'email' => $roleName . '.lotes@test.local',
            'nombreusuario' => $roleName . '_lotes',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);

        $user->syncRoles([$role->name]);
        return $user;
    }

    public function test_admin_supervisa_lotes_pero_no_los_crea(): void
    {
        $admin = $this->createUser('admin');
        $this->actingAs($admin);

        $this->get(route('lotes.index'))->assertOk();
        $this->get(route('lotes.create'))->assertForbidden();
        $this->post(route('lotes.store'), [])->assertForbidden();
    }

    public function test_agricultor_ve_sus_lotes_y_actividades_sin_crear_lotes(): void
    {
        $agricultor = $this->createUser('agricultor');
        $this->actingAs($agricultor);

        $this->get(route('lotes.index'))->assertOk();
        $this->get(route('lotes.create'))->assertForbidden();
        $this->get(route('actividades.index'))->assertOk();
        $this->get(route('actividades.calendario'))->assertOk();
    }

    public function test_admin_y_agricultor_acceden_api_lotes(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);
        $this->getJson('/api/lotes')->assertOk();

        $agricultor = $this->createUser('agricultor');
        Sanctum::actingAs($agricultor);
        $this->getJson('/api/lotes')->assertOk();
    }

    public function test_agricultor_accede_api_lotes(): void
    {
        $agricultor = $this->createUser('agricultor');
        Sanctum::actingAs($agricultor);
        $this->getJson('/api/lotes')->assertOk();
    }
}

