<?php

namespace App\Support;

use App\Models\AsignacionEtapaPlanta;
use App\Models\LoteProduccionPedido;
use App\Models\LoteProduccionRutaPaso;
use App\Models\MaquinaPlanta;
use App\Models\ProcesoPlanta;
use App\Models\RegistroProcesoMaquinaPlanta;
use App\Models\Usuario;
use App\Services\NotificacionUsuarioService;
use App\Support\UsuarioRol;
use Illuminate\Support\Facades\DB;

class AsignacionEtapaPlantaService
{
    public function __construct(
        private readonly LoteProduccionTrazabilidadService $trazabilidad,
        private readonly LoteProduccionTransformacionService $transformacion,
        private readonly NotificacionUsuarioService $notificaciones,
    ) {}

    public function puedeAsignar(LoteProduccionPedido $lote): bool
    {
        if ($this->tienePlanConfirmado($lote)) {
            return false;
        }

        return ! $this->trazabilidad->transformacionCompleta($lote)
            && $this->transformacion->puedeAsignarNuevaEtapa($lote);
    }

    public function tienePlanConfirmado(LoteProduccionPedido $lote): bool
    {
        return AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->activas()
            ->exists();
    }

    /**
     * Pasos de ruta aún sin operario asignado (activo).
     *
     * @return \Illuminate\Support\Collection<int, LoteProduccionRutaPaso>
     */
    public function etapasSinAsignar(LoteProduccionPedido $lote): \Illuminate\Support\Collection
    {
        if ($this->trazabilidad->transformacionCompleta($lote)) {
            return collect();
        }

        $rutaService = app(LoteProduccionRutaService::class);
        if (! $rutaService->tieneRuta($lote)) {
            return collect();
        }

        $completados = $this->transformacion->etapasCompletadasCount($lote);
        $pasoIdsAsignados = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->activas()
            ->whereNotNull('loteproduccionrutapasoid')
            ->pluck('loteproduccionrutapasoid')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $rutaService->pasosOrdenados($lote)
            ->where('orden', '>', $completados)
            ->filter(fn (LoteProduccionRutaPaso $paso) => ! in_array((int) $paso->loteproduccionrutapasoid, $pasoIdsAsignados, true))
            ->values();
    }

    public function puedeAsignarPlan(LoteProduccionPedido $lote): bool
    {
        return $this->etapasSinAsignar($lote)->isNotEmpty();
    }

    public function puedeCompletar(AsignacionEtapaPlanta $asignacion): bool
    {
        if (! $asignacion->estaPendiente()) {
            return false;
        }

        $lote = $asignacion->loteProduccion;
        if (! $lote) {
            return false;
        }

        return ! $this->trazabilidad->transformacionCompleta($lote);
    }

    /**
     * @param  array{procesoplantaid:int,maquinaplantaid:int,operador_usuarioid:int,observaciones?:?string}  $data
     */
    public function asignar(LoteProduccionPedido $lote, array $data, Usuario $asignador): AsignacionEtapaPlanta
    {
        if (! $this->puedeAsignar($lote)) {
            throw new \InvalidArgumentException(
                $this->transformacion->mensajeBloqueoAsignacion($lote)
                ?? 'La transformación de este lote ya no admite nuevas asignaciones.'
            );
        }

        $proceso = ProcesoPlanta::query()->findOrFail($data['procesoplantaid']);
        $errorProceso = $this->transformacion->validarProcesoParaAsignar($lote, (int) $proceso->procesoplantaid);
        if ($errorProceso !== null) {
            throw new \InvalidArgumentException($errorProceso);
        }

        if (in_array($proceso->nombre, ['Control de Calidad'], true)) {
            throw new \InvalidArgumentException('«Control de Calidad» corresponde a la fase de certificación, no a transformación.');
        }

        $maquina = MaquinaPlanta::findOrFail($data['maquinaplantaid']);
        if (! MaquinaProcesoCompatibilidad::compatible((int) $data['procesoplantaid'], (int) $data['maquinaplantaid'])) {
            throw new \InvalidArgumentException('La maquinaria no es compatible con el proceso seleccionado.');
        }
        if ($maquina->enMantenimiento()) {
            throw new \InvalidArgumentException('La maquinaria está en mantenimiento.');
        }

        $operador = UsuarioRol::queryOperariosPlanta()
            ->where('usuarioid', $data['operador_usuarioid'])
            ->first();

        if (! $operador || ! UsuarioRol::esOperarioPlanta($operador)) {
            throw new \InvalidArgumentException('El operario seleccionado debe tener rol planta (no jefe de planta).');
        }

        $asignacion = AsignacionEtapaPlanta::create([
            'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
            'procesoplantaid' => $data['procesoplantaid'],
            'maquinaplantaid' => $data['maquinaplantaid'],
            'operador_usuarioid' => $operador->usuarioid,
            'asignado_por_usuarioid' => $asignador->usuarioid,
            'estado' => AsignacionEtapaPlanta::ESTADO_PENDIENTE,
            'observaciones' => $data['observaciones'] ?? null,
            'creado_en' => now(),
        ]);

        $this->notificaciones->etapaPlantaAsignada($asignacion);

        return $asignacion;
    }

    /**
     * Cierra una fase: asigna operario y rangos a una sola etapa sin operario.
     *
     * @param  array{loteproduccionrutapasoid: int, operador_usuarioid: int, variables?: list<array{variableestandarid: int, valor_minimo: float|int, valor_maximo: float|int}>}  $etapa
     */
    public function cerrarFase(LoteProduccionPedido $lote, array $etapa, Usuario $asignador): void
    {
        if (! UsuarioRol::gestionaPlanta($asignador)) {
            throw new \InvalidArgumentException('Solo el jefe de planta o administrador puede cerrar fases.');
        }

        $pasoId = (int) ($etapa['loteproduccionrutapasoid'] ?? 0);
        $paso = $this->etapasSinAsignar($lote)->firstWhere('loteproduccionrutapasoid', $pasoId);

        if (! $paso) {
            throw new \InvalidArgumentException('Esta etapa no admite cierre de fase en el estado actual.');
        }

        $this->validarFilaPlanEtapa($paso, $etapa);

        DB::transaction(function () use ($lote, $paso, $etapa, $asignador) {
            $this->crearAsignacionDesdeFilaPlan($lote, $paso, $etapa, $asignador);
        });
    }

    /**
     * Asigna operarios a todas las etapas sin operario del lote.
     *
     * @param  list<array{loteproduccionrutapasoid: int, operador_usuarioid: int, variables?: list<array{variableestandarid: int, valor_minimo: float|int, valor_maximo: float|int}>}>  $etapas
     */
    public function asignarPlanEtapas(LoteProduccionPedido $lote, array $etapas, Usuario $asignador): void
    {
        if (! $this->puedeAsignarPlan($lote)) {
            throw new \InvalidArgumentException(
                'No puede cerrar fases en el estado actual del lote.'
            );
        }

        $pasosSinAsignar = $this->etapasSinAsignar($lote);

        if ($pasosSinAsignar->isEmpty()) {
            throw new \InvalidArgumentException('No hay etapas pendientes de asignar operario.');
        }

        $porPasoId = collect($etapas)->keyBy(fn ($e) => (int) $e['loteproduccionrutapasoid']);

        foreach ($pasosSinAsignar as $paso) {
            $fila = $porPasoId->get((int) $paso->loteproduccionrutapasoid);
            if (! $fila) {
                throw new \InvalidArgumentException('Falta la asignación para la etapa '.$paso->orden.'.');
            }

            $this->validarFilaPlanEtapa($paso, $fila);
        }

        DB::transaction(function () use ($lote, $pasosSinAsignar, $porPasoId, $asignador) {
            foreach ($pasosSinAsignar as $paso) {
                $fila = $porPasoId->get((int) $paso->loteproduccionrutapasoid);
                $this->crearAsignacionDesdeFilaPlan($lote, $paso, $fila, $asignador);
            }
        });
    }

    public function puedeCambiarFase(LoteProduccionPedido $lote, int $loteproduccionrutapasoid): bool
    {
        if ($this->trazabilidad->transformacionCompleta($lote)) {
            return false;
        }

        return AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->where('loteproduccionrutapasoid', $loteproduccionrutapasoid)
            ->activas()
            ->exists();
    }

    public function cambiarFase(LoteProduccionPedido $lote, int $loteproduccionrutapasoid, Usuario $usuario): void
    {
        if (! UsuarioRol::gestionaPlanta($usuario)) {
            throw new \InvalidArgumentException('Solo el jefe de planta o administrador puede cambiar una fase.');
        }

        if (! $this->puedeCambiarFase($lote, $loteproduccionrutapasoid)) {
            throw new \InvalidArgumentException('No hay una asignación activa en esta etapa para liberar.');
        }

        $asignacion = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->where('loteproduccionrutapasoid', $loteproduccionrutapasoid)
            ->activas()
            ->firstOrFail();

        DB::transaction(function () use ($asignacion) {
            $asignacion->update(['estado' => AsignacionEtapaPlanta::ESTADO_CANCELADA]);
            $this->notificaciones->descartarEtapaPlantaAsignada((int) $asignacion->asignacionetapaplantaid);
        });
    }

    /**
     * @param  array{loteproduccionrutapasoid: int, operador_usuarioid: int, variables?: list<array{variableestandarid: int, valor_minimo: float|int, valor_maximo: float|int}>}  $fila
     */
    private function validarFilaPlanEtapa(LoteProduccionRutaPaso $paso, array $fila): void
    {
        $operador = UsuarioRol::queryOperariosPlanta()
            ->where('usuarioid', (int) $fila['operador_usuarioid'])
            ->first();

        if (! $operador || ! UsuarioRol::esOperarioPlanta($operador)) {
            throw new \InvalidArgumentException('Etapa '.$paso->orden.': el operario debe tener rol planta.');
        }

        if (in_array($paso->proceso?->nombre, ['Control de Calidad'], true)) {
            throw new \InvalidArgumentException('«Control de Calidad» corresponde a certificación, no a transformación.');
        }

        if ($paso->maquinaplantaid && $paso->maquina?->enMantenimiento()) {
            throw new \InvalidArgumentException('La máquina «'.$paso->maquina->nombre.'» está en mantenimiento.');
        }
    }

    /**
     * @param  array{loteproduccionrutapasoid: int, operador_usuarioid: int, variables?: list<array{variableestandarid: int, valor_minimo: float|int, valor_maximo: float|int}>}  $fila
     */
    private function crearAsignacionDesdeFilaPlan(
        LoteProduccionPedido $lote,
        LoteProduccionRutaPaso $paso,
        array $fila,
        Usuario $asignador,
    ): AsignacionEtapaPlanta {
        $rutaService = app(LoteProduccionRutaService::class);
        $completados = $this->transformacion->etapasCompletadasCount($lote);
        $variables = $fila['variables'] ?? [];

        if ($variables !== []) {
            $rutaService->actualizarVariablesPaso($paso, $variables);
        }

        $esActiva = (int) $paso->orden === $completados + 1;

        $asignacion = AsignacionEtapaPlanta::create([
            'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
            'loteproduccionrutapasoid' => (int) $paso->loteproduccionrutapasoid,
            'orden' => (int) $paso->orden,
            'procesoplantaid' => (int) $paso->procesoplantaid,
            'maquinaplantaid' => (int) $paso->maquinaplantaid,
            'operador_usuarioid' => (int) $fila['operador_usuarioid'],
            'asignado_por_usuarioid' => $asignador->usuarioid,
            'estado' => $esActiva
                ? AsignacionEtapaPlanta::ESTADO_PENDIENTE
                : AsignacionEtapaPlanta::ESTADO_PROGRAMADA,
            'observaciones' => null,
            'creado_en' => now(),
        ]);

        if ($esActiva) {
            $this->notificaciones->etapaPlantaAsignada($asignacion);
        }

        return $asignacion;
    }

    /**
     * @param  array{hora_inicio:string,hora_fin:string}  $data
     */
    /**
     * @param  array{hora_inicio: string, hora_fin: string, parametros?: list<array{variableestandarid: int, valor: float|int|string}>}  $data
     */
    public function completar(AsignacionEtapaPlanta $asignacion, array $data, Usuario $usuario): RegistroProcesoMaquinaPlanta
    {
        $this->activarAsignacionProgramadaSiCorresponde($asignacion);

        if (! $asignacion->estaPendiente()) {
            throw new \InvalidArgumentException('Esta tarea ya fue completada o aún no está activa.');
        }

        $esOperador = UsuarioRol::esOperarioPlanta($usuario)
            && (int) $asignacion->operador_usuarioid === (int) $usuario->usuarioid;
        $esSupervisor = UsuarioRol::gestionaPlanta($usuario);

        if (! $esOperador && ! $esSupervisor) {
            throw new \InvalidArgumentException('No tiene permiso para completar esta tarea.');
        }

        $lote = $asignacion->loteProduccion()->firstOrFail();
        if ($this->trazabilidad->transformacionCompleta($lote)) {
            throw new \InvalidArgumentException('La transformación del lote ya finalizó.');
        }

        $ordenActual = $this->transformacion->ordenPasoActual($lote);
        if ($asignacion->orden !== null && (int) $asignacion->orden !== $ordenActual) {
            throw new \InvalidArgumentException(
                'Esta etapa aún no puede ejecutarse. Complete primero la etapa '.max(1, $ordenActual - 1).'.'
            );
        }

        $paramService = app(LoteProduccionParametrosService::class);
        $parametrosIngresados = $data['parametros'] ?? [];
        if ($parametrosIngresados === []) {
            if ($esOperador) {
                $parametrosRegistrados = $paramService->parametrosRegistradosDesdePlan($asignacion);
            } else {
                $parametrosRegistrados = [];
            }
        } else {
            $parametrosRegistrados = $paramService->validarYFormatearValoresEtapa(
                $asignacion,
                $parametrosIngresados,
            );
        }

        $registro = DB::transaction(function () use ($asignacion, $data, $lote, $esSupervisor, $usuario, $parametrosRegistrados) {
            $paso = $this->transformacion->resolverPasoProcesoMaquina(
                (int) $asignacion->procesoplantaid,
                (int) $asignacion->maquinaplantaid
            );

            $proceso = $asignacion->proceso;
            $maquina = $asignacion->maquina;
            $observaciones = $asignacion->observaciones;
            if ($esSupervisor && (int) $asignacion->operador_usuarioid !== (int) $usuario->usuarioid) {
                $observaciones = trim(($observaciones ? $observaciones.' ' : '').'(Completada por '.$usuario->nombreCompleto().')');
            }

            $registro = RegistroProcesoMaquinaPlanta::create([
                'procesomaquinaplantaid' => $paso->procesomaquinaplantaid,
                'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
                'usuarioid' => $asignacion->operador_usuarioid,
                'variables_ingresadas' => json_encode([
                    'proceso' => $proceso?->nombre,
                    'maquina' => $maquina?->nombre,
                    'asignacion_id' => $asignacion->asignacionetapaplantaid,
                    'completada_por_supervisor' => $esSupervisor && (int) $asignacion->operador_usuarioid !== (int) $usuario->usuarioid,
                    'parametros' => $parametrosRegistrados,
                ], JSON_UNESCAPED_UNICODE),
                'cumple_estandar' => true,
                'observaciones' => $observaciones,
                'hora_inicio' => $data['hora_inicio'],
                'hora_fin' => $data['hora_fin'],
                'fecha_registro' => $data['hora_fin'],
            ]);

            $asignacion->update([
                'estado' => AsignacionEtapaPlanta::ESTADO_COMPLETADA,
                'registroprocesomaquinaplantaid' => $registro->registroprocesomaquinaplantaid,
                'completada_en' => now(),
            ]);

            if (! $lote->hora_inicio) {
                $lote->update(['hora_inicio' => $data['hora_inicio']]);
            }
            $lote->update(['procesoplantaid' => $asignacion->procesoplantaid]);

            return $registro;
        });

        $this->notificaciones->descartarEtapaPlantaAsignada((int) $asignacion->asignacionetapaplantaid);

        $lote = $lote->fresh();
        $this->promoverSiguienteProgramada($lote);
        $this->transformacion->limpiarAsignacionesObsoletas($lote);

        return $registro;
    }

    public function sincronizarPromocionCola(LoteProduccionPedido $lote): void
    {
        if ($this->trazabilidad->transformacionCompleta($lote) || ! $this->tienePlanConfirmado($lote)) {
            return;
        }

        $hayPendiente = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->where('estado', AsignacionEtapaPlanta::ESTADO_PENDIENTE)
            ->exists();

        if ($hayPendiente) {
            return;
        }

        $this->promoverSiguienteProgramada($lote);
    }

    public function promoverSiguienteProgramada(LoteProduccionPedido $lote): void
    {
        $completados = $this->transformacion->etapasCompletadasCount($lote);

        $siguiente = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->where('estado', AsignacionEtapaPlanta::ESTADO_PROGRAMADA)
            ->where('orden', '>', $completados)
            ->orderBy('orden')
            ->first();

        if (! $siguiente) {
            return;
        }

        $siguiente->update(['estado' => AsignacionEtapaPlanta::ESTADO_PENDIENTE]);
        $this->notificaciones->etapaPlantaAsignada($siguiente->fresh());
    }

    /**
     * @param  list<array{variableestandarid: int, valor: float|int|string}>  $parametros
     */
    public function completarPorSupervisor(AsignacionEtapaPlanta $asignacion, Usuario $supervisor, array $parametros = []): RegistroProcesoMaquinaPlanta
    {
        if (! UsuarioRol::gestionaPlanta($supervisor)) {
            throw new \InvalidArgumentException('Solo el jefe de planta o administrador puede completar etapas desde procesamiento.');
        }

        $inicio = $asignacion->creado_en ?? now();

        return $this->completar($asignacion, [
            'hora_inicio' => $inicio->toDateTimeString(),
            'hora_fin' => now()->toDateTimeString(),
            'parametros' => $parametros,
        ], $supervisor);
    }

    public function puedeReiniciarTodo(LoteProduccionPedido $lote): bool
    {
        if ($this->trazabilidad->transformacionCompleta($lote)) {
            return false;
        }

        return AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->activas()
            ->exists();
    }

    /** @deprecated Use puedeReiniciarTodo() */
    public function puedeReiniciarPlan(LoteProduccionPedido $lote): bool
    {
        return $this->puedeReiniciarTodo($lote);
    }

    private function activarAsignacionProgramadaSiCorresponde(AsignacionEtapaPlanta $asignacion): void
    {
        if (! $asignacion->estaProgramada()) {
            return;
        }

        $lote = $asignacion->loteProduccion()->first();
        if ($lote === null) {
            return;
        }

        $ordenActual = $this->transformacion->ordenPasoActual($lote);
        if ($asignacion->orden !== null && (int) $asignacion->orden === $ordenActual) {
            $asignacion->update(['estado' => AsignacionEtapaPlanta::ESTADO_PENDIENTE]);
            $asignacion->refresh();
        }
    }

    public function reiniciarTodo(LoteProduccionPedido $lote, Usuario $usuario): void
    {
        if (! UsuarioRol::gestionaPlanta($usuario)) {
            throw new \InvalidArgumentException('Solo el jefe de planta o administrador puede reiniciar las asignaciones.');
        }

        if (! $this->puedeReiniciarTodo($lote)) {
            throw new \InvalidArgumentException('No hay asignaciones activas para reiniciar.');
        }

        $activas = AsignacionEtapaPlanta::query()
            ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
            ->activas()
            ->get();

        DB::transaction(function () use ($activas) {
            foreach ($activas as $asignacion) {
                $asignacion->update(['estado' => AsignacionEtapaPlanta::ESTADO_CANCELADA]);
                $this->notificaciones->descartarEtapaPlantaAsignada((int) $asignacion->asignacionetapaplantaid);
            }
        });
    }

    /** @deprecated Use reiniciarTodo() */
    public function reiniciarPlan(LoteProduccionPedido $lote, Usuario $usuario): void
    {
        $this->reiniciarTodo($lote, $usuario);
    }
}
