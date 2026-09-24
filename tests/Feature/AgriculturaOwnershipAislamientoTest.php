<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\Cultivo;
use App\Models\EstadoLoteTipo;
use App\Models\Lote;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Support\AlmacenAmbito;
use App\Support\LoteAcceso;
use App\Support\LoteTrazabilidadService;
use Database\Seeders\CatalogosOperacionAgricolaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TEST-01 — Jefe Agricultor A ≠ B (AGR-01, AGR-02, AGR-03).
 */
class AgriculturaOwnershipAislamientoTest extends TestCase
{
    use RefreshDatabase;

    private function createJefe(string $suffix): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate('jefe_agricultor', 'web');

        $user = Usuario::create([
            'nombre' => 'Jefe',
            'apellido' => $suffix,
            'email' => "jefe.{$suffix}@test.local",
            'nombreusuario' => "jefe_{$suffix}",
            'passwordhash' => Hash::make('secret123'),
            'role' => 'jefe_agricultor',
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);
        $user->syncRoles([$role->name]);

        return $user;
    }

    private function crearLote(Usuario $responsable): Lote
    {
        $this->seed(CatalogosOperacionAgricolaSeeder::class);
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

    private function crearAlmacenAgricola(Usuario $responsable, string $nombre): Almacen
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
            $payload['ambito'] = AlmacenAmbito::AGRICOLA;
        }
        if (Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            $payload['responsable_usuarioid'] = $responsable->usuarioid;
        }

        return Almacen::create($payload);
    }

    public function test_jefe_a_no_gestiona_lote_de_jefe_b(): void
    {
        $jefeA = $this->createJefe('A');
        $jefeB = $this->createJefe('B');
        $loteB = $this->crearLote($jefeB);

        $this->assertTrue(LoteAcceso::puedeGestionar($jefeB, $loteB));
        $this->assertFalse(LoteAcceso::puedeGestionar($jefeA, $loteB));
        $this->assertFalse(LoteAcceso::puedeVer($jefeA, $loteB));
    }

    public function test_jefe_a_no_puede_registrar_cosecha_de_lote_b(): void
    {
        $jefeA = $this->createJefe('A');
        $jefeB = $this->createJefe('B');
        $loteB = $this->crearLote($jefeB);

        $servicio = app(LoteTrazabilidadService::class);
        $this->assertFalse($servicio->puedeUsuarioRegistrarCosecha($loteB, $jefeA));
    }

    public function test_jefe_a_no_puede_certificar_lote_b_por_post(): void
    {
        $jefeA = $this->createJefe('A');
        $jefeB = $this->createJefe('B');
        $loteB = $this->crearLote($jefeB);

        $this->actingAs($jefeA)
            ->post(route('certificaciones.store'), [
                'loteid' => $loteB->loteid,
                'resultado' => 'Certificado',
            ])
            ->assertSessionHasErrors('loteid');
    }

    public function test_jefe_a_no_abre_almacen_agricola_de_jefe_b(): void
    {
        $jefeA = $this->createJefe('A');
        $jefeB = $this->createJefe('B');
        $almacenB = $this->crearAlmacenAgricola($jefeB, 'Almacen Jefe B');

        $this->actingAs($jefeA)
            ->get(route('almacen-agricola.show', $almacenB))
            ->assertForbidden();

        $this->actingAs($jefeA)
            ->get(route('almacen-agricola.edit', $almacenB))
            ->assertForbidden();
    }

    public function test_jefe_a_si_abre_su_almacen_agricola(): void
    {
        $jefeA = $this->createJefe('A');
        $almacenA = $this->crearAlmacenAgricola($jefeA, 'Almacen Jefe A');

        $this->actingAs($jefeA)
            ->get(route('almacen-agricola.show', $almacenA))
            ->assertOk();
    }
}
