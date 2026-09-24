<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Cultivo;
use App\Models\EstadoLoteTipo;
use App\Models\Lote;
use App\Models\Prioridad;
use App\Models\TipoActividad;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Support\LoteTrazabilidadService;
use Database\Seeders\CatalogosOperacionAgricolaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActividadLotePermisosTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName, array $overrides = []): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CatalogosOperacionAgricolaSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create(array_merge([
            'nombre' => 'Test',
            'apellido' => ucfirst($roleName),
            'email' => $roleName.'.actividad@test.local',
            'nombreusuario' => $roleName.'_actividad',
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ], $overrides));

        $user->syncRoles([$role->name]);

        return $user;
    }

    private function crearLote(Usuario $responsable): Lote
    {
        $estado = EstadoLoteTipo::query()->firstOrFail();
        $unidad = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'ha'],
            ['nombre' => 'Hectárea', 'categoria' => 'superficie']
        );
        $cultivo = Cultivo::query()->firstOrCreate(
            ['nombre' => 'Tomate'],
            ['detalle' => 'Test']
        );

        return Lote::create([
            'usuarioid' => $responsable->usuarioid,
            'nombre' => 'Lote prueba '.$responsable->usuarioid,
            'ubicacion' => 'Parcela test',
            'superficie' => 1,
            'unidadsuperficieid' => $unidad->unidadmedidaid,
            'cultivoid' => $cultivo->cultivoid,
            'estadolotetipoid' => $estado->estadolotetipoid,
            'fechacreacion' => now(),
            'fechamodificacion' => now(),
        ]);
    }

    public function test_agricultor_puede_abrir_asignar_actividad_de_su_lote(): void
    {
        TipoActividad::create(['nombre' => 'Riego']);
        $agricultor = $this->createUser('agricultor');
        $lote = $this->crearLote($agricultor);

        $this->actingAs($agricultor)
            ->get(route('actividades.create', [
                'loteid' => $lote->loteid,
                'tipo' => 'Riego',
                'return' => route('lotes.trazabilidad', $lote, absolute: false),
            ]))
            ->assertOk();
    }

    public function test_agricultor_no_puede_asignar_actividad_en_lote_ajeno(): void
    {
        TipoActividad::create(['nombre' => 'Riego']);
        $agricultor = $this->createUser('agricultor');
        $otro = $this->createUser('agricultor', [
            'email' => 'otro.agricultor@test.local',
            'nombreusuario' => 'otro_agricultor',
        ]);
        $lote = $this->crearLote($otro);

        $this->actingAs($agricultor)
            ->get(route('actividades.create', [
                'loteid' => $lote->loteid,
                'tipo' => 'Riego',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error_modal', true);
    }

    public function test_agricultor_ve_lote_donde_participo_en_listado_y_trazabilidad(): void
    {
        TipoActividad::create(['nombre' => 'Siembra']);
        $jefe = $this->createUser('jefe_agricultor', [
            'email' => 'jefe.participa@test.local',
            'nombreusuario' => 'jefe_participa',
        ]);
        $operario = $this->createUser('agricultor', [
            'email' => 'oper.participa@test.local',
            'nombreusuario' => 'oper_participa',
        ]);
        $lote = $this->crearLote($jefe);

        Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $operario->usuarioid,
            'usuarioid_ejecutor' => $operario->usuarioid,
            'descripcion' => 'Siembra',
            'fechainicio' => now(),
            'fechafin' => now(),
            'tipoactividadid' => TipoActividad::where('nombre', 'Siembra')->value('tipoactividadid'),
            'prioridadid' => Prioridad::query()->orderBy('prioridadid')->value('prioridadid'),
        ]);

        $this->actingAs($operario)
            ->get(route('lotes.index'))
            ->assertOk()
            ->assertSee($lote->nombre);

        $this->actingAs($operario)
            ->get(route('lotes.trazabilidad', $lote))
            ->assertOk()
            ->assertSee('modo solo lectura', false);
    }

    public function test_agricultor_sin_participacion_recibe_modal_no_403_crudo(): void
    {
        $operario = $this->createUser('agricultor', [
            'email' => 'oper.ajeno@test.local',
            'nombreusuario' => 'oper_ajeno',
        ]);
        $jefe = $this->createUser('jefe_agricultor', [
            'email' => 'jefe.ajeno@test.local',
            'nombreusuario' => 'jefe_ajeno',
        ]);
        $lote = $this->crearLote($jefe);

        $this->actingAs($operario)
            ->get(route('lotes.trazabilidad', $lote))
            ->assertRedirect()
            ->assertSessionHas('error_modal', true);
    }

    public function test_agricultor_puede_acceder_ruta_siembra_de_su_lote_sin_403(): void
    {
        TipoActividad::create(['nombre' => 'Siembra']);
        $agricultor = $this->createUser('agricultor');
        $lote = $this->crearLote($agricultor);

        $this->actingAs($agricultor)
            ->get(route('lotes.siembra.create', [
                'lote' => $lote,
                'return' => route('lotes.trazabilidad', $lote, absolute: false),
            ]))
            ->assertOk();
    }

    public function test_jefe_agricultor_asigna_siembra_y_admin_no(): void
    {
        $admin = $this->createUser('admin');
        $jefe = $this->createUser('jefe_agricultor');
        $agricultor = $this->createUser('agricultor', [
            'email' => 'agri.siembra@test.local',
            'nombreusuario' => 'agri_siembra',
            'supervisor_usuarioid' => $jefe->usuarioid,
        ]);
        $estado = EstadoLoteTipo::query()->whereRaw('LOWER(nombre) LIKE ?', ['%planif%'])->first()
            ?? EstadoLoteTipo::query()->firstOrFail();
        $lote = Lote::create([
            'usuarioid' => $agricultor->usuarioid,
            'nombre' => 'Lote siembra test',
            'ubicacion' => 'Parcela test',
            'superficie' => 1,
            'unidadsuperficieid' => UnidadMedida::query()->firstOrCreate(
                ['abreviatura' => 'ha'],
                ['nombre' => 'Hectárea', 'categoria' => 'superficie']
            )->unidadmedidaid,
            'cultivoid' => Cultivo::query()->firstOrCreate(['nombre' => 'Tomate'], ['detalle' => 'Test'])->cultivoid,
            'estadolotetipoid' => $estado->estadolotetipoid,
            'fechacreacion' => now(),
            'fechamodificacion' => now(),
        ]);

        // El admin supervisa: no designa responsables de siembra.
        $this->actingAs($admin)
            ->post(route('lotes.siembra.asignar', $lote), [
                'usuarioid' => $agricultor->usuarioid,
            ])
            ->assertForbidden();

        $this->actingAs($jefe)
            ->post(route('lotes.siembra.asignar', $lote), [
                'usuarioid' => $agricultor->usuarioid,
            ])
            ->assertRedirect(route('lotes.trazabilidad', $lote));
    }

    public function test_agricultor_puede_completar_siembra_asignada_con_evidencia(): void
    {
        Storage::fake('public');
        $tipoSiembra = TipoActividad::create(['nombre' => 'Siembra']);
        $tipoPrep = TipoActividad::create(['nombre' => 'Labranza']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Media']);
        $agricultor = $this->createUser('agricultor');
        $estado = EstadoLoteTipo::query()->whereRaw('LOWER(nombre) LIKE ?', ['%planif%'])->first()
            ?? EstadoLoteTipo::query()->firstOrFail();
        $lote = Lote::create([
            'usuarioid' => $agricultor->usuarioid,
            'nombre' => 'Lote completar siembra',
            'ubicacion' => 'Parcela test',
            'superficie' => 1,
            'unidadsuperficieid' => UnidadMedida::query()->firstOrCreate(
                ['abreviatura' => 'ha'],
                ['nombre' => 'Hectárea', 'categoria' => 'superficie']
            )->unidadmedidaid,
            'cultivoid' => Cultivo::query()->firstOrCreate(['nombre' => 'Tomate'], ['detalle' => 'Test'])->cultivoid,
            'estadolotetipoid' => $estado->estadolotetipoid,
            'fechacreacion' => now(),
            'fechamodificacion' => now(),
        ]);

        Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $agricultor->usuarioid,
            'descripcion' => 'Preparación',
            'fechainicio' => now()->subDay(),
            'fechafin' => now()->subDay(),
            'tipoactividadid' => $tipoPrep->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
        ]);

        $pendiente = Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $agricultor->usuarioid,
            'descripcion' => 'Siembra',
            'fechainicio' => now(),
            'fechafin' => null,
            'tipoactividadid' => $tipoSiembra->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
        ]);

        $foto = UploadedFile::fake()->image('siembra.jpg');

        $this->actingAs($agricultor)
            ->post(route('lotes.siembra.completar.store', $lote), [
                'evidencia_foto' => $foto,
                'observaciones' => 'Surcos listos',
                'return' => route('lotes.trazabilidad', $lote, absolute: false),
            ])
            ->assertRedirect(route('lotes.trazabilidad', $lote))
            ->assertSessionHas('success');

        $pendiente->refresh();
        $this->assertNotNull($pendiente->fechafin);
        $this->assertNotNull($pendiente->evidencia_foto_path);

        $trazabilidad = app(LoteTrazabilidadService::class);
        $this->assertTrue($trazabilidad->siembraCompletada($lote->fresh()));
    }
}
