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
use Database\Seeders\CatalogosOperacionAgricolaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * AGR-06 / OPA-01 / OPA-02 — aislamiento API lotes y actividades A ≠ B.
 */
class ApiCampoOwnershipAislamientoTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName, string $suffix, ?int $supervisorId = null): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CatalogosOperacionAgricolaSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $payload = [
            'nombre' => 'Test',
            'apellido' => $suffix,
            'email' => "{$roleName}.{$suffix}@api-campo.test",
            'nombreusuario' => "{$roleName}_{$suffix}",
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ];
        if ($supervisorId !== null) {
            $payload['supervisor_usuarioid'] = $supervisorId;
        }

        $user = Usuario::create($payload);
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
            ['nombre' => 'Papa'],
            ['detalle' => 'Test']
        );

        return Lote::create([
            'usuarioid' => $responsable->usuarioid,
            'nombre' => 'Lote '.$responsable->apellido,
            'ubicacion' => 'Parcela test',
            'superficie' => 1,
            'unidadsuperficieid' => $unidad->unidadmedidaid,
            'cultivoid' => $cultivo->cultivoid,
            'estadolotetipoid' => $estado->estadolotetipoid,
            'fechacreacion' => now(),
            'fechamodificacion' => now(),
        ]);
    }

    private function crearActividad(Lote $lote, Usuario $asignado): Actividad
    {
        $tipo = TipoActividad::query()->firstOrCreate(
            ['nombre' => 'Riego'],
            ['descripcion' => 'Riego test']
        );
        $prioridad = Prioridad::query()->firstOrCreate(
            ['nombre' => 'Media']
        );

        return Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $asignado->usuarioid,
            'descripcion' => 'Actividad de '.$asignado->apellido,
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
            'fechainicio' => now(),
        ]);
    }

    public function test_guest_no_accede_api_actividades(): void
    {
        $this->getJson('/api/actividades')->assertUnauthorized();
        $this->postJson('/api/actividades', [])->assertUnauthorized();
    }

    public function test_operario_a_no_ve_lote_ni_actividad_de_operario_b(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefeAB');
        $opA = $this->createUser('agricultor', 'OpA', $jefe->usuarioid);
        $opB = $this->createUser('agricultor', 'OpB', $jefe->usuarioid);

        $loteB = $this->crearLote($jefe);
        $actB = $this->crearActividad($loteB, $opB);

        Sanctum::actingAs($opA);

        $this->getJson('/api/lotes/'.$loteB->loteid)->assertForbidden();
        $this->getJson('/api/actividades/'.$actB->actividadid)->assertForbidden();

        $idsLotes = collect($this->getJson('/api/lotes')->assertOk()->json())
            ->pluck('loteid')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertNotContains((int) $loteB->loteid, $idsLotes);

        $idsAct = collect($this->getJson('/api/actividades')->assertOk()->json())
            ->pluck('actividadid')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertNotContains((int) $actB->actividadid, $idsAct);
    }

    public function test_operario_ve_lote_donde_participa_y_su_actividad(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefePart');
        $opA = $this->createUser('agricultor', 'PartA', $jefe->usuarioid);

        $lote = $this->crearLote($jefe);
        $act = $this->crearActividad($lote, $opA);

        Sanctum::actingAs($opA);

        $this->getJson('/api/lotes/'.$lote->loteid)->assertOk();
        $this->getJson('/api/actividades/'.$act->actividadid)->assertOk();

        $idsLotes = collect($this->getJson('/api/lotes')->assertOk()->json())
            ->pluck('loteid')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertContains((int) $lote->loteid, $idsLotes);
    }

    public function test_operario_no_crea_actividad_en_lote_ajeno_sin_participacion(): void
    {
        $jefeA = $this->createUser('jefe_agricultor', 'JefeSoloA');
        $jefeB = $this->createUser('jefe_agricultor', 'JefeSoloB');
        $opA = $this->createUser('agricultor', 'SoloA', $jefeA->usuarioid);

        $loteB = $this->crearLote($jefeB);
        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => 'Riego'], ['descripcion' => 'Riego']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Media']);

        Sanctum::actingAs($opA);

        $this->postJson('/api/actividades', [
            'loteid' => $loteB->loteid,
            'usuarioid' => $opA->usuarioid,
            'descripcion' => 'Intento ilegal',
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
        ])->assertForbidden();
    }

    public function test_operario_no_actualiza_lote_ajeno_por_api(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefeUpd');
        $opA = $this->createUser('agricultor', 'UpdA', $jefe->usuarioid);
        $lote = $this->crearLote($jefe);

        Sanctum::actingAs($opA);

        $this->putJson('/api/lotes/'.$lote->loteid, [
            'nombre' => 'Hackeado',
        ])->assertForbidden();
    }
}
