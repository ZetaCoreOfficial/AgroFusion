@extends('layouts.app')

@section('title', 'Editar Actividad | AgroFusion')
@section('page_title', 'Editar Actividad')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}" style="color: #2c5530;">Inicio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('actividades.index') }}" style="color: #2c5530;">Actividades</a></li>
    <li class="breadcrumb-item active">Editar</li>
@endsection

@push('styles')
<style>
    :root {
        --primary-color: #2c5530;
        --secondary-color: #4a7c59;
    }

    .form-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }

    .form-header h2 {
        margin: 0;
        font-weight: 700;
    }

    .form-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        padding: 25px;
        margin-bottom: 20px;
    }

    .form-card h5 {
        color: var(--primary-color);
        font-weight: 600;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #f1f3f4;
    }

    .form-group label {
        font-weight: 600;
        color: #1a252f;
        margin-bottom: 8px;
    }

    .form-control {
        border-radius: 8px;
        border: 2px solid #dee2e6;
        padding: 12px 15px;
        transition: border-color 0.2s ease;
        height: auto;
        min-height: 46px;
        font-size: 0.95rem;
    }

    .form-control:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(44, 85, 48, 0.15);
    }

    select.form-control {
        padding-right: 35px;
        background-position: right 12px center;
    }

    .btn-action {
        padding: 12px 30px;
        border-radius: 8px;
        font-weight: 500;
    }

    .required-field::after {
        content: " *";
        color: #dc3545;
    }
</style>
@endpush

@section('content')
<!-- Header -->
<div class="form-header">
    <h2><i class="fas fa-edit mr-2"></i>Editar Actividad</h2>
    <p class="mb-0 mt-2" style="opacity: 0.9;">
        {{ $actividad->tipoActividad->nombre ?? 'Actividad' }} - {{ $actividad->lote->nombre ?? 'Sin lote' }}
    </p>
</div>

<form action="{{ route('actividades.update', $actividad) }}" method="POST">
    @csrf
    @method('PUT')

    <div class="row">
        <div class="col-lg-9 col-md-8">
            <!-- Información Principal -->
            <div class="form-card">
                <h5><i class="fas fa-info-circle mr-2"></i>Información Principal</h5>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="required-field"><i class="fas fa-tasks mr-1"></i> Tipo de Actividad</label>
                            <select name="tipoactividadid" class="form-control" required>
                                <option value="">Seleccionar...</option>
                                @foreach($tipos as $t)
                                    <option value="{{ $t->tipoactividadid }}" {{ $t->tipoactividadid == $actividad->tipoactividadid ? 'selected' : '' }}>
                                        {{ $t->nombre }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="required-field"><i class="fas fa-map-marked-alt mr-1"></i> Lote</label>
                            <select name="loteid" class="form-control" required>
                                <option value="">Seleccionar...</option>
                                @foreach($lotes as $l)
                                    <option value="{{ $l->loteid }}" {{ $l->loteid == $actividad->loteid ? 'selected' : '' }}>
                                        {{ $l->nombre }} {{ $l->cultivo ? '- ' . $l->cultivo->nombre : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        @if(!empty($puedeDesignarResponsable))
                            @include('partials.selector-catalogo', [
                                'id' => 'actividad_edit_responsable',
                                'name' => 'usuarioid',
                                'label' => 'Responsable',
                                'icon' => 'fa-user',
                                'value' => old('usuarioid', $actividad->usuarioid),
                                'labelSelected' => $responsableLabel ?? '',
                                'endpoint' => route('catalogo-selector.usuarios'),
                                'params' => $responsableSelectorParams ?? ['roles' => 'agricultor'],
                                'title' => 'Seleccionar responsable',
                                'searchPlaceholder' => 'Nombre, correo o usuario…',
                                'help' => ! empty($esJefeAgricultorDesignando)
                                    ? 'Solo agricultores operativos de su equipo (el jefe no es responsable de campo).'
                                    : 'Agricultor operativo que ejecutará la actividad.',
                                'required' => true,
                            ])
                        @else
                            <div class="form-group">
                                <label class="required-field"><i class="fas fa-user mr-1"></i> Responsable</label>
                                <input type="text" class="form-control bg-light" readonly
                                       value="{{ $responsableLabel ?? trim(auth()->user()->nombre.' '.(auth()->user()->apellido ?? '')) }}">
                                <input type="hidden" name="usuarioid" value="{{ auth()->user()->usuarioid }}">
                            </div>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label><i class="fas fa-flag mr-1"></i> Prioridad</label>
                            <select name="prioridadid" class="form-control">
                                <option value="">Seleccionar...</option>
                                @foreach($prioridades as $p)
                                    <option value="{{ $p->prioridadid }}" {{ $p->prioridadid == $actividad->prioridadid ? 'selected' : '' }}>
                                        {{ $p->nombre }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-align-left mr-1"></i> Descripción</label>
                    <input type="text" name="descripcion" class="form-control" value="{{ $actividad->descripcion }}" maxlength="200" placeholder="Breve descripción de la actividad">
                </div>
            </div>

            <!-- Fechas -->
            <div class="form-card">
                <h5><i class="fas fa-calendar-alt mr-2"></i>Fechas</h5>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day mr-1"></i> Fecha planificada</label>
                            <input type="date" name="fecha_planificada" class="form-control"
                                   value="{{ old('fecha_planificada', $actividad->fecha_planificada ? \Carbon\Carbon::parse($actividad->fecha_planificada)->format('Y-m-d') : '') }}">
                            <small class="text-muted">Plan del día; no marca iniciada/completada</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="required-field"><i class="fas fa-calendar mr-1"></i> Fecha Inicio (real)</label>
                            <input type="datetime-local" name="fechainicio" class="form-control" value="{{ $actividad->fechainicio ? \Carbon\Carbon::parse($actividad->fechainicio)->format('Y-m-d\TH:i') : '' }}" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check mr-1"></i> Fecha Fin (real)</label>
                            <input type="datetime-local" name="fechafin" class="form-control" value="{{ $actividad->fechafin ? \Carbon\Carbon::parse($actividad->fechafin)->format('Y-m-d\TH:i') : '' }}">
                            <small class="text-muted">Dejar vacío si aún no ha finalizado</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Observaciones -->
            <div class="form-card">
                <h5><i class="fas fa-comment mr-2"></i>Observaciones</h5>
                
                <div class="form-group mb-0">
                    <textarea name="observaciones" class="form-control" rows="4" maxlength="250" placeholder="Notas adicionales sobre la actividad...">{{ $actividad->observaciones }}</textarea>
                </div>
            </div>
        </div>

        <!-- Panel Lateral -->
        <div class="col-lg-3 col-md-4">
            <!-- Estado Actual -->
            <div class="form-card">
                <h5><i class="fas fa-info mr-2"></i>Estado Actual</h5>
                
                <div class="text-center mb-3">
                    @if($actividad->fechafin)
                        <span class="badge badge-success" style="font-size: 1rem; padding: 10px 20px;">
                            <i class="fas fa-check-circle mr-1"></i> Completada
                        </span>
                    @else
                        <span class="badge badge-warning" style="font-size: 1rem; padding: 10px 20px;">
                            <i class="fas fa-clock mr-1"></i> Pendiente
                        </span>
                    @endif
                </div>

                <small class="text-muted d-block text-center">
                    ID: #{{ $actividad->actividadid }}
                </small>
            </div>

            <!-- Acciones -->
            <div class="form-card">
                <h5><i class="fas fa-cogs mr-2"></i>Acciones</h5>
                
                <button type="submit" class="btn btn-success btn-block btn-action mb-2">
                    <i class="fas fa-save mr-1"></i> Guardar Cambios
                </button>
                
                <a href="{{ route('actividades.show', $actividad) }}" class="btn btn-info btn-block btn-action mb-2">
                    <i class="fas fa-eye mr-1"></i> Ver Detalle
                </a>
                
                <a href="{{ route('actividades.index') }}" class="btn btn-secondary btn-block btn-action">
                    <i class="fas fa-times mr-1"></i> Cancelar
                </a>
            </div>

            <!-- Ayuda -->
            <div class="form-card">
                <h5><i class="fas fa-question-circle mr-2"></i>Ayuda</h5>
                <small class="text-muted">
                    <p><i class="fas fa-asterisk text-danger"></i> Campos obligatorios</p>
                    <p class="mb-0">Al establecer una fecha de fin, la actividad se marcará como completada.</p>
                </small>
            </div>
        </div>
    </div>
</form>
@endsection