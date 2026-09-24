@extends('layouts.app')

@section('title', 'Actividad | AgroFusion')
@section('page_title', 'Detalle de actividad')

@section('content')
@php
    $completada = $actividad->fechafin !== null;
@endphp
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">
            <i class="fas fa-tasks mr-2"></i>{{ $actividad->tipoActividad->nombre ?? 'Actividad' }}
        </h3>
        <span class="badge badge-{{ $completada ? 'success' : 'warning' }}">
            {{ $completada ? 'Completada' : 'Pendiente' }}
        </span>
    </div>

    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <p><strong>Lote:</strong> {{ $actividad->lote->nombre ?? '—' }}</p>
                <p><strong>Responsable:</strong> {{ trim(($actividad->usuario->nombre ?? '').' '.($actividad->usuario->apellido ?? '')) ?: '—' }}</p>
                <p><strong>Prioridad:</strong> {{ $actividad->prioridad->nombre ?? '—' }}</p>
            </div>
            <div class="col-md-6">
                <p><strong>Fecha planificada:</strong>
                    @if($actividad->fecha_planificada)
                        {{ \Carbon\Carbon::parse($actividad->fecha_planificada)->format('d/m/Y') }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </p>
                <p><strong>Fecha inicio real:</strong>
                    {{ $actividad->fechainicio ? \Carbon\Carbon::parse($actividad->fechainicio)->format('d/m/Y H:i') : '—' }}
                </p>
                <p><strong>Fecha fin real:</strong>
                    @if($completada)
                        {{ \Carbon\Carbon::parse($actividad->fechafin)->format('d/m/Y H:i') }}
                    @else
                        <span class="text-muted">Pendiente de ejecución</span>
                    @endif
                </p>
            </div>
        </div>

        @if($actividad->descripcion)
            <p class="mb-1"><strong>Descripción:</strong></p>
            <p class="text-muted">{{ $actividad->descripcion }}</p>
        @endif

        @if($actividad->observaciones)
            <p class="mb-1"><strong>Observaciones:</strong></p>
            <p class="text-muted">{{ $actividad->observaciones }}</p>
        @endif

        @if($completada && $actividad->evidencia_foto_path)
            @php $evidenciaUrl = asset('storage/'.$actividad->evidencia_foto_path); @endphp
            <div class="mt-3">
                <p class="mb-2"><strong><i class="fas fa-camera mr-1"></i> Evidencia fotográfica</strong></p>
                <a href="{{ $evidenciaUrl }}" target="_blank" rel="noopener" class="d-inline-block">
                    <img src="{{ $evidenciaUrl }}"
                         alt="Evidencia: {{ $actividad->tipoActividad->nombre ?? 'Actividad' }}"
                         class="img-fluid rounded border shadow-sm"
                         style="max-width: 320px; max-height: 240px; object-fit: cover; cursor: zoom-in;">
                </a>
            </div>
        @endif
    </div>

    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center">
        <a href="{{ route('actividades.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
        <div class="d-flex flex-wrap" style="gap: 6px;">
            @if(!empty($puedeMarcarCompletada))
                <button type="button" class="btn btn-success btn-completar-evidencia"
                    data-action="{{ route('actividades.marcar-realizada', $actividad) }}"
                    data-resumen-url="{{ route('actividades.resumen-completar', $actividad) }}"
                    data-titulo="{{ $actividad->descripcion ?: ($actividad->tipoActividad->nombre ?? 'Actividad') }}"
                    data-lote="{{ $actividad->lote->nombre ?? 'Sin lote' }}">
                    <i class="fas fa-check mr-1"></i> Marcar como completada
                </button>
            @endif
            @can('lotes.update')
                <a href="{{ route('actividades.edit', $actividad) }}" class="btn btn-warning">
                    <i class="fas fa-edit mr-1"></i> Editar
                </a>
                <form action="{{ route('actividades.destroy', $actividad) }}" method="POST" class="d-inline on-submit-confirm">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash mr-1"></i> Eliminar
                    </button>
                </form>
            @endcan
        </div>
    </div>
</div>

@include('partials.modal-completar-evidencia')
@include('partials.modal-ver-evidencia')
@endsection
