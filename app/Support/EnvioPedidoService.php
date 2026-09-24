<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\EnvioAsignacionMultiple;
use App\Models\Pedido;
use App\Models\PerfilTransportista;
use App\Models\RutaMultiEntrega;
use App\Models\RutaParada;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Services\TransporteCapacidadService;
use InvalidArgumentException;

final class EnvioPedidoService
{
    /** @var array<int, string> */
    private const ESTADOS_EN_RUTA_PLANTA = ['en_transporte_planta', 'en_ruta', 'en_transito'];

    /** @var array<int, string> */
    private const ESTADOS_ASIGNADO = ['asignado', 'asignada', 'pendiente', 'creada'];

    public static function placaTransportista(int $transportistaId): ?string
    {
        $perfil = PerfilTransportista::query()
            ->with('vehiculo')
            ->where('usuarioid', $transportistaId)
            ->first();

        return $perfil?->vehiculo?->placa;
    }

    public static function vehiculoIdDesdeEnvio(?EnvioAsignacionMultiple $envio): ?int
    {
        if ($envio === null) {
            return null;
        }

        if ($envio->vehiculo_ref) {
            $id = Vehiculo::query()
                ->where('placa', $envio->vehiculo_ref)
                ->value('vehiculoid');

            if ($id !== null) {
                return (int) $id;
            }
        }

        return PerfilTransportista::query()
            ->where('usuarioid', $envio->transportista_usuarioid)
            ->value('vehiculoid');
    }

    public static function resolverVehiculoAsignado(?EnvioAsignacionMultiple $envio): ?Vehiculo
    {
        if ($envio === null) {
            return null;
        }

        if ($envio->vehiculo_ref) {
            $vehiculo = Vehiculo::query()
                ->with('tipoVehiculo')
                ->where('placa', $envio->vehiculo_ref)
                ->first();

            if ($vehiculo) {
                return $vehiculo;
            }
        }

        return $envio->transportista?->perfilTransportista?->vehiculo;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function datosLogistica(?EnvioAsignacionMultiple $envio): ?array
    {
        if ($envio === null || ! $envio->transportista_usuarioid) {
            return null;
        }

        $transportista = $envio->transportista;
        $vehiculo = self::resolverVehiculoAsignado($envio);
        $tipo = $vehiculo?->tipoVehiculo?->nombre;

        $nombreVehiculo = trim(collect([$vehiculo?->marca, $vehiculo?->modelo])->filter()->implode(' '));
        if ($nombreVehiculo === '' && $tipo) {
            $nombreVehiculo = $tipo;
        }

        $estado = strtolower(trim((string) ($envio->estado ?? '')));
        $cargadoEnRuta = in_array($estado, self::ESTADOS_EN_RUTA_PLANTA, true);
        $recibidoPlanta = in_array($estado, ['recibido_planta', 'entregado', 'entregada'], true);

        return [
            'transportista_nombre' => trim(($transportista->nombre ?? '').' '.($transportista->apellido ?? '')),
            'transportista_usuarioid' => (int) $envio->transportista_usuarioid,
            'vehiculoid' => $vehiculo?->vehiculoid,
            'vehiculo_nombre' => $nombreVehiculo !== '' ? $nombreVehiculo : ($tipo ?? '—'),
            'placa' => $envio->vehiculo_ref ?? $vehiculo?->placa ?? '—',
            'estado' => $estado,
            'estado_etiqueta' => EnvioAsignacionEstadoCatalogo::etiqueta($estado),
            'asignado' => in_array($estado, self::ESTADOS_ASIGNADO, true) || $cargadoEnRuta || $recibidoPlanta,
            'cargado_en_ruta' => $cargadoEnRuta,
            'recibido_planta' => $recibidoPlanta,
            'fecha_asignacion' => $envio->fecha_asignacion,
            'costo_bs' => $envio->costo_bs !== null ? (float) $envio->costo_bs : null,
            'asignado_por' => $envio->asignadoPor
                ? trim($envio->asignadoPor->nombre.' '.($envio->asignadoPor->apellido ?? ''))
                : null,
        ];
    }

    public static function asignarTransportistaYVehiculo(
        Pedido $pedido,
        int $transportistaId,
        int $vehiculoId,
        int $asignadoPorId,
        bool $permitirReasignar = true,
        ?float $costoBs = null
    ): EnvioAsignacionMultiple {
        if (! PedidoCatalogo::puedeAsignarTransportista($pedido)) {
            throw new InvalidArgumentException('Producción agrícola debe aceptar el pedido y reservar stock antes de asignar transportista.');
        }

        $transportista = Usuario::query()
            ->where('usuarioid', $transportistaId)
            ->where('role', 'transportista')
            ->where('activo', true)
            ->first();

        if (! $transportista) {
            throw new InvalidArgumentException('El usuario seleccionado no es un transportista activo.');
        }

        $vehiculo = Vehiculo::query()
            ->with('tipoVehiculo')
            ->where('vehiculoid', $vehiculoId)
            ->where('activo', true)
            ->first();

        if (! $vehiculo) {
            throw new InvalidArgumentException('El vehículo seleccionado no está disponible.');
        }

        // Pool agrícola explícito (TRA-04): perfil de flota agrícola, disponible y sin viaje en curso.
        TransportistaPool::asegurarAsignable($transportista, TransportistaFlotaCatalogo::AGRICOLA);

        $capacidad = app(TransporteCapacidadService::class);
        $capacidad->validarAsignacion($transportista, $vehiculo);
        $capacidad->validarCarga($vehiculo, $capacidad->pesoPedido($pedido->loadMissing('detalles')));

        $envioExistente = EnvioAsignacionMultiple::query()
            ->where(function ($q) use ($pedido) {
                $q->where('pedidoid', $pedido->pedidoid)
                    ->orWhere('externo_envio_id', $pedido->numero_solicitud);
            })
            ->first();

        if ($envioExistente?->transportista_usuarioid && ! $permitirReasignar) {
            throw new InvalidArgumentException('Este pedido ya tiene transportista asignado.');
        }

        $estadoActual = strtolower(trim((string) ($envioExistente?->estado ?? '')));
        $estadoNuevo = in_array($estadoActual, ['en_transporte_planta', 'en_ruta', 'en_transito', 'recibido_planta', 'entregado', 'entregada'], true)
            ? $estadoActual
            : 'asignado';

        $atributos = [
            'pedidoid' => $pedido->pedidoid,
            'transportista_usuarioid' => $transportista->usuarioid,
            'asignadopor_usuarioid' => $asignadoPorId,
            'vehiculo_ref' => $vehiculo->placa,
            'estado' => $estadoNuevo,
            'fecha_asignacion' => $envioExistente?->fecha_asignacion ?? now(),
        ];

        if ($costoBs !== null) {
            $atributos['costo_bs'] = round($costoBs, 2);
        }

        return EnvioAsignacionMultiple::updateOrCreate(
            ['externo_envio_id' => $pedido->numero_solicitud],
            EnvioAsignacionEstadoCatalogo::applyToAttributes($atributos)
        );
    }

    /**
     * Guarda chofer y vehículo antes de que producción agrícola acepte el pedido.
     * El envío queda en estado pendiente hasta la aceptación.
     */
    public static function programarTransportista(
        Pedido $pedido,
        int $transportistaId,
        int $vehiculoId,
        int $programadoPorId,
        ?float $costoBs = null
    ): EnvioAsignacionMultiple {
        $transportista = Usuario::query()
            ->where('usuarioid', $transportistaId)
            ->where('role', 'transportista')
            ->where('activo', true)
            ->first();

        if (! $transportista) {
            throw new InvalidArgumentException('El usuario seleccionado no es un transportista activo.');
        }

        $vehiculo = Vehiculo::query()
            ->with('tipoVehiculo')
            ->where('vehiculoid', $vehiculoId)
            ->where('activo', true)
            ->first();

        if (! $vehiculo) {
            throw new InvalidArgumentException('El vehículo seleccionado no está disponible.');
        }

        $capacidad = app(TransporteCapacidadService::class);
        $capacidad->validarAsignacion($transportista, $vehiculo);
        $capacidad->validarCarga($vehiculo, $capacidad->pesoPedido($pedido->loadMissing('detalles')));

        $envioExistente = EnvioAsignacionMultiple::query()
            ->where(function ($q) use ($pedido) {
                $q->where('pedidoid', $pedido->pedidoid)
                    ->orWhere('externo_envio_id', $pedido->numero_solicitud);
            })
            ->first();

        $atributos = [
            'pedidoid' => $pedido->pedidoid,
            'transportista_usuarioid' => $transportista->usuarioid,
            'asignadopor_usuarioid' => $programadoPorId,
            'vehiculo_ref' => $vehiculo->placa,
            'estado' => 'pendiente',
            'fecha_asignacion' => $envioExistente?->fecha_asignacion ?? now(),
        ];

        if ($costoBs !== null) {
            $atributos['costo_bs'] = round($costoBs, 2);
        }

        return EnvioAsignacionMultiple::updateOrCreate(
            ['externo_envio_id' => $pedido->numero_solicitud],
            EnvioAsignacionEstadoCatalogo::applyToAttributes($atributos)
        );
    }

    /**
     * Crea ruta multientrega cuando hay varios puntos de recogida antes de la planta.
     *
     * @param  array<int, array{latitud: float|int|string, longitud: float|int|string, direccion?: string|null}>  $recogidasExtra
     */
    public static function crearRutaRecogidasMultiples(
        Pedido $pedido,
        EnvioAsignacionMultiple $envio,
        array $recogidasExtra,
        ?int $transportistaId,
        int $creadoPorId
    ): ?RutaMultiEntrega {
        if ($recogidasExtra === []) {
            return null;
        }

        $ruta = RutaMultiEntrega::create([
            'nombre' => 'Recogidas '.$pedido->numero_solicitud,
            'creadopor_usuarioid' => $creadoPorId,
            'transportista_usuarioid' => $transportistaId,
            'fecha_salida' => $pedido->fechaEntregaDeseada ?? now(),
            'estado' => 'planificada',
        ]);

        $orden = 1;

        RutaParada::create([
            'rutamultientregaid' => $ruta->rutamultientregaid,
            'orden' => $orden++,
            'destino' => 'Recogida: '.($pedido->origen_direccion ?: 'Almacén agrícola 1'),
            'pedidoid' => $pedido->pedidoid,
            'externo_envio_id' => $pedido->numero_solicitud,
            'latitud' => $pedido->origen_latitud,
            'longitud' => $pedido->origen_longitud,
            'estado' => 'pendiente',
        ]);

        foreach ($recogidasExtra as $idx => $recogida) {
            RutaParada::create([
                'rutamultientregaid' => $ruta->rutamultientregaid,
                'orden' => $orden++,
                'destino' => 'Recogida: '.($recogida['direccion'] ?? 'Almacén agrícola '.($idx + 2)),
                'pedidoid' => $pedido->pedidoid,
                'externo_envio_id' => $pedido->numero_solicitud,
                'latitud' => (float) $recogida['latitud'],
                'longitud' => (float) $recogida['longitud'],
                'estado' => 'pendiente',
            ]);
        }

        RutaParada::create([
            'rutamultientregaid' => $ruta->rutamultientregaid,
            'orden' => $orden,
            'destino' => 'Entrega: '.($pedido->direccion_texto ?: 'Almacén de planta'),
            'pedidoid' => $pedido->pedidoid,
            'externo_envio_id' => $pedido->numero_solicitud,
            'latitud' => $pedido->latitud,
            'longitud' => $pedido->longitud,
            'estado' => 'pendiente',
        ]);

        $ruta->load('paradas');
        $geo = app(RutaPorCallesService::class)->rutaDesdeParadas($ruta->paradas);
        if ($geo) {
            $ruta->update(['rutageojson' => json_encode($geo)]);
        }

        $envio->update(['rutamultientregaid' => $ruta->rutamultientregaid]);

        return $ruta;
    }

    /** Activa el chofer programado cuando el pedido ya fue aceptado por producción agrícola. */
    public static function activarTransportistaProgramado(Pedido $pedido): void
    {
        if (! PedidoCatalogo::listoParaLogistica($pedido)) {
            return;
        }

        $envio = EnvioAsignacionMultiple::query()
            ->where(function ($q) use ($pedido) {
                $q->where('pedidoid', $pedido->pedidoid)
                    ->orWhere('externo_envio_id', $pedido->numero_solicitud);
            })
            ->first();

        if (! $envio?->transportista_usuarioid) {
            return;
        }

        $estado = strtolower(trim((string) ($envio->estado ?? '')));
        if (in_array($estado, ['en_transporte_planta', 'en_ruta', 'en_transito', 'recibido_planta', 'entregado', 'entregada', 'asignado', 'asignada'], true)) {
            return;
        }

        $envio->update(EnvioAsignacionEstadoCatalogo::applyToAttributes([
            'estado' => 'asignado',
            'fecha_asignacion' => $envio->fecha_asignacion ?? now(),
        ]));
    }

    public static function confirmarCargaHaciaPlanta(EnvioAsignacionMultiple $envio): void
    {
        if (! $envio->transportista_usuarioid) {
            throw new InvalidArgumentException('El envío no tiene transportista asignado.');
        }

        $estado = strtolower(trim((string) ($envio->estado ?? '')));

        if (in_array($estado, self::ESTADOS_EN_RUTA_PLANTA, true)) {
            return;
        }

        if (! in_array($estado, self::ESTADOS_ASIGNADO, true)) {
            throw new InvalidArgumentException('Solo puede confirmar la carga cuando el envío está asignado.');
        }

        if ($envio->pedido && ! PedidoCatalogo::puedeAsignarTransportista($envio->pedido)) {
            throw new InvalidArgumentException('El pedido aún no está listo para el envío.');
        }

        $envio->update(EnvioAsignacionEstadoCatalogo::applyToAttributes([
            'estado' => 'en_transporte_planta',
            'fecha_asignacion' => $envio->fecha_asignacion ?? now(),
        ]));
    }

    /**
     * @return array{recogidas: array<int, string>, destino: ?string}|null
     */
    public static function trayectoPartesPedido(Pedido $pedido): ?array
    {
        $envio = $pedido->envioAsignacion;
        if ($envio !== null) {
            $envio->loadMissing(['ruta.paradas', 'pedido']);
            $envio->setRelation('pedido', $pedido);

            return self::trayectoPartes($envio);
        }

        $origen = self::etiquetaOrigenPedido($pedido);
        $destino = self::etiquetaDestinoPedido($pedido);

        if ($origen === null && $destino === null) {
            return null;
        }

        return [
            'recogidas' => $origen !== null ? [$origen] : [],
            'destino' => $destino,
        ];
    }

    /**
     * @return array{recogidas: array<int, string>, destino: ?string}|null
     */
    public static function trayectoPartes(EnvioAsignacionMultiple $envio): ?array
    {
        $envio->loadMissing(['ruta.paradas', 'pedido']);
        $pedido = $envio->pedido;
        $ruta = $envio->ruta;

        if ($ruta?->paradas?->isNotEmpty()) {
            $paradas = $ruta->paradas->sortBy('orden')->values();
            $recogidas = $paradas
                ->filter(fn (RutaParada $p) => str_starts_with((string) $p->destino, 'Recogida:'))
                ->map(fn (RutaParada $p) => self::etiquetaParada($p->destino, 'Recogida:'))
                ->filter(fn (string $n) => $n !== '—')
                ->values()
                ->all();
            $entrega = $paradas->first(fn (RutaParada $p) => str_starts_with((string) $p->destino, 'Entrega:'));
            $destino = $entrega
                ? self::etiquetaParada($entrega->destino, 'Entrega:')
                : self::etiquetaDestinoPedido($pedido);

            if ($destino === '—') {
                $destino = null;
            }

            if ($recogidas !== [] || $destino !== null) {
                return ['recogidas' => $recogidas, 'destino' => $destino];
            }
        }

        $origen = self::etiquetaOrigenPedido($pedido);
        $destino = self::etiquetaDestinoPedido($pedido);

        if ($origen === null && $destino === null) {
            return null;
        }

        return [
            'recogidas' => $origen !== null ? [$origen] : [],
            'destino' => $destino,
        ];
    }

    public static function trayectoTexto(EnvioAsignacionMultiple $envio): ?string
    {
        $partes = self::trayectoPartes($envio);

        if ($partes === null) {
            return null;
        }

        $recogidas = $partes['recogidas'];
        $destino = $partes['destino'];

        if ($recogidas === [] && $destino === null) {
            return null;
        }

        if ($recogidas === []) {
            return $destino;
        }

        if ($destino === null) {
            return count($recogidas) === 1
                ? $recogidas[0]
                : implode(' → ', $recogidas);
        }

        $textoRecogidas = count($recogidas) === 1
            ? $recogidas[0]
            : implode(' → ', $recogidas);

        return $textoRecogidas.' a '.$destino;
    }

    /**
     * @return array<int, array{lat: float, lng: float, orden: int, label: string}>
     */
    public static function paradasMapaEnvio(EnvioAsignacionMultiple $envio): array
    {
        $envio->loadMissing(['ruta.paradas', 'pedido']);

        $puntos = [];
        if ($envio->ruta?->paradas?->isNotEmpty()) {
            $puntos = app(RutaPorCallesService::class)->paradasConCoordenadas($envio->ruta->paradas);
            $puntos = array_values(array_map(fn (array $p) => [
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'orden' => (int) ($p['orden'] ?? 0),
                'label' => self::etiquetaMapaParada($p['label'] ?? 'Parada'),
            ], $puntos));
        }

        if (count($puntos) < 2 && $envio->pedido !== null) {
            $puntos = self::paradasDesdePedido($envio->pedido);
        }

        return $puntos;
    }

    /**
     * @return array<int, array{lat: float, lng: float, orden: int, label: string}>
     */
    private static function paradasDesdePedido(Pedido $pedido): array
    {
        $puntos = [];

        $origen = self::coordenadasDesdeCampoPedido(
            $pedido->origen_latitud,
            $pedido->origen_longitud,
            $pedido->origen_direccion
        );
        if ($origen !== null) {
            $puntos[] = [
                'lat' => $origen['lat'],
                'lng' => $origen['lng'],
                'orden' => 1,
                'label' => self::etiquetaOrigenPedido($pedido) ?? 'Origen',
            ];
        }

        $destino = self::coordenadasDesdeCampoPedido(
            $pedido->latitud,
            $pedido->longitud,
            $pedido->direccion_texto ?? $pedido->nombre_planta
        );
        if ($destino === null) {
            $aprox = app(RutaPorCallesService::class)->coordsDesdePedido($pedido);
            if ($aprox !== null) {
                $destino = ['lat' => $aprox['lat'], 'lng' => $aprox['lng']];
            }
        }
        if ($destino !== null) {
            $puntos[] = [
                'lat' => $destino['lat'],
                'lng' => $destino['lng'],
                'orden' => count($puntos) + 1,
                'label' => self::etiquetaDestinoPedido($pedido) ?? 'Destino',
            ];
        }

        return $puntos;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private static function coordenadasDesdeCampoPedido($lat, $lng, ?string $texto): ?array
    {
        if ($lat !== null && $lng !== null) {
            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }

        return self::coordenadasDesdeTextoGps($texto);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private static function coordenadasDesdeTextoGps(?string $texto): ?array
    {
        if ($texto === null || trim($texto) === '') {
            return null;
        }

        if (preg_match('/GPS\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/i', $texto, $coincidencias)) {
            return [
                'lat' => (float) $coincidencias[1],
                'lng' => (float) $coincidencias[2],
            ];
        }

        return null;
    }

    private static function etiquetaMapaParada(string $label): string
    {
        if (str_starts_with($label, 'Recogida:')) {
            return self::etiquetaParada($label, 'Recogida:');
        }

        if (str_starts_with($label, 'Entrega:')) {
            return self::etiquetaParada($label, 'Entrega:');
        }

        return self::nombreUbicacionLimpio($label) ?? $label;
    }

    private static function etiquetaParada(?string $destino, string $prefijo): string
    {
        $texto = trim((string) $destino);
        if (str_starts_with($texto, $prefijo)) {
            $texto = trim(substr($texto, strlen($prefijo)));
        }

        return self::nombreUbicacionLimpio($texto) ?? '—';
    }

    private static function etiquetaOrigenPedido(?Pedido $pedido): ?string
    {
        if ($pedido === null) {
            return null;
        }

        $nombre = self::nombreUbicacionLimpio($pedido->origen_direccion);
        if ($nombre !== null) {
            return $nombre;
        }

        $nombre = self::resolverNombreAlmacenPorCoordenadas(
            $pedido->origen_latitud !== null ? (float) $pedido->origen_latitud : null,
            $pedido->origen_longitud !== null ? (float) $pedido->origen_longitud : null,
            'agricola'
        );
        if ($nombre !== null) {
            return $nombre;
        }

        if ($pedido->origen_latitud !== null && $pedido->origen_longitud !== null) {
            return 'Almacén agrícola';
        }

        return null;
    }

    private static function etiquetaDestinoPedido(?Pedido $pedido): ?string
    {
        if ($pedido === null) {
            return null;
        }

        $texto = $pedido->direccion_texto ?? $pedido->nombre_planta;
        $nombre = self::nombreUbicacionLimpio($texto);
        if ($nombre !== null) {
            return $nombre;
        }

        $nombre = self::resolverNombreAlmacenPorCoordenadas(
            $pedido->latitud !== null ? (float) $pedido->latitud : null,
            $pedido->longitud !== null ? (float) $pedido->longitud : null,
            'planta'
        );
        if ($nombre !== null) {
            return $nombre;
        }

        if ($pedido->latitud !== null && $pedido->longitud !== null) {
            return 'Almacén de planta';
        }

        return null;
    }

    public static function etiquetaPlantaDestinoPedido(Pedido $pedido): ?string
    {
        return self::etiquetaDestinoPedido($pedido);
    }

    /** Nombre corto de planta destino para tablas (solo almacén, sin dirección larga). */
    public static function etiquetaPlantaDestinoLista(Pedido $pedido): ?string
    {
        if ($pedido->nombre_planta) {
            $nombre = self::nombreUbicacionLimpio($pedido->nombre_planta);

            return $nombre ? self::extraerNombreAlmacen($nombre) : trim($pedido->nombre_planta);
        }

        return self::etiquetaUbicacionLista(
            $pedido->direccion_texto,
            $pedido->latitud !== null ? (float) $pedido->latitud : null,
            $pedido->longitud !== null ? (float) $pedido->longitud : null,
            'planta'
        );
    }

    /**
     * @return array{recogidas: array<int, string>, destino: ?string}|null
     */
    public static function trayectoPartesListaPedido(Pedido $pedido): ?array
    {
        $partes = self::trayectoPartesPedido($pedido);
        if ($partes === null) {
            return null;
        }

        $recogidas = array_values(array_filter(array_map(
            fn (string $rec) => AlmacenNombreCatalogo::etiquetaListaDesdeTexto($rec, 'agricola'),
            $partes['recogidas']
        ), fn (string $etiqueta) => $etiqueta !== '—'));

        $destinoAlmacen = self::buscarAlmacenPorCoordenadas(
            $pedido->latitud !== null ? (float) $pedido->latitud : null,
            $pedido->longitud !== null ? (float) $pedido->longitud : null,
            'planta'
        );
        $destino = $destinoAlmacen !== null
            ? AlmacenNombreCatalogo::etiquetaLista($destinoAlmacen)
            : ($partes['destino'] !== null
                ? AlmacenNombreCatalogo::etiquetaListaDesdeTexto((string) $partes['destino'], 'planta')
                : null);

        if ($recogidas === [] && ($destino === null || $destino === '—')) {
            return null;
        }

        return [
            'recogidas' => $recogidas,
            'destino' => $destino !== '—' ? $destino : null,
        ];
    }

    private static function resolverNombreAlmacenPorCoordenadas(?float $lat, ?float $lng, ?string $ambito = null): ?string
    {
        $almacen = self::buscarAlmacenPorCoordenadas($lat, $lng, $ambito);

        return $almacen ? self::referenciaUbicacionAlmacen($almacen) : null;
    }

    private static function buscarAlmacenPorCoordenadas(?float $lat, ?float $lng, ?string $ambito = null): ?Almacen
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        $mejor = null;
        $mejorDistancia = PHP_FLOAT_MAX;
        $tolerancia = 0.008;

        $query = Almacen::query()->where('activo', true);
        if ($ambito !== null && $ambito !== '') {
            $query->where('ambito', $ambito);
        }

        foreach ($query->get() as $almacen) {
            $coords = UbicacionGpsParser::fromTexto($almacen->ubicacion);
            if ($coords === null) {
                continue;
            }

            $distancia = hypot($coords['lat'] - $lat, $coords['lng'] - $lng);
            if ($distancia <= $tolerancia && $distancia < $mejorDistancia) {
                $mejorDistancia = $distancia;
                $mejor = $almacen;
            }
        }

        return $mejor;
    }

    private static function etiquetaUbicacionLista(?string $texto, ?float $lat, ?float $lng, ?string $ambito): ?string
    {
        $limpio = self::nombreUbicacionLimpio($texto);
        if ($limpio !== null && self::esEtiquetaCortaAlmacen($limpio)) {
            return self::extraerNombreAlmacen($limpio);
        }

        $almacen = self::buscarAlmacenPorCoordenadas($lat, $lng, $ambito);
        if ($almacen !== null) {
            $nombre = trim((string) $almacen->nombre);

            return $nombre !== '' ? $nombre : null;
        }

        if ($texto !== null && str_contains($texto, '·')) {
            $parte = trim(explode('·', $texto, 2)[0]);

            return self::extraerNombreAlmacen($parte);
        }

        if ($limpio !== null) {
            return self::extraerNombreAlmacen($limpio);
        }

        if ($lat !== null && $lng !== null) {
            return $ambito === 'planta' ? 'Almacén de planta' : 'Almacén agrícola';
        }

        return null;
    }

    private static function acortarEtiquetaLista(?string $texto): ?string
    {
        if ($texto === null || trim($texto) === '') {
            return null;
        }

        $texto = trim($texto);
        if (self::esEtiquetaCortaAlmacen($texto)) {
            return self::extraerNombreAlmacen($texto);
        }

        if (str_contains($texto, ' — ') || str_contains($texto, '·')) {
            return self::extraerNombreAlmacen($texto);
        }

        if (preg_match('/^(Almac[eé]n|Almacen)\s+/iu', $texto)) {
            $segmento = trim(explode(',', $texto, 2)[0]);

            return $segmento !== '' ? $segmento : $texto;
        }

        return $texto;
    }

    private static function esEtiquetaCortaAlmacen(string $texto): bool
    {
        if (preg_match('/^(Av\.|Avenida|Calle|Km|Carretera|GPS)/iu', $texto)) {
            return false;
        }

        return mb_strlen($texto) <= 48;
    }

    private static function extraerNombreAlmacen(string $texto): string
    {
        if (str_contains($texto, ' — ')) {
            return trim(explode(' — ', $texto, 2)[0]);
        }

        if (str_contains($texto, '·')) {
            return trim(explode('·', $texto, 2)[0]);
        }

        if (str_contains($texto, ',')) {
            return trim(explode(',', $texto, 2)[0]);
        }

        return trim($texto);
    }

    private static function referenciaUbicacionAlmacen(Almacen $almacen): string
    {
        $nombre = trim((string) $almacen->nombre);
        $resuelto = UbicacionGpsParser::resolverAlmacen(
            (int) $almacen->almacenid,
            $almacen->nombre,
            $almacen->ubicacion
        );
        $calle = self::nombreUbicacionLimpio($resuelto['direccion']);

        if ($calle === null && str_contains((string) $resuelto['direccion'], '·')) {
            $partes = explode('·', (string) $resuelto['direccion'], 2);
            $calle = self::nombreUbicacionLimpio(trim($partes[1] ?? ''));
        }

        if ($calle !== null && $nombre !== '' && ! str_contains(mb_strtolower($calle), mb_strtolower($nombre))) {
            return "{$nombre} — {$calle}";
        }

        if ($calle !== null) {
            return $calle;
        }

        return $nombre !== '' ? $nombre : 'Ubicación registrada';
    }

    private static function nombreUbicacionLimpio(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        if (str_contains($texto, '·')) {
            $nombre = trim(explode('·', $texto, 2)[0]);
            if ($nombre !== '') {
                return $nombre;
            }
        }

        if (preg_match('/^GPS\s*-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?/i', $texto)) {
            return null;
        }

        $sinGps = preg_replace('/\s*·\s*GPS\s*-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?/i', '', $texto);
        $sinGps = preg_replace('/\s*GPS\s*-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?/i', '', (string) $sinGps);
        $sinGps = trim((string) $sinGps, " ·");

        return $sinGps !== '' ? $sinGps : null;
    }

    public static function esRutaRecogidasAutomatica(?RutaMultiEntrega $ruta): bool
    {
        return $ruta !== null && str_starts_with((string) $ruta->nombre, 'Recogidas ');
    }

    /**
     * @return array<int, array{latitud: float, longitud: float, direccion: string}>
     */
    public static function recogidasExtraDesdeEnvio(EnvioAsignacionMultiple $envio): array
    {
        $envio->loadMissing(['ruta.paradas', 'pedido']);

        if (! $envio->ruta?->paradas?->isNotEmpty()) {
            return [];
        }

        $recogidas = $envio->ruta->paradas
            ->filter(fn (RutaParada $p) => str_starts_with((string) $p->destino, 'Recogida:'))
            ->sortBy('orden')
            ->values();

        if ($recogidas->count() <= 1) {
            return [];
        }

        return $recogidas->slice(1)->map(fn (RutaParada $p) => [
            'latitud' => (float) $p->latitud,
            'longitud' => (float) $p->longitud,
            'direccion' => self::etiquetaParada((string) $p->destino, 'Recogida:'),
        ])->values()->all();
    }

    /**
     * @param  array{
     *     transportista_usuarioid?: int|null,
     *     vehiculoid?: int|null,
     *     vehiculo_ref?: string|null,
     *     rutamultientregaid?: int|null,
     *     fechaEntregaDeseada?: string|null,
     *     origen_latitud?: float,
     *     origen_longitud?: float,
     *     origen_direccion?: string|null,
     *     recogidas?: array<int, array{latitud: float|int|string, longitud: float|int|string, direccion?: string|null}>
     * }  $datos
     */
    public static function actualizarAsignacionEnvio(
        EnvioAsignacionMultiple $envio,
        array $datos,
        int $editorId,
        string $nivelEdicion = PedidoCatalogo::EDICION_ASIGNACION_COMPLETA
    ): void {
        $envio->loadMissing(['pedido', 'ruta.paradas']);

        $transportistaId = ! empty($datos['transportista_usuarioid'])
            ? (int) $datos['transportista_usuarioid']
            : null;

        if ($nivelEdicion === PedidoCatalogo::EDICION_ASIGNACION_SOLO_TRANSPORTISTA) {
            $vehiculoRef = $transportistaId !== null
                ? (self::placaTransportista($transportistaId) ?? $envio->vehiculo_ref)
                : null;

            $envio->update([
                'transportista_usuarioid' => $transportistaId,
                'vehiculo_ref' => $vehiculoRef,
            ]);

            return;
        }

        $pedido = $envio->pedido;

        $vehiculoRef = null;
        if (! empty($datos['vehiculoid'])) {
            $vehiculoRef = Vehiculo::query()
                ->where('vehiculoid', (int) $datos['vehiculoid'])
                ->value('placa');
        } elseif (array_key_exists('vehiculo_ref', $datos)) {
            $vehiculoRef = $datos['vehiculo_ref'] !== '' ? $datos['vehiculo_ref'] : null;
        }

        if ($transportistaId && ! empty($datos['vehiculoid']) && $pedido !== null) {
            $transportista = Usuario::query()
                ->where('usuarioid', $transportistaId)
                ->where('role', 'transportista')
                ->where('activo', true)
                ->first();

            $vehiculo = Vehiculo::query()
                ->with('tipoVehiculo')
                ->where('vehiculoid', (int) $datos['vehiculoid'])
                ->where('activo', true)
                ->first();

            if ($transportista && $vehiculo) {
                $capacidad = app(TransporteCapacidadService::class);
                $capacidad->validarAsignacion($transportista, $vehiculo);
                $capacidad->validarCarga($vehiculo, $capacidad->pesoPedido($pedido->loadMissing('detalles')));
            }
        }

        if ($pedido !== null) {
            $camposPedido = [];
            if (array_key_exists('fechaEntregaDeseada', $datos)) {
                $camposPedido['fechaEntregaDeseada'] = $datos['fechaEntregaDeseada'] ?: now()->toDateString();
            }
            if (isset($datos['origen_latitud'], $datos['origen_longitud'])) {
                $camposPedido['origen_latitud'] = (float) $datos['origen_latitud'];
                $camposPedido['origen_longitud'] = (float) $datos['origen_longitud'];
                $camposPedido['origen_direccion'] = $datos['origen_direccion'] ?? null;
            }
            if ($camposPedido !== []) {
                $pedido->update($camposPedido);
            }
        }

        $rutaCatalogoId = array_key_exists('rutamultientregaid', $datos) && ! empty($datos['rutamultientregaid'])
            ? (int) $datos['rutamultientregaid']
            : null;
        $recogidasExtra = array_values($datos['recogidas'] ?? []);

        if ($rutaCatalogoId) {
            $envio->update([
                'transportista_usuarioid' => $transportistaId,
                'vehiculo_ref' => $vehiculoRef,
                'rutamultientregaid' => $rutaCatalogoId,
            ]);

            return;
        }

        $rutaAnterior = $envio->ruta;
        if ($rutaAnterior !== null && self::esRutaRecogidasAutomatica($rutaAnterior)) {
            $rutaAnterior->paradas()->delete();
            $rutaAnterior->delete();
            $envio->update(['rutamultientregaid' => null]);
            $envio->unsetRelation('ruta');
        }

        $envio->update([
            'transportista_usuarioid' => $transportistaId,
            'vehiculo_ref' => $vehiculoRef,
        ]);

        if ($pedido !== null && $recogidasExtra !== []) {
            self::crearRutaRecogidasMultiples(
                $pedido->fresh(),
                $envio->fresh(),
                $recogidasExtra,
                $transportistaId,
                $editorId
            );
        }
    }
}
