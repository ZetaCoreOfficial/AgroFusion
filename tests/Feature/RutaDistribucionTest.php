<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\DetallePedidoDistribucion;
use App\Models\Insumo;
use App\Models\PedidoDistribucion;
use App\Models\PerfilTransportista;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Models\TipoInsumo;
use App\Models\UnidadMedida;
use App\Models\Vehiculo;
use App\Services\DistribucionRutaService;
use App\Support\AlmacenAmbito;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RutaDistribucionTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAdmin(): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate('admin', 'web');

        $user = Usuario::create([
            'nombre' => 'Admin',
            'apellido' => 'Ruta',
            'email' => 'admin.ruta@test.local',
            'nombreusuario' => 'admin_ruta',
            'passwordhash' => Hash::make('secret'),
            'role' => 'admin',
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_admin_supervisor_no_planifica_distribucion(): void
    {
        $user = $this->usuarioAdmin();
        $this->actingAs($user);

        $this->get(route('punto-venta.rutas.index'))->assertForbidden();
        $this->get(route('punto-venta.rutas.create'))->assertForbidden();
        $this->post(route('punto-venta.rutas.store'), [])->assertForbidden();
    }

    public function test_servicio_crea_ruta_con_pedidos_confirmados(): void
    {
        $user = $this->usuarioAdmin();

        $unidad = UnidadMedida::create(['nombre' => 'Kilogramo', 'abreviatura' => 'kg']);

        $almacenMayorista = Almacen::create([
            'nombre' => 'Almacén Mayorista Test',
            'ubicacion' => 'GPS -17.79420, -63.16150',
            'capacidad' => 1000,
            'unidadmedidaid' => $unidad->unidadmedidaid,
            'activo' => true,
            'ambito' => AlmacenAmbito::MAYORISTA,
        ]);

        $almacenPlanta = Almacen::create([
            'nombre' => 'Almacén Planta Test',
            'ubicacion' => 'GPS -17.79420, -63.16150',
            'capacidad' => 1000,
            'unidadmedidaid' => $unidad->unidadmedidaid,
            'activo' => true,
            'ambito' => AlmacenAmbito::PLANTA,
        ]);

        $chofer = Usuario::create([
            'nombre' => 'Chofer',
            'apellido' => 'Planta',
            'email' => 'chofer.planta@test.local',
            'nombreusuario' => 'chofer_planta',
            'passwordhash' => Hash::make('secret'),
            'role' => 'transportista',
            'fecharegistro' => now(),
            'activo' => true,
        ]);

        $vehiculo = Vehiculo::create([
            'placa' => 'PDV-001',
            'marca' => 'Toyota',
            'modelo' => 'Hilux',
            'activo' => true,
            // Conductor y vehículo de la misma flota (TRA-04).
            'ambito_flota' => TransportistaFlotaCatalogo::MAYORISTA,
        ]);

        PerfilTransportista::create([
            'usuarioid' => $chofer->usuarioid,
            'ambito_flota' => TransportistaFlotaCatalogo::MAYORISTA,
            'vehiculoid' => $vehiculo->vehiculoid,
            'disponible' => true,
        ]);

        $minorista = Usuario::create([
            'nombre' => 'Min',
            'apellido' => 'Test',
            'email' => 'minorista@test.local',
            'nombreusuario' => 'minorista_test',
            'passwordhash' => Hash::make('secret'),
            'role' => 'minorista',
            'fecharegistro' => now(),
            'activo' => true,
        ]);

        $pdv = PuntoVenta::create([
            'usuarioid' => $minorista->usuarioid,
            'nombre' => 'PDV Centro',
            'direccion' => 'Av. Test',
            'latitud' => -17.78,
            'longitud' => -63.18,
            'activo' => true,
        ]);

        $tipo = TipoInsumo::create(['nombre' => 'Producto terminado']);

        $insumo = Insumo::create([
            'nombre' => 'Producto PDV',
            'stock' => 100,
            'tipoinsumoid' => $tipo->tipoinsumoid,
            'unidadmedidaid' => $unidad->unidadmedidaid,
            'almacenid' => $almacenMayorista->almacenid,
        ]);

        $pedido = PedidoDistribucion::create([
            'numero_solicitud' => 'PDV-TEST-0001',
            'puntoventaid' => $pdv->puntoventaid,
            'almacen_planta_origenid' => $almacenPlanta->almacenid,
            'almacen_mayorista_origenid' => $almacenMayorista->almacenid,
            'estado' => PedidoDistribucionCatalogo::ESTADO_CONFIRMADO,
            'fechapedido' => now(),
            'fecha_aceptacion' => now(),
            'aceptado_por_usuarioid' => $user->usuarioid,
        ]);

        DetallePedidoDistribucion::create([
            'pedidodistribucionid' => $pedido->pedidodistribucionid,
            'insumoid' => $insumo->insumoid,
            'producto_nombre' => $insumo->nombre,
            'cantidad' => 10,
        ]);

        // El admin ya no planifica por HTTP; se valida el servicio de dominio directamente.
        $this->actingAs($user)->post(route('punto-venta.rutas.store'), [
            'almacen_mayorista_origenid' => $almacenMayorista->almacenid,
            'transportista_usuarioid' => $chofer->usuarioid,
            'vehiculoid' => $vehiculo->vehiculoid,
            'costo_bs' => 250.50,
            'pedidos' => [$pedido->pedidodistribucionid],
        ])->assertForbidden();

        app(DistribucionRutaService::class)->crear(
            $almacenMayorista,
            [$pedido->pedidodistribucionid],
            (int) $chofer->usuarioid,
            (int) $vehiculo->vehiculoid,
            (int) $minorista->usuarioid,
            null,
            250.50
        );

        $pedido->refresh();
        $this->assertNotNull($pedido->rutadistribucionid);
        $ruta = \App\Models\RutaDistribucion::query()->find($pedido->rutadistribucionid);
        $this->assertSame($almacenMayorista->almacenid, $ruta?->almacen_mayorista_origenid);
        $this->assertSame(PedidoDistribucionCatalogo::ESTADO_CONFIRMADO, $pedido->estado);
    }
}
