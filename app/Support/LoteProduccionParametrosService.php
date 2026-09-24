<?php

namespace App\Support;

use App\Models\AsignacionEtapaPlanta;
use App\Models\LoteProduccionPasoVariable;
use App\Models\LoteProduccionPedido;
use App\Models\MaquinaVariablePlanta;
use App\Models\PlantillaTransformacion;
use App\Models\PlantillaTransformacionPaso;
use App\Models\PlantillaTransformacionPasoVariable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class LoteProduccionParametrosService
{
    /**
     * @param  list<array{plantillapasoid: int, variableestandarid: int, valor_minimo: float|int|string, valor_maximo: float|int|string}>|null  $overrides
     */
    public function sincronizarDesdeLote(
        LoteProduccionPedido $lote,
        ?int $plantillatransformacionid,
        ?array $overrides = null,
    ): void {
        if (! Schema::hasTable('lote_produccion_paso_variable')) {
            return;
        }

        LoteProduccionPasoVariable::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->delete();

        if ($plantillatransformacionid === null || $overrides === null || $overrides === []) {
            return;
        }

        $plantilla = PlantillaTransformacion::query()
            ->with(['pasos.variables', 'pasos.maquina'])
            ->find($plantillatransformacionid);

        if (! $plantilla) {
            return;
        }

        $pasosPorId = $plantilla->pasos->keyBy('plantillapasoid');

        foreach ($overrides as $fila) {
            $pasoId = (int) ($fila['plantillapasoid'] ?? 0);
            $varId = (int) ($fila['variableestandarid'] ?? 0);
            if ($pasoId <= 0 || $varId <= 0) {
                continue;
            }

            /** @var PlantillaTransformacionPaso|null $paso */
            $paso = $pasosPorId->get($pasoId);
            if (! $paso) {
                continue;
            }

            $min = (float) ($fila['valor_minimo'] ?? 0);
            $max = (float) ($fila['valor_maximo'] ?? 0);

            $defecto = $paso->variables->firstWhere('variableestandarid', $varId);
            if ($defecto
                && abs($min - (float) $defecto->valor_minimo) < 0.001
                && abs($max - (float) $defecto->valor_maximo) < 0.001) {
                continue;
            }

            $maqId = (int) ($paso->maquinaplantaid ?? 0);
            $error = ParametroRangoPlanta::validarRango($maqId > 0 ? $maqId : null, $varId, $min, $max);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }

            LoteProduccionPasoVariable::create([
                'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
                'plantillapasoid' => $pasoId,
                'variableestandarid' => $varId,
                'valor_minimo' => $min,
                'valor_maximo' => $max,
                'obligatorio' => true,
            ]);
        }
    }

    /**
     * @return Collection<int, LoteProduccionPasoVariable>
     */
    public function overridesDelLote(LoteProduccionPedido $lote): Collection
    {
        if (! Schema::hasTable('lote_produccion_paso_variable')) {
            return collect();
        }

        return LoteProduccionPasoVariable::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->with(['variableEstandar', 'pasoPlantilla.proceso'])
            ->get();
    }

    /**
     * @return list<array{
     *   plantillapasoid: int,
     *   orden: int,
     *   proceso: string,
     *   maquina: ?string,
     *   maquinaplantaid: ?int,
     *   variables: list<array{
     *     variableestandarid: int,
     *     nombre: string,
     *     unidad: ?string,
     *     valor_minimo: float,
     *     valor_maximo: float,
     *     maq_minimo: ?float,
     *     maq_maximo: ?float,
     *     es_override: bool,
     *     obligatorio: bool
     *   }>
     * }>
     */
    public function parametrosEfectivosPorPlantilla(LoteProduccionPedido $lote, ?PlantillaTransformacion $plantilla = null): array
    {
        $plantilla ??= app(LoteProduccionTransformacionService::class)->plantillaDelLote($lote);
        if (! $plantilla) {
            return [];
        }

        $plantilla->loadMissing(['pasos.proceso', 'pasos.maquina', 'pasos.variables.variableEstandar']);

        $overrides = $this->overridesDelLote($lote)
            ->groupBy(fn (LoteProduccionPasoVariable $o) => $o->plantillapasoid.'-'.$o->variableestandarid);

        $items = [];

        foreach ($plantilla->pasos as $paso) {
            $vars = [];

            foreach ($paso->variables as $pv) {
                /** @var PlantillaTransformacionPasoVariable $pv */
                $key = $paso->plantillapasoid.'-'.$pv->variableestandarid;
                $override = $overrides->get($key)?->first();
                $maqId = (int) ($paso->maquinaplantaid ?? 0);
                $maqLim = $maqId > 0
                    ? ParametroRangoPlanta::limitesMaquina($maqId, (int) $pv->variableestandarid)
                    : null;

                $vars[] = [
                    'variableestandarid' => (int) $pv->variableestandarid,
                    'nombre' => $pv->variableEstandar?->nombre ?? '—',
                    'unidad' => $pv->variableEstandar?->unidad,
                    'valor_minimo' => $override ? (float) $override->valor_minimo : (float) $pv->valor_minimo,
                    'valor_maximo' => $override ? (float) $override->valor_maximo : (float) $pv->valor_maximo,
                    'maq_minimo' => $maqLim['min'] ?? null,
                    'maq_maximo' => $maqLim['max'] ?? null,
                    'es_override' => $override !== null,
                    'obligatorio' => (bool) ($override?->obligatorio ?? $pv->obligatorio ?? true),
                ];
            }

            $items[] = [
                'plantillapasoid' => (int) $paso->plantillapasoid,
                'orden' => (int) $paso->orden,
                'proceso' => $paso->proceso?->nombre ?? '—',
                'maquina' => $paso->maquina?->nombre,
                'maquinaplantaid' => $paso->maquinaplantaid ? (int) $paso->maquinaplantaid : null,
                'variables' => $vars,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parametrosJsonPlantilla(PlantillaTransformacion $plantilla): array
    {
        $plantilla->loadMissing(['pasos.proceso', 'pasos.maquina', 'pasos.variables.variableEstandar']);

        $items = [];

        foreach ($plantilla->pasos as $paso) {
            $vars = [];

            foreach ($paso->variables as $pv) {
                $maqId = (int) ($paso->maquinaplantaid ?? 0);
                $maqLim = $maqId > 0
                    ? ParametroRangoPlanta::limitesMaquina($maqId, (int) $pv->variableestandarid)
                    : null;

                $vars[] = [
                    'variableestandarid' => (int) $pv->variableestandarid,
                    'nombre' => $pv->variableEstandar?->nombre ?? '—',
                    'unidad' => $pv->variableEstandar?->unidad,
                    'valor_minimo' => (float) $pv->valor_minimo,
                    'valor_maximo' => (float) $pv->valor_maximo,
                    'maq_minimo' => $maqLim['min'] ?? null,
                    'maq_maximo' => $maqLim['max'] ?? null,
                ];
            }

            $items[] = [
                'plantillapasoid' => (int) $paso->plantillapasoid,
                'orden' => (int) $paso->orden,
                'proceso' => $paso->proceso?->nombre ?? '—',
                'maquina' => $paso->maquina?->nombre,
                'maquinaplantaid' => $paso->maquinaplantaid ? (int) $paso->maquinaplantaid : null,
                'variables' => $vars,
            ];
        }

        return $items;
    }

    /**
     * Parámetros obligatorios al completar una etapa asignada.
     *
     * @return list<array{
     *   variableestandarid: int,
     *   nombre: string,
     *   unidad: ?string,
     *   valor_minimo: float,
     *   valor_maximo: float,
     *   maq_minimo: ?float,
     *   maq_maximo: ?float
     * }>
     */
    public function parametrosRequeridosParaAsignacion(AsignacionEtapaPlanta $asignacion): array
    {
        $asignacion->loadMissing(['loteProduccion', 'proceso', 'maquina']);
        $lote = $asignacion->loteProduccion;
        if (! $lote) {
            return [];
        }

        $maqId = (int) ($asignacion->maquinaplantaid ?? 0);
        $orden = $asignacion->orden !== null
            ? (int) $asignacion->orden
            : app(LoteProduccionTransformacionService::class)->ordenPasoActual($lote);
        $defs = [];

        $rutaService = app(LoteProduccionRutaService::class);
        if ($rutaService->tieneRuta($lote)) {
            $pasoRuta = $rutaService->pasoEnOrden($lote, $orden);
            if ($pasoRuta) {
                $pasoRuta->loadMissing(['variables.variableEstandar']);
                foreach ($pasoRuta->variables as $pv) {
                    $defs[] = $this->normalizarDefParametro([
                        'variableestandarid' => $pv->variableestandarid,
                        'nombre' => $pv->variableEstandar?->nombre,
                        'unidad' => $pv->variableEstandar?->unidad,
                        'valor_minimo' => $pv->valor_minimo,
                        'valor_maximo' => $pv->valor_maximo,
                        'obligatorio' => (bool) ($pv->obligatorio ?? true),
                    ], $maqId);
                }
            }
        }

        if ($defs === []) {
            $efectivos = $this->parametrosEfectivosPorPlantilla($lote);
            $pasoEfectivo = collect($efectivos)->firstWhere('orden', $orden);

            if ($pasoEfectivo && ! empty($pasoEfectivo['variables'])) {
                foreach ($pasoEfectivo['variables'] as $v) {
                    $defs[] = $this->normalizarDefParametro($v, $maqId);
                }
            }
        }

        if ($defs === [] && $maqId > 0 && Schema::hasTable('maquina_variable_planta')) {
            $defs = $this->parametrosDesdeMaquina($maqId);
        }

        return array_values(array_filter(
            $defs,
            fn (array $d) => (bool) ($d['obligatorio'] ?? true)
        ));
    }

    /**
     * Parámetros de la etapa actual (vista previa al asignar).
     *
     * @return list<array{variableestandarid: int, nombre: string, unidad: ?string, valor_minimo: float, valor_maximo: float, maq_minimo: ?float, maq_maximo: ?float}>
     */
    public function parametrosParaEtapaActual(LoteProduccionPedido $lote, ?int $maquinaplantaid = null): array
    {
        $orden = app(LoteProduccionTransformacionService::class)->ordenPasoActual($lote);
        $maqId = (int) ($maquinaplantaid ?? 0);
        if ($maqId <= 0) {
            $paso = app(LoteProduccionTransformacionService::class)->pasoPlantillaActual($lote);
            $maqId = (int) ($paso?->maquinaplantaid ?? 0);
        }

        $defs = [];
        $rutaService = app(LoteProduccionRutaService::class);
        if ($rutaService->tieneRuta($lote)) {
            $pasoRuta = $rutaService->pasoEnOrden($lote, $orden);
            if ($pasoRuta) {
                $pasoRuta->loadMissing(['variables.variableEstandar']);
                foreach ($pasoRuta->variables as $pv) {
                    $defs[] = $this->normalizarDefParametro([
                        'variableestandarid' => $pv->variableestandarid,
                        'nombre' => $pv->variableEstandar?->nombre,
                        'unidad' => $pv->variableEstandar?->unidad,
                        'valor_minimo' => $pv->valor_minimo,
                        'valor_maximo' => $pv->valor_maximo,
                    ], $maqId);
                }
            }
        }

        if ($defs === []) {
            $efectivos = $this->parametrosEfectivosPorPlantilla($lote);
            $pasoEfectivo = collect($efectivos)->firstWhere('orden', $orden);
            if ($pasoEfectivo && ! empty($pasoEfectivo['variables'])) {
                foreach ($pasoEfectivo['variables'] as $v) {
                    $defs[] = $this->normalizarDefParametro($v, $maqId);
                }
            }
        }

        if ($defs === [] && $maqId > 0 && Schema::hasTable('maquina_variable_planta')) {
            $defs = $this->parametrosDesdeMaquina($maqId);
        }

        return $defs;
    }

    /**
     * @param  list<array{variableestandarid: int, valor: float|int|string}>  $ingresados
     * @return list<array{variableestandarid: int, nombre: string, unidad: ?string, valor: float, valor_minimo: float, valor_maximo: float, cumple: bool}>
     */
    public function validarYFormatearValoresEtapa(AsignacionEtapaPlanta $asignacion, array $ingresados): array
    {
        $requeridos = $this->parametrosRequeridosParaAsignacion($asignacion);

        if ($requeridos === []) {
            return [];
        }

        $porId = [];
        foreach ($ingresados as $fila) {
            $id = (int) ($fila['variableestandarid'] ?? 0);
            if ($id > 0) {
                $porId[$id] = (float) ($fila['valor'] ?? 0);
            }
        }

        $salida = [];

        foreach ($requeridos as $req) {
            $varId = (int) $req['variableestandarid'];
            if (! array_key_exists($varId, $porId)) {
                throw new \InvalidArgumentException(
                    'Debe registrar el parámetro «'.$req['nombre'].'».'
                );
            }

            $valor = $porId[$varId];
            $error = ParametroRangoPlanta::validarValorRegistrado(
                $valor,
                (float) $req['valor_minimo'],
                (float) $req['valor_maximo'],
                $req['nombre'],
            );

            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }

            $salida[] = [
                'variableestandarid' => $varId,
                'nombre' => $req['nombre'],
                'unidad' => $req['unidad'] ?? null,
                'valor' => $valor,
                'valor_minimo' => (float) $req['valor_minimo'],
                'valor_maximo' => (float) $req['valor_maximo'],
                'cumple' => true,
            ];
        }

        return $salida;
    }

    /**
     * @deprecated OPP-06 — no inventar mediciones con midpoint. Usar validarYFormatearValoresEtapa.
     *
     * @return list<array{variableestandarid: int, nombre: string, unidad: ?string, valor: float, valor_minimo: float, valor_maximo: float, cumple: bool}>
     */
    public function parametrosRegistradosDesdePlan(AsignacionEtapaPlanta $asignacion): array
    {
        $requeridos = $this->parametrosRequeridosParaAsignacion($asignacion);
        if ($requeridos !== []) {
            throw new \InvalidArgumentException(
                'Debe registrar los parámetros medidos de la etapa. No se generan valores automáticamente.'
            );
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{variableestandarid: int, nombre: string, unidad: ?string, valor_minimo: float, valor_maximo: float, maq_minimo: ?float, maq_maximo: ?float}
     */
    private function normalizarDefParametro(array $v, int $maquinaplantaid): array
    {
        $varId = (int) $v['variableestandarid'];
        $maqLim = $maquinaplantaid > 0 ? ParametroRangoPlanta::limitesMaquina($maquinaplantaid, $varId) : null;
        $min = (float) $v['valor_minimo'];
        $max = (float) $v['valor_maximo'];

        if ($maqLim !== null) {
            $min = max($min, $maqLim['min']);
            $max = min($max, $maqLim['max']);
        }

        return [
            'variableestandarid' => $varId,
            'nombre' => (string) ($v['nombre'] ?? '—'),
            'unidad' => $v['unidad'] ?? null,
            'valor_minimo' => $min,
            'valor_maximo' => $max,
            'maq_minimo' => $maqLim['min'] ?? null,
            'maq_maximo' => $maqLim['max'] ?? null,
            'obligatorio' => array_key_exists('obligatorio', $v) ? (bool) $v['obligatorio'] : true,
        ];
    }

    /** @return list<array{variableestandarid: int, nombre: string, unidad: ?string, valor_minimo: float, valor_maximo: float, maq_minimo: float, maq_maximo: float, obligatorio: bool}> */
    private function parametrosDesdeMaquina(int $maquinaplantaid): array
    {
        return MaquinaVariablePlanta::query()
            ->where('maquinaplantaid', $maquinaplantaid)
            ->with('variableEstandar')
            ->get()
            ->map(fn (MaquinaVariablePlanta $mv) => [
                'variableestandarid' => (int) $mv->variableestandarid,
                'nombre' => $mv->variableEstandar?->nombre ?? '—',
                'unidad' => $mv->variableEstandar?->unidad,
                'valor_minimo' => (float) $mv->valor_minimo,
                'valor_maximo' => (float) $mv->valor_maximo,
                'maq_minimo' => (float) $mv->valor_minimo,
                'maq_maximo' => (float) $mv->valor_maximo,
                'obligatorio' => (bool) ($mv->obligatorio ?? true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|string|null  $variablesIngresadas
     * @return list<array{nombre: string, unidad: ?string, valor: float, valor_minimo: float, valor_maximo: float}>
     */
    public function parametrosDesdeRegistroJson(array|string|null $variablesIngresadas): array
    {
        if (is_string($variablesIngresadas)) {
            $variablesIngresadas = json_decode($variablesIngresadas, true);
        }

        if (! is_array($variablesIngresadas)) {
            return [];
        }

        $lista = $variablesIngresadas['parametros'] ?? [];

        if (! is_array($lista)) {
            return [];
        }

        $out = [];
        foreach ($lista as $p) {
            if (! is_array($p) || ! isset($p['valor'])) {
                continue;
            }
            $out[] = [
                'nombre' => (string) ($p['nombre'] ?? '—'),
                'unidad' => $p['unidad'] ?? null,
                'valor' => (float) $p['valor'],
                'valor_minimo' => (float) ($p['valor_minimo'] ?? 0),
                'valor_maximo' => (float) ($p['valor_maximo'] ?? 0),
            ];
        }

        return $out;
    }
}
