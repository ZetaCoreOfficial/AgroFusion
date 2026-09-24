<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Lote;

final class ActividadSecuenciaService
{
    public function siguienteOrden(Lote $lote): int
    {
        $max = Actividad::query()
            ->where('loteid', $lote->loteid)
            ->max('orden_secuencia');

        return ((int) $max) + 1;
    }

    public function asignarOrden(Actividad $actividad): void
    {
        if ($actividad->orden_secuencia !== null && (int) $actividad->orden_secuencia > 0) {
            return;
        }

        $actividad->loadMissing('lote');
        $lote = $actividad->lote;
        if (! $lote) {
            return;
        }

        $actividad->orden_secuencia = $this->siguienteOrden($lote);
        $actividad->save();
    }

    /** @return \Illuminate\Support\Collection<int, Actividad> */
    public function pendientesOrdenadas(Lote $lote, bool $soloFaseActual = false): \Illuminate\Support\Collection
    {
        $trazabilidad = app(LoteTrazabilidadService::class);
        $faseActual = $soloFaseActual ? $trazabilidad->resolverFaseActual($lote) : null;

        $query = Actividad::query()
            ->where('loteid', $lote->loteid)
            ->whereNull('fechafin')
            ->with('tipoActividad')
            ->orderBy('orden_secuencia')
            ->orderBy('fechainicio')
            ->orderBy('actividadid');

        return $query->get()
            ->when($faseActual !== null, fn ($items) => $items->filter(
                fn (Actividad $actividad) => $trazabilidad->tipoActividadPermitidoEnFase(
                    $actividad->tipoActividad->nombre ?? null,
                    $faseActual
                )
            ))
            ->values();
    }

    public function siguientePendiente(Lote $lote, bool $soloFaseActual = false): ?Actividad
    {
        return $this->pendientesOrdenadas($lote, $soloFaseActual)->first();
    }

    public function esSiguienteEnCola(Actividad $actividad, bool $soloFaseActual = false): bool
    {
        if ($actividad->fechafin !== null) {
            return false;
        }

        $actividad->loadMissing('lote');
        if (! $actividad->lote) {
            return false;
        }

        $siguiente = $this->siguientePendiente($actividad->lote, $soloFaseActual);

        return $siguiente !== null
            && (int) $siguiente->actividadid === (int) $actividad->actividadid;
    }

    public function mensajeBloqueoOrden(Actividad $actividad): ?string
    {
        if ($this->esSiguienteEnCola($actividad, false)) {
            return null;
        }

        $actividad->loadMissing(['lote', 'lote.actividades.tipoActividad']);
        $anterior = $this->siguientePendiente($actividad->lote, false);
        if (! $anterior) {
            return null;
        }

        $titulo = $anterior->descripcion ?: ($anterior->tipoActividad->nombre ?? 'otra actividad');

        return "Debe completar primero: «{$titulo}» (asignada en orden anterior).";
    }

    /**
     * Única puerta de secuencia para completar (marcar-realizada, store+completar, API, siembra).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function asegurarEnTurnoParaCompletar(Actividad $actividad): void
    {
        $bloqueo = $this->mensajeBloqueoOrden($actividad);
        if ($bloqueo !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'actividad' => $bloqueo,
            ]);
        }
    }

    /**
     * ¿Se puede crear una actividad ya marcada como completada en este lote?
     * Solo si no hay otras pendientes delante en la cola.
     */
    public function puedeCompletarAlCrear(Lote $lote): bool
    {
        return $this->siguientePendiente($lote, false) === null;
    }

    public function nombreEjecutor(Actividad $actividad): ?string
    {
        $actividad->loadMissing(['usuario', 'ejecutor']);

        if ($actividad->fechafin !== null && $actividad->ejecutor) {
            return trim($actividad->ejecutor->nombre.' '.($actividad->ejecutor->apellido ?? '')) ?: null;
        }

        if ($actividad->usuario) {
            return trim($actividad->usuario->nombre.' '.($actividad->usuario->apellido ?? '')) ?: null;
        }

        return null;
    }
}
