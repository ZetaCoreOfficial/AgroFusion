<?php

namespace Tests\Feature;

use App\Models\MaquinaPlanta;
use App\Models\PlantillaTransformacion;
use App\Models\ProcesoPlanta;
use App\Models\Usuario;
use App\Models\VariableEstandar;
use App\Support\CatalogoTecnicoPlantaAcceso;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase D — JPL-07, OPP-01, OPP-09.
 *
 * Catálogos técnicos son GLOBALES (sin FK de planta). Scope = rol gestionaPlanta;
 * no se inventa ownership A≠B.
 */
class FaseDCatalogosPlantaPermisosTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $roleName, string $suffix): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');

        $user = Usuario::create([
            'nombre' => 'Test',
            'apellido' => $suffix,
            'email' => "{$roleName}.{$suffix}@fased.test",
            'nombreusuario' => "{$roleName}_{$suffix}",
            'passwordhash' => Hash::make('secret123'),
            'role' => $roleName,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);
        $user->syncRoles([$role->name]);

        return $user;
    }

    private function crearMaquina(string $nombre): MaquinaPlanta
    {
        return MaquinaPlanta::create([
            'nombre' => $nombre,
            'codigo' => 'T-'.substr(md5($nombre), 0, 6),
            'descripcion' => 'Test',
            'activo' => true,
        ]);
    }

    public function test_catalogos_tecnicos_son_globales_sin_fk_planta(): void
    {
        foreach ([
            MaquinaPlanta::class,
            ProcesoPlanta::class,
            PlantillaTransformacion::class,
            VariableEstandar::class,
        ] as $model) {
            $table = (new $model)->getTable();
            $this->assertFalse(Schema::hasColumn($table, 'almacenid'), "{$table} no debe tener almacenid");
            $this->assertFalse(Schema::hasColumn($table, 'responsable_usuarioid'), "{$table} sin responsable");
            $this->assertFalse(Schema::hasColumn($table, 'plantaid'), "{$table} sin plantaid");
        }

        $this->assertTrue(CatalogoTecnicoPlantaAcceso::puedeGestionar($this->createUser('jefe_planta', 'Doc')));
        $this->assertFalse(CatalogoTecnicoPlantaAcceso::puedeGestionar($this->createUser('planta', 'DocOp')));
    }

    public function test_operario_planta_post_put_delete_maquina_403(): void
    {
        $op = $this->createUser('planta', 'MaqOp');
        $maquina = $this->crearMaquina('Lavadora Op');

        $this->actingAs($op)->post(route('maquinas-planta.store'), [
            'nombre' => 'Nueva',
            'codigo' => 'NEW-1',
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($op)->put(route('maquinas-planta.update', $maquina), [
            'nombre' => 'Hack',
            'codigo' => $maquina->codigo,
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($op)->delete(route('maquinas-planta.destroy', $maquina))->assertForbidden();

        $this->actingAs($op)->patch(route('maquinas-planta.toggle-activo', $maquina))->assertForbidden();
    }

    public function test_operario_planta_crud_proceso_plantilla_variable_403(): void
    {
        $op = $this->createUser('planta', 'CatOp');

        $proceso = ProcesoPlanta::create(['nombre' => 'Lavado', 'descripcion' => 't', 'activo' => true]);
        $variable = VariableEstandar::create([
            'codigo' => 'TEMP',
            'nombre' => 'Temperatura',
            'unidad' => 'C',
            'activo' => true,
        ]);
        $plantilla = PlantillaTransformacion::create([
            'nombre' => 'Plantilla test',
            'descripcion' => 't',
            'activo' => true,
        ]);

        $this->actingAs($op)->post(route('procesos-planta.store'), [
            'nombre' => 'X',
            'activo' => 1,
        ])->assertForbidden();
        $this->actingAs($op)->put(route('procesos-planta.update', $proceso), [
            'nombre' => 'Y',
            'activo' => 1,
        ])->assertForbidden();
        $this->actingAs($op)->delete(route('procesos-planta.destroy', $proceso))->assertForbidden();

        $this->actingAs($op)->post(route('plantillas-transformacion.store'), [
            'nombre' => 'P',
            'activo' => 1,
            'pasos' => [],
        ])->assertForbidden();
        $this->actingAs($op)->put(route('plantillas-transformacion.update', $plantilla), [
            'nombre' => 'P2',
            'activo' => 1,
            'pasos' => [],
        ])->assertForbidden();
        $this->actingAs($op)->delete(route('plantillas-transformacion.destroy', $plantilla))->assertForbidden();

        $this->actingAs($op)->post(route('variables-estandar.store'), [
            'codigo' => 'P',
            'nombre' => 'Presion',
            'unidad' => 'bar',
            'activo' => 1,
        ])->assertForbidden();
        $this->actingAs($op)->put(route('variables-estandar.update', $variable), [
            'codigo' => 'TEMP',
            'nombre' => 'Hack',
            'unidad' => 'C',
            'activo' => 1,
        ])->assertForbidden();
        $this->actingAs($op)->delete(route('variables-estandar.destroy', $variable))->assertForbidden();

        $this->actingAs($op)->get(route('maquinas-planta.index'))->assertForbidden();
        $this->actingAs($op)->get(route('procesos-planta.index'))->assertForbidden();
        $this->actingAs($op)->get(route('produccion-planta.catalogos.index', 'tipos-empaque'))->assertForbidden();
    }

    public function test_jefe_planta_administra_catalogo_global_ok(): void
    {
        $jefe = $this->createUser('jefe_planta', 'MaqJefe');

        $this->actingAs($jefe)->post(route('maquinas-planta.store'), [
            'nombre' => 'Cortadora Jefe',
            'codigo' => 'CJ-01',
            'descripcion' => 'ok',
            'activo' => 1,
        ])->assertRedirect();

        $maquina = MaquinaPlanta::query()->where('codigo', 'CJ-01')->firstOrFail();

        $this->actingAs($jefe)->put(route('maquinas-planta.update', $maquina), [
            'nombre' => 'Cortadora Edit',
            'codigo' => 'CJ-01',
            'descripcion' => 'edit',
            'activo' => 1,
        ])->assertRedirect();

        $this->actingAs($jefe)->get(route('maquinas-planta.index'))->assertOk();
        $this->actingAs($jefe)->get(route('procesos-planta.index'))->assertOk();
    }

    /**
     * Ownership A≠B no aplica: ambos jefes administran el mismo catálogo global.
     * Documentado — no se inventa scope por planta.
     */
    public function test_jefe_a_y_jefe_b_comparten_catalogo_global_documentado(): void
    {
        $jefeA = $this->createUser('jefe_planta', 'CatA');
        $jefeB = $this->createUser('jefe_planta', 'CatB');
        $maquina = $this->crearMaquina('Compartida');

        $this->actingAs($jefeA)->put(route('maquinas-planta.update', $maquina), [
            'nombre' => 'Editada por A',
            'codigo' => $maquina->codigo,
            'activo' => 1,
        ])->assertRedirect();

        $this->actingAs($jefeB)->put(route('maquinas-planta.update', $maquina), [
            'nombre' => 'Editada por B',
            'codigo' => $maquina->codigo,
            'activo' => 1,
        ])->assertRedirect();

        $this->assertSame('Editada por B', $maquina->fresh()->nombre);
    }

    public function test_opp09_operario_sin_pedidos_distribucion(): void
    {
        $op = $this->createUser('planta', 'DistOp');
        $jefe = $this->createUser('jefe_planta', 'DistJefe');

        $this->assertFalse($op->can('pedidos_distribucion.view'));
        $this->assertFalse($op->can('pedidos_distribucion.update'));
        $this->assertTrue($jefe->can('pedidos_distribucion.view'));
        $this->assertTrue($jefe->can('pedidos_distribucion.update'));

        $this->actingAs($op)->get(route('punto-venta.pedidos.index'))->assertForbidden();
    }
}
