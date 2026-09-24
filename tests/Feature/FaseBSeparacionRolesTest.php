<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Almacen;
use App\Models\AsignacionEtapaPlanta;
use App\Models\Cultivo;
use App\Models\EstadoLoteTipo;
use App\Models\Lote;
use App\Models\LoteProduccionPedido;
use App\Models\MaquinaPlanta;
use App\Models\Pedido;
use App\Models\Prioridad;
use App\Models\ProcesoPlanta;
use App\Models\TipoActividad;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Support\ActividadPermisos;
use App\Support\AlmacenAmbito;
use App\Support\AsignacionEtapaPlantaService;
use App\Support\CampoAccess;
use App\Support\PlantaAccess;
use App\Support\UsuarioRol;
use Database\Seeders\CatalogosOperacionAgricolaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase B — Separación Jefe / Operario (AGR-05, OPA-03/04/05, JPL-03/06, OPP-03/04/08).
 */
class FaseBSeparacionRolesTest extends TestCase
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
            'email' => "{$roleName}.{$suffix}@faseb.test",
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

    private function crearActividad(Lote $lote, Usuario $asignado): Actividad
    {
        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => 'Riego'], ['descripcion' => 'Riego']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Media']);

        return Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $asignado->usuarioid,
            'descripcion' => 'Riego asignado',
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
            'fechainicio' => now(),
            'orden_secuencia' => 1,
        ]);
    }

    private function crearAlmacen(string $ambito, Usuario $responsable, string $nombre): Almacen
    {
        $um = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'kg'],
            ['nombre' => 'Kilogramo', 'categoria' => 'peso']
        );
        $payload = [
            'nombre' => $nombre,
            'descripcion' => 'Test',
            'ubicacion' => 'Santa Cruz',
            'capacidad' => 1000,
            'unidadmedidaid' => $um->unidadmedidaid,
            'activo' => true,
        ];
        if (Schema::hasColumn('almacen', 'ambito')) {
            $payload['ambito'] = $ambito;
        }
        if (Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            $payload['responsable_usuarioid'] = $responsable->usuarioid;
        }

        return Almacen::create($payload);
    }

    public function test_jefe_agricultor_no_completa_tarea_de_operario(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefeAgr');
        $op = $this->createUser('agricultor', 'OpAgr', $jefe->usuarioid);
        $lote = $this->crearLote($jefe);
        $act = $this->crearActividad($lote, $op);

        $this->assertFalse(ActividadPermisos::puedeMarcarCompletada($jefe, $act));
        $this->assertTrue(ActividadPermisos::puedeMarcarCompletada($op, $act));

        $this->actingAs($jefe)
            ->post(route('actividades.marcar-realizada', $act))
            ->assertForbidden();
    }

    public function test_operario_agricultor_no_crea_actividades(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefeCrea');
        $op = $this->createUser('agricultor', 'OpCrea', $jefe->usuarioid);
        $lote = $this->crearLote($jefe);
        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => 'Riego'], ['descripcion' => 'Riego']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Media']);

        $this->actingAs($op)
            ->post(route('actividades.store'), [
                'loteid' => $lote->loteid,
                'tipoactividadid' => $tipo->tipoactividadid,
                'prioridadid' => $prioridad->prioridadid,
                'descripcion' => 'Intento crear',
            ])
            ->assertForbidden();
    }

    public function test_operario_agricultor_no_administra_almacenes(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'JefeAlm');
        $op = $this->createUser('agricultor', 'OpAlm', $jefe->usuarioid);
        $almacen = $this->crearAlmacen(AlmacenAmbito::AGRICOLA, $jefe, 'Campo Jefe');

        $this->assertTrue(CampoAccess::puedeVerAlmacen($op, $almacen));
        $this->assertFalse(CampoAccess::puedeGestionarAlmacen($op, $almacen));

        $this->actingAs($op)
            ->get(route('almacen-agricola.edit', $almacen))
            ->assertForbidden();

        $this->actingAs($op)
            ->post(route('almacen-agricola.movimientos.store', ['naturaleza' => 'ingreso']), [
                'almacenid' => $almacen->almacenid,
            ])
            ->assertForbidden();
    }

    public function test_operario_agricultor_solo_ve_almacenes_de_su_equipo(): void
    {
        $jefeA = $this->createUser('jefe_agricultor', 'JefeEqA');
        $jefeB = $this->createUser('jefe_agricultor', 'JefeEqB');
        $opA = $this->createUser('agricultor', 'OpEqA', $jefeA->usuarioid);
        $almA = $this->crearAlmacen(AlmacenAmbito::AGRICOLA, $jefeA, 'Alm Equipo A');
        $almB = $this->crearAlmacen(AlmacenAmbito::AGRICOLA, $jefeB, 'Alm Equipo B');

        $this->assertTrue(CampoAccess::puedeVerAlmacen($opA, $almA));
        $this->assertFalse(CampoAccess::puedeVerAlmacen($opA, $almB));

        $this->actingAs($opA)
            ->get(route('almacen-agricola.show', $almB))
            ->assertForbidden();
    }

    public function test_jefe_planta_no_completa_etapa_de_operario(): void
    {
        $jefe = $this->createUser('jefe_planta', 'JefePl');
        $op = $this->createUser('planta', 'OpPl', $jefe->usuarioid);

        $pedido = Pedido::create([
            'numero_solicitud' => 'FB-PL-001',
            'nombre_planta' => 'Planta',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-FB-001',
            'nombre' => 'Lote FB',
            'producto' => 'Producto',
            'fecha_creacion' => now()->toDateString(),
        ]);
        $proceso = ProcesoPlanta::create(['nombre' => 'Lavado', 'activo' => true]);
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

        $this->actingAs($jefe)
            ->post(route('procesamiento.completar-etapa-asignada', [$lote, $asignacion]))
            ->assertForbidden();
    }

    public function test_jefe_planta_a_no_asigna_operario_b(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'JefePoolA');
        $jefeB = $this->createUser('jefe_planta', 'JefePoolB');
        $opB = $this->createUser('planta', 'OpPoolB', $jefeB->usuarioid);

        $this->assertFalse(PlantaAccess::puedeAsignarOperario($jefeA, $opB));
        $ids = PlantaAccess::queryOperariosAsignables($jefeA)->pluck('usuarioid')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $opB->usuarioid, $ids);
    }

    public function test_operario_planta_no_confirma_recepcion(): void
    {
        $jefe = $this->createUser('jefe_planta', 'JefeRec');
        $op = $this->createUser('planta', 'OpRec', $jefe->usuarioid);

        $this->assertFalse(UsuarioRol::puedeConfirmarRecepcionPlanta($op));
        $this->assertTrue(UsuarioRol::puedeConfirmarRecepcionPlanta($jefe));
    }

    public function test_operario_planta_no_hace_movimientos_ni_almacena(): void
    {
        $jefe = $this->createUser('jefe_planta', 'JefeMov');
        $op = $this->createUser('planta', 'OpMov', $jefe->usuarioid);
        $almacen = $this->crearAlmacen(AlmacenAmbito::PLANTA, $jefe, 'Planta Mov');

        $pedido = Pedido::create([
            'numero_solicitud' => 'FB-ALM-001',
            'nombre_planta' => 'Planta',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-FB-ALM',
            'nombre' => 'Lote alm',
            'producto' => 'Producto',
            'fecha_creacion' => now()->toDateString(),
        ]);

        $this->actingAs($op)
            ->post(route('almacen-planta.movimientos.store', ['naturaleza' => 'ingreso']), [
                'almacenid' => $almacen->almacenid,
            ])
            ->assertForbidden();

        $this->actingAs($op)
            ->post(route('procesamiento.almacenar', $lote), [
                'almacenid' => $almacen->almacenid,
            ])
            ->assertForbidden();

        $this->actingAs($op)
            ->post(route('procesamiento.completar', $lote))
            ->assertForbidden();
    }
}
