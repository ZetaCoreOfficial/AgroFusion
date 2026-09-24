@extends('layouts.app')

@section('title', 'Registrar Actividad | AgroFusion')
@section('page_title', 'Registrar Actividad')

@section('content')
<div class="card">
    <div class="card-header bg-info text-white">
        <h3 class="card-title"><i class="fas fa-tasks mr-2"></i>Registrar Actividad</h3>
    </div>

    <form action="{{ route('actividades.store') }}" method="POST" enctype="multipart/form-data" id="formActividad">
        @csrf
        @if(!empty($returnUrl))
            <input type="hidden" name="return" value="{{ $returnUrl }}">
        @endif
        @if(!empty($desdeTrazabilidad))
            <input type="hidden" name="desde_trazabilidad" value="1">
        @endif
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">

                    @php
                        $loteFijoId = (int) old('loteid', $loteid ?? 0);
                        $loteBloqueado = $loteFijoId > 0 && (
                            ! empty($loteid) || ! empty($desdeTrazabilidad) || old('desde_trazabilidad')
                        );
                    @endphp

                    @if($loteBloqueado)
                        <div class="form-group">
                            <label><i class="fas fa-map-marked-alt mr-1"></i> Lote <span class="text-danger">*</span></label>
                            <input type="text" class="form-control bg-light font-weight-bold" readonly
                                   value="{{ $loteLabel ?? ('Lote #' . $loteFijoId) }}">
                            <input type="hidden" name="loteid" value="{{ $loteFijoId }}">
                            <small class="form-text text-muted">
                                <i class="fas fa-lock mr-1"></i> Lote fijado. Para registrar en otro lote, vuelva a la trazabilidad de ese lote.
                            </small>
                        </div>
                    @else
                        @include('partials.selector-catalogo', [
                            'id' => 'actividad_lote',
                            'name' => 'loteid',
                            'label' => 'Lote',
                            'icon' => 'fa-map-marked-alt',
                            'value' => old('loteid'),
                            'labelSelected' => $loteLabel ?? '',
                            'endpoint' => route('catalogo-selector.lotes'),
                            'title' => 'Seleccionar lote',
                            'searchPlaceholder' => 'Nombre, código o ubicación…',
                            'required' => true,
                        ])
                    @endif

                    @if(!empty($puedeDesignarResponsable))
                        @include('partials.selector-catalogo', [
                            'id' => 'actividad_responsable',
                            'name' => 'usuarioid',
                            'label' => 'Responsable',
                            'icon' => 'fa-user',
                            'value' => old('usuarioid', $responsableInicial ?? ''),
                            'labelSelected' => $responsableLabel ?? '',
                            'endpoint' => route('catalogo-selector.usuarios'),
                            'params' => $responsableSelectorParams ?? ['roles' => 'agricultor'],
                            'pinnedOption' => $responsablePinnedOption ?? null,
                            'title' => 'Seleccionar responsable',
                            'searchPlaceholder' => 'Nombre, correo o usuario…',
                            'help' => ! empty($esJefeAgricultorDesignando)
                                ? 'Elija un operario de su equipo o «Asignarse a sí mismo» si va a ejecutar la tarea.'
                                : 'Elija el operario que ejecutará la actividad, o «Asignarse a sí mismo».',
                            'required' => true,
                        ])
                    @else
                        <div class="form-group">
                            <label><i class="fas fa-user mr-1"></i> Responsable</label>
                            <input type="text" class="form-control bg-light" readonly
                                   value="{{ $responsableLabel ?? '' }}"
                                   placeholder="Usted es el responsable">
                            <input type="hidden" name="usuarioid" value="{{ auth()->user()->usuarioid }}">
                            <small class="form-text text-muted">
                                <i class="fas fa-info-circle"></i> Usted queda asignado automáticamente como responsable
                            </small>
                        </div>
                    @endif

                    <div class="form-group">
                        <label><i class="fas fa-clipboard-list mr-1"></i> Tipo de Actividad <span class="text-danger">*</span></label>
                        <select name="tipoactividadid" id="tipoactividadid" class="form-control" required>
                            <option value="">— Elija el tipo —</option>
                            @foreach($tipos as $t)
                                <option value="{{ $t->tipoactividadid }}"
                                    @selected(old('tipoactividadid') == $t->tipoactividadid)>
                                    {{ ucfirst($t->nombre) }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">Al elegir el tipo se abrirá un cuadro para indicar insumos o tipo de riego.</small>
                    </div>

                    @include('actividades.partials.detalle-actividad', [
                        'modoSiembra' => false,
                        'sugerenciaSiembra' => null,
                        'insumosSiembra' => [],
                    ])

                    <div class="form-group">
                        <label><i class="fas fa-flag mr-1"></i> Prioridad <span class="text-danger">*</span></label>
                        <select name="prioridadid" class="form-control" required>
                            @php
                                $prioridadSeleccionada = old('prioridadid');
                                if (! $prioridadSeleccionada) {
                                    $prioridadSeleccionada = $prioridades->firstWhere(
                                        fn ($p) => mb_strtolower(trim($p->nombre ?? '')) === 'media'
                                    )?->prioridadid ?? $prioridades->first()?->prioridadid;
                                }
                            @endphp
                            @foreach($prioridades as $p)
                                <option value="{{ $p->prioridadid }}" @selected((int) $prioridadSeleccionada === (int) $p->prioridadid)>
                                    {{ ucfirst($p->nombre) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-calendar-day mr-1"></i> Fecha planificada</label>
                        <input type="date" name="fecha_planificada" class="form-control"
                               value="{{ old('fecha_planificada') }}">
                        <small class="form-text text-muted">
                            Día previsto para ejecutar la actividad (el operario la verá como límite/plan).
                            No marca la actividad como iniciada ni completada.
                        </small>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment mr-1"></i> Observaciones</label>
                        <textarea name="observaciones" class="form-control" maxlength="250" rows="2"
                                  placeholder="Opcional...">{{ old('observaciones') }}</textarea>
                    </div>

                    <div class="alert alert-light border small mb-0">
                        <i class="fas fa-bell text-info mr-1"></i>
                        @if(!empty($puedeDesignarResponsable))
                            La actividad quedará <strong>pendiente</strong> hasta que quien la ejecute la marque como completada con foto de evidencia.
                        @else
                            La actividad quedará <strong>pendiente</strong> hasta que la marque como completada desde Actividades o la trazabilidad del lote.
                        @endif
                    </div>

                </div>

                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-info-circle mr-1"></i> Informacion</h6>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">
                                <i class="fas fa-calendar-day mr-1"></i> <strong>Fecha planificada:</strong> cuándo debe hacerse (opcional)
                            </p>
                            <p class="small text-muted mb-2">
                                <i class="fas fa-calendar mr-1"></i> <strong>Fecha inicio real:</strong> se registra al crear / al ejecutar
                            </p>
                            <p class="small text-muted mb-0">
                                <i class="fas fa-user mr-1"></i> <strong>Responsable:</strong>
                                @if(!empty($puedeDesignarResponsable))
                                    @if(!empty($esJefeAgricultorDesignando))
                                        Puede asignar a un operario o a usted mismo para ejecutar la tarea
                                    @else
                                        Elija el ejecutor o asígnese a usted mismo si va a completar la tarea
                                    @endif
                                @else
                                    Usted (agricultor asignado al lote)
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="card mt-3 border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="fas fa-list mr-1"></i> Tipos disponibles</h6>
                        </div>
                        <div class="card-body small">
                            <ul class="list-unstyled mb-0">
                                @php
                                    $iconosTipo = [
                                        'siembra' => ['fa-seedling', 'text-success'],
                                        'riego' => ['fa-tint', 'text-primary'],
                                        'fertilización' => ['fa-flask', 'text-teal'],
                                        'fertilizacion' => ['fa-flask', 'text-teal'],
                                        'control de plagas' => ['fa-bug', 'text-warning'],
                                    ];
                                @endphp
                                @forelse($tipos as $t)
                                    @php
                                        $clave = mb_strtolower(trim($t->nombre));
                                        $icono = $iconosTipo[$clave] ?? ['fa-tasks', 'text-secondary'];
                                    @endphp
                                    <li class="mb-1">
                                        <i class="fas {{ $icono[0] }} {{ $icono[1] }} mr-1"></i> {{ ucfirst($t->nombre) }}
                                    </li>
                                @empty
                                    <li class="text-muted">No hay tipos configurados.</li>
                                @endforelse
                            </ul>
                            <p class="text-muted mb-0 mt-2"><i class="fas fa-info-circle mr-1"></i> La cosecha se registra desde la trazabilidad del lote, no como actividad.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <div class="d-flex justify-content-between">
                <a href="{{ $returnUrl ?? route('actividades.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-save mr-1"></i> Registrar Actividad
                </button>
            </div>
        </div>
    </form>
</div>
@endsection

@if($errors->any())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ag-flash--error').forEach(function (el) { el.remove(); });
    if (window.ModalConfirmar) {
        window.ModalConfirmar.aviso({
            titulo: 'No se pudo registrar la actividad',
            mensaje: @json($errors->first()),
            tono: 'warning',
        });
    }
});
</script>
@endpush
@endif