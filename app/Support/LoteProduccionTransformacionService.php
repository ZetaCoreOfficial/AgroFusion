<?php

namespace App\Support;

use App\Models\AsignacionEtapaPlanta;
use App\Models\LoteProduccionPedido;
use App\Models\PlantillaTransformacion;
use App\Models\PlantillaTransformacionPaso;
use App\Models\ProcesoMaquinaPlanta;
use App\Models\ProcesoPlanta;
use App\Models\RegistroProcesoMaquinaPlanta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class LoteProduccionTransformacionService
{
    public function registrosOrdenados(LoteProduccionPedido $lote): Collection
    {
        return RegistroProcesoMaquinaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->orderBy('hora_inicio')
            ->orderBy('registroprocesomaquinaplantaid')
            ->with(['procesoMaquina.proceso', 'procesoMaquina.maquina', 'usuario'])
            ->get();
    }

    public function transformacionCompleta(LoteProduccionPedido $lote): bool
    {
        if ($this->tieneAsignacionesActivasPendientes($lote)) {
            return false;
        }

        $ruta = $this->rutaPlantilla($lote);
        if ($ruta !== []) {
            return $this->transformacionCompletaSegunRuta($ruta, $lote);
        }

        $registros = $this->registrosOrdenados($lote);
        if ($registros->isEmpty()) {
            return false;
        }

        $plantilla = $this->plantillaDelLote($lote);
        if ($plantilla) {
            $plantilla->loadMissing(['pasos.proceso']);
            $totalPasos = $plantilla->pasos->count();
            if ($totalPasos === 0 || $registros->count() < $totalPasos) {
                return false;
            }

            if (! $this->procesosDeRutaRegistrados($plantilla->pasos->pluck('procesoplantaid'), $registros)) {
                return false;
            }

            $ultimoPasoPlantilla = $plantilla->pasos->sortByDesc('orden')->first();
            $nombreUltimoPaso = $ultimoPasoPlantilla?->proceso?->nombre ?? '';

            return ProcesoPlantaCatalogo::esCierreTransformacion($nombreUltimoPaso)
                && $this->registroCierreTransformacion($registros);
        }

        $ultimo = $registros->last();
        $nombre = $ultimo->procesoMaquina?->proceso?->nombre;

        return ProcesoPlantaCatalogo::esCierreTransformacion($nombre);
    }

    private function tieneAsignacionesActivasPendientes(LoteProduccionPedido $lote): bool
    {
        if ($this->procesosRutaCompletos($lote)) {
            return false;
        }

        if ($this->tieneAsignacionesPendientes($lote)) {
            return true;
        }

        return AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->where('estado', AsignacionEtapaPlanta::ESTADO_PROGRAMADA)
            ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $ruta
     */
    private function transformacionCompletaSegunRuta(array $ruta, LoteProduccionPedido $lote): bool
    {
        $coleccion = collect($ruta);
        if ($coleccion->isEmpty()) {
            return false;
        }

        if (! $coleccion->every(fn (array $paso) => ($paso['estado'] ?? '') === 'hecho')) {
            return false;
        }

        $ultimo = $coleccion->sortByDesc('orden')->first();
        $nombreUltimoPaso = $ultimo['proceso'] ?? '';
        if (! ProcesoPlantaCatalogo::esCierreTransformacion($nombreUltimoPaso)) {
            return false;
        }

        $registros = $this->registrosOrdenados($lote);
        if ($registros->isEmpty()) {
            return false;
        }

        if (! $this->procesosDeRutaRegistrados($coleccion->pluck('procesoplantaid'), $registros)) {
            return false;
        }

        return $this->registroCierreTransformacion($registros);
    }

    private function procesosDeRutaRegistrados(Collection $procesosRuta, Collection $registros): bool
    {
        $procesosRegistrados = $registros
            ->map(fn (RegistroProcesoMaquinaPlanta $registro) => (int) $registro->procesoMaquina?->procesoplantaid)
            ->filter()
            ->unique();

        foreach ($procesosRuta as $procesoId) {
            if (! $procesosRegistrados->contains((int) $procesoId)) {
                return false;
            }
        }

        return true;
    }

    private function registroCierreTransformacion(Collection $registros): bool
    {
        return $registros->contains(
            fn (RegistroProcesoMaquinaPlanta $registro) => ProcesoPlantaCatalogo::esCierreTransformacion(
                $registro->procesoMaquina?->proceso?->nombre
            )
        );
    }

    public function etapasCompletadasCount(LoteProduccionPedido $lote): int
    {
        return $this->pasosRutaCompletadosEnSecuencia($lote);
    }

    public function pasosRutaCompletadosEnSecuencia(LoteProduccionPedido $lote): int
    {
        $rutaService = app(LoteProduccionRutaService::class);

        if ($rutaService->tieneRuta($lote)) {
            return $this->pasosRutaCompletadosConAsignaciones($lote, $rutaService->pasosOrdenados($lote))
                ?? $this->pasosRutaCompletadosConRegistros($rutaService->pasosOrdenados($lote), $this->registrosOrdenados($lote));
        }

        $plantilla = $this->plantillaDelLote($lote);
        if ($plantilla) {
            $plantilla->loadMissing('pasos');

            return $this->pasosRutaCompletadosConAsignaciones($lote, $plantilla->pasos->sortBy('orden'))
                ?? $this->pasosRutaCompletadosConRegistros($plantilla->pasos->sortBy('orden'), $this->registrosOrdenados($lote));
        }

        $porAsignaciones = $this->pasosCompletadosPorAsignacionesOrden($lote);
        if ($porAsignaciones !== null) {
            return $porAsignaciones;
        }

        return $this->registrosOrdenados($lote)->count();
    }

    /**
     * Cuenta etapas completadas en secuencia según asignaciones (orden 1, 2, …) cuando no hay ruta/plantilla.
     */
    private function pasosCompletadosPorAsignacionesOrden(LoteProduccionPedido $lote): ?int
    {
        if (! Schema::hasColumn('asignacion_etapa_planta', 'orden')) {
            return null;
        }

        $asignaciones = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->whereIn('estado', [
                AsignacionEtapaPlanta::ESTADO_COMPLETADA,
                AsignacionEtapaPlanta::ESTADO_PENDIENTE,
                AsignacionEtapaPlanta::ESTADO_PROGRAMADA,
            ])
            ->orderBy('orden')
            ->get();

        if ($asignaciones->isEmpty()) {
            return null;
        }

        $count = 0;
        foreach ($asignaciones as $asignacion) {
            if ($asignacion->estado === AsignacionEtapaPlanta::ESTADO_COMPLETADA) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * @param  Collection<int, \App\Models\LoteProduccionRutaPaso|PlantillaTransformacionPaso>  $pasos
     */
    private function pasosRutaCompletadosConAsignaciones(LoteProduccionPedido $lote, Collection $pasos): ?int
    {
        if (! Schema::hasColumn('asignacion_etapa_planta', 'orden')) {
            return null;
        }

        $asignaciones = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->whereIn('estado', [
                AsignacionEtapaPlanta::ESTADO_COMPLETADA,
                AsignacionEtapaPlanta::ESTADO_PENDIENTE,
                AsignacionEtapaPlanta::ESTADO_PROGRAMADA,
            ])
            ->get()
            ->keyBy(fn (AsignacionEtapaPlanta $a) => (int) $a->orden);

        if ($asignaciones->isEmpty()) {
            return null;
        }

        $count = 0;
        foreach ($pasos as $paso) {
            $asig = $asignaciones->get((int) $paso->orden);
            if ($asig && $asig->estado === AsignacionEtapaPlanta::ESTADO_COMPLETADA) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Empareja cada paso de la ruta con un registro distinto (evita marcar dos pasos iguales al completar uno).
     *
     * @param  Collection<int, \App\Models\LoteProduccionRutaPaso|PlantillaTransformacionPaso>  $pasos
     * @param  Collection<int, RegistroProcesoMaquinaPlanta>  $registros
     */
    private function pasosRutaCompletadosConRegistros(Collection $pasos, Collection $registros): int
    {
        $usados = collect();
        $count = 0;

        foreach ($pasos as $paso) {
            $match = $registros->first(function (RegistroProcesoMaquinaPlanta $reg) use ($paso, $usados) {
                if ($usados->contains($reg->registroprocesomaquinaplantaid)) {
                    return false;
                }

                $procesoId = (int) ($reg->procesoMaquina?->procesoplantaid ?? 0);
                $maquinaId = (int) ($reg->procesoMaquina?->maquinaplantaid ?? 0);

                return $procesoId === (int) $paso->procesoplantaid
                    && $maquinaId === (int) ($paso->maquinaplantaid ?? 0);
            });

            if ($match) {
                $usados->push($match->registroprocesomaquinaplantaid);
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    public function procesosRutaCompletos(LoteProduccionPedido $lote): bool
    {
        $rutaService = app(LoteProduccionRutaService::class);

        if ($rutaService->tieneRuta($lote)) {
            $pasos = $rutaService->pasosOrdenados($lote);
            if ($pasos->isEmpty()) {
                return false;
            }

            return $this->pasosRutaCompletadosEnSecuencia($lote) >= $pasos->count();
        }

        $plantilla = $this->plantillaDelLote($lote);
        if (! $plantilla) {
            return false;
        }

        $plantilla->loadMissing('pasos');
        if ($plantilla->pasos->isEmpty()) {
            return false;
        }

        return $this->pasosRutaCompletadosEnSecuencia($lote) >= $plantilla->pasos->count();
    }

    public function pasoEstaRegistrado(LoteProduccionPedido $lote, int $procesoplantaid): bool
    {
        return in_array($procesoplantaid, $this->procesosRegistradosIds($lote), true);
    }

    public function ordenPasoActual(LoteProduccionPedido $lote): int
    {
        return $this->etapasCompletadasCount($lote) + 1;
    }

    /** @return Collection<int, AsignacionEtapaPlanta> */
    public function asignacionesPendientes(LoteProduccionPedido $lote): Collection
    {
        $ordenActual = $this->ordenPasoActual($lote);

        $query = AsignacionEtapaPlanta::query()
            ->pendientes()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->with(['proceso', 'maquina', 'operador']);

        if (Schema::hasColumn('asignacion_etapa_planta', 'orden')) {
            $query->where('orden', $ordenActual);
        }

        $paso = $this->pasoPlantillaActual($lote);
        if ($paso) {
            $query->where('procesoplantaid', (int) $paso->procesoplantaid);
        }

        return $query->orderByDesc('creado_en')->get();
    }

    /** @return Collection<int, AsignacionEtapaPlanta> */
    public function asignacionesActivas(LoteProduccionPedido $lote): Collection
    {
        return AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->activas()
            ->with(['proceso', 'maquina', 'operador'])
            ->orderBy('orden')
            ->orderBy('asignacionetapaplantaid')
            ->get();
    }

    /** @return list<int> */
    public function pasosRutaPasoIdsBloqueadosReorden(LoteProduccionPedido $lote): array
    {
        if (! Schema::hasColumn('asignacion_etapa_planta', 'loteproduccionrutapasoid')) {
            return [];
        }

        return AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->whereIn('estado', [
                AsignacionEtapaPlanta::ESTADO_PENDIENTE,
                AsignacionEtapaPlanta::ESTADO_PROGRAMADA,
                AsignacionEtapaPlanta::ESTADO_COMPLETADA,
            ])
            ->whereNotNull('loteproduccionrutapasoid')
            ->pluck('loteproduccionrutapasoid')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function puedeAsignarPlanEtapas(LoteProduccionPedido $lote): bool
    {
        return app(AsignacionEtapaPlantaService::class)->puedeAsignarPlan($lote);
    }

    public function limpiarAsignacionesObsoletas(LoteProduccionPedido $lote): int
    {
        if ($this->procesosRutaCompletos($lote)) {
            return AsignacionEtapaPlanta::query()
                ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
                ->activas()
                ->update(['estado' => AsignacionEtapaPlanta::ESTADO_CANCELADA]);
        }

        $query = AsignacionEtapaPlanta::query()
            ->pendientes()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid);

        if (app(LoteProduccionTrazabilidadService::class)->transformacionCompleta($lote)) {
            return $query->update(['estado' => AsignacionEtapaPlanta::ESTADO_CANCELADA]);
        }

        $paso = $this->pasoPlantillaActual($lote);
        if (! $paso) {
            return $query->update(['estado' => AsignacionEtapaPlanta::ESTADO_CANCELADA]);
        }

        return (clone $query)
            ->where('procesoplantaid', '!=', (int) $paso->procesoplantaid)
            ->update(['estado' => AsignacionEtapaPlanta::ESTADO_CANCELADA]);
    }

    public function tieneAsignacionesPendientes(LoteProduccionPedido $lote): bool
    {
        return $this->asignacionesPendientes($lote)->isNotEmpty();
    }

    public function pasoPlantillaActual(LoteProduccionPedido $lote): ?PlantillaTransformacionPaso
    {
        $rutaService = app(LoteProduccionRutaService::class);
        if ($rutaService->tieneRuta($lote)) {
            $pasoRuta = $rutaService->pasoEnOrden($lote, $this->ordenPasoActual($lote));
            if (! $pasoRuta) {
                return null;
            }

            $pasoRuta->loadMissing(['proceso', 'maquina']);

            return PlantillaTransformacionPaso::make([
                'orden' => $pasoRuta->orden,
                'procesoplantaid' => $pasoRuta->procesoplantaid,
                'maquinaplantaid' => $pasoRuta->maquinaplantaid,
                'notas' => $pasoRuta->notas,
                'plantillapasoid' => $pasoRuta->plantillapasoid,
            ])->setRelation('proceso', $pasoRuta->proceso)
                ->setRelation('maquina', $pasoRuta->maquina);
        }

        $plantilla = $this->plantillaDelLote($lote);
        if (! $plantilla) {
            return null;
        }

        return $plantilla->pasos()
            ->with(['proceso', 'maquina'])
            ->where('orden', $this->ordenPasoActual($lote))
            ->first();
    }

    public function esUltimaEtapaPlantilla(LoteProduccionPedido $lote): bool
    {
        $rutaService = app(LoteProduccionRutaService::class);
        if ($rutaService->tieneRuta($lote)) {
            $maxOrden = (int) $rutaService->pasosOrdenados($lote)->max('orden');

            return $this->ordenPasoActual($lote) >= $maxOrden;
        }

        $plantilla = $this->plantillaDelLote($lote);
        if (! $plantilla) {
            return false;
        }

        $maxOrden = (int) $plantilla->pasos()->max('orden');

        return $this->ordenPasoActual($lote) >= $maxOrden;
    }

    public function pendienteEsUltimaEtapaPlantilla(LoteProduccionPedido $lote): bool
    {
        return $this->tieneAsignacionesPendientes($lote) && $this->esUltimaEtapaPlantilla($lote);
    }

    public function puedeAsignarNuevaEtapa(LoteProduccionPedido $lote): bool
    {
        if (app(AsignacionEtapaPlantaService::class)->tienePlanConfirmado($lote)) {
            return false;
        }

        if ($this->plantillaAgotada($lote)) {
            return false;
        }

        if ($this->tieneAsignacionesPendientes($lote)) {
            return false;
        }

        $plantilla = $this->plantillaDelLote($lote);
        if ($plantilla && ! $this->pasoPlantillaActual($lote)) {
            return false;
        }

        return true;
    }

    public function mensajeBloqueoAsignacion(LoteProduccionPedido $lote): ?string
    {
        if ($this->plantillaAgotada($lote)) {
            return 'Ya registró todos los pasos del proceso de transformación.';
        }

        if ($this->tieneAsignacionesPendientes($lote)) {
            if ($this->pendienteEsUltimaEtapaPlantilla($lote)) {
                return null;
            }

            if (app(AsignacionEtapaPlantaService::class)->tienePlanConfirmado($lote)) {
                return null;
            }

            return 'Hay asignaciones pendientes. Márquelas como completadas antes de asignar la siguiente etapa.';
        }

        $paso = $this->pasoPlantillaActual($lote);
        if ($this->plantillaDelLote($lote) && ! $paso) {
            return 'Ya registró todos los pasos del proceso de transformación.';
        }

        return null;
    }

    public function validarProcesoParaAsignar(LoteProduccionPedido $lote, int $procesoplantaid): ?string
    {
        $bloqueo = $this->mensajeBloqueoAsignacion($lote);
        if ($bloqueo !== null) {
            return $bloqueo;
        }

        $paso = $this->pasoPlantillaActual($lote);
        if ($paso && (int) $paso->procesoplantaid !== $procesoplantaid) {
            $procesoIntentado = ProcesoPlanta::query()->find($procesoplantaid);
            $nombreIntentado = $procesoIntentado?->nombre ?? 'ese proceso';
            $etapaAnterior = max(1, (int) $paso->orden - 1);

            return 'Debe completar primero la etapa '.$etapaAnterior
                .' antes de asignar «'.$nombreIntentado.'». Etapa actual: «'.$paso->proceso?->nombre.'».';
        }

        return null;
    }

    /**
     * @return Collection<int, ProcesoPlanta>
     */
    public function procesosDisponiblesParaAsignar(LoteProduccionPedido $lote): Collection
    {
        $todos = ProcesoPlantaCatalogo::paraTransformacion();
        if (! $this->puedeAsignarNuevaEtapa($lote)) {
            return collect();
        }

        $paso = $this->pasoPlantillaActual($lote);
        if ($paso) {
            return $todos->where('procesoplantaid', (int) $paso->procesoplantaid)->values();
        }

        return $todos;
    }

    public function plantillaAgotada(LoteProduccionPedido $lote): bool
    {
        $rutaService = app(LoteProduccionRutaService::class);
        if ($rutaService->tieneRuta($lote)) {
            return $this->procesosRutaCompletos($lote);
        }

        $plantilla = $this->plantillaDelLote($lote);
        if (! $plantilla) {
            return false;
        }

        return $this->procesosRutaCompletos($lote);
    }

    public function transformacionIniciada(LoteProduccionPedido $lote): bool
    {
        return $this->registrosOrdenados($lote)->isNotEmpty();
    }

    /**
     * @return list<array{numero: int, registro: RegistroProcesoMaquinaPlanta, proceso: string, maquina: string, inicio: ?\Carbon\Carbon, fin: ?\Carbon\Carbon, observaciones: ?string, operador: ?string, es_cierre: bool}>
     */
    public function timeline(LoteProduccionPedido $lote): array
    {
        $paramService = app(LoteProduccionParametrosService::class);
        $items = [];
        $num = 1;

        foreach ($this->registrosOrdenados($lote) as $registro) {
            $proceso = $registro->procesoMaquina?->proceso?->nombre ?? '—';
            $items[] = [
                'numero' => $num++,
                'registro' => $registro,
                'proceso' => $proceso,
                'maquina' => $registro->procesoMaquina?->maquina?->nombre ?? '—',
                'inicio' => $registro->hora_inicio,
                'fin' => $registro->hora_fin,
                'observaciones' => $registro->observaciones,
                'operador' => $registro->usuario?->nombre,
                'es_cierre' => ProcesoPlantaCatalogo::esCierreTransformacion($proceso),
                'parametros_medidos' => $paramService->parametrosDesdeRegistroJson($registro->variables_ingresadas),
            ];
        }

        return $items;
    }

    /**
     * Timeline enriquecido para vista (ruta + completados + imágenes).
     *
     * @return list<array<string, mixed>>
     */
    public function timelineVisual(LoteProduccionPedido $lote): array
    {
        $ruta = $this->rutaPlantilla($lote);
        $completados = $this->timeline($lote);
        $porOrden = collect($completados)->keyBy('numero');
        $asignacionesActivas = collect($this->asignacionesActivas($lote))->keyBy('orden');

        if ($ruta === []) {
            return array_map(function (array $item) {
                $maq = null;
                $reg = $item['registro'] ?? null;
                if ($reg?->procesoMaquina?->maquina) {
                    $maq = $reg->procesoMaquina->maquina;
                }

                return array_merge($item, [
                    'orden' => $item['numero'],
                    'estado' => 'hecho',
                    'imagen_src' => $maq?->imagenSrc(),
                    'maquina_codigo' => $maq?->codigo,
                    'parametros_rango' => [],
                ]);
            }, $completados);
        }

        $items = [];
        foreach ($ruta as $paso) {
            $reg = null;
            if ($paso['estado'] === 'hecho') {
                $idx = (int) $paso['orden'];
                $reg = $porOrden->get($idx);
            }

            $maqImg = null;
            $maqCodigo = null;
            if ($reg && $reg['registro']?->procesoMaquina?->maquina) {
                $maq = $reg['registro']->procesoMaquina->maquina;
                $maqImg = $maq->imagenSrc();
                $maqCodigo = $maq->codigo;
            } elseif (! empty($paso['maquinaplantaid'])) {
                $maq = \App\Models\MaquinaPlanta::query()->find($paso['maquinaplantaid']);
                $maqImg = $maq?->imagenSrc();
                $maqCodigo = $maq?->codigo;
            }

            $asig = $asignacionesActivas->get((int) $paso['orden']);

            $items[] = [
                'loteproduccionrutapasoid' => (int) ($paso['loteproduccionrutapasoid'] ?? 0),
                'procesoplantaid' => (int) ($paso['procesoplantaid'] ?? 0),
                'maquinaplantaid' => $paso['maquinaplantaid'] ? (int) $paso['maquinaplantaid'] : null,
                'orden' => (int) $paso['orden'],
                'proceso' => $paso['proceso'],
                'maquina' => $paso['maquina'],
                'maquina_codigo' => $maqCodigo,
                'estado' => $paso['estado'],
                'notas' => $paso['notas'] ?? null,
                'imagen_src' => $maqImg,
                'parametros_rango' => $paso['parametros'] ?? [],
                'parametros_medidos' => $reg['parametros_medidos'] ?? [],
                'inicio' => $reg['inicio'] ?? null,
                'fin' => $reg['fin'] ?? null,
                'operador' => $reg['operador'] ?? ($asig?->operador?->nombreCompleto()),
                'operador_asignado' => $asig?->operador?->nombreCompleto(),
                'operador_usuarioid' => $asig?->operador_usuarioid ? (int) $asig->operador_usuarioid : null,
                'estado_asignacion' => $asig?->estado,
                'asignacion_id' => $asig?->asignacionetapaplantaid,
                'es_cierre' => ProcesoPlantaCatalogo::esCierreTransformacion($paso['proceso']),
                'editable' => ! empty($paso['editable']),
                'asignacion_bloqueada' => ! empty($paso['editable']) ? false : (
                    ($paso['estado'] ?? '') !== 'hecho'
                    && in_array((int) ($paso['loteproduccionrutapasoid'] ?? 0), $this->pasosRutaPasoIdsBloqueadosReorden($lote), true)
                ),
            ];
        }

        return $items;
    }

    public function procesoYaRegistrado(LoteProduccionPedido $lote, int $procesoplantaid): bool
    {
        return $this->registrosOrdenados($lote)->contains(function (RegistroProcesoMaquinaPlanta $reg) use ($procesoplantaid) {
            return (int) ($reg->procesoMaquina?->procesoplantaid ?? 0) === $procesoplantaid;
        });
    }

    /**
     * @return list<int>
     */
    public function procesosRegistradosIds(LoteProduccionPedido $lote): array
    {
        return $this->registrosOrdenados($lote)
            ->map(fn (RegistroProcesoMaquinaPlanta $r) => (int) ($r->procesoMaquina?->procesoplantaid ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function resolverPasoProcesoMaquina(int $procesoplantaid, int $maquinaplantaid): ProcesoMaquinaPlanta
    {
        if (! MaquinaProcesoCompatibilidad::compatible($procesoplantaid, $maquinaplantaid)) {
            throw new \InvalidArgumentException('La maquinaria seleccionada no corresponde a ese proceso de planta.');
        }

        $existente = ProcesoMaquinaPlanta::query()
            ->where('procesoplantaid', $procesoplantaid)
            ->where('maquinaplantaid', $maquinaplantaid)
            ->first();

        if ($existente) {
            return $existente;
        }

        $proceso = ProcesoPlanta::query()->find($procesoplantaid);
        $orden = (int) ProcesoMaquinaPlanta::query()
            ->where('procesoplantaid', $procesoplantaid)
            ->max('orden_paso');

        return ProcesoMaquinaPlanta::create([
            'procesoplantaid' => $procesoplantaid,
            'maquinaplantaid' => $maquinaplantaid,
            'orden_paso' => max(1, $orden + 1),
            'nombre' => $proceso?->nombre ?? 'Paso',
            'descripcion' => 'Vínculo generado automáticamente desde plantilla de transformación.',
        ]);
    }

    public function plantillaDelLote(LoteProduccionPedido $lote): ?PlantillaTransformacion
    {
        if (! $lote->plantillatransformacionid) {
            return null;
        }

        return PlantillaTransformacionResolver::resolverPorId((int) $lote->plantillatransformacionid);
    }

    /**
     * @return list<array{orden: int, proceso: string, maquina: ?string, procesoplantaid: int, maquinaplantaid: ?int, notas: ?string, estado: string}>
     */
    public function rutaPlantilla(LoteProduccionPedido $lote): array
    {
        $rutaService = app(LoteProduccionRutaService::class);
        if ($rutaService->tieneRuta($lote)) {
            return $rutaService->rutaParaVista($lote);
        }

        $plantilla = $this->plantillaDelLote($lote);
        if (! $plantilla) {
            return [];
        }

        $plantilla->loadMissing(['pasos.proceso', 'pasos.maquina']);
        $parametrosPorPaso = collect(
            app(LoteProduccionParametrosService::class)->parametrosEfectivosPorPlantilla($lote, $plantilla)
        )->keyBy('plantillapasoid');

        $completados = $this->pasosRutaCompletadosEnSecuencia($lote);
        $ordenActual = $completados + 1;
        $hayPendiente = $this->tieneAsignacionesPendientes($lote);
        $items = [];

        foreach ($plantilla->pasos as $paso) {
            $orden = (int) $paso->orden;
            $procesoHecho = $orden <= $completados;
            $estado = match (true) {
                $procesoHecho => 'hecho',
                $orden === $ordenActual && $hayPendiente => 'en_curso',
                $orden === $ordenActual => 'actual',
                default => 'bloqueado',
            };

            $items[] = [
                'orden' => $orden,
                'proceso' => $paso->proceso?->nombre ?? '—',
                'maquina' => $paso->maquina?->nombre,
                'procesoplantaid' => (int) $paso->procesoplantaid,
                'maquinaplantaid' => $paso->maquinaplantaid ? (int) $paso->maquinaplantaid : null,
                'notas' => $paso->notas,
                'estado' => $estado,
                'parametros' => $parametrosPorPaso->get($paso->plantillapasoid)['variables'] ?? [],
            ];
        }

        return $items;
    }

    public function siguientePasoPlantilla(LoteProduccionPedido $lote): ?PlantillaTransformacionPaso
    {
        if ($this->tieneAsignacionesPendientes($lote)) {
            return null;
        }

        return $this->pasoPlantillaActual($lote);
    }

    /** @return ?array{orden: int, total_etapas: int, proceso_nombre: string, procesoplantaid: int, maquinaplantaid: ?int, maquina_nombre: ?string, maquina_codigo: ?string, notas: ?string, parametros_preview: list<array<string, mixed>>} */
    public function datosEtapaAsignacion(LoteProduccionPedido $lote): ?array
    {
        if ($this->tieneAsignacionesPendientes($lote)) {
            return null;
        }

        $paso = $this->pasoPlantillaActual($lote);
        if (! $paso) {
            return null;
        }

        $orden = $this->ordenPasoActual($lote);
        $rutaService = app(LoteProduccionRutaService::class);
        $totalEtapas = $rutaService->tieneRuta($lote)
            ? (int) $rutaService->pasosOrdenados($lote)->count()
            : (int) ($this->plantillaDelLote($lote)?->pasos()->count() ?? 0);

        return [
            'orden' => $orden,
            'total_etapas' => $totalEtapas,
            'proceso_nombre' => $paso->proceso?->nombre ?? '—',
            'procesoplantaid' => (int) $paso->procesoplantaid,
            'maquinaplantaid' => $paso->maquinaplantaid ? (int) $paso->maquinaplantaid : null,
            'maquina_nombre' => $paso->maquina?->nombre,
            'maquina_codigo' => $paso->maquina?->codigo,
            'notas' => $paso->notas,
            'parametros_preview' => app(LoteProduccionParametrosService::class)
                ->parametrosParaEtapaActual($lote, (int) ($paso->maquinaplantaid ?? 0)),
        ];
    }
}
