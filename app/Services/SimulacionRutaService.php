<?php

namespace App\Services;

use App\Models\EnvioAsignacionMultiple;
use App\Models\PedidoDistribucion;
use App\Models\RutaDistribucion;
use App\Models\RutaDistribucionParada;
use App\Models\Usuario;
use App\Support\EnvioAsignacionEstadoCatalogo;
use App\Support\EnvioPedidoService;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\RutaDistribucionCatalogo;
use App\Support\RutaPorCallesService;
use App\Support\EnvioEstadoRecepcionCatalogo;
use App\Support\SimulacionRutaCatalogo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SimulacionRutaService
{
    public function __construct(
        private readonly RutaPorCallesService $rutasCalles,
        private readonly DistribucionRutaService $distribucion,
    ) {}

    public function empezarAgricola(EnvioAsignacionMultiple $envio): void
    {
        $envio->loadMissing(['pedido', 'ruta.paradas']);
        $this->asegurarSinOtroViajeEnCurso((int) $envio->transportista_usuarioid, $envio, null);

        if (! SimulacionRutaCatalogo::puedeEmpezarAgricola($envio)) {
            throw new InvalidArgumentException('Este envío no está listo para iniciar la ruta.');
        }

        if (! app(CierreEnvioAgricolaService::class)->tieneCondicionesVehiculo($envio)) {
            throw new InvalidArgumentException('Debe registrar las condiciones del vehículo antes de marcar en ruta.');
        }

        $paradas = EnvioPedidoService::paradasMapaEnvio($envio);
        if (count($paradas) < 2) {
            throw new InvalidArgumentException('El envío no tiene coordenadas suficientes para simular la ruta.');
        }

        $geo = $this->construirGeoJson($paradas);
        $duracion = $this->calcularDuracionSegundos($geo, $paradas);

        DB::transaction(function () use ($envio, $geo, $duracion) {
            // Lock del conductor y del envío + revalidación (TRA-06, TRA-15): dos «Empezar ruta»
            // simultáneos no inician dos veces ni dejan al conductor con dos viajes en curso.
            $this->bloquearConductor((int) $envio->transportista_usuarioid);
            $bloqueado = EnvioAsignacionMultiple::query()->whereKey($envio->envioasignacionmultipleid)->lockForUpdate()->firstOrFail();
            if ($bloqueado->simulacion_inicio_at !== null) {
                throw new InvalidArgumentException('Este envío ya está en ruta.');
            }
            $this->asegurarSinOtroViajeEnCurso((int) $envio->transportista_usuarioid, $envio, null);

            $envio->update([
                'estado' => 'en_transporte_planta',
                'fecha_asignacion' => $envio->fecha_asignacion ?? now(),
                'simulacion_inicio_at' => now(),
                'simulacion_duracion_seg' => $duracion,
                'simulacion_geojson' => $geo,
            ]);
        });
    }

    private function bloquearConductor(int $conductorId): void
    {
        if ($conductorId > 0) {
            Usuario::query()->whereKey($conductorId)->lockForUpdate()->first();
        }
    }

    private function asegurarSinOtroViajeEnCurso(int $conductorId, ?EnvioAsignacionMultiple $envio, ?RutaDistribucion $ruta): void
    {
        $enCurso = \App\Support\ViajeAcceso::viajeEnCursoDelConductor($conductorId, $envio, $ruta);
        if ($enCurso !== null) {
            throw new InvalidArgumentException("El transportista ya tiene un viaje en curso ({$enCurso}). Debe completarlo antes de iniciar otro.");
        }
    }

    public function empezarDistribucion(RutaDistribucion $ruta): void
    {
        $ruta->loadMissing(['paradas', 'pedidos']);

        if (! SimulacionRutaCatalogo::puedeEmpezarDistribucion($ruta)) {
            throw new InvalidArgumentException('Esta ruta no está lista para iniciar.');
        }

        $this->asegurarSinOtroViajeEnCurso((int) $ruta->transportista_usuarioid, null, $ruta);

        if (RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)
            && ! app(CierreEnvioPlantaMayoristaService::class)->tieneCondicionesVehiculo($ruta)) {
            throw new InvalidArgumentException('Debe registrar las condiciones del vehículo antes de marcar en ruta.');
        }

        if (! RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)
            && ! app(CierreEnvioDistribucionPdvService::class)->tieneCondicionesVehiculo($ruta)) {
            throw new InvalidArgumentException('Debe registrar las condiciones del vehículo antes de marcar en ruta.');
        }

        $paradas = $this->distribucion->paradasMapa($ruta);
        if (count($paradas) < 2) {
            throw new InvalidArgumentException('La ruta no tiene coordenadas suficientes para la simulación.');
        }

        $geo = $this->construirGeoJson($paradas);
        $duracion = $this->calcularDuracionSegundos($geo, $paradas);

        DB::transaction(function () use ($ruta, $geo, $duracion) {
            $this->bloquearConductor((int) $ruta->transportista_usuarioid);
            $bloqueada = RutaDistribucion::query()->whereKey($ruta->rutadistribucionid)->lockForUpdate()->firstOrFail();
            if (! SimulacionRutaCatalogo::puedeEmpezarDistribucion($bloqueada)) {
                throw new InvalidArgumentException('Esta ruta ya fue iniciada o no está lista para iniciar.');
            }
            $this->asegurarSinOtroViajeEnCurso((int) $ruta->transportista_usuarioid, null, $ruta);

            $ruta->update([
                'estado' => RutaDistribucionCatalogo::ESTADO_EN_RUTA,
                'fecha_salida' => now(),
                'simulacion_inicio_at' => now(),
                'simulacion_duracion_seg' => $duracion,
                'simulacion_geojson' => $geo,
            ]);

            foreach ($ruta->pedidos as $pedido) {
                $pedido->update([
                    'estado' => PedidoDistribucionCatalogo::ESTADO_EN_TRANSITO,
                    'fecha_envio' => now(),
                ]);
            }

            $usuarioSalida = Usuario::query()->find($ruta->transportista_usuarioid)
                ?? Usuario::query()->find($ruta->creado_por_usuarioid);

            if ($usuarioSalida !== null) {
                app(PedidoDistribucionSalidaMayoristaService::class)
                    ->descontarPedidosDeRuta($ruta, $usuarioSalida);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function estadoAgricola(EnvioAsignacionMultiple $envio): array
    {
        $estado = $this->armarEstado(
            SimulacionRutaCatalogo::TIPO_AGRICOLA,
            $envio->externo_envio_id ?? ('#'.$envio->envioasignacionmultipleid),
            $envio->simulacion_inicio_at,
            (int) ($envio->simulacion_duracion_seg ?? 0),
            $envio->simulacion_geojson,
            EnvioAsignacionEstadoCatalogo::llegoADestino($envio),
            EnvioPedidoService::paradasMapaEnvio($envio),
        );

        if ($envio->llegada_confirmada_at !== null) {
            $estado['esperando_confirmacion'] = false;
        }

        return $estado;
    }

    /**
     * @return array<string, mixed>
     */
    public function estadoDistribucion(RutaDistribucion $ruta): array
    {
        $estado = $this->armarEstado(
            RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)
                ? SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA
                : SimulacionRutaCatalogo::TIPO_DISTRIBUCION,
            $ruta->codigo,
            $ruta->simulacion_inicio_at,
            (int) ($ruta->simulacion_duracion_seg ?? 0),
            $ruta->simulacion_geojson,
            $ruta->estado === RutaDistribucionCatalogo::ESTADO_COMPLETADA,
            $this->distribucion->paradasMapa($ruta),
        );

        if ($ruta->llegada_confirmada_at !== null) {
            $estado['esperando_confirmacion'] = false;
        }

        return $estado;
    }

    /**
     * Cierre manual del seguimiento GPS: da el recorrido por llegado (100 %) para que el transportista
     * confirme la llegada y siga el cierre. NO recibe, NO firma y NO mueve inventario (MAY-08, MIN-10):
     * antes completaba la ruta y, si la transferencia fallaba, igual la marcaba completada (MAY-09).
     */
    public function completarManualAgricola(EnvioAsignacionMultiple $envio): void
    {
        if (! SimulacionRutaCatalogo::simulacionActivaAgricola($envio)) {
            throw new InvalidArgumentException('Este envío no tiene un recorrido activo para cerrar.');
        }

        $envio->update(['simulacion_inicio_at' => $this->inicioRecorridoTerminado((int) ($envio->simulacion_duracion_seg ?? 0))]);
    }

    public function completarManualDistribucion(RutaDistribucion $ruta): void
    {
        if (! SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta)) {
            throw new InvalidArgumentException('Esta ruta no tiene un recorrido activo para cerrar.');
        }

        $ruta->update(['simulacion_inicio_at' => $this->inicioRecorridoTerminado((int) ($ruta->simulacion_duracion_seg ?? 0))]);
    }

    private function inicioRecorridoTerminado(int $duracionAlmacenada): Carbon
    {
        return now()->subSeconds(SimulacionRutaCatalogo::duracionEfectiva($duracionAlmacenada) + 1);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listarActivas(): Collection
    {
        $agricolas = EnvioAsignacionMultiple::query()
            ->with(['transportista', 'pedido.detalles'])
            ->whereNotNull('simulacion_inicio_at')
            ->whereNull('fecha_recepcion_planta')
            ->whereNotIn('estado', ['recibido_planta', 'entregado', 'entregada', 'cancelado', 'cancelada'])
            ->get()
            ->filter(fn (EnvioAsignacionMultiple $e) => SimulacionRutaCatalogo::simulacionActivaAgricola($e))
            ->map(fn (EnvioAsignacionMultiple $e) => $this->mapearItemLista($e));

        $distribucion = RutaDistribucion::query()
            ->with(['transportista', 'vehiculo', 'almacenOrigen', 'paradas'])
            ->whereNotNull('simulacion_inicio_at')
            ->where('estado', RutaDistribucionCatalogo::ESTADO_EN_RUTA)
            ->get()
            ->map(fn (RutaDistribucion $r) => $this->mapearItemLista($r));

        return collect($agricolas->all())
            ->merge($distribucion->all())
            ->filter()
            ->sortByDesc('progreso')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listarActivasFiltradas(?string $busqueda = null, ?string $variante = null): Collection
    {
        $coleccion = $this->listarActivas()->values();
        $offset = 0;

        return $coleccion
            ->map(function (array $item) use (&$offset) {
                $item['mapa_offset'] = $offset++;

                return $item;
            })
            ->filter(function (array $item) use ($busqueda, $variante) {
                if ($variante !== null && $variante !== '' && ($item['variante'] ?? '') !== $variante) {
                    return false;
                }
                if ($busqueda === null || trim($busqueda) === '') {
                    return true;
                }
                $q = mb_strtolower(trim($busqueda));
                $texto = mb_strtolower(implode(' ', [
                    $item['codigo'] ?? '',
                    $item['chofer'] ?? '',
                    $item['destino'] ?? '',
                    $item['origen'] ?? '',
                    $item['tipo_etiqueta'] ?? '',
                ]));

                return str_contains($texto, $q);
            })
            ->values();
    }

    /** @return list<array<string, mixed>> */
    public function estadosMapaGlobal(): array
    {
        $items = [];
        $offset = 0;

        foreach ($this->listarActivas() as $item) {
            $estado = match ($item['tipo']) {
                SimulacionRutaCatalogo::TIPO_AGRICOLA => $this->estadoAgricola(
                    EnvioAsignacionMultiple::query()->findOrFail($item['id'])
                ),
                SimulacionRutaCatalogo::TIPO_DISTRIBUCION => $this->estadoDistribucion(
                    RutaDistribucion::query()->findOrFail($item['id'])
                ),
                SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA => $this->estadoDistribucion(
                    RutaDistribucion::query()->findOrFail($item['id'])
                ),
                default => null,
            };

            if ($estado === null || ($estado['completada'] ?? false)) {
                continue;
            }

            $geo = $this->desplazarGeojson($estado['geojson'] ?? null, $offset);
            $paradas = $this->desplazarParadas($estado['paradas'] ?? [], $offset);
            $posicion = $this->posicionDesplazada($estado['posicion'] ?? null, $offset);

            $items[] = array_merge($item, [
                'mapa_offset' => $offset,
                'progreso' => $estado['progreso'] ?? 0,
                'progreso_ratio' => $estado['progreso_ratio'] ?? 0,
                'segundos_restantes' => $estado['segundos_restantes'] ?? 0,
                'geojson' => $geo,
                'paradas' => $paradas,
                'posicion' => $posicion,
                'completada' => false,
                'esperando_confirmacion' => (bool) ($estado['esperando_confirmacion'] ?? false),
            ]);
            $offset++;
        }

        return $items;
    }

    private function segundosTranscurridos(?Carbon $inicio): int
    {
        if ($inicio === null) {
            return 0;
        }

        return (int) $inicio->diffInSeconds(now());
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $paradas
     * @return array<string, mixed>
     */
    private function construirGeoJson(array $paradas): array
    {
        $waypoints = array_map(fn (array $p) => ['lat' => $p['lat'], 'lng' => $p['lng']], $paradas);
        $geo = $this->rutasCalles->rutaPorCalles($waypoints);

        return $geo ?? [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => ['provider' => 'straight'],
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => array_map(fn (array $p) => [$p['lng'], $p['lat']], $waypoints),
                ],
            ]],
        ];
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $paradas
     */
    private function calcularDuracionSegundos(array $geo, array $paradas): int
    {
        return SimulacionRutaCatalogo::duracionEfectiva();
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $paradas
     */
    private function distanciaTotalParadas(array $paradas): float
    {
        $total = 0.0;
        for ($i = 1, $n = count($paradas); $i < $n; $i++) {
            $total += $this->haversineMetros(
                $paradas[$i - 1]['lat'],
                $paradas[$i - 1]['lng'],
                $paradas[$i]['lat'],
                $paradas[$i]['lng'],
            );
        }

        return $total;
    }

    private function haversineMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function progreso(?Carbon $inicio, int $duracionSeg): float
    {
        $duracionEfectiva = SimulacionRutaCatalogo::duracionEfectiva($duracionSeg);
        if ($inicio === null || $duracionEfectiva <= 0) {
            return 0.0;
        }

        return min(1.0, $this->segundosTranscurridos($inicio) / $duracionEfectiva);
    }

    /**
     * @param  array<string, mixed>|null  $geo
     * @return array{lat: float, lng: float}|null
     */
    public function posicionEnRuta(?array $geo, float $progreso): ?array
    {
        $coords = $this->extraerCoordenadas($geo);
        if ($coords === []) {
            return null;
        }

        if ($progreso >= 1.0) {
            $ultimo = $coords[array_key_last($coords)];

            return ['lat' => $ultimo[1], 'lng' => $ultimo[0]];
        }

        $distancias = [0.0];
        $total = 0.0;
        for ($i = 1, $n = count($coords); $i < $n; $i++) {
            $total += $this->haversineMetros($coords[$i - 1][1], $coords[$i - 1][0], $coords[$i][1], $coords[$i][0]);
            $distancias[] = $total;
        }

        if ($total <= 0) {
            return ['lat' => $coords[0][1], 'lng' => $coords[0][0]];
        }

        $objetivo = $total * $progreso;
        for ($i = 1, $n = count($coords); $i < $n; $i++) {
            if ($distancias[$i] >= $objetivo) {
                $segmento = $distancias[$i] - $distancias[$i - 1];
                $t = $segmento > 0 ? ($objetivo - $distancias[$i - 1]) / $segmento : 0;
                $lng = $coords[$i - 1][0] + ($coords[$i][0] - $coords[$i - 1][0]) * $t;
                $lat = $coords[$i - 1][1] + ($coords[$i][1] - $coords[$i - 1][1]) * $t;

                return ['lat' => $lat, 'lng' => $lng];
            }
        }

        $ultimo = $coords[array_key_last($coords)];

        return ['lat' => $ultimo[1], 'lng' => $ultimo[0]];
    }

    /**
     * @param  array<string, mixed>|null  $geo
     * @return array<int, array{0: float, 1: float}>
     */
    private function extraerCoordenadas(?array $geo): array
    {
        if (! is_array($geo)) {
            return [];
        }

        $geometry = $geo['features'][0]['geometry'] ?? null;
        if (! is_array($geometry) || ($geometry['type'] ?? '') !== 'LineString') {
            return [];
        }

        $coords = $geometry['coordinates'] ?? [];

        return is_array($coords) ? $coords : [];
    }

    /**
     * @param  array<int, array{lat: float, lng: float, orden?: int, label?: string}>  $paradas
     * @return array<string, mixed>
     */
    private function armarEstado(
        string $tipo,
        string $codigo,
        ?Carbon $inicio,
        int $duracionSeg,
        ?array $geo,
        bool $completada,
        array $paradas,
    ): array {
        $duracionEfectiva = SimulacionRutaCatalogo::duracionEfectiva($duracionSeg);
        $progreso = $completada ? 1.0 : $this->progreso($inicio, $duracionSeg);
        $geoEfectivo = $geo;
        if ($this->extraerCoordenadas($geoEfectivo) === [] && count($paradas) >= 2) {
            $geoEfectivo = $this->construirGeoJson(array_map(
                fn (array $p) => ['lat' => $p['lat'], 'lng' => $p['lng']],
                $paradas
            ));
        }
        $posicion = $this->posicionEnRuta($geoEfectivo, $progreso)
            ?? $this->posicionEntreParadas($paradas, $progreso);
        $transcurrido = $this->segundosTranscurridos($inicio);
        $restante = $completada || $inicio === null || $duracionEfectiva <= 0
            ? 0
            : max(0, $duracionEfectiva - $transcurrido);

        return [
            'tipo' => $tipo,
            'codigo' => $codigo,
            'activa' => $inicio !== null && ! $completada,
            'completada' => $completada,
            'esperando_confirmacion' => ! $completada && $progreso >= 1.0,
            'progreso' => round($progreso * 100, 1),
            'progreso_ratio' => $progreso,
            'segundos_restantes' => $restante,
            'eta' => $completada || $restante <= 0
                ? null
                : now()->addSeconds($restante)->toIso8601String(),
            'posicion' => $posicion,
            'geojson' => $geoEfectivo,
            'paradas' => $paradas,
            'inicio_at' => $inicio?->toIso8601String(),
            'inicio_at_unix' => $inicio?->timestamp,
            'duracion_seg' => $duracionEfectiva,
        ];
    }

    /**
     * @param  array<int, array{lat: float, lng: float, orden?: int, label?: string}>  $paradas
     * @return array{lat: float, lng: float}|null
     */
    private function posicionEntreParadas(array $paradas, float $progreso): ?array
    {
        if (count($paradas) < 2) {
            return null;
        }

        $origen = $paradas[0];
        $destino = $paradas[array_key_last($paradas)];
        $ratio = min(1.0, max(0.0, $progreso));

        return [
            'lat' => $origen['lat'] + ($destino['lat'] - $origen['lat']) * $ratio,
            'lng' => $origen['lng'] + ($destino['lng'] - $origen['lng']) * $ratio,
        ];
    }

    /** @return array<string, mixed>|null */
    private function mapearItemLista(EnvioAsignacionMultiple|RutaDistribucion $item): ?array
    {
        if ($item instanceof EnvioAsignacionMultiple) {
            $estado = $this->estadoAgricola($item);
            $item->refresh();
            if ($estado['completada'] || ! SimulacionRutaCatalogo::simulacionActivaAgricola($item)) {
                return null;
            }
            $chofer = trim(($item->transportista?->nombre ?? '').' '.($item->transportista?->apellido ?? ''));
            $destino = $item->pedido
                ? (EnvioPedidoService::etiquetaPlantaDestinoLista($item->pedido) ?? 'Planta')
                : 'Planta';
            $meta = SimulacionRutaCatalogo::metaVariante(SimulacionRutaCatalogo::TIPO_AGRICOLA);

            $etiquetaEstado = ($estado['esperando_confirmacion'] ?? false)
                ? EnvioEstadoRecepcionCatalogo::etiquetaListaEsperando()
                : EnvioEstadoRecepcionCatalogo::etiquetaListaEnCamino('planta');

            return [
                'tipo' => SimulacionRutaCatalogo::TIPO_AGRICOLA,
                'variante' => $meta['variante'],
                'tipo_etiqueta' => $meta['etiqueta'],
                'color' => $meta['color'],
                'icono' => $meta['icono'],
                'estado_etiqueta' => $etiquetaEstado,
                'esperando_confirmacion' => (bool) ($estado['esperando_confirmacion'] ?? false),
                'origen' => 'Almacén agrícola',
                'id' => $item->envioasignacionmultipleid,
                'codigo' => $item->externo_envio_id ?? ('#'.$item->envioasignacionmultipleid),
                'chofer' => $chofer,
                'destino' => $destino,
                'progreso' => $estado['progreso'],
                'segundos_restantes' => $estado['segundos_restantes'],
                'ver_url' => route('logistica.rutas-tiempo-real.show', [
                    'tipo' => SimulacionRutaCatalogo::TIPO_AGRICOLA,
                    'id' => $item->envioasignacionmultipleid,
                ]),
            ];
        }

        $estado = $this->estadoDistribucion($item);
        $item->refresh();
        if ($estado['completada'] || ! SimulacionRutaCatalogo::simulacionActivaDistribucion($item)) {
            return null;
        }

        $chofer = trim(($item->transportista?->nombre ?? '').' '.($item->transportista?->apellido ?? ''));

        if (RutaDistribucionCatalogo::esTrasladoPlantaMayorista($item)) {
            $item->loadMissing(['almacenPlantaOrigen', 'almacenMayoristaDestino', 'paradas']);
            $meta = SimulacionRutaCatalogo::metaVariante(SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA);

            $etiquetaEstadoPm = ($estado['esperando_confirmacion'] ?? false)
                ? EnvioEstadoRecepcionCatalogo::etiquetaListaEsperando()
                : EnvioEstadoRecepcionCatalogo::etiquetaListaEnCamino('mayorista');

            return [
                'tipo' => SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA,
                'variante' => $meta['variante'],
                'tipo_etiqueta' => $meta['etiqueta'],
                'color' => $meta['color'],
                'icono' => $meta['icono'],
                'estado_etiqueta' => $etiquetaEstadoPm,
                'esperando_confirmacion' => (bool) ($estado['esperando_confirmacion'] ?? false),
                'origen' => $item->almacenPlantaOrigen?->nombre ?? 'Almacén de planta',
                'id' => $item->rutadistribucionid,
                'codigo' => $item->codigo,
                'chofer' => $chofer,
                'destino' => $item->almacenMayoristaDestino?->nombre ?? 'Almacén mayorista',
                'progreso' => $estado['progreso'],
                'segundos_restantes' => $estado['segundos_restantes'],
                'ver_url' => route('logistica.rutas-tiempo-real.show', [
                    'tipo' => SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA,
                    'id' => $item->rutadistribucionid,
                ]),
            ];
        }

        $pdv = $item->paradas?->firstWhere('tipo', RutaDistribucionCatalogo::PARADA_ENTREGA_PDV);
        $destinoPdv = $pdv ? str_replace('Entrega: ', '', (string) $pdv->destino) : 'Punto de venta';
        $meta = SimulacionRutaCatalogo::metaVariante(SimulacionRutaCatalogo::TIPO_DISTRIBUCION);
        $item->loadMissing(['pedidos.detalles', 'vehiculo']);
        $primerPedido = $item->pedidos?->first();
        $primerDetalle = $primerPedido?->detalles?->first();
        $producto = $primerDetalle?->producto_nombre ?? $primerDetalle?->cultivo_personalizado ?? 'Distribución';
        $etiquetaEstado = ($estado['esperando_confirmacion'] ?? false)
            ? EnvioEstadoRecepcionCatalogo::etiquetaListaEsperando()
            : EnvioEstadoRecepcionCatalogo::etiquetaListaEnCamino('pdv');

        return [
            'tipo' => SimulacionRutaCatalogo::TIPO_DISTRIBUCION,
            'variante' => $meta['variante'],
            'tipo_etiqueta' => $meta['etiqueta'],
            'color' => $meta['color'],
            'icono' => $meta['icono'],
            'estado_etiqueta' => $etiquetaEstado,
            'esperando_confirmacion' => (bool) ($estado['esperando_confirmacion'] ?? false),
            'origen' => $item->almacenOrigen?->nombre ?? 'Almacén mayorista',
            'id' => $item->rutadistribucionid,
            'codigo' => $item->codigo,
            'chofer' => $chofer,
            'destino' => $destinoPdv,
            'producto' => $producto,
            'pedido_solicitud' => $primerPedido?->numero_solicitud,
            'vehiculo_placa' => $item->vehiculo?->placa
                ?? $item->transportista?->perfilTransportista?->vehiculo?->placa,
            'progreso' => $estado['progreso'],
            'segundos_restantes' => $estado['segundos_restantes'],
            'ver_url' => route('logistica.rutas-tiempo-real.show', [
                'tipo' => SimulacionRutaCatalogo::TIPO_DISTRIBUCION,
                'id' => $item->rutadistribucionid,
            ]),
        ];
    }

    /** @param  array<int, array{lat: float, lng: float, label?: string}>|null  $paradas */
    private function desplazarParadas(?array $paradas, int $offsetIndice): ?array
    {
        if ($paradas === null || $paradas === []) {
            return $paradas;
        }

        [$dLat, $dLng] = $this->deltaOffset($offsetIndice);

        return array_map(function (array $p) use ($dLat, $dLng) {
            return array_merge($p, [
                'lat' => (float) $p['lat'] + $dLat,
                'lng' => (float) $p['lng'] + $dLng,
            ]);
        }, $paradas);
    }

    /** @param  array{lat?: float, lng?: float}|null  $posicion */
    private function posicionDesplazada(?array $posicion, int $offsetIndice): ?array
    {
        if ($posicion === null || ! isset($posicion['lat'], $posicion['lng'])) {
            return $posicion;
        }
        [$dLat, $dLng] = $this->deltaOffset($offsetIndice);

        return [
            'lat' => (float) $posicion['lat'] + $dLat,
            'lng' => (float) $posicion['lng'] + $dLng,
        ];
    }

    private function desplazarGeojson(?array $geojson, int $offsetIndice): ?array
    {
        if ($geojson === null || $offsetIndice === 0) {
            return $geojson;
        }

        [$dLat, $dLng] = $this->deltaOffset($offsetIndice);
        $features = $geojson['features'] ?? [];
        foreach ($features as &$feature) {
            $coords = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($coords)) {
                continue;
            }
            $feature['geometry']['coordinates'] = array_map(
                fn (array $c) => [$c[0] + $dLng, $c[1] + $dLat],
                $coords
            );
        }
        unset($feature);
        $geojson['features'] = $features;

        return $geojson;
    }

    /** @return array{0: float, 1: float} */
    private function deltaOffset(int $offsetIndice): array
    {
        $lane = ($offsetIndice % 5) - 2;

        return [$lane * 0.00014, $lane * 0.00009];
    }
}
