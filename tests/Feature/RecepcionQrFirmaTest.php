<?php

namespace Tests\Feature;

use App\Models\CondicionTransporte;
use App\Models\EnvioAsignacionMultiple;
use App\Models\FirmaTransportistaEnvio;
use App\Models\RecepcionQrEnvio;
use App\Models\TipoIncidenteTransporte;
use App\Models\Usuario;
use App\Services\CierreEnvioAgricolaService;
use App\Services\RecepcionQrFirmaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecepcionQrFirmaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        CondicionTransporte::create(['codigo' => 'COND001', 'titulo' => 'Luces delanteras', 'descripcion' => 'Test']);
        TipoIncidenteTransporte::create(['codigo' => 'INC001', 'titulo' => 'Retraso en tráfico', 'descripcion' => 'Test']);
    }

    private function transportista(): Usuario
    {
        Role::findOrCreate('transportista', 'web');

        $user = Usuario::create([
            'nombre' => 'Marco',
            'apellido' => 'Polo',
            'email' => 'marco.polo.qr@test.local',
            'nombreusuario' => 'marco_polo_qr',
            'passwordhash' => Hash::make('secret'),
            'role' => 'transportista',
            'fecharegistro' => now(),
            'activo' => true,
        ]);
        $user->assignRole('transportista');

        return $user;
    }

    public function test_tras_firma_transportista_genera_qr_y_resumen_espera_movil(): void
    {
        $transportista = $this->transportista();
        $envio = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-QR-001',
            'transportista_usuarioid' => $transportista->usuarioid,
            'estado' => 'asignado',
            'fecha_asignacion' => now(),
        ]);

        $cierre = app(CierreEnvioAgricolaService::class);
        $cierre->registrarCondicionesVehiculo($envio, $transportista, true);
        $envio->update([
            'estado' => 'en_transporte_planta',
            'simulacion_inicio_at' => now()->subMinutes(5),
            'simulacion_duracion_seg' => 120,
            'llegada_confirmada_at' => now(),
        ]);
        $cierre->registrarIncidentes($envio->fresh(), $transportista, true);

        $cierre->guardarFirmaTransportista($envio->fresh(), $transportista, 'data:image/png;base64,iVBORw0KGgo=');

        $resumen = $cierre->resumenPasos($envio->fresh());
        $this->assertFalse($resumen['puede_firmar_recepcion']);
        $this->assertTrue($resumen['esperando_firma_qr']);
        $this->assertNotEmpty($resumen['qr_recepcion_url']);

        $qr = RecepcionQrEnvio::query()->where('envioasignacionmultipleid', $envio->envioasignacionmultipleid)->first();
        $this->assertNotNull($qr);

        $firmaT = FirmaTransportistaEnvio::query()->where('envioasignacionmultipleid', $envio->envioasignacionmultipleid)->first();
        $this->assertSame('Marco Polo', $firmaT->nombrefirmante);
    }

    public function test_firma_desde_qr_exige_la_cuenta_del_receptor_y_nunca_la_del_transportista(): void
    {
        $transportista = $this->transportista();
        $envio = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-QR-002',
            'transportista_usuarioid' => $transportista->usuarioid,
            'estado' => 'asignado',
            'fecha_asignacion' => now(),
            'llegada_confirmada_at' => now(),
        ]);
        app(CierreEnvioAgricolaService::class)->registrarIncidentes($envio->fresh(), $transportista, true);

        FirmaTransportistaEnvio::create([
            'envioasignacionmultipleid' => $envio->envioasignacionmultipleid,
            'imagenfirma' => 'data:image/png;base64,iVBORw0KGgo=',
            'nombrefirmante' => 'Marco Polo',
            'firmante_usuarioid' => $transportista->usuarioid,
            'fechafirma' => now(),
        ]);

        $qr = app(RecepcionQrFirmaService::class)->ensureToken($envio);
        $imagen = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        // Sin sesión: la pantalla pide iniciar sesión y el POST no firma (antes bastaba escribir un nombre).
        $this->get(route('recepcion.publica', $qr->token))->assertOk()->assertSee('Iniciar sesión para firmar');
        $this->post(route('recepcion.publica.firmar', $qr->token), [
            'nombrefirmante' => 'Ana Recepción',
            'imagen_firma' => $imagen,
        ])->assertRedirect(route('login'));
        $this->assertFalse($envio->fresh()->firmaRecepcion()->exists());

        // El transportista, aunque tenga el QR en su pantalla, no firma la recepción (TRA-01).
        $this->actingAs($transportista)
            ->postJson(route('recepcion.publica.firmar', $qr->token), ['imagen_firma' => $imagen])
            ->assertStatus(422);
        $this->assertFalse($envio->fresh()->firmaRecepcion()->exists());

        // Personal de planta (receptor real) firma con su cuenta.
        Role::findOrCreate('planta', 'web');
        $receptora = Usuario::create([
            'nombre' => 'Ana', 'apellido' => 'Recepción', 'email' => 'ana.recepcion.qr@test.local',
            'nombreusuario' => 'ana_recepcion_qr', 'passwordhash' => Hash::make('secret'),
            'role' => 'planta', 'fecharegistro' => now(), 'activo' => true,
        ]);
        $receptora->assignRole('planta');

        $this->actingAs($receptora)
            ->postJson(route('recepcion.publica.firmar', $qr->token), ['imagen_firma' => $imagen])
            ->assertOk();

        $firma = $envio->fresh()->firmaRecepcion;
        $this->assertNotNull($firma);
        $this->assertSame((int) $receptora->usuarioid, (int) $firma->firmante_usuarioid);
        $this->assertStringContainsString('Ana', (string) $firma->nombrefirmante);
    }

    public function test_pagina_publica_qr_responde_sin_login(): void
    {
        $envio = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-QR-003',
            'estado' => 'asignado',
            'fecha_asignacion' => now(),
        ]);

        $qr = RecepcionQrEnvio::create([
            'token' => 'token-publico-test-123',
            'envioasignacionmultipleid' => $envio->envioasignacionmultipleid,
        ]);

        $this->get(route('recepcion.publica', $qr->token))
            ->assertOk()
            ->assertSee('Firma de recepción');
    }
}
