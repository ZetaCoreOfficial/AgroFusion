<?php



namespace Tests\Feature;



use App\Models\Almacen;

use App\Models\Insumo;

use App\Models\PerfilTransportista;

use App\Models\RutaDistribucion;

use App\Models\TipoInsumo;

use App\Models\UnidadMedida;

use App\Models\Usuario;

use App\Models\Vehiculo;

use App\Services\SimulacionRutaService;

use App\Services\TrasladoPlantaMayoristaService;

use App\Support\AlmacenAmbito;

use App\Support\RutaDistribucionCatalogo;

use App\Support\SimulacionRutaCatalogo;

use App\Support\TransportistaFlotaCatalogo;

use Database\Seeders\RolePermissionSeeder;

use Illuminate\Foundation\Testing\RefreshDatabase;

use Illuminate\Support\Facades\Hash;

use Spatie\Permission\Models\Role;

use Tests\TestCase;



class TrasladoPlantaMayoristaTest extends TestCase

{

    use RefreshDatabase;



    private function admin(): Usuario

    {

        $this->seed(RolePermissionSeeder::class);

        Role::findOrCreate('admin', 'web');



        $user = Usuario::create([

            'nombre' => 'Admin',

            'apellido' => 'Traslado',

            'email' => 'admin.traslado@test.local',

            'nombreusuario' => 'admin_traslado',

            'passwordhash' => Hash::make('secret'),

            'role' => 'admin',

            'fecharegistro' => now(),

            'activo' => true,

        ]);

        $user->assignRole('admin');



        return $user;

    }



    private function jefePlanta(): Usuario

    {

        $this->seed(RolePermissionSeeder::class);

        Role::findOrCreate('jefe_planta', 'web');



        $user = Usuario::create([

            'nombre' => 'Jhon',

            'apellido' => 'Rojas',

            'email' => 'jefe.planta@test.local',

            'nombreusuario' => 'jhon_rojas',

            'passwordhash' => Hash::make('secret'),

            'role' => 'jefe_planta',

            'fecharegistro' => now(),

            'activo' => true,

        ]);

        $user->assignRole('jefe_planta');



        return $user;

    }



    private function almacen(string $nombre, string $ambito, string $ubicacion): Almacen

    {

        $unidad = UnidadMedida::create([

            'nombre' => 'Kilogramo',

            'abreviatura' => 'kg',

            'activo' => true,

        ]);



        $almacen = Almacen::create([

            'nombre' => $nombre,

            'ubicacion' => $ubicacion,

            'ambito' => $ambito,

            'capacidad' => 10000,

            'unidadmedidaid' => $unidad->unidadmedidaid,

            'activo' => true,

        ]);

        // Todo destino mayorista tiene un mayorista responsable que pueda recibir (MAY-BUG-01).
        if ($ambito === AlmacenAmbito::MAYORISTA) {
            $this->mayoristaResponsable($almacen);
        }

        return $almacen->fresh();

    }



    private function mayoristaResponsable(Almacen $almacen): Usuario
    {
        Role::findOrCreate('mayorista', 'web');

        $user = Usuario::create([
            'nombre' => 'Mayorista',
            'apellido' => 'Destino',
            'email' => 'mayorista.destino'.$almacen->almacenid.'@test.local',
            'nombreusuario' => 'mayorista_destino_'.$almacen->almacenid,
            'passwordhash' => Hash::make('Password'),
            'role' => 'mayorista',
            'fecharegistro' => now(),
            'activo' => true,
        ]);
        $user->assignRole('mayorista');
        $almacen->update(['responsable_usuarioid' => $user->usuarioid]);

        return $user;
    }

    /** @return array{transportista: Usuario, vehiculo: Vehiculo} */

    private function flotaPlanta(): array

    {

        $transportista = Usuario::create([

            'nombre' => 'Chofer',

            'apellido' => 'Planta',

            'email' => 'chofer.traslado@test.local',

            'nombreusuario' => 'chofer_traslado',

            'passwordhash' => Hash::make('Password'),

            'role' => 'transportista',

            'fecharegistro' => now(),

            'activo' => true,

        ]);

        // Rol canónico Spatie sincronizado con la columna legacy (TRA-09).
        Role::findOrCreate('transportista', 'web');
        $transportista->assignRole('transportista');



        $vehiculo = Vehiculo::create([

            'placa' => 'SCZ-TPM-01',

            'marca' => 'Toyota',

            'modelo' => 'Hilux',

            'anio' => 2022,

            'activo' => true,

            'ambito_flota' => TransportistaFlotaCatalogo::PLANTA,

        ]);



        PerfilTransportista::create([

            'usuarioid' => $transportista->usuarioid,

            'ambito_flota' => TransportistaFlotaCatalogo::PLANTA,

            'vehiculoid' => $vehiculo->vehiculoid,

        ]);



        return ['transportista' => $transportista, 'vehiculo' => $vehiculo];

    }



    private function insumoPlanta(Almacen $planta, float $stock = 500): Insumo

    {

        $tipo = TipoInsumo::query()->firstOrCreate(['nombre' => 'Producto terminado']);

        $unidad = UnidadMedida::first() ?? UnidadMedida::create([

            'nombre' => 'Kilogramo',

            'abreviatura' => 'kg',

            'activo' => true,

        ]);



        return Insumo::create([

            'nombre' => 'Zanahoria procesada',

            'tipoinsumoid' => $tipo->tipoinsumoid,

            'unidadmedidaid' => $unidad->unidadmedidaid,

            'stock' => $stock,

            'stockminimo' => 10,

            'almacenid' => $planta->almacenid,

        ]);

    }



    public function test_crea_traslado_y_aparece_en_tiempo_real_al_marcar_en_ruta(): void

    {

        $admin = $this->admin();

        $planta = $this->almacen('Planta Central', AlmacenAmbito::PLANTA, 'GPS -17.78,-63.18');

        $mayorista = $this->almacen('Almacen Pirai Test', AlmacenAmbito::MAYORISTA, 'GPS -17.79,-63.19');

        $insumo = $this->insumoPlanta($planta);

        $flota = $this->flotaPlanta();



        $ruta = app(TrasladoPlantaMayoristaService::class)->crear(

            $planta,

            $mayorista,

            (int) $flota['transportista']->usuarioid,

            (int) $flota['vehiculo']->vehiculoid,

            (int) $admin->usuarioid,

            [['insumoid' => $insumo->insumoid, 'cantidad' => 100]],

            null,

            50.0

        );



        $this->assertTrue($ruta->esTrasladoPlantaMayorista());

        $this->assertSame(RutaDistribucionCatalogo::ESTADO_PENDIENTE_APROBACION, $ruta->estado);

        $this->assertCount(1, $ruta->detallesTraslado);



        // La aprobación de planta la hace el jefe de planta (el admin supervisa).
        app(TrasladoPlantaMayoristaService::class)->aceptar($ruta->fresh(), $this->jefePlanta());

        $ruta->refresh();

        $this->assertSame(RutaDistribucionCatalogo::ESTADO_PLANIFICADA, $ruta->estado);

        \App\Models\CondicionTransporte::create([
            'codigo' => 'COND_TPM',
            'titulo' => 'Luces delanteras',
            'descripcion' => 'Test',
        ]);

        app(\App\Services\CierreEnvioPlantaMayoristaService::class)->registrarCondicionesVehiculo(
            $ruta->fresh(),
            $flota['transportista'],
            true,
        );

        app(SimulacionRutaService::class)->empezarDistribucion($ruta->fresh());

        $ruta->refresh();



        $this->assertSame(RutaDistribucionCatalogo::ESTADO_EN_RUTA, $ruta->estado);

        $this->assertNotNull($ruta->simulacion_inicio_at);



        $activas = app(SimulacionRutaService::class)->listarActivas();

        $item = $activas->first(fn ($i) => ($i['tipo'] ?? '') === SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA);



        $this->assertNotNull($item);

        $this->assertSame($ruta->rutadistribucionid, $item['id']);

        $this->assertStringContainsString('Planta Central', $item['origen']);

        $this->assertStringContainsString('Pirai', $item['destino']);

    }



    public function test_admin_supervisor_no_crea_traslado_desde_formulario(): void

    {

        $admin = $this->admin();

        $planta = $this->almacen('Planta Norte', AlmacenAmbito::PLANTA, 'GPS -17.77,-63.17');

        $mayorista = $this->almacen('Mayorista Sur', AlmacenAmbito::MAYORISTA, 'GPS -17.80,-63.20');

        $insumo = $this->insumoPlanta($planta);

        $flota = $this->flotaPlanta();



        $response = $this->actingAs($admin)->post(route('logistica.traslados-planta.store'), [

            'almacen_planta_origenid' => $planta->almacenid,

            'almacen_mayorista_destinoid' => $mayorista->almacenid,

            'transportista_usuarioid' => $flota['transportista']->usuarioid,

            'vehiculoid' => $flota['vehiculo']->vehiculoid,

            'costo_bs' => 50,

            'detalles' => [

                ['insumoid' => $insumo->insumoid, 'cantidad' => 80],

            ],

        ]);



        $response->assertForbidden();

        $this->assertNull(RutaDistribucion::query()->first());

    }

    public function test_jefe_planta_puede_crear_traslado_desde_formulario(): void
    {
        $jefe = $this->jefePlanta();
        $planta = $this->almacen('Planta Jefe', AlmacenAmbito::PLANTA, 'GPS -17.77,-63.17');
        $mayorista = $this->almacen('Mayorista Jefe', AlmacenAmbito::MAYORISTA, 'GPS -17.80,-63.20');
        $insumo = $this->insumoPlanta($planta);
        $flota = $this->flotaPlanta();

        $response = $this->actingAs($jefe)->post(route('logistica.traslados-planta.store'), [
            'almacen_planta_origenid' => $planta->almacenid,
            'almacen_mayorista_destinoid' => $mayorista->almacenid,
            'transportista_usuarioid' => $flota['transportista']->usuarioid,
            'vehiculoid' => $flota['vehiculo']->vehiculoid,
            'costo_bs' => 40,
            'detalles' => [
                ['insumoid' => $insumo->insumoid, 'cantidad' => 33],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertNotNull(RutaDistribucion::query()->first());
    }

    public function test_traslado_calcula_kg_desde_presentacion_comercial(): void
    {

        $admin = $this->admin();

        $planta = $this->almacen('Planta Presentacion', AlmacenAmbito::PLANTA, 'GPS -17.77,-63.17');

        $mayorista = $this->almacen('Mayorista Presentacion', AlmacenAmbito::MAYORISTA, 'GPS -17.80,-63.20');

        $insumo = $this->insumoPlanta($planta, 500);

        $presentacion = \App\Models\InsumoPresentacion::create([

            'insumoid' => $insumo->insumoid,

            'nombre' => 'Lata 400 g',

            'tipo_envase' => 'lata',

            'peso_neto_kg' => 0.4,

            'orden' => 1,

            'activo' => true,

        ]);

        $inventario = \App\Models\InventarioPresentacionLote::create([

            'almacenid' => $planta->almacenid,

            'insumoid' => $insumo->insumoid,

            'insumo_presentacionid' => $presentacion->insumo_presentacionid,

            'referencia_lote' => 'LOTE-PRES-A',

            'cantidad_unidades' => 500,

            'cantidad_kg' => 200,

        ]);

        $flota = $this->flotaPlanta();



        $ruta = app(TrasladoPlantaMayoristaService::class)->crear(

            $planta,

            $mayorista,

            (int) $flota['transportista']->usuarioid,

            (int) $flota['vehiculo']->vehiculoid,

            (int) $admin->usuarioid,

            [[

                'insumoid' => $insumo->insumoid,

                'insumo_presentacionid' => $presentacion->insumo_presentacionid,

                'inventario_presentacion_loteid' => $inventario->inventario_presentacion_loteid,

                'cantidad_unidades' => 100,

            ]],

            null,

            50.0

        );



        $detalle = $ruta->detallesTraslado->first();

        $this->assertNotNull($detalle);

        $this->assertSame('Lata 400 g', $detalle->presentacion_nombre);

        $this->assertEquals(100.0, (float) $detalle->cantidad_unidades);

        $this->assertEquals(40.0, (float) $detalle->cantidad);

    }



    public function test_traslado_descuenta_inventario_por_lote_presentacion(): void

    {

        $admin = $this->admin();

        $planta = $this->almacen('Planta Lote', AlmacenAmbito::PLANTA, 'GPS -17.77,-63.17');

        $mayorista = $this->almacen('Mayorista Lote', AlmacenAmbito::MAYORISTA, 'GPS -17.80,-63.20');

        $insumo = $this->insumoPlanta($planta, 200);

        $presentacion = \App\Models\InsumoPresentacion::create([

            'insumoid' => $insumo->insumoid,

            'nombre' => 'Lata 400 g',

            'tipo_envase' => 'lata',

            'peso_neto_kg' => 0.4,

            'orden' => 1,

            'activo' => true,

        ]);

        $inventario = \App\Models\InventarioPresentacionLote::create([

            'almacenid' => $planta->almacenid,

            'insumoid' => $insumo->insumoid,

            'insumo_presentacionid' => $presentacion->insumo_presentacionid,

            'referencia_lote' => 'LOTE-TEST-A',

            'cantidad_unidades' => 300,

            'cantidad_kg' => 120,

        ]);

        $loteProduccion = \App\Models\LoteProduccionPedido::create([

            'pedidoid' => \App\Models\Pedido::create([

                'numero_solicitud' => 'TEST-ALM-TPM',

                'nombre_planta' => 'Planta Lote',

                'latitud' => -17.77,

                'longitud' => -63.17,

                'estado' => 'completado',

            ])->pedidoid,

            'nombre' => 'Lote test traslado',

            'codigo_lote' => 'LOTE-TEST-A',

            'cantidad_objetivo' => 300,

            'cantidad_producida' => 300,

            'fecha_creacion' => now()->toDateString(),

        ]);

        $inventario->update(['loteproduccionpedidoid' => $loteProduccion->loteproduccionpedidoid]);

        $almacenaje = \App\Models\AlmacenajeLoteProduccion::create([

            'loteproduccionpedidoid' => $loteProduccion->loteproduccionpedidoid,

            'almacenid' => $planta->almacenid,

            'ubicacion' => 'Planta Lote',

            'condicion' => 'Buena',

            'cantidad' => 300,

            'fecha_almacenaje' => now(),

        ]);

        $flota = $this->flotaPlanta();

        $service = app(TrasladoPlantaMayoristaService::class);

        $ruta = $service->crear(

            $planta,

            $mayorista,

            (int) $flota['transportista']->usuarioid,

            (int) $flota['vehiculo']->vehiculoid,

            (int) $admin->usuarioid,

            [[

                'insumoid' => $insumo->insumoid,

                'insumo_presentacionid' => $presentacion->insumo_presentacionid,

                'inventario_presentacion_loteid' => $inventario->inventario_presentacion_loteid,

                'cantidad_unidades' => 50,

            ]],

            null,

            50.0

        );

        $ruta->update(['estado' => RutaDistribucionCatalogo::ESTADO_PLANIFICADA]);

        // Sin la firma del mayorista destino no se acredita nada (MAY-08).
        try {
            $service->transferirInventarioAlCompletar($ruta->fresh(), $admin);
            $this->fail('La transferencia no debe aplicarse sin la recepción firmada por el mayorista destino.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('debe firmar la recepción', $e->getMessage());
        }
        $this->assertEquals(300.0, (float) $inventario->fresh()->cantidad_unidades);

        $receptor = Usuario::query()->findOrFail($mayorista->fresh()->responsable_usuarioid);
        \App\Models\FirmaTransportistaEnvio::create([
            'rutadistribucionid' => $ruta->rutadistribucionid,
            'imagenfirma' => 'data:image/png;base64,iVBORw0KGgo=',
            'firmante_usuarioid' => $flota['transportista']->usuarioid,
            'fechafirma' => now(),
        ]);
        \App\Models\FirmaRecepcionEnvio::create([
            'rutadistribucionid' => $ruta->rutadistribucionid,
            'imagenfirma' => 'data:image/png;base64,iVBORw0KGgo=',
            'firmante_usuarioid' => $receptor->usuarioid,
            'fechafirma' => now(),
        ]);

        $service->transferirInventarioAlCompletar($ruta->fresh(), $receptor);

        // Idempotencia: una segunda llamada no vuelve a transferir.
        try {
            $service->transferirInventarioAlCompletar($ruta->fresh(), $receptor);
            $this->fail('La transferencia del traslado no debe aplicarse dos veces.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ya fue transferido', $e->getMessage());
        }

        $inventario->refresh();

        $this->assertEquals(250.0, (float) $inventario->cantidad_unidades);

        $this->assertEquals(100.0, (float) $inventario->cantidad_kg);

        $almacenaje->refresh();

        $this->assertEquals(250.0, (float) $almacenaje->cantidad);

        $this->assertNull($almacenaje->fecha_retiro);

    }



    public function test_rechaza_traslado_si_supera_capacidad_vehiculo(): void

    {

        $admin = $this->admin();

        $planta = $this->almacen('Planta Cap', AlmacenAmbito::PLANTA, 'GPS -17.77,-63.17');

        $mayorista = $this->almacen('Mayorista Cap', AlmacenAmbito::MAYORISTA, 'GPS -17.80,-63.20');

        $insumo = $this->insumoPlanta($planta, 5000);

        $flota = $this->flotaPlanta();

        $tipoVehiculo = \App\Models\TipoVehiculo::query()->create([

            'nombre' => 'Camión test',

            'capacidad_kg' => 100,

            'capacidad_m3' => 10,

            'activo' => true,

        ]);

        $flota['vehiculo']->update(['tipovehiculoid' => $tipoVehiculo->tipovehiculoid]);

        $this->expectException(\InvalidArgumentException::class);

        app(TrasladoPlantaMayoristaService::class)->crear(

            $planta,

            $mayorista,

            (int) $flota['transportista']->usuarioid,

            (int) $flota['vehiculo']->vehiculoid,

            (int) $admin->usuarioid,

            [[

                'insumoid' => $insumo->insumoid,

                'cantidad' => 500,

            ]],

            null,

            50.0

        );

    }



    public function test_no_permite_marcar_en_ruta_sin_aprobacion_mayorista(): void

    {

        $admin = $this->admin();

        $planta = $this->almacen('Planta Bloqueo', AlmacenAmbito::PLANTA, 'GPS -17.78,-63.18');

        $mayorista = $this->almacen('Mayorista Bloqueo', AlmacenAmbito::MAYORISTA, 'GPS -17.79,-63.19');

        $insumo = $this->insumoPlanta($planta);

        $flota = $this->flotaPlanta();



        $ruta = app(TrasladoPlantaMayoristaService::class)->crear(

            $planta,

            $mayorista,

            (int) $flota['transportista']->usuarioid,

            (int) $flota['vehiculo']->vehiculoid,

            (int) $admin->usuarioid,

            [['insumoid' => $insumo->insumoid, 'cantidad' => 50]],

            null,

            40.0

        );



        // El admin supervisor no inicia transporte.
        $this->actingAs($admin)->patch(route('logistica.traslados-planta.empezar-ruta', $ruta))->assertForbidden();

        $response = $this->actingAs($this->jefePlanta())->patch(route('logistica.traslados-planta.empezar-ruta', $ruta));

        $response->assertRedirect();

        $response->assertSessionHas('error');

        $this->assertSame(RutaDistribucionCatalogo::ESTADO_PENDIENTE_APROBACION, $ruta->fresh()->estado);

    }



    public function test_jefe_planta_puede_ver_traslado_pendiente(): void

    {

        $admin = $this->admin();

        $jefe = $this->jefePlanta();

        $planta = $this->almacen('Planta Jefe', AlmacenAmbito::PLANTA, 'GPS -17.78,-63.18');

        $mayorista = $this->almacen('Mayorista Jefe', AlmacenAmbito::MAYORISTA, 'GPS -17.79,-63.19');

        $insumo = $this->insumoPlanta($planta);

        $flota = $this->flotaPlanta();



        $ruta = app(TrasladoPlantaMayoristaService::class)->crear(

            $planta,

            $mayorista,

            (int) $flota['transportista']->usuarioid,

            (int) $flota['vehiculo']->vehiculoid,

            (int) $admin->usuarioid,

            [['insumoid' => $insumo->insumoid, 'cantidad' => 16]],

            null,

            34.0

        );



        $this->actingAs($jefe)

            ->get(route('logistica.traslados-planta.show', $ruta))

            ->assertOk();

    }



    public function test_jefe_planta_puede_aprobar_traslado_pendiente(): void

    {

        $admin = $this->admin();

        $jefe = $this->jefePlanta();

        $planta = $this->almacen('Planta Aprobacion', AlmacenAmbito::PLANTA, 'GPS -17.78,-63.18');

        $mayorista = $this->almacen('Mayorista Aprobacion', AlmacenAmbito::MAYORISTA, 'GPS -17.79,-63.19');

        $insumo = $this->insumoPlanta($planta);

        $flota = $this->flotaPlanta();



        $ruta = app(TrasladoPlantaMayoristaService::class)->crear(

            $planta,

            $mayorista,

            (int) $flota['transportista']->usuarioid,

            (int) $flota['vehiculo']->vehiculoid,

            (int) $admin->usuarioid,

            [['insumoid' => $insumo->insumoid, 'cantidad' => 16]],

            null,

            34.0

        );



        $this->actingAs($jefe)

            ->patch(route('logistica.traslados-planta.aceptar', $ruta))

            ->assertRedirect(route('logistica.traslados-planta.show', $ruta));



        $this->assertSame(RutaDistribucionCatalogo::ESTADO_PLANIFICADA, $ruta->fresh()->estado);

    }

    public function test_crea_traslado_con_dos_plantas_y_productos_por_almacen(): void
    {
        $admin = $this->admin();
        $plantaA = $this->almacen('Planta Norte', AlmacenAmbito::PLANTA, 'GPS -17.78,-63.18');
        $plantaB = $this->almacen('Planta Sur', AlmacenAmbito::PLANTA, 'GPS -17.80,-63.20');
        $mayorista = $this->almacen('Mayorista Multi', AlmacenAmbito::MAYORISTA, 'GPS -17.79,-63.19');
        $insumoA = $this->insumoPlanta($plantaA, 200);
        $insumoB = Insumo::create([
            'nombre' => 'Tomate procesado',
            'tipoinsumoid' => $insumoA->tipoinsumoid,
            'unidadmedidaid' => $insumoA->unidadmedidaid,
            'stock' => 150,
            'stockminimo' => 10,
            'almacenid' => $plantaB->almacenid,
        ]);
        $flota = $this->flotaPlanta();

        $ruta = app(TrasladoPlantaMayoristaService::class)->crear(
            $plantaA,
            $mayorista,
            (int) $flota['transportista']->usuarioid,
            (int) $flota['vehiculo']->vehiculoid,
            (int) $admin->usuarioid,
            [
                ['insumoid' => $insumoA->insumoid, 'almacen_plantaid' => $plantaA->almacenid, 'cantidad' => 40],
                ['insumoid' => $insumoB->insumoid, 'almacen_plantaid' => $plantaB->almacenid, 'cantidad' => 25],
            ],
            null,
            60.0,
            [(int) $plantaB->almacenid],
        );

        $ruta->load('paradas');

        $this->assertCount(3, $ruta->paradas);
        $this->assertSame(
            RutaDistribucionCatalogo::PARADA_CARGA_PLANTA,
            $ruta->paradas->where('orden', 1)->first()?->tipo
        );
        $this->assertSame(
            RutaDistribucionCatalogo::PARADA_CARGA_PLANTA,
            $ruta->paradas->where('orden', 2)->first()?->tipo
        );
        $this->assertSame(
            RutaDistribucionCatalogo::PARADA_ENTREGA_MAYORISTA,
            $ruta->paradas->where('orden', 3)->first()?->tipo
        );
        $this->assertCount(2, $ruta->detallesTraslado);
        $this->assertStringContainsString('Planta Norte', app(TrasladoPlantaMayoristaService::class)->trayectoTexto($ruta));
        $this->assertStringContainsString('Planta Sur', app(TrasladoPlantaMayoristaService::class)->trayectoTexto($ruta));
    }

}
