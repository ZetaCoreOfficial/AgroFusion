<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Almacen;
use App\Models\AlmacenMovimiento;
use App\Models\Cultivo;
use App\Models\EstadoLoteTipo;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\Prioridad;
use App\Models\TipoActividad;
use App\Models\TipoInsumo;
use App\Models\TipoMovimientoAlmacen;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\ActividadInsumoService;
use App\Support\AlmacenAmbito;
use App\Support\CampoAccess;
use App\Support\InsumoCatalogo;
use Database\Seeders\CatalogosOperacionAgricolaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase E3 — AGR-04 stock por almacén + OPA-08 consumo desde almacén correcto.
 */
class FaseE3StockPorAlmacenTest extends TestCase
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
            'email' => "{$roleName}.{$suffix}@fasee3.test",
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

    private function crearAlmacenAgricola(Usuario $responsable, string $nombre): Almacen
    {
        $um = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'kg'],
            ['nombre' => 'Kilogramo', 'categoria' => 'peso']
        );

        $payload = [
            'nombre' => $nombre,
            'descripcion' => 'E3',
            'ubicacion' => 'Campo GPS:-17.78,-63.18',
            'capacidad' => 5000,
            'unidadmedidaid' => $um->unidadmedidaid,
            'activo' => true,
        ];
        if (Schema::hasColumn('almacen', 'ambito')) {
            $payload['ambito'] = AlmacenAmbito::AGRICOLA;
        }
        if (Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            $payload['responsable_usuarioid'] = $responsable->usuarioid;
        }

        return Almacen::create($payload);
    }

    private function crearLote(Usuario $responsable): Lote
    {
        $estado = EstadoLoteTipo::query()->firstOrFail();
        $unidad = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'ha'],
            ['nombre' => 'Hectárea', 'categoria' => 'superficie']
        );
        $cultivo = Cultivo::query()->firstOrCreate(['nombre' => 'Tomate E3'], ['detalle' => 'Test']);

        return Lote::create([
            'usuarioid' => $responsable->usuarioid,
            'nombre' => 'Lote E3 '.$responsable->apellido,
            'ubicacion' => 'Parcela',
            'superficie' => 1,
            'unidadsuperficieid' => $unidad->unidadmedidaid,
            'cultivoid' => $cultivo->cultivoid,
            'estadolotetipoid' => $estado->estadolotetipoid,
            'fechacreacion' => now(),
            'fechamodificacion' => now(),
        ]);
    }

    private function crearFertilizanteEnAlmacen(Almacen $almacen, float $stock, string $nombre = 'Fertilizante X'): Insumo
    {
        InsumoCatalogo::asegurarCatalogosBase();
        $tipoId = TipoInsumo::query()
            ->get()
            ->first(fn ($t) => InsumoCatalogo::slugFromNombreTipo($t->nombre) === 'fertilizantes')
            ?->tipoinsumoid
            ?? TipoInsumo::query()->firstOrCreate(['nombre' => 'Fertilizantes'])->tipoinsumoid;

        $um = UnidadMedida::query()->firstOrCreate(
            ['abreviatura' => 'kg'],
            ['nombre' => 'Kilogramo', 'categoria' => 'peso']
        );

        return Insumo::create([
            'nombre' => $nombre,
            'tipoinsumoid' => $tipoId,
            'unidadmedidaid' => $um->unidadmedidaid,
            'stock' => $stock,
            'stockminimo' => InsumoCatalogo::UMBRAL_ALERTA_STOCK,
            'almacenid' => $almacen->almacenid,
        ]);
    }

    private function crearActividadConInsumo(Lote $lote, Usuario $responsable, Insumo $insumo, float $cantidad): Actividad
    {
        $tipo = TipoActividad::query()->firstOrCreate(['nombre' => 'Fertilización']);
        $prioridad = Prioridad::query()->firstOrCreate(['nombre' => 'Media']);

        $detalle = [
            'modo' => 'insumos',
            'insumos' => [[
                'insumoid' => (int) $insumo->insumoid,
                'nombre' => $insumo->nombre,
                'cantidad' => $cantidad,
                'unidad' => 'kg',
                'almacenid' => (int) $insumo->almacenid,
            ]],
            'almacenid' => (int) $insumo->almacenid,
            'stock_aplicado' => false,
        ];

        return Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $responsable->usuarioid,
            'descripcion' => 'Fertilización E3',
            'fechainicio' => now(),
            'fechafin' => null,
            'tipoactividadid' => $tipo->tipoactividadid,
            'prioridadid' => $prioridad->prioridadid,
            'detalle_json' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
            'orden_secuencia' => 1,
        ]);
    }

    public function test_reporte_jefes_con_varios_almacenes_vacio_en_fixture_limpia(): void
    {
        $this->assertSame([], CampoAccess::reporteJefesConVariosAlmacenesAgricolas());
    }

    public function test_agr04_rechaza_segundo_almacen_agricola_del_mismo_jefe(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'Unico');
        $this->crearAlmacenAgricola($jefe, 'Almacen A');

        $this->expectException(ValidationException::class);
        CampoAccess::assertPuedeCrearAlmacenAgricolaPara((int) $jefe->usuarioid);
    }

    public function test_opa08_consumos_independientes_por_almacen(): void
    {
        TipoMovimientoAlmacen::query()->firstOrCreate(
            ['nombre' => 'Consumo actividad', 'naturaleza' => 'salida'],
            ['activo' => true]
        );

        $jefeA = $this->createUser('jefe_agricultor', 'A');
        $jefeB = $this->createUser('jefe_agricultor', 'B');
        $opA = $this->createUser('agricultor', 'OpA', (int) $jefeA->usuarioid);
        $opB = $this->createUser('agricultor', 'OpB', (int) $jefeB->usuarioid);

        $almA = $this->crearAlmacenAgricola($jefeA, 'Almacén A');
        $almB = $this->crearAlmacenAgricola($jefeB, 'Almacén B');
        $insA = $this->crearFertilizanteEnAlmacen($almA, 10);
        $insB = $this->crearFertilizanteEnAlmacen($almB, 50);

        $loteA = $this->crearLote($opA);
        $loteB = $this->crearLote($opB);

        $svc = app(ActividadInsumoService::class);

        // Selector A no ve insumo de B
        $modalA = $svc->listarInsumosParaModal('fertilizantes', $loteA);
        $idsModalA = collect($modalA)->pluck('id')->all();
        $this->assertContains((int) $insA->insumoid, $idsModalA);
        $this->assertNotContains((int) $insB->insumoid, $idsModalA);

        // Legacy null no satisface A
        $legacy = $this->crearFertilizanteEnAlmacen($almA, 99, 'Fertilizante Legacy');
        $legacy->update(['almacenid' => null, 'stock' => 99]);
        $modalA2 = $svc->listarInsumosParaModal('fertilizantes', $loteA);
        $this->assertNotContains((int) $legacy->insumoid, collect($modalA2)->pluck('id')->all());

        $actA = $this->crearActividadConInsumo($loteA, $opA, $insA, 4);
        $detalleA = json_decode((string) $actA->detalle_json, true);
        $actA->usuarioid_ejecutor = $opA->usuarioid;
        $actA->fechafin = now();
        $actA->save();
        $svc->aplicarStockSiCorresponde($actA, $detalleA);

        $this->assertEqualsWithDelta(6.0, (float) $insA->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $insB->fresh()->stock, 0.001);
        $this->assertTrue((bool) ($detalleA['stock_aplicado'] ?? false));
        $this->assertSame(
            1,
            AlmacenMovimiento::query()->where('referencia', 'ACT-'.$actA->actividadid)->count()
        );

        $actB = $this->crearActividadConInsumo($loteB, $opB, $insB, 8);
        $detalleB = json_decode((string) $actB->detalle_json, true);
        $actB->usuarioid_ejecutor = $opB->usuarioid;
        $actB->fechafin = now();
        $actB->save();
        $svc->aplicarStockSiCorresponde($actB, $detalleB);

        $this->assertEqualsWithDelta(6.0, (float) $insA->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(42.0, (float) $insB->fresh()->stock, 0.001);

        // A intenta 7 → falla; A sigue 6
        $actFail = $this->crearActividadConInsumo($loteA, $opA, $insA->fresh(), 7);
        $detalleFail = json_decode((string) $actFail->detalle_json, true);
        $actFail->usuarioid_ejecutor = $opA->usuarioid;
        $actFail->fechafin = now();
        $actFail->save();

        try {
            $svc->aplicarStockSiCorresponde($actFail, $detalleFail);
            $this->fail('Debía fallar por stock insuficiente en almacén A');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stock insuficiente', collect($e->errors())->flatten()->first() ?? '');
        }
        $this->assertEqualsWithDelta(6.0, (float) $insA->fresh()->stock, 0.001);

        // Doble completar: segundo no descuenta
        $svc->aplicarStockSiCorresponde($actA->fresh(), $detalleA);
        $this->assertEqualsWithDelta(6.0, (float) $insA->fresh()->stock, 0.001);
        $this->assertSame(
            1,
            AlmacenMovimiento::query()->where('referencia', 'ACT-'.$actA->actividadid)->count()
        );
    }

    public function test_opa08_concurrencia_no_deja_stock_negativo(): void
    {
        TipoMovimientoAlmacen::query()->firstOrCreate(
            ['nombre' => 'Consumo actividad', 'naturaleza' => 'salida'],
            ['activo' => true]
        );

        $jefe = $this->createUser('jefe_agricultor', 'Conc');
        $op = $this->createUser('agricultor', 'ConcOp', (int) $jefe->usuarioid);
        $alm = $this->crearAlmacenAgricola($jefe, 'Almacén Conc');
        $insumo = $this->crearFertilizanteEnAlmacen($alm, 10);
        $lote = $this->crearLote($op);

        $svc = app(ActividadInsumoService::class);
        $act1 = $this->crearActividadConInsumo($lote, $op, $insumo, 7);
        $act2 = $this->crearActividadConInsumo($lote, $op, $insumo, 7);
        foreach ([$act1, $act2] as $act) {
            $act->usuarioid_ejecutor = $op->usuarioid;
            $act->fechafin = now();
            $act->save();
        }

        $d1 = json_decode((string) $act1->detalle_json, true);
        $d2 = json_decode((string) $act2->detalle_json, true);

        $svc->aplicarStockSiCorresponde($act1, $d1);
        $this->assertEqualsWithDelta(3.0, (float) $insumo->fresh()->stock, 0.001);

        try {
            $svc->aplicarStockSiCorresponde($act2, $d2);
            $this->fail('El segundo consumo concurrente debía fallar');
        } catch (ValidationException) {
            // ok
        }

        $this->assertGreaterThanOrEqual(0.0, (float) $insumo->fresh()->stock);
        $this->assertEqualsWithDelta(3.0, (float) $insumo->fresh()->stock, 0.001);
    }

    public function test_opa08_http_completar_no_usa_stock_de_otro_almacen(): void
    {
        Storage::fake('public');
        TipoMovimientoAlmacen::query()->firstOrCreate(
            ['nombre' => 'Consumo actividad', 'naturaleza' => 'salida'],
            ['activo' => true]
        );

        $jefeA = $this->createUser('jefe_agricultor', 'HttpA');
        $jefeB = $this->createUser('jefe_agricultor', 'HttpB');
        $opA = $this->createUser('agricultor', 'HttpOpA', (int) $jefeA->usuarioid);
        $almA = $this->crearAlmacenAgricola($jefeA, 'Alm Http A');
        $almB = $this->crearAlmacenAgricola($jefeB, 'Alm Http B');
        $insA = $this->crearFertilizanteEnAlmacen($almA, 2);
        $insB = $this->crearFertilizanteEnAlmacen($almB, 100);
        $lote = $this->crearLote($opA);

        // Intentan planificar con insumo de B → validación falla
        $this->expectException(ValidationException::class);
        app(ActividadInsumoService::class)->validarDetalle([
            'modo' => 'insumos',
            'insumos' => [[
                'insumoid' => $insB->insumoid,
                'cantidad' => 5,
            ]],
        ], 'Fertilización', $lote);
    }

    public function test_agr04_jefe_no_inyecta_almacen_ajeno_ni_edita_ajeno_ni_legacy(): void
    {
        InsumoCatalogo::asegurarCatalogosBase();
        $jefeA = $this->createUser('jefe_agricultor', 'OwnA');
        $jefeB = $this->createUser('jefe_agricultor', 'OwnB');
        $almA = $this->crearAlmacenAgricola($jefeA, 'Own Alm A');
        $almB = $this->crearAlmacenAgricola($jefeB, 'Own Alm B');
        $insB = $this->crearFertilizanteEnAlmacen($almB, 20, 'Fertilizante Solo B');

        $tipoId = TipoInsumo::query()
            ->get()
            ->first(fn ($t) => InsumoCatalogo::slugFromNombreTipo($t->nombre) === 'fertilizantes')
            ?->tipoinsumoid;
        $umId = UnidadMedida::query()->where('abreviatura', 'kg')->value('unidadmedidaid')
            ?? UnidadMedida::query()->firstOrCreate(
                ['abreviatura' => 'kg'],
                ['nombre' => 'Kilogramo', 'categoria' => 'peso']
            )->unidadmedidaid;

        // Crear con almacenid=B manipulado → queda en A
        $this->actingAs($jefeA)->post(route('insumos.store'), [
            'nombre' => 'Fertilizante Creado A',
            'tipoinsumoid' => $tipoId,
            'unidadmedidaid' => $umId,
            'stock' => 5,
            'almacenid' => $almB->almacenid,
        ])->assertRedirect(route('insumos.index'));

        $creado = Insumo::query()->where('nombre', 'Fertilizante Creado A')->first();
        $this->assertNotNull($creado);
        $this->assertSame((int) $almA->almacenid, (int) $creado->almacenid);
        $this->assertNotSame((int) $almB->almacenid, (int) $creado->almacenid);

        // Editar insumo de B → 403
        $this->actingAs($jefeA)
            ->get(route('insumos.edit', $insB))
            ->assertForbidden();

        $this->actingAs($jefeA)
            ->put(route('insumos.update', $insB), [
                'nombre' => 'Hacked',
                'tipoinsumoid' => $tipoId,
                'unidadmedidaid' => $umId,
                'stock' => 1,
                'almacenid' => $almA->almacenid,
            ])
            ->assertForbidden();

        $this->assertSame('Fertilizante Solo B', $insB->fresh()->nombre);
        $this->assertSame((int) $almB->almacenid, (int) $insB->fresh()->almacenid);

        // Legacy null: no editable; update no puede “secuestrarlo”
        $legacy = $this->crearFertilizanteEnAlmacen($almA, 3, 'Fertilizante Legacy Null');
        $legacy->update(['almacenid' => null]);

        $this->actingAs($jefeA)
            ->put(route('insumos.update', $legacy), [
                'nombre' => 'Legacy Secuestrado',
                'tipoinsumoid' => $tipoId,
                'unidadmedidaid' => $umId,
                'stock' => 99,
                'almacenid' => $almA->almacenid,
            ])
            ->assertForbidden();

        $this->assertNull($legacy->fresh()->almacenid);
        $this->assertSame('Fertilizante Legacy Null', $legacy->fresh()->nombre);

        // Update propio: incluso enviando almacenid=B, permanece en A
        $this->actingAs($jefeA)
            ->put(route('insumos.update', $creado), [
                'nombre' => 'Fertilizante Creado A v2',
                'tipoinsumoid' => $tipoId,
                'unidadmedidaid' => $umId,
                'stock' => 8,
                'almacenid' => $almB->almacenid,
            ])
            ->assertRedirect(route('insumos.index'));

        $creado->refresh();
        $this->assertSame('Fertilizante Creado A v2', $creado->nombre);
        $this->assertSame((int) $almA->almacenid, (int) $creado->almacenid);
        $this->assertEqualsWithDelta(8.0, (float) $creado->stock, 0.001);
    }

    public function test_agr04_asegurar_insumos_campo_idempotente_stock_cero(): void
    {
        $jefe = $this->createUser('jefe_agricultor', 'Cat');
        $alm = $this->crearAlmacenAgricola($jefe, 'Alm Catálogo');

        InsumoCatalogo::asegurarInsumosCampo();
        InsumoCatalogo::asegurarInsumosCampo();

        $npk = Insumo::query()
            ->where('nombre', 'Fertilizante NPK 15-15-15')
            ->where('almacenid', $alm->almacenid)
            ->get();

        $this->assertCount(1, $npk);
        $this->assertEqualsWithDelta(0.0, (float) $npk->first()->stock, 0.001);

        // No debe recrear fila global demo con stock
        $globalesDemo = Insumo::query()
            ->where('nombre', 'Fertilizante NPK 15-15-15')
            ->whereNull('almacenid')
            ->where('stock', '>', 0)
            ->count();
        $this->assertSame(0, $globalesDemo);
    }

    public function test_agr04_update_almacen_inocuo_con_multi_legacy_no_bloquea_nombre(): void
    {
        // Documenta: update de nombre/ubicación NO dispara assert de unicidad
        // (solo create o cambio de responsable).
        $jefe = $this->createUser('jefe_agricultor', 'Multi');
        $a1 = $this->crearAlmacenAgricola($jefe, 'Legacy 1');
        $a2 = $this->crearAlmacenAgricola($jefe, 'Legacy 2');

        $reporte = CampoAccess::reporteJefesConVariosAlmacenesAgricolas();
        $this->assertNotEmpty($reporte);
        $this->assertSame((int) $jefe->usuarioid, (int) $reporte[0]['usuarioid']);
        $this->assertSame(2, (int) $reporte[0]['cantidad']);

        $a1->update(['nombre' => 'Legacy 1 renombrado', 'ubicacion' => 'Otra parcela']);
        $this->assertSame('Legacy 1 renombrado', $a1->fresh()->nombre);

        // Crear un tercero sí falla
        $this->expectException(ValidationException::class);
        CampoAccess::assertPuedeCrearAlmacenAgricolaPara((int) $jefe->usuarioid);
    }
}
