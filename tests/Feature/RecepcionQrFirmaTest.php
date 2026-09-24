<?php

namespace Tests\Feature;

use App\Models\CondicionTransporte;
use App\Models\Almacen;
use App\Models\EnvioAsignacionMultiple;
use App\Models\FirmaTransportistaEnvio;
use App\Models\Pedido;
use App\Models\RecepcionQrEnvio;
use App\Models\TipoIncidenteTransporte;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\CierreEnvioAgricolaService;
use App\Services\RecepcionQrFirmaService;
use App\Support\AlmacenAmbito;
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

    private function usuarioConRol(string $rol, string $sufijo): Usuario
    {
        Role::findOrCreate($rol, 'web');

        $usuario = Usuario::create([
            'nombre' => 'Ana', 'apellido' => $sufijo,
            'email' => strtolower($sufijo).'@qr.test.local',
            'nombreusuario' => 'qr_'.strtolower($sufijo),
            'passwordhash' => Hash::make('secret'),
            'role' => $rol, 'fecharegistro' => now(), 'activo' => true,
        ]);
        $usuario->assignRole($rol);

        return $usuario;
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
        $receptora = $this->usuarioConRol('jefe_planta', 'JefeA');
        $unidad = UnidadMedida::create(['nombre' => 'Kilogramo', 'abreviatura' => 'kg', 'activo' => true]);
        $almacen = Almacen::create([
            'nombre' => 'Planta QR A', 'ubicacion' => 'GPS -17.78,-63.18',
            'ambito' => AlmacenAmbito::PLANTA, 'capacidad' => 10000,
            'unidadmedidaid' => $unidad->unidadmedidaid, 'activo' => true,
            'responsable_usuarioid' => $receptora->usuarioid,
        ]);
        $pedido = Pedido::create([
            'numero_solicitud' => 'QR-AGR-002',
            'nombre_planta' => $almacen->nombre,
            'direccion_texto' => $almacen->nombre.' · GPS',
            'latitud' => -17.78, 'longitud' => -63.18,
            'estado' => 'en_transito', 'fechapedido' => now(),
        ]);
        $envio = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-QR-002',
            'pedidoid' => $pedido->pedidoid,
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

        // Solo la jefa responsable del destino puede firmar.
        foreach ([
            [$this->usuarioConRol('admin', 'Admin'), 403],
            [$this->usuarioConRol('planta', 'Operaria'), 422],
            [$this->usuarioConRol('jefe_planta', 'JefeB'), 422],
        ] as [$noReceptor, $estadoEsperado]) {
            // Admin se bloquea antes de llegar al servicio por AdminSoloSupervision.
            $this->actingAs($noReceptor)
                ->postJson(route('recepcion.publica.firmar', $qr->token), ['imagen_firma' => $imagen])
                ->assertStatus($estadoEsperado);
            $this->assertFalse($envio->fresh()->firmaRecepcion()->exists());
        }

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
