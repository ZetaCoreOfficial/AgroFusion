<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Almacen;
use App\Models\AlmacenMovimiento;
use App\Models\AsignacionEtapaPlanta;
use App\Models\Cultivo;
use App\Models\DetallePedido;
use App\Models\EnvioAsignacionMultiple;
use App\Models\EstadoLoteTipo;
use App\Models\Lote;
use App\Models\LoteProduccionPedido;
use App\Models\LoteProduccionRutaPaso;
use App\Models\MaquinaPlanta;
use App\Models\Pedido;
use App\Models\Prioridad;
use App\Models\ProcesoPlanta;
use App\Models\TipoActividad;
use App\Models\TipoMovimientoAlmacen;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\RecepcionPlantaEnvioService;
use App\Support\AlmacenAmbito;
use App\Support\AsignacionEtapaPlantaService;
use Database\Seeders\CatalogosOperacionAgricolaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase E2 — OPA-09, JPL-08, PLT-FUNC-01.
 */
class FaseE2IntegridadFuncionalTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName, string $suffix, ?int $supervisorId = null): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $payload = [
            'nombre' => 'Test',
            'apellido' => $suffix,
            'email' => "{$roleName}.{$suffix}@fasee2.test",
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

    private function crearAlmacen(string $ambito, Usuario $responsable, string $nombre): Almacen
    {
        $um = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'kg'],
            ['nombre' => 'Kilogramo', 'categoria' => 'peso']
        );

        $payload = [
            'nombre' => $nombre,
            'descripcion' => 'E2',
            'ubicacion' => 'Santa Cruz GPS:-17.78,-63.18',
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

    private function crearLoteAgricola(Usuario $responsable): Lote
    {
        $this->seed(CatalogosOperacionAgricolaSeeder::class);
        $estado = EstadoLoteTipo::query()->firstOrFail();
        $unidad = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'ha'],
            ['nombre' => 'Hectárea', 'categoria' => 'superficie']
        );
        $cultivo = Cultivo::query()->firstOrCreate(
            ['nombre' => 'Tomate E2'],
            ['detalle' => 'Test']
        );

        return Lote::create([
            'usuarioid' => $responsable->usuarioid,
            'nombre' => 'Lote E2 '.$responsable->usuarioid,
            'ubicacion' => 'Parcela E2',
            'superficie' => 1,
            'unidadsuperficieid' => $unidad->unidadmedidaid,
            'cultivoid' => $cultivo->cultivoid,
            'estadolotetipoid' => $estado->estadolotetipoid,
            'fechacreacion' => now(),
            'fechamodificacion' => now(),
        ]);
    }

    // ─── OPA-09 ───────────────────────────────────────────────────────────

    public function test_opa09_jefe_crea_actividad_con_fecha_planificada(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'OpaJefe');
        $operario = $this->createUser('agricultor', 'OpaOp', (int) $jefe->usuarioid);
        $lote = $this->crearLoteAgricola($operario);
        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => 'Labranza']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Media']);

        $plan = now()->addDays(3)->toDateString();

        $response = $this->actingAs($jefe)->post(route('actividades.store'), [
            'loteid' => $lote->loteid,
            'usuarioid' => $operario->usuarioid,
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
            'descripcion' => 'Labranza planificada E2',
            'fecha_planificada' => $plan,
            'observaciones' => 'OPA-09',
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('error');
        $response->assertSessionHasNoErrors();
        $act = Actividad::query()->where('loteid', $lote->loteid)->latest('actividadid')->first();
        $this->assertNotNull($act);
        $this->assertSame($plan, $act->fecha_planificada?->format('Y-m-d'));
        $this->assertNull($act->fechafin);
    }

    public function test_opa09_operario_ve_fecha_planificada(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'OpaSeeJ');
        $operario = $this->createUser('agricultor', 'OpaSeeO', (int) $jefe->usuarioid);
        $lote = $this->crearLoteAgricola($operario);
        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => 'Labranza']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Alta']);
        $plan = now()->addDays(5)->toDateString();

        Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $operario->usuarioid,
            'descripcion' => 'Ver plan E2',
            'fechainicio' => now(),
            'fecha_planificada' => $plan,
            'fechafin' => null,
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
        ]);

        $fmt = \Carbon\Carbon::parse($plan)->format('d/m/Y');
        // show() redirige a trazabilidad; el operario ve la plan en el listado / dashboard.
        $this->actingAs($operario)
            ->get(route('actividades.index'))
            ->assertOk()
            ->assertSee($fmt)
            ->assertSee('plan', false);
    }

    public function test_opa09_fecha_planificada_no_completa_ni_altera_fechafin(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'OpaEditJ');
        $operario = $this->createUser('agricultor', 'OpaEditO', (int) $jefe->usuarioid);
        $lote = $this->crearLoteAgricola($operario);
        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => 'Labranza']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Baja']);

        $act = Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $operario->usuarioid,
            'descripcion' => 'Pendiente con plan',
            'fechainicio' => now()->subDay(),
            'fecha_planificada' => now()->addDay()->toDateString(),
            'fechafin' => null,
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
        ]);

        $nuevaPlan = now()->addDays(10)->toDateString();
        $this->actingAs($jefe)->put(route('actividades.update', $act), [
            'loteid' => $lote->loteid,
            'usuarioid' => $operario->usuarioid,
            'descripcion' => 'Pendiente con plan',
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
            'fecha_planificada' => $nuevaPlan,
            'fechainicio' => $act->fechainicio->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $act->refresh();
        $this->assertSame($nuevaPlan, $act->fecha_planificada?->format('Y-m-d'));
        $this->assertNull($act->fechafin);
    }

    // ─── JPL-08 ───────────────────────────────────────────────────────────

    private function prepararPedidoRecepcion(Usuario $jefePlanta, string $suffix): array
    {
        TipoMovimientoAlmacen::query()->firstOrCreate(
            ['codigo' => 'ING-E2'],
            ['nombre' => 'Producción recibida', 'naturaleza' => 'ingreso', 'activo' => true]
        );

        $almacen = $this->crearAlmacen(AlmacenAmbito::PLANTA, $jefePlanta, 'Planta E2 '.$suffix);

        $pedido = Pedido::create([
            'numero_solicitud' => 'E2-PES-'.$suffix,
            'nombre_planta' => $almacen->nombre,
            'direccion_texto' => $almacen->nombre.' · GPS',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en_transito',
            'fechapedido' => now(),
        ]);

        $detalle = DetallePedido::create([
            'pedidoid' => $pedido->pedidoid,
            'cultivo_personalizado' => 'Tomate fresco',
            'cantidad' => 100.0,
        ]);

        $envio = EnvioAsignacionMultiple::create([
            'externo_envio_id' => 'ENV-E2-'.$suffix,
            'pedidoid' => $pedido->pedidoid,
            'estado' => 'en_transporte_planta',
            'fecha_asignacion' => now(),
        ]);

        return compact('almacen', 'pedido', 'detalle', 'envio');
    }

    public function test_jpl08_jefe_confirma_pesaje_con_discrepancia(): void
    {
        $jefe = $this->createUser('jefe_planta', 'PesJefe');
        ['pedido' => $pedido, 'detalle' => $detalle, 'envio' => $envio] = $this->prepararPedidoRecepcion($jefe, 'OK');

        $this->actingAs($jefe)->post(route('pedidos.confirmar-llegada-planta', $pedido), [
            'cantidades' => [
                $detalle->detallepedidoid => 95.5,
            ],
        ])->assertRedirect();

        $envio->refresh();
        $this->assertSame('recibido_planta', $envio->estado);
        $this->assertNotNull($envio->fecha_recepcion_planta);
        $this->assertSame((int) $jefe->usuarioid, (int) $envio->recepcion_usuarioid);

        $mov = AlmacenMovimiento::query()
            ->where('referencia', $envio->externo_envio_id)
            ->first();
        $this->assertNotNull($mov);
        $this->assertEqualsWithDelta(95.5, (float) $mov->cantidad, 0.001);
        $this->assertStringContainsString('discrepancia', (string) $mov->observaciones);
        $this->assertStringContainsString('recibido 95.5 kg', (string) $mov->observaciones);
    }

    public function test_jpl08_operario_planta_no_puede_confirmar(): void
    {
        $jefe = $this->createUser('jefe_planta', 'PesJ2');
        $op = $this->createUser('planta', 'PesOp', (int) $jefe->usuarioid);
        ['pedido' => $pedido, 'detalle' => $detalle] = $this->prepararPedidoRecepcion($jefe, 'OP');

        $this->actingAs($op)->post(route('pedidos.confirmar-llegada-planta', $pedido), [
            'cantidades' => [
                $detalle->detallepedidoid => 100,
            ],
        ])->assertForbidden();
    }

    public function test_jpl08_no_confirma_dos_veces_ni_cantidad_cero(): void
    {
        $jefe = $this->createUser('jefe_planta', 'PesJ3');
        ['pedido' => $pedido, 'detalle' => $detalle, 'envio' => $envio] = $this->prepararPedidoRecepcion($jefe, 'DUP');

        $this->actingAs($jefe)->post(route('pedidos.confirmar-llegada-planta', $pedido), [
            'cantidades' => [$detalle->detallepedidoid => 0],
        ])->assertSessionHasErrors();

        app(RecepcionPlantaEnvioService::class)->confirmarDesdePedido(
            $pedido->fresh(['detalles', 'envioAsignacion']),
            $jefe,
            [(int) $detalle->detallepedidoid => 88.0]
        );

        $this->expectException(\InvalidArgumentException::class);
        app(RecepcionPlantaEnvioService::class)->confirmarDesdePedido(
            $pedido->fresh(['detalles', 'envioAsignacion']),
            $jefe,
            [(int) $detalle->detallepedidoid => 90.0]
        );
    }

    public function test_jpl08_ownership_otra_planta_bloquea(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'PesA');
        $jefeB = $this->createUser('jefe_planta', 'PesB');
        $this->crearAlmacen(AlmacenAmbito::PLANTA, $jefeB, 'Planta E2 Alien');
        ['pedido' => $pedido, 'detalle' => $detalle] = $this->prepararPedidoRecepcion($jefeA, 'OWN');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('otra planta');

        app(RecepcionPlantaEnvioService::class)->confirmarDesdePedido(
            $pedido->fresh(['detalles', 'envioAsignacion']),
            $jefeB,
            [(int) $detalle->detallepedidoid => 100.0]
        );
    }

    public function test_jpl08_transportista_no_acredita_stock_sin_pesaje(): void
    {
        $jefe = $this->createUser('jefe_planta', 'PesLegJ');
        $transportista = $this->createUser('transportista', 'PesLegT');
        ['pedido' => $pedido, 'detalle' => $detalle, 'envio' => $envio] = $this->prepararPedidoRecepcion($jefe, 'LEG');
        $envio->update(['transportista_usuarioid' => $transportista->usuarioid]);

        // Path legacy null: no puede acreditar cantidad del pedido.
        try {
            app(RecepcionPlantaEnvioService::class)->confirmarDesdePedido(
                $pedido->fresh(['detalles', 'envioAsignacion']),
                $transportista,
                null
            );
            $this->fail('Transportista no debe confirmar recepción sin pesaje.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('jefe de planta', $e->getMessage());
        }

        // Jefe tampoco puede omitir cantidades.
        try {
            app(RecepcionPlantaEnvioService::class)->confirmarDesdePedido(
                $pedido->fresh(['detalles', 'envioAsignacion']),
                $jefe,
                null
            );
            $this->fail('Jefe no debe confirmar sin mapa de cantidades.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('pesaje', mb_strtolower($e->getMessage()));
        }

        // Finalizar cierre logístico sin pesaje previo no acredita stock.
        $envio->update([
            'llegada_confirmada_at' => now(),
            'transportista_usuarioid' => $transportista->usuarioid,
        ]);

        try {
            app(\App\Services\CierreEnvioAgricolaService::class)->finalizarEntrega(
                $envio->fresh(),
                $transportista
            );
            $this->fail('Finalizar sin pesaje no debe acreditar recepción.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(
                str_contains(mb_strtolower($e->getMessage()), 'pesaje')
                || str_contains(mb_strtolower($e->getMessage()), 'complete')
            );
        }

        $envio->refresh();
        $this->assertNull($envio->fecha_recepcion_planta);
        $this->assertSame(0, AlmacenMovimiento::query()->where('referencia', $envio->externo_envio_id)->count());

        // Simulación agrícola tampoco marca recibido_planta.
        app(\App\Services\SimulacionRutaService::class)->completarAgricola($envio->fresh());
        $envio->refresh();
        $this->assertNull($envio->fecha_recepcion_planta);
        $this->assertNotSame('recibido_planta', $envio->estado);
        $this->assertNotNull($envio->llegada_confirmada_at);
    }

    // ─── PLT-FUNC-01 ──────────────────────────────────────────────────────

    private function prepararLoteConRuta(Usuario $jefe, int $pasos = 3): array
    {
        $pedido = Pedido::create([
            'numero_solicitud' => 'E2-PLT-'.uniqid(),
            'nombre_planta' => 'P',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-E2-'.uniqid(),
            'nombre' => 'Lote E2',
            'producto' => 'X',
            'fecha_creacion' => now()->toDateString(),
        ]);

        $defs = [
            ['Preparación de Materias Primas', 'L-100'],
            ['Mezclado', 'MX-200'],
            ['Extrusión', 'EX-300'],
        ];

        $rutaPasos = [];
        for ($i = 0; $i < $pasos; $i++) {
            [$procNombre, $maqCodigo] = $defs[$i];
            $proceso = ProcesoPlanta::query()->firstOrCreate(
                ['nombre' => $procNombre],
                ['activo' => true]
            );
            $maquina = MaquinaPlanta::query()->firstOrCreate(
                ['codigo' => $maqCodigo],
                ['nombre' => 'Máquina '.$maqCodigo, 'activo' => true]
            );
            $rutaPasos[] = LoteProduccionRutaPaso::create([
                'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
                'orden' => $i + 1,
                'procesoplantaid' => $proceso->procesoplantaid,
                'maquinaplantaid' => $maquina->maquinaplantaid,
            ]);
        }

        return compact('lote', 'rutaPasos', 'pedido');
    }

    public function test_plt01_asignar_todas_conserva_orden_sin_completar(): void
    {
        $jefe = $this->createUser('jefe_planta', 'AsigJ');
        $opA = $this->createUser('planta', 'AsigA', (int) $jefe->usuarioid);
        $opB = $this->createUser('planta', 'AsigB', (int) $jefe->usuarioid);
        ['lote' => $lote, 'rutaPasos' => $pasos] = $this->prepararLoteConRuta($jefe, 3);

        $svc = app(AsignacionEtapaPlantaService::class);
        $svc->asignarTodasPendientesAOperario($lote, (int) $opA->usuarioid, $jefe);

        $asignaciones = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->activas()
            ->orderBy('orden')
            ->get();

        $this->assertCount(3, $asignaciones);
        $this->assertSame([1, 2, 3], $asignaciones->pluck('orden')->map(fn ($o) => (int) $o)->all());
        $this->assertTrue($asignaciones->every(fn ($a) => (int) $a->operador_usuarioid === (int) $opA->usuarioid));
        $this->assertTrue($asignaciones->every(fn ($a) => $a->estado !== AsignacionEtapaPlanta::ESTADO_COMPLETADA));
        $this->assertSame(AsignacionEtapaPlanta::ESTADO_PENDIENTE, $asignaciones[0]->estado);
        $this->assertSame(AsignacionEtapaPlanta::ESTADO_PROGRAMADA, $asignaciones[1]->estado);
        $this->assertSame(AsignacionEtapaPlanta::ESTADO_PROGRAMADA, $asignaciones[2]->estado);

        $this->expectException(\InvalidArgumentException::class);
        $svc->completar($asignaciones[0]->fresh(), [
            'hora_inicio' => '08:00',
            'hora_fin' => '09:00',
            'parametros' => [],
        ], $opB);
    }

    public function test_plt01_completadas_no_reasignan_e_individual_sigue(): void
    {
        $jefe = $this->createUser('jefe_planta', 'AsigJ2');
        $opA = $this->createUser('planta', 'AsigA2', (int) $jefe->usuarioid);
        $opC = $this->createUser('planta', 'AsigC2', (int) $jefe->usuarioid);
        ['lote' => $lote, 'rutaPasos' => $pasos] = $this->prepararLoteConRuta($jefe, 3);

        // Primera etapa ya completada (no debe reasignarse)
        AsignacionEtapaPlanta::create([
            'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
            'loteproduccionrutapasoid' => $pasos[0]->loteproduccionrutapasoid,
            'orden' => 1,
            'procesoplantaid' => $pasos[0]->procesoplantaid,
            'maquinaplantaid' => $pasos[0]->maquinaplantaid,
            'operador_usuarioid' => $opA->usuarioid,
            'asignado_por_usuarioid' => $jefe->usuarioid,
            'estado' => AsignacionEtapaPlanta::ESTADO_COMPLETADA,
            'completada_en' => now(),
            'creado_en' => now(),
        ]);

        // Simular registro de transformación para que etapasCompletadasCount = 1
        // Si el conteo viene de asignaciones completadas / registros, etapasSinAsignar
        // excluye orden <= completados. Forzar vía registro si hace falta.
        $svc = app(AsignacionEtapaPlantaService::class);

        // Si el servicio considera completados por transformacion, solo pasos 2-3 pendientes
        $pendientes = $svc->etapasSinAsignar($lote->fresh());
        // Al menos no debe incluir el paso 1 si está asignado (activo o el filtro de ids)
        $idsPend = $pendientes->pluck('loteproduccionrutapasoid')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $pasos[0]->loteproduccionrutapasoid, $idsPend);

        if ($pendientes->isNotEmpty()) {
            $svc->asignarTodasPendientesAOperario($lote->fresh(), (int) $opC->usuarioid, $jefe);
        }

        $paso1Asig = AsignacionEtapaPlanta::query()
            ->where('loteproduccionrutapasoid', $pasos[0]->loteproduccionrutapasoid)
            ->where('estado', AsignacionEtapaPlanta::ESTADO_COMPLETADA)
            ->first();
        $this->assertSame((int) $opA->usuarioid, (int) $paso1Asig->operador_usuarioid);

        // Asignación individual de una etapa restante si quedara alguna
        $lote2Pedido = Pedido::create([
            'numero_solicitud' => 'E2-IND-1',
            'nombre_planta' => 'P',
            'latitud' => -17.7,
            'longitud' => -63.1,
            'estado' => 'en produccion',
        ]);
        $lote2 = LoteProduccionPedido::create([
            'pedidoid' => $lote2Pedido->pedidoid,
            'codigo_lote' => 'LP-E2-IND',
            'nombre' => 'Lote Ind',
            'producto' => 'Y',
            'fecha_creacion' => now()->toDateString(),
        ]);
        $proceso = ProcesoPlanta::query()->firstOrCreate(
            ['nombre' => 'Preparación de Materias Primas'],
            ['activo' => true]
        );
        $maquina = MaquinaPlanta::query()->firstOrCreate(
            ['codigo' => 'L-100'],
            ['nombre' => 'Máquina L-100', 'activo' => true]
        );
        $pasoInd = LoteProduccionRutaPaso::create([
            'loteproduccionpedidoid' => $lote2->loteproduccionpedidoid,
            'orden' => 1,
            'procesoplantaid' => $proceso->procesoplantaid,
            'maquinaplantaid' => $maquina->maquinaplantaid,
        ]);

        $svc->cerrarFase($lote2, [
            'loteproduccionrutapasoid' => (int) $pasoInd->loteproduccionrutapasoid,
            'operador_usuarioid' => (int) $opA->usuarioid,
        ], $jefe);

        $ind = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote2->loteproduccionpedidoid)
            ->activas()
            ->first();
        $this->assertNotNull($ind);
        $this->assertSame((int) $opA->usuarioid, (int) $ind->operador_usuarioid);
        $this->assertSame(AsignacionEtapaPlanta::ESTADO_PENDIENTE, $ind->estado);
    }

    public function test_plt01_http_asignar_todas_pendientes(): void
    {
        $jefe = $this->createUser('jefe_planta', 'HttpJ');
        $op = $this->createUser('planta', 'HttpOp', (int) $jefe->usuarioid);
        ['lote' => $lote] = $this->prepararLoteConRuta($jefe, 2);

        $this->actingAs($jefe)->post(route('procesamiento.asignar-todas-pendientes', $lote), [
            'operador_usuarioid' => $op->usuarioid,
        ])->assertRedirect(route('procesamiento.show', $lote));

        $this->assertSame(
            2,
            AsignacionEtapaPlanta::query()
                ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
                ->activas()
                ->where('operador_usuarioid', $op->usuarioid)
                ->count()
        );
    }
}
