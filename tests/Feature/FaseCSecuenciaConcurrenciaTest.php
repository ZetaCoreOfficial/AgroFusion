<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\AsignacionEtapaPlanta;
use App\Models\Cultivo;
use App\Models\EstadoLoteTipo;
use App\Models\Lote;
use App\Models\LoteProduccionPedido;
use App\Models\MaquinaPlanta;
use App\Models\Pedido;
use App\Models\Prioridad;
use App\Models\ProcesoPlanta;
use App\Models\RegistroProcesoMaquinaPlanta;
use App\Models\TipoActividad;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Support\AsignacionEtapaPlantaService;
use App\Support\ActividadSecuenciaService;
use Database\Seeders\CatalogosOperacionAgricolaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase C — secuencia agrícola + planta (OPA-06, JPL-04/05, OPP-05).
 */
class FaseCSecuenciaConcurrenciaTest extends TestCase
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
            'email' => "{$roleName}.{$suffix}@fasec.test",
            'nombreusuario' => "{$roleName}_{$suffix}",
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ];
        if ($supervisorId !== null && Schema::hasColumn('usuario', 'supervisor_usuarioid')) {
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
        $cultivo = Cultivo::query()->firstOrCreate(['nombre' => 'Papa'], ['detalle' => 'Test']);

        return Lote::create([
            'usuarioid' => $responsable->usuarioid,
            'nombre' => 'Lote '.$responsable->apellido,
            'ubicacion' => 'Parcela',
            'superficie' => 1,
            'unidadsuperficieid' => $unidad->unidadmedidaid,
            'cultivoid' => $cultivo->cultivoid,
            'estadolotetipoid' => $estado->estadolotetipoid,
            'fechacreacion' => now(),
            'fechamodificacion' => now(),
        ]);
    }

    private function crearActividad(Lote $lote, Usuario $asignado, string $nombre, int $orden): Actividad
    {
        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => $nombre], ['descripcion' => $nombre]);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Media']);

        return Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $asignado->usuarioid,
            'descripcion' => $nombre,
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
            'fechainicio' => now(),
            'orden_secuencia' => $orden,
        ]);
    }

    public function test_opa06_tarea_5_bloqueada_mientras_4_pendiente(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefeSeq');
        $opA = $this->createUser('agricultor', 'OpA', $jefe->usuarioid);
        $opB = $this->createUser('agricultor', 'OpB', $jefe->usuarioid);
        $lote = $this->crearLote($jefe);

        $act4 = $this->crearActividad($lote, $opA, 'Fertilización', 4);
        $act5 = $this->crearActividad($lote, $opB, 'Riego', 5);

        $seq = app(ActividadSecuenciaService::class);
        $this->assertTrue($seq->esSiguienteEnCola($act4));
        $this->assertFalse($seq->esSiguienteEnCola($act5));

        $this->expectException(ValidationException::class);
        $seq->asegurarEnTurnoParaCompletar($act5);
    }

    public function test_opa06_tras_completar_4_se_puede_completar_5(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefeSeq2');
        $opA = $this->createUser('agricultor', 'OpA2', $jefe->usuarioid);
        $opB = $this->createUser('agricultor', 'OpB2', $jefe->usuarioid);
        $lote = $this->crearLote($jefe);

        $act4 = $this->crearActividad($lote, $opA, 'Fertilización', 4);
        $act5 = $this->crearActividad($lote, $opB, 'Riego', 5);

        $foto = UploadedFile::fake()->image('ev.jpg');
        $this->actingAs($opA)
            ->post(route('actividades.marcar-realizada', $act4), ['evidencia_foto' => $foto])
            ->assertRedirect();

        $this->assertNotNull($act4->fresh()->fechafin);

        $seq = app(ActividadSecuenciaService::class);
        $seq->asegurarEnTurnoParaCompletar($act5->fresh());
        $this->assertTrue($seq->esSiguienteEnCola($act5->fresh()));
    }

    public function test_opa06_store_completar_no_salta_cola(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefeStore');
        $op = $this->createUser('agricultor', 'OpStore', $jefe->usuarioid);
        $lote = $this->crearLote($jefe);
        $this->crearActividad($lote, $op, 'Preparación', 1);

        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => 'Riego'], ['descripcion' => 'Riego']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Media']);

        $this->actingAs($jefe)
            ->post(route('actividades.store'), [
                'loteid' => $lote->loteid,
                'tipoactividadid' => $tipo->tipoactividadid,
                'prioridadid' => $prioridad->prioridadid,
                'descripcion' => 'Intento saltar',
                'completar' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(
            1,
            Actividad::query()->where('loteid', $lote->loteid)->whereNull('fechafin')->count()
        );
    }

    public function test_jpl04_registrar_etapa_legacy_bloqueado(): void
    {
        $jefe = $this->createUser('jefe_planta', 'JefeReg');
        $pedido = Pedido::create([
            'numero_solicitud' => 'FC-REG-1',
            'nombre_planta' => 'P',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-REG',
            'nombre' => 'Lote',
            'producto' => 'X',
            'fecha_creacion' => now()->toDateString(),
        ]);

        $regsAntes = RegistroProcesoMaquinaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->count();
        $asignCompletasAntes = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->where('estado', AsignacionEtapaPlanta::ESTADO_COMPLETADA)
            ->count();
        $ordenAntes = app(\App\Support\LoteProduccionTransformacionService::class)->ordenPasoActual($lote);

        $this->actingAs($jefe)
            ->post(route('procesamiento.registrar-etapa', $lote), [
                'procesoplantaid' => 1,
                'maquinaplantaid' => 1,
                'hora_inicio' => now()->toDateTimeString(),
                'hora_fin' => now()->toDateTimeString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(
            $regsAntes,
            RegistroProcesoMaquinaPlanta::query()
                ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
                ->count(),
            'registrar-etapa legacy no debe crear registro de proceso'
        );
        $this->assertSame(
            $asignCompletasAntes,
            AsignacionEtapaPlanta::query()
                ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
                ->where('estado', AsignacionEtapaPlanta::ESTADO_COMPLETADA)
                ->count(),
            'registrar-etapa legacy no debe completar etapas'
        );
        $this->assertSame(
            $ordenAntes,
            app(\App\Support\LoteProduccionTransformacionService::class)->ordenPasoActual($lote->fresh()),
            'registrar-etapa legacy no debe avanzar el orden'
        );
    }

    public function test_jpl05_asignar_siempre_tiene_orden(): void
    {
        $jefe = $this->createUser('jefe_planta', 'JefeOrd');
        $op = $this->createUser('planta', 'OpOrd', $jefe->usuarioid);
        $pedido = Pedido::create([
            'numero_solicitud' => 'FC-ORD-1',
            'nombre_planta' => 'P',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-ORD',
            'nombre' => 'Lote',
            'producto' => 'X',
            'fecha_creacion' => now()->toDateString(),
        ]);
        $proceso = ProcesoPlanta::create(['nombre' => 'Preparación de Materias Primas', 'activo' => true]);
        $maquina = MaquinaPlanta::create(['nombre' => 'L-100', 'codigo' => 'L-100', 'activo' => true]);

        // Compatibilidad por código L-100 ↔ Preparación de Materias Primas
        $asignacion = app(AsignacionEtapaPlantaService::class)->asignar($lote, [
            'procesoplantaid' => $proceso->procesoplantaid,
            'maquinaplantaid' => $maquina->maquinaplantaid,
            'operador_usuarioid' => $op->usuarioid,
        ], $jefe);

        $this->assertNotNull($asignacion->orden);
        $this->assertSame(1, (int) $asignacion->orden);
    }

    public function test_opp05_segundo_completar_falla(): void
    {
        $jefe = $this->createUser('jefe_planta', 'JefeDbl');
        $op = $this->createUser('planta', 'OpDbl', $jefe->usuarioid);
        $pedido = Pedido::create([
            'numero_solicitud' => 'FC-DBL-1',
            'nombre_planta' => 'P',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-DBL',
            'nombre' => 'Lote',
            'producto' => 'X',
            'fecha_creacion' => now()->toDateString(),
        ]);
        $proceso = ProcesoPlanta::create(['nombre' => 'Preparación de Materias Primas', 'activo' => true]);
        $maquina = MaquinaPlanta::create(['nombre' => 'L-100', 'codigo' => 'L-100', 'activo' => true]);

        $asignacion = AsignacionEtapaPlanta::create([
            'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
            'procesoplantaid' => $proceso->procesoplantaid,
            'maquinaplantaid' => $maquina->maquinaplantaid,
            'operador_usuarioid' => $op->usuarioid,
            'asignado_por_usuarioid' => $jefe->usuarioid,
            'orden' => 1,
            'estado' => AsignacionEtapaPlanta::ESTADO_PENDIENTE,
            'creado_en' => now(),
        ]);

        $service = app(AsignacionEtapaPlantaService::class);
        $payload = [
            'hora_inicio' => now()->subHour()->toDateTimeString(),
            'hora_fin' => now()->toDateTimeString(),
            'parametros' => [],
        ];

        $service->completar($asignacion, $payload, $op);
        $this->assertSame(1, RegistroProcesoMaquinaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->count());

        $this->expectException(\InvalidArgumentException::class);
        $service->completar($asignacion->fresh(), $payload, $op);
    }
}
