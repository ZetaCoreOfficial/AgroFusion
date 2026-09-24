<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrgTrackAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $roleName): Usuario
    {
        $this->seed(RolePermissionSeeder::class);

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => ucfirst($roleName),
            'email' => $roleName.'@test.local',
            'nombreusuario' => $roleName.'_user',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);

        $user->syncRoles([$roleName]);

        return $user;
    }

    public function test_agricultor_no_accede_modulos_envios(): void
    {
        $agricultor = $this->createUserWithRole('agricultor');
        $this->actingAs($agricultor);

        $this->get(route('envios.seguimiento'))->assertForbidden();
    }

    public function test_agricultor_no_puede_acceder_dashboard_admin_logistico(): void
    {
        $agricultor = $this->createUserWithRole('agricultor');
        $this->actingAs($agricultor);

        $this->get(route('envios.admin'))->assertForbidden();
    }

    public function test_admin_puede_acceder_dashboard_admin_logistico(): void
    {
        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin);

        $this->get(route('envios.admin'))->assertOk();
    }

    public function test_transportista_no_accede_a_gestion_de_vehiculos(): void
    {
        $transportista = $this->createUserWithRole('transportista');
        $this->actingAs($transportista);

        $this->get(route('envios.reportes-distribucion'))->assertForbidden();
        $this->get(route('envios.vehiculos'))->assertForbidden();
    }

    public function test_proxy_envia_bearer_token_a_orgtrack(): void
    {
        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin);

        config()->set('services.orgtrack.url', 'https://orgtrack.example');
        config()->set('services.orgtrack.token', 'token-prueba');

        Http::fake([
            'https://orgtrack.example/*' => Http::response(['ok' => true], 200),
        ]);

        $this->get(route('envios.api.vehiculos'))->assertOk();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer token-prueba')
                && $request->url() === 'https://orgtrack.example/api/vehiculos';
        });
    }

    public function test_orgtrack_sin_url_no_llama_http_y_devuelve_payload_local(): void
    {
        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin);

        Http::fake();

        config()->set('services.orgtrack.url', '');

        $this->get(route('envios.api.envios'))
            ->assertOk()
            ->assertJsonStructure(['data']);

        Http::assertNothingSent();
    }
}
