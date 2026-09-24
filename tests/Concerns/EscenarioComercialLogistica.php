<?php

namespace Tests\Concerns;

use App\Models\Almacen;
use App\Models\AlmacenMovimiento;
use App\Models\Insumo;
use App\Models\PerfilTransportista;
use App\Models\PuntoVenta;
use App\Models\TipoInsumo;
use App\Models\TipoMovimientoAlmacen;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Support\AlmacenAmbito;
use App\Support\TransportistaFlotaCatalogo;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/** Actores y datos mínimos para las pruebas A ≠ B del Frente 3 (mayorista, minorista, transportista). */
trait EscenarioComercialLogistica
{
    private int $secuenciaActores = 0;

    private bool $permisosSembrados = false;

    protected function actor(string $rol, array $overrides = []): Usuario
    {
        if (! $this->permisosSembrados) {
            $this->seed(RolePermissionSeeder::class);
            $this->permisosSembrados = true;
        }

        Role::findOrCreate($rol, 'web');
        $n = ++$this->secuenciaActores;

        $usuario = Usuario::create(array_merge([
            'nombre' => ucfirst($rol),
            'apellido' => 'F3-'.$n,
            'email' => "{$rol}{$n}.f3@test.local",
            'nombreusuario' => "{$rol}{$n}_f3",
            'passwordhash' => Hash::make('secret123'),
            'role' => $rol,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ], $overrides));

        $usuario->syncRoles([$rol]);

        return $usuario;
    }

    protected function conductor(string $ambito = TransportistaFlotaCatalogo::MAYORISTA, array $overrides = []): Usuario
    {
        $usuario = $this->actor('transportista', $overrides);

        PerfilTransportista::create([
            'usuarioid' => $usuario->usuarioid,
            'ambito_flota' => $ambito,
            'tipo_licencia' => 'C',
            'licencias_json' => ['C'],
            'disponible' => true,
        ]);

        return $usuario->fresh();
    }

    protected function unidadKg(): UnidadMedida
    {
        return UnidadMedida::query()->firstOrCreate(['abreviatura' => 'kg'], ['nombre' => 'Kilogramo']);
    }

    protected function almacen(string $ambito, ?Usuario $responsable, string $nombre, float $capacidad = 5000): Almacen
    {
        return Almacen::create([
            'nombre' => $nombre,
            'ubicacion' => 'GPS -17.78'.random_int(100, 999).', -63.18'.random_int(100, 999),
            'capacidad' => $capacidad,
            'unidadmedidaid' => $this->unidadKg()->unidadmedidaid,
            'activo' => true,
            'ambito' => $ambito,
            'responsable_usuarioid' => $responsable?->usuarioid,
        ]);
    }

    protected function almacenMayorista(?Usuario $responsable, string $nombre): Almacen
    {
        return $this->almacen(AlmacenAmbito::MAYORISTA, $responsable, $nombre);
    }

    protected function productoTerminado(Almacen $almacen, string $nombre = 'Tomate empacado', float $stock = 100): Insumo
    {
        $tipo = TipoInsumo::query()->firstOrCreate(['nombre' => 'Producto terminado']);

        return Insumo::create([
            'nombre' => $nombre,
            'tipoinsumoid' => $tipo->tipoinsumoid,
            'unidadmedidaid' => $this->unidadKg()->unidadmedidaid,
            'stock' => $stock,
            'stockminimo' => 0,
            'almacenid' => $almacen->almacenid,
        ]);
    }

    protected function tipoMovimiento(string $naturaleza): TipoMovimientoAlmacen
    {
        return TipoMovimientoAlmacen::query()->firstOrCreate(
            ['nombre' => $naturaleza === 'ingreso' ? 'Ingreso de prueba' : 'Salida de prueba', 'naturaleza' => $naturaleza],
            ['activo' => true]
        );
    }

    protected function movimiento(Almacen $almacen, Insumo $insumo, Usuario $usuario, string $referencia): AlmacenMovimiento
    {
        return AlmacenMovimiento::create([
            'almacenid' => $almacen->almacenid,
            'insumoid' => $insumo->insumoid,
            'tipo_movimiento_almacenid' => $this->tipoMovimiento('ingreso')->tipo_movimiento_almacenid,
            'usuarioid' => $usuario->usuarioid,
            'fecha' => now()->toDateString(),
            'cantidad' => 5,
            'referencia' => $referencia,
            'observaciones' => 'Movimiento '.$referencia,
        ]);
    }

    protected function puntoVenta(Usuario $minorista, string $nombre): PuntoVenta
    {
        return PuntoVenta::create([
            'usuarioid' => $minorista->usuarioid,
            'nombre' => $nombre,
            'direccion' => 'Av. Prueba',
            'latitud' => -17.7801,
            'longitud' => -63.1802,
            'activo' => true,
            'fechacreacion' => now(),
        ]);
    }
}
