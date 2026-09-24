<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\PedidoDistribucion;
use App\Models\RutaDistribucion;
use App\Models\RutaDistribucionParada;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\RutaDistribucionCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use App\Support\UbicacionGpsParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DistribucionRutaService
{
    /**
     * Envío directo mayorista → PDV con una o más paradas de carga mayorista.
     *
     * @param  array<int, Almacen>  $almacenesOrigen  Orden de recogida
     */
    public function crearEnvioDirectoPedido(
        array $almacenesOrigen,
        PedidoDistribucion $pedido,
        int $transportistaId,
        ?int $vehiculoId,
        int $creadoPorId,
        ?string $nombre = null,
        ?float $costoBs = null
    ): RutaDistribucion {
        if ($almacenesOrigen === []) {
            throw new InvalidArgumentException('El pedido no tiene almacenes mayorista de origen.');
        }

        return $this->crear(
            $almacenesOrigen[0],
            [$pedido->pedidodistribucionid],
            $transportistaId,
            $vehiculoId,
            $creadoPorId,
            $nombre,
            $costoBs,
            array_slice($almacenesOrigen, 1)
        );
    }

    /**
     * @param  array<int>  $pedidoIds  Orden de visita a PDV
     * @param  array<int, Almacen>  $almacenesRecogidaExtra  Recogidas mayorista adicionales
     */
    public function crear(
        Almacen $almacenOrigen,
        array $pedidoIds,
        int $transportistaId,
        ?int $vehiculoId,
        int $creadoPorId,
        ?string $nombre = null,
        ?float $costoBs = null,
        array $almacenesRecogidaExtra = []
    ): RutaDistribucion {
        if ($pedidoIds === []) {
            throw new InvalidArgumentException('Seleccione al menos un pedido para la ruta.');
        }

        $pedidos = PedidoDistribucion::query()
            ->with(['puntoVenta.minorista', 'detalles'])
            ->whereIn('pedidodistribucionid', $pedidoIds)
            ->get();

        if ($pedidos->count() !== count($pedidoIds)) {
            throw new InvalidArgumentException('Uno o más pedidos no existen.');
        }

        foreach ($pedidos as $pedido) {
            if ($pedido->estado !== PedidoDistribucionCatalogo::ESTADO_CONFIRMADO) {
                throw new InvalidArgumentException("El pedido {$pedido->numero_solicitud} no está listo para distribución.");
            }
            if ($pedido->rutadistribucionid !== null) {
                throw new InvalidArgumentException("El pedido {$pedido->numero_solicitud} ya está asignado a otra ruta.");
            }
            if ($pedido->puntoVenta === null) {
                throw new InvalidArgumentException("El pedido {$pedido->numero_solicitud} no tiene punto de venta.");
            }
            if ($pedido->puntoVenta->latitud === null || $pedido->puntoVenta->longitud === null) {
                throw new InvalidArgumentException("El punto «{$pedido->puntoVenta->nombre}» no tiene ubicación GPS.");
            }
        }

        $ordenPedidos = collect($pedidoIds)
            ->map(fn (int $id) => $pedidos->firstWhere('pedidodistribucionid', $id))
            ->filter()
            ->values();

        if ($vehiculoId) {
            $vehiculo = \App\Models\Vehiculo::query()
                ->with('tipoVehiculo')
                ->where('vehiculoid', $vehiculoId)
                ->where('activo', true)
                ->first();

            if (! $vehiculo) {
                throw new InvalidArgumentException('El vehículo seleccionado no está disponible.');
            }

            $transportista = \App\Models\Usuario::query()
                ->where('usuarioid', $transportistaId)
                ->where('role', 'transportista')
                ->where('activo', true)
                ->first();

            if (! $transportista) {
                throw new InvalidArgumentException('El transportista seleccionado no está disponible.');
            }

            $capacidad = app(TransporteCapacidadService::class);
            $capacidad->validarAsignacion($transportista, $vehiculo);
            $capacidad->validarCarga(
                $vehiculo,
                $capacidad->pesoPedidosDistribucion($ordenPedidos),
                $capacidad->volumenPedidosDistribucion($ordenPedidos)
            );
        }

        $almacenesCarga = array_merge([$almacenOrigen], $almacenesRecogidaExtra);

        return DB::transaction(function () use (
            $almacenOrigen,
            $almacenesCarga,
            $ordenPedidos,
            $transportistaId,
            $vehiculoId,
            $creadoPorId,
            $nombre,
            $costoBs
        ) {
            $etiquetaOrigenes = collect($almacenesCarga)->pluck('nombre')->implode(' + ');

            $ruta = RutaDistribucion::create([
                'codigo' => RutaDistribucionCatalogo::generarCodigo(),
                'nombre' => $nombre ?: 'Distribución '.$etiquetaOrigenes,
                'tipo_ruta' => RutaDistribucionCatalogo::TIPO_RUTA_MAYORISTA_PDV,
                'almacen_mayorista_origenid' => $almacenOrigen->almacenid,
                'transportista_usuarioid' => $transportistaId,
                'vehiculoid' => $vehiculoId,
                'costo_bs' => $costoBs !== null ? round($costoBs, 2) : null,
                'creado_por_usuarioid' => $creadoPorId,
                'estado' => RutaDistribucionCatalogo::ESTADO_PLANIFICADA,
                'fecha_salida' => null,
            ]);

            $orden = 1;
            foreach ($almacenesCarga as $alm) {
                $coords = UbicacionGpsParser::resolverAlmacen(
                    (int) $alm->almacenid,
                    $alm->nombre,
                    $alm->ubicacion
                );

                if ($coords === null || $coords['lat'] === null || $coords['lng'] === null) {
                    throw new InvalidArgumentException('Cada almacén mayorista debe tener ubicación GPS: '.$alm->nombre);
                }

                RutaDistribucionParada::create([
                    'rutadistribucionid' => $ruta->rutadistribucionid,
                    'orden' => $orden,
                    'tipo' => RutaDistribucionCatalogo::PARADA_CARGA_MAYORISTA,
                    'almacenid' => $alm->almacenid,
                    'destino' => 'Carga: '.$alm->nombre,
                    'latitud' => $coords['lat'],
                    'longitud' => $coords['lng'],
                    'estado' => 'completada',
                ]);
                $orden++;
            }

            foreach ($ordenPedidos as $pedido) {
                /** @var PedidoDistribucion $pedido */
                $pdv = $pedido->puntoVenta;

                RutaDistribucionParada::create([
                    'rutadistribucionid' => $ruta->rutadistribucionid,
                    'orden' => $orden++,
                    'tipo' => RutaDistribucionCatalogo::PARADA_ENTREGA_PDV,
                    'puntoventaid' => $pdv->puntoventaid,
                    'pedidodistribucionid' => $pedido->pedidodistribucionid,
                    'destino' => 'Entrega: '.$pdv->nombre,
                    'latitud' => (float) $pdv->latitud,
                    'longitud' => (float) $pdv->longitud,
                    'estado' => 'pendiente',
                ]);

                $pedido->update([
                    'rutadistribucionid' => $ruta->rutadistribucionid,
                ]);
            }

            return $ruta->load(['paradas', 'pedidos.puntoVenta', 'transportista', 'vehiculo', 'almacenOrigen']);
        });
    }

    /** @return array{origen: string, destinos: array<int, string>}|null */
    public function trayectoPartes(RutaDistribucion $ruta): ?array
    {
        $ruta->loadMissing(['paradas', 'almacenOrigen']);

        $cargas = $ruta->paradas
            ->filter(fn (RutaDistribucionParada $p) => in_array($p->tipo, [
                RutaDistribucionCatalogo::PARADA_CARGA_PLANTA,
                RutaDistribucionCatalogo::PARADA_CARGA_MAYORISTA,
            ], true))
            ->sortBy('orden')
            ->map(fn (RutaDistribucionParada $p) => $this->nombreParada($p))
            ->values()
            ->all();

        $entregas = $ruta->paradas
            ->filter(fn (RutaDistribucionParada $p) => $p->tipo === RutaDistribucionCatalogo::PARADA_ENTREGA_PDV)
            ->sortBy('orden')
            ->map(fn (RutaDistribucionParada $p) => $this->nombreParada($p))
            ->values()
            ->all();

        $origen = $cargas !== []
            ? (count($cargas) === 1 ? $cargas[0] : implode(' + ', $cargas))
            : ($ruta->almacenOrigen?->nombre);

        if ($origen === null && $entregas === []) {
            return null;
        }

        return [
            'origen' => $origen ?? 'Origen',
            'destinos' => $entregas,
        ];
    }

    public function trayectoTexto(RutaDistribucion $ruta): ?string
    {
        $partes = $this->trayectoPartes($ruta);
        if ($partes === null) {
            return null;
        }

        $destinos = $partes['destinos'];
        if ($destinos === []) {
            return $partes['origen'];
        }

        $textoDestinos = count($destinos) === 1
            ? $destinos[0]
            : implode(' → ', $destinos);

        return $partes['origen'].' a '.$textoDestinos;
    }

    /**
     * @return array<int, array{lat: float, lng: float, orden: int, label: string, tipo: string}>
     */
    public function paradasMapa(RutaDistribucion $ruta): array
    {
        $ruta->loadMissing('paradas');

        return $ruta->paradas
            ->sortBy('orden')
            ->map(fn (RutaDistribucionParada $p) => [
                'lat' => (float) $p->latitud,
                'lng' => (float) $p->longitud,
                'orden' => (int) $p->orden,
                'label' => $this->nombreParada($p),
                'tipo' => $p->tipo,
            ])
            ->filter(fn (array $punto) => $punto['lat'] && $punto['lng'])
            ->values()
            ->all();
    }

    /** @return Collection<int, PedidoDistribucion> */
    public function pedidosListosParaRuta(): Collection
    {
        return PedidoDistribucion::query()
            ->with(['puntoVenta.minorista', 'detalles.insumo.unidadMedida', 'almacenPlantaOrigen'])
            ->where('estado', PedidoDistribucionCatalogo::ESTADO_CONFIRMADO)
            ->whereNull('rutadistribucionid')
            ->orderByDesc('fecha_aceptacion')
            ->get();
    }

    private function nombreParada(RutaDistribucionParada $parada): string
    {
        $texto = trim((string) $parada->destino);
        if (str_starts_with($texto, 'Carga:')) {
            return trim(substr($texto, 6));
        }
        if (str_starts_with($texto, 'Entrega:')) {
            return trim(substr($texto, 8));
        }

        return $texto !== '' ? $texto : 'Parada';
    }

    /** Corrige rutas «en ruta» cuyos pedidos ya terminaron (recibidos, rechazados o cancelados). */
    public function sincronizarEstadosRutasActivas(): int
    {
        $actualizadas = 0;

        RutaDistribucion::query()
            ->where('estado', RutaDistribucionCatalogo::ESTADO_EN_RUTA)
            ->with('pedidos')
            ->orderBy('rutadistribucionid')
            ->each(function (RutaDistribucion $ruta) use (&$actualizadas) {
                if ($this->sincronizarEstadoRuta($ruta)) {
                    $actualizadas++;
                }
            });

        return $actualizadas;
    }

    public function sincronizarEstadoRuta(RutaDistribucion $ruta): bool
    {
        if ($ruta->estado !== RutaDistribucionCatalogo::ESTADO_EN_RUTA) {
            return false;
        }

        $ruta->loadMissing('pedidos');
        $pedidos = $ruta->pedidos;

        if ($pedidos->isEmpty()) {
            $ruta->update(['estado' => RutaDistribucionCatalogo::ESTADO_COMPLETADA]);

            return true;
        }

        $terminales = [
            PedidoDistribucionCatalogo::ESTADO_RECIBIDO,
            PedidoDistribucionCatalogo::ESTADO_RECHAZADO,
            PedidoDistribucionCatalogo::ESTADO_CANCELADO,
        ];

        $todosTerminales = $pedidos->every(
            fn (PedidoDistribucion $pedido) => in_array($pedido->estado, $terminales, true)
        );

        if (! $todosTerminales) {
            return false;
        }

        DB::transaction(function () use ($ruta) {
            $ruta->update(['estado' => RutaDistribucionCatalogo::ESTADO_COMPLETADA]);
            $ruta->paradas()
                ->where('tipo', RutaDistribucionCatalogo::PARADA_ENTREGA_PDV)
                ->update(['estado' => 'completada']);
        });

        return true;
    }

    /** Pool mayorista (MAY-17): sin ámbito por defecto, solo perfiles de flota mayorista explícita. */
    public function asegurarTransportistaMayorista(int $transportistaId): void
    {
        \App\Support\TransportistaPool::asegurarAsignable(
            \App\Models\Usuario::query()->find($transportistaId),
            TransportistaFlotaCatalogo::MAYORISTA
        );
    }

    public function asegurarTransportistaPlanta(int $transportistaId): void
    {
        \App\Support\TransportistaPool::asegurarAsignable(
            \App\Models\Usuario::query()->find($transportistaId),
            TransportistaFlotaCatalogo::PLANTA
        );
    }
}
