<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\AlmacenMovimiento;
use App\Models\EnvioAsignacionMultiple;
use App\Models\Insumo;
use App\Models\Pedido;
use App\Models\TipoMovimientoAlmacen;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Support\AlmacenAmbito;
use App\Support\EnvioAsignacionEstadoCatalogo;
use App\Support\InsumoCatalogo;
use App\Support\PlantaAccess;
use App\Support\UsuarioRol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecepcionPlantaEnvioService
{
    public function confirmar(
        EnvioAsignacionMultiple $asignacion,
        Usuario $usuario,
        int $almacenid,
        int $insumoid,
        float $cantidad,
        ?string $observaciones = null
    ): void {
        if (! in_array($asignacion->estado, ['en_transporte_planta', 'en_ruta', 'en_transito'], true)) {
            throw new \InvalidArgumentException('El envío debe estar en transporte hacia planta para confirmar la recepción.');
        }

        if ($asignacion->fecha_recepcion_planta) {
            throw new \InvalidArgumentException('Este envío ya fue confirmado en planta.');
        }

        $almacen = Almacen::query()
            ->whereKey($almacenid)
            ->where('activo', true)
            ->firstOrFail();

        if (($almacen->ambito ?? AlmacenAmbito::PLANTA) !== AlmacenAmbito::PLANTA) {
            throw new \InvalidArgumentException('Debe seleccionar un almacén de planta.');
        }

        $insumo = Insumo::query()->findOrFail($insumoid);
        if ((int) $insumo->almacenid !== (int) $almacenid) {
            $insumo->almacenid = $almacenid;
            $insumo->save();
        }

        $tipoIngreso = $this->tipoMovimientoIngresoRecepcion();

        DB::transaction(function () use ($asignacion, $usuario, $almacen, $insumo, $cantidad, $observaciones, $tipoIngreso) {
            $ahora = now();

            AlmacenMovimiento::create([
                'almacenid' => $almacen->almacenid,
                'insumoid' => $insumo->insumoid,
                'tipo_movimiento_almacenid' => $tipoIngreso->tipo_movimiento_almacenid,
                'usuarioid' => $usuario->usuarioid,
                'fecha' => $ahora->toDateString(),
                'cantidad' => $cantidad,
                'referencia' => $asignacion->externo_envio_id,
                'destino_motivo' => $almacen->nombre,
                'observaciones' => '[Recepción planta — '.$asignacion->externo_envio_id.'] '.($observaciones ?? ''),
            ]);

            $insumo->incrementarStock($cantidad);

            $asignacion->update(EnvioAsignacionEstadoCatalogo::applyToAttributes([
                'estado' => 'recibido_planta',
                'almacenid' => $almacen->almacenid,
                'fecha_recepcion_planta' => $ahora,
                'recepcion_usuarioid' => $usuario->usuarioid,
            ]));
        });
    }

    /**
     * Confirma recepción en planta con pesaje (JPL-08).
     *
     * @param  array<int, float>  $cantidadesRecibidas  mapa detallepedidoid => kg recibidos (obligatorio).
     *        No hay path legacy sin pesaje: transportista/cierre/simulación no pueden acreditar stock.
     */
    public function confirmarDesdePedido(Pedido $pedido, Usuario $usuario, ?array $cantidadesRecibidas = null): void
    {
        $pedido->load(['detalles.insumo', 'envioAsignacion']);
        $asignacion = $pedido->envioAsignacion;

        if ($asignacion === null) {
            throw new \InvalidArgumentException('El pedido no tiene envío registrado.');
        }

        if ($asignacion->fecha_recepcion_planta) {
            throw new \InvalidArgumentException('Este envío ya fue confirmado en planta.');
        }

        if (! in_array($asignacion->estado, ['en_transporte_planta', 'en_ruta', 'en_transito'], true)) {
            throw new \InvalidArgumentException('El envío debe estar en transporte hacia planta para confirmar la recepción.');
        }

        if ($pedido->detalles->isEmpty()) {
            throw new \InvalidArgumentException('El pedido no tiene productos.');
        }

        if (! UsuarioRol::puedeConfirmarRecepcionPlanta($usuario)) {
            throw new \InvalidArgumentException('Solo el jefe de planta puede confirmar la recepción con pesaje.');
        }

        if ($cantidadesRecibidas === null || $cantidadesRecibidas === []) {
            throw new \InvalidArgumentException(
                'Debe indicar la cantidad recibida (pesaje) para cada producto.'
            );
        }

        $almacen = $this->resolverAlmacenPlantaDesdePedido($pedido);

        if (! UsuarioRol::esAdminGlobal($usuario)
            && ! PlantaAccess::puedeGestionarAlmacen($usuario, $almacen)) {
            throw new \InvalidArgumentException('No puede confirmar recepción en un almacén de otra planta.');
        }

        $tipoIngreso = $this->tipoMovimientoIngresoRecepcion();
        $numeroSolicitud = (string) $pedido->numero_solicitud;

        DB::transaction(function () use (
            $asignacion,
            $usuario,
            $almacen,
            $pedido,
            $tipoIngreso,
            $numeroSolicitud,
            $cantidadesRecibidas
        ) {
            $ahora = now();

            foreach ($pedido->detalles as $detalle) {
                $enviada = (float) $detalle->cantidad;
                $detalleId = (int) $detalle->detallepedidoid;

                if (! array_key_exists($detalleId, $cantidadesRecibidas)) {
                    throw new \InvalidArgumentException(
                        'Debe indicar la cantidad recibida (pesaje) para cada producto.'
                    );
                }
                $cantidad = (float) $cantidadesRecibidas[$detalleId];
                if ($cantidad <= 0) {
                    throw new \InvalidArgumentException('La cantidad recibida debe ser mayor que 0.');
                }

                $producto = trim((string) ($detalle->cultivo_personalizado ?? $detalle->insumo?->nombre ?? ''));
                if ($producto === '') {
                    $producto = 'Cosecha recibida de pedido';
                }

                $insumo = $this->resolverInsumoEnAlmacen($almacen, $producto);
                if ($insumo === null && $detalle->insumoid) {
                    $ref = Insumo::query()->find($detalle->insumoid);
                    if ($ref !== null) {
                        $insumo = $this->resolverInsumoEnAlmacen($almacen, $ref->nombre);
                    }
                }
                if ($insumo === null || (int) $insumo->almacenid !== (int) $almacen->almacenid) {
                    $insumo = $this->crearInsumoRecepcionEnAlmacen($almacen, $producto, $numeroSolicitud, $detalle->insumo);
                } else {
                    $this->aplicarDetalleRecepcionPedido($insumo, $numeroSolicitud);
                }

                $obs = '[Recepción planta — '.$asignacion->externo_envio_id.'] '
                    .$producto.' · '.$this->textoRecepcionPedido($numeroSolicitud)
                    .' · enviado '.$enviada.' kg · recibido '.$cantidad.' kg';
                if (abs($cantidad - $enviada) > 0.0001) {
                    $diff = $cantidad - $enviada;
                    $obs .= ' · discrepancia '.($diff > 0 ? '+' : '').round($diff, 2).' kg';
                }

                AlmacenMovimiento::create([
                    'almacenid' => $almacen->almacenid,
                    'insumoid' => $insumo->insumoid,
                    'tipo_movimiento_almacenid' => $tipoIngreso->tipo_movimiento_almacenid,
                    'usuarioid' => $usuario->usuarioid,
                    'fecha' => $ahora->toDateString(),
                    'cantidad' => $cantidad,
                    'referencia' => $asignacion->externo_envio_id,
                    'destino_motivo' => $almacen->nombre,
                    'observaciones' => $obs,
                ]);

                $insumo->incrementarStock($cantidad);
            }

            $asignacion->update(EnvioAsignacionEstadoCatalogo::applyToAttributes([
                'estado' => 'recibido_planta',
                'almacenid' => $almacen->almacenid,
                'fecha_recepcion_planta' => $ahora,
                'recepcion_usuarioid' => $usuario->usuarioid,
            ]));
        });
    }

    private function resolverAlmacenPlantaDesdePedido(Pedido $pedido): Almacen
    {
        $texto = (string) ($pedido->direccion_texto ?? '');
        $nombre = trim(explode('·', $texto)[0]);
        $nombre = trim(explode('GPS', $nombre)[0]);

        $query = AlmacenAmbito::scope(
            Almacen::query()->where('activo', true),
            AlmacenAmbito::PLANTA
        );

        if ($nombre === '') {
            throw new \InvalidArgumentException(
                'No se pudo determinar el almacén de planta destino del pedido. Indique un destino inequívoco.'
            );
        }

        $coincidencias = (clone $query)->where('nombre', 'like', '%'.$nombre.'%')->get();

        if ($coincidencias->count() === 1) {
            return $coincidencias->first();
        }

        if ($coincidencias->count() > 1) {
            throw new \InvalidArgumentException(
                'Hay varios almacenes de planta que coinciden con «'.$nombre.'». Especifique el destino exacto.'
            );
        }

        throw new \InvalidArgumentException(
            'No se encontró un almacén de planta destino para «'.$nombre.'».'
        );
    }

    private function crearInsumoRecepcionEnAlmacen(
        Almacen $almacen,
        string $nombreProducto,
        string $numeroSolicitud,
        ?Insumo $referencia = null
    ): Insumo {
        InsumoCatalogo::asegurarCatalogosBase();

        $tipoId = $referencia?->tipoinsumoid
            ?? InsumoCatalogo::tiposOrdenados()->firstWhere(
                fn ($t) => InsumoCatalogo::slugFromNombreTipo($t->nombre) === 'material_siembra'
            )?->tipoinsumoid
            ?? InsumoCatalogo::tiposOrdenados()->first()?->tipoinsumoid;

        $unidadId = $referencia?->unidadmedidaid
            ?? UnidadMedida::query()->where('nombre', 'Kilogramo')->value('unidadmedidaid')
            ?? UnidadMedida::query()->value('unidadmedidaid');

        return Insumo::query()->create([
            'nombre' => $nombreProducto,
            'tipoinsumoid' => (int) $tipoId,
            'unidadmedidaid' => (int) $unidadId,
            'stock' => 0,
            'stockminimo' => InsumoCatalogo::UMBRAL_ALERTA_STOCK,
            'almacenid' => $almacen->almacenid,
            'descripcion' => $this->textoRecepcionPedido($numeroSolicitud),
        ]);
    }

    private function textoRecepcionPedido(string $numeroSolicitud, ?\DateTimeInterface $fecha = null): string
    {
        $fecha = $fecha ?? now();

        return 'Recepción pedido '.$numeroSolicitud.' · '.$fecha->format('d/m/Y');
    }

    private function aplicarDetalleRecepcionPedido(Insumo $insumo, string $numeroSolicitud): void
    {
        if (! $this->debeActualizarDetalleRecepcionPedido($insumo)) {
            return;
        }

        $insumo->update([
            'descripcion' => $this->textoRecepcionPedido($numeroSolicitud),
        ]);
    }

    private function debeActualizarDetalleRecepcionPedido(Insumo $insumo): bool
    {
        $actual = trim((string) $insumo->descripcion);
        if ($actual === '') {
            return true;
        }

        $lower = Str::lower($actual);

        return Str::contains($lower, 'creado automáticamente')
            || Str::contains($lower, 'confirmar llegada de pedido');
    }

    /**
     * Corrige insumos que quedaron con el detalle automático anterior.
     */
    public function corregirDetallesRecepcionLegacy(): int
    {
        $actualizados = 0;

        Insumo::query()
            ->where(function ($query) {
                $query->where('descripcion', 'like', '%automáticamente%')
                    ->orWhere('descripcion', 'like', '%confirmar llegada%');
            })
            ->each(function (Insumo $insumo) use (&$actualizados) {
                $movimiento = AlmacenMovimiento::query()
                    ->where('insumoid', $insumo->insumoid)
                    ->where('observaciones', 'like', '%Recepción planta%')
                    ->orderByDesc('fecha')
                    ->first();

                if ($movimiento === null || $movimiento->referencia === null) {
                    return;
                }

                $asignacion = EnvioAsignacionMultiple::query()
                    ->where('externo_envio_id', $movimiento->referencia)
                    ->with('pedido')
                    ->first();

                $pedido = $asignacion?->pedido;
                if ($pedido === null) {
                    return;
                }

                $fecha = $asignacion->fecha_recepcion_planta ?? $movimiento->fecha;
                $insumo->update([
                    'descripcion' => $this->textoRecepcionPedido($pedido->numero_solicitud, $fecha),
                ]);
                $actualizados++;
            });

        return $actualizados;
    }

    /**
     * @return array{cantidad: float, producto: ?string}
     */
    public function sugerenciaDesdeEnvio(EnvioAsignacionMultiple $asignacion): array
    {
        $detalles = is_array($asignacion->detalles_productos) ? $asignacion->detalles_productos : [];
        $primero = $detalles[0] ?? [];

        $cantidad = (float) ($primero['cantidad'] ?? $primero['peso_kg'] ?? $primero['peso'] ?? 0);
        $producto = (string) ($primero['producto'] ?? $primero['nombre'] ?? $primero['cultivo'] ?? '');

        return [
            'cantidad' => $cantidad > 0 ? $cantidad : 0,
            'producto' => $producto !== '' ? $producto : null,
        ];
    }

    public function resolverInsumoEnAlmacen(Almacen $almacen, ?string $nombreProducto): ?Insumo
    {
        if ($nombreProducto === null || trim($nombreProducto) === '') {
            return null;
        }

        InsumoCatalogo::asegurarCatalogosBase();
        $needle = Str::lower(trim($nombreProducto));

        return Insumo::query()
            ->where('almacenid', $almacen->almacenid)
            ->whereIn('tipoinsumoid', InsumoCatalogo::tiposValidosIds())
            ->get()
            ->first(function (Insumo $insumo) use ($needle) {
                $nombre = Str::lower($insumo->nombre);

                return Str::contains($nombre, $needle) || Str::contains($needle, $nombre);
            });
    }

    private function tipoMovimientoIngresoRecepcion(): TipoMovimientoAlmacen
    {
        $tipo = TipoMovimientoAlmacen::query()
            ->where('naturaleza', 'ingreso')
            ->where('activo', true)
            ->get()
            ->first(fn (TipoMovimientoAlmacen $t) => in_array(
                TipoMovimientoAlmacen::normalizeNombre($t->nombre),
                ['produccion recibida', 'producción recibida', 'compra', 'ajuste positivo'],
                true
            ));

        return $tipo ?? TipoMovimientoAlmacen::activosPorNaturaleza('ingreso')->firstOrFail();
    }
}
