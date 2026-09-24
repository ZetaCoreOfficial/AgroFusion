@extends('layouts.app')

@section('title', 'Actividades | AgroFusion')
@section('page_title', 'Gestión de actividades')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Inicio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('lotes.index') }}">Lotes</a></li>
    <li class="breadcrumb-item active">Actividades</li>
@endsection

@php
    $filtrosActivos = collect($filtros ?? [])->filter(fn ($v) => $v !== null && $v !== '');
    $prioridadBadge = fn ($nombre) => match (strtolower($nombre ?? '')) {
        'alta' => 'danger',
        'media' => 'warning',
        default => 'secondary',
    };
    $pctCompletadas = $stats['total'] > 0
        ? round(($stats['completadas'] / $stats['total']) * 100)
        : 0;
    $urlTrazabilidadActividad = fn ($act) => $act->lote
        ? route('lotes.trazabilidad', $act->lote).'#historial-eventos'
        : null;
    $loteFiltroId = $filtros['loteid'] ?? '';
    $loteFiltroNombre = $loteFiltroNombre ?? '';
@endphp

@push('styles')
@include('partials.modulo-lotes-actividades-styles')
<style>
.page-actividades .consulta-banner {
    background: linear-gradient(90deg, #f0f7f1 0%, #fff 100%);
    border-bottom: 1px solid #e3ebe4;
    padding: .65rem 1.25rem;
    font-size: .85rem;
    color: #5a6c5c;
}
.page-actividades .consulta-banner i { color: #28a745; }
.page-actividades .table-actividades thead th {
    background: #f4f6f9;
    border-bottom: 2px solid #dee2e6;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #6c757d;
    font-weight: 600;
    white-space: nowrap;
}
.page-actividades .table-actividades tbody td {
    vertical-align: middle;
    padding: 0.9rem 0.75rem;
    font-size: 0.875rem;
}
.page-actividades .table-actividades tbody tr:hover { background: #f8fbf8; }
.page-actividades .act-tipo {
    font-weight: 600;
    color: #2c5530;
    display: inline-block;
}
.page-actividades .act-tipo:hover { color: #1e3d22; text-decoration: none; }
.page-actividades .act-desc {
    margin: .35rem 0 0;
    padding-left: 1.35rem;
    font-size: .8rem;
    color: #6c757d;
    line-height: 1.35;
    position: relative;
}
.page-actividades .act-desc i {
    position: absolute;
    left: 0;
    top: .15rem;
    opacity: .7;
}
.page-actividades .act-lote {
    font-weight: 500;
    color: #343a40;
}
.page-actividades .act-row-card {
    display: flex;
    align-items: flex-start;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #f1f3f4;
    transition: background 0.15s ease;
    gap: .85rem;
}
.page-actividades .act-row-card:hover { background: #f8fbf8; }
.page-actividades .act-avatar {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.page-actividades .act-avatar.pendiente { background: #fff8e1; color: #f39c12; }
.page-actividades .act-avatar.completada { background: #e8f5e9; color: #28a745; }
.page-actividades .btn-actions .btn {
    padding: 0.25rem 0.5rem;
    line-height: 1.2;
}
</style>
@endpush

@section('content')
<div class="modulo-la page-actividades">

    <div class="row mb-2">
        <div class="col-lg-3 col-6">
            <div class="small-box small-box-green">
                <div class="inner"><h3>{{ $stats['total'] }}</h3><p>Total actividades</p></div>
                <div class="icon"><i class="fas fa-tasks"></i></div>
                <span class="small-box-footer">Historial completo</span>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box small-box-yellow">
                <div class="inner"><h3>{{ $stats['pendientes'] }}</h3><p>Pendientes</p></div>
                <div class="icon"><i class="fas fa-clock"></i></div>
                <span class="small-box-footer">Por completar</span>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box small-box-blue">
                <div class="inner"><h3>{{ $stats['completadas'] }}</h3><p>Completadas</p></div>
                <div class="icon"><i class="fas fa-check-circle"></i></div>
                <span class="small-box-footer">{{ $pctCompletadas }}% del total</span>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box small-box-red">
                <div class="inner"><h3>{{ $stats['hoy'] }}</h3><p>Hoy</p></div>
                <div class="icon"><i class="fas fa-calendar-day"></i></div>
                <a href="{{ route('actividades.calendario') }}" class="small-box-footer">
                    Calendario <i class="fas fa-arrow-circle-right"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="card card-outline card-success card-modulo-main elevation-1">
        <x-modulo-index-header
            titulo="Actividades"
            icono="fa-tasks"
            :registros="$actividades->total()"
            filtros-target="#filtrosActividadesPanel"
            :view-toggle="true"
            view-default="table"
        />

        <div class="consulta-banner">
            <i class="fas fa-info-circle mr-1"></i>
            Vista de <strong>consulta</strong>. Para registrar o completar actividades, use la trazabilidad del lote correspondiente.
        </div>

        <div id="filtrosActividadesPanel" class="filtros-panel collapse {{ $filtrosActivos->isNotEmpty() ? 'show' : '' }}">
            <form method="GET" action="{{ route('actividades.index') }}">
                <div class="row">
                    <div class="col-lg-4 col-md-6 mb-2">
                        <label class="small text-muted mb-1">Buscar</label>
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            </div>
                            <input type="text" name="q" class="form-control"
                                value="{{ $filtros['q'] ?? '' }}" placeholder="Tipo, lote, responsable o descripción">
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-3 col-6 mb-2">
                        <label class="small text-muted mb-1">Estado</label>
                        <select name="estado" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            <option value="pendiente" @selected(($filtros['estado'] ?? '') === 'pendiente')>Pendientes</option>
                            <option value="completada" @selected(($filtros['estado'] ?? '') === 'completada')>Completadas</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6 mb-2">
                        <label class="small text-muted mb-1">Lote</label>
                        @include('partials.selector-catalogo', [
                            'id' => 'actividades_filtro_lote',
                            'name' => 'loteid',
                            'value' => $loteFiltroId,
                            'labelSelected' => $loteFiltroNombre,
                            'endpoint' => route('catalogo-selector.lotes'),
                            'title' => 'Filtrar por lote',
                            'searchPlaceholder' => 'Nombre, código o ubicación…',
                            'searchLabel' => 'Buscar lote',
                            'allowEmpty' => true,
                            'emptyLabel' => 'Todos los lotes',
                            'placeholderEmpty' => 'Todos los lotes',
                            'inputGroup' => true,
                            'showLabel' => false,
                            'modalIcon' => 'fa-map-marked-alt',
                            'rowIcon' => 'fa-seedling',
                            'colNombre' => 'Lote',
                            'colDetalle' => 'Cultivo / ubicación',
                            'variant' => 'filtros',
                        ])
                    </div>
                    <div class="col-lg-3 col-md-3 col-6 mb-2">
                        <label class="small text-muted mb-1">Tipo</label>
                        <select name="tipoactividadid" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            @foreach($tiposActividad as $tipo)
                                <option value="{{ $tipo->tipoactividadid }}" @selected(($filtros['tipoactividadid'] ?? '') == $tipo->tipoactividadid)>{{ $tipo->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <x-filtros-form-actions
                    :limpiar-url="route('actividades.index', ['filtros_abiertos' => 1])"
                    :resultados="$filtrosActivos->isNotEmpty() ? $actividades->total() : null"
                />
            </form>
        </div>

        <div id="tableView" class="table-responsive">
            <table class="table table-actividades table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Lote</th>
                        <th>Responsable</th>
                        <th>Inicio</th>
                        <th title="La fecha fin se registra al marcar la actividad como realizada">Fin</th>
                        <th>Estado</th>
                        <th class="text-center" style="width: 72px;">Ver</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($actividades as $act)
                        @php
                            $esCompletada = $act->fechafin !== null;
                            $trzUrl = $urlTrazabilidadActividad($act);
                        @endphp
                        <tr>
                            <td>
                                @if($trzUrl)
                                    <a href="{{ $trzUrl }}" class="act-tipo">{{ $act->tipoActividad->nombre ?? '—' }}</a>
                                @else
                                    <span class="act-tipo">{{ $act->tipoActividad->nombre ?? '—' }}</span>
                                @endif
                                @if($act->prioridad)
                                <span class="badge badge-{{ $prioridadBadge($act->prioridad->nombre) }} badge-sm ml-1">
                                    {{ $act->prioridad->nombre }}
                                </span>
                                @endif
                                @if($act->descripcion)
                                <p class="act-desc mb-0">
                                    <i class="fas fa-comment-alt"></i>{{ Str::limit($act->descripcion, 120) }}
                                </p>
                                @endif
                            </td>
                            <td>
                                <span class="act-lote">{{ $act->lote->nombre ?? '—' }}</span>
                                @if($act->lote?->cultivo)
                                <br><small class="text-muted">{{ $act->lote->cultivo->nombre }}</small>
                                @endif
                            </td>
                            <td class="text-muted">{{ $act->usuario->nombre ?? '—' }}</td>
                            <td>
                                @if($act->fecha_planificada)
                                    {{ \Carbon\Carbon::parse($act->fecha_planificada)->format('d/m/Y') }}
                                    <span class="d-block small text-muted">plan</span>
                                @elseif($act->fechainicio)
                                    {{ \Carbon\Carbon::parse($act->fechainicio)->format('d/m/Y') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if($esCompletada)
                                    {{ \Carbon\Carbon::parse($act->fechafin)->format('d/m/Y') }}
                                @else
                                    <span class="badge badge-light border text-muted font-weight-normal">Pendiente</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-{{ $esCompletada ? 'success' : 'warning' }}">
                                    {{ $esCompletada ? 'Completada' : 'Pendiente' }}
                                </span>
                            </td>
                            <td class="text-center">
                                @if($trzUrl)
                                <a href="{{ $trzUrl }}" class="btn btn-default btn-sm btn-actions" title="Ver en trazabilidad del lote">
                                    <i class="fas fa-eye text-info"></i>
                                </a>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="fas fa-tasks fa-2x mb-2 text-light d-block"></i>
                                No hay actividades que coincidan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div id="cardView" style="display: none;">
            @forelse($actividades as $act)
                @php
                    $esCompletada = $act->fechafin !== null;
                    $trzUrl = $urlTrazabilidadActividad($act);
                @endphp
                <div class="act-row-card">
                    <div class="act-avatar {{ $esCompletada ? 'completada' : 'pendiente' }}">
                        <i class="fas fa-{{ $esCompletada ? 'check' : 'clock' }}"></i>
                    </div>
                    <div class="flex-grow-1 min-width-0">
                        @if($trzUrl)
                            <a href="{{ $trzUrl }}" class="act-tipo">{{ $act->tipoActividad->nombre ?? 'Sin tipo' }}</a>
                        @else
                            <span class="act-tipo">{{ $act->tipoActividad->nombre ?? 'Sin tipo' }}</span>
                        @endif
                        <div class="mt-1">
                            <span class="badge badge-{{ $esCompletada ? 'success' : 'warning' }} badge-sm mr-1">
                                {{ $esCompletada ? 'Completada' : 'Pendiente' }}
                            </span>
                            @if($act->prioridad)
                            <span class="badge badge-{{ $prioridadBadge($act->prioridad->nombre) }} badge-sm">
                                {{ $act->prioridad->nombre }}
                            </span>
                            @endif
                        </div>
                        <div class="mt-2 small text-muted">
                            <span><i class="fas fa-map-marker-alt mr-1"></i>{{ $act->lote->nombre ?? '—' }}</span>
                            @if($act->lote?->cultivo)
                            <span class="mx-1">·</span>
                            <span>{{ $act->lote->cultivo->nombre }}</span>
                            @endif
                        </div>
                        <div class="small text-muted mt-1">
                            <i class="fas fa-user mr-1"></i>{{ $act->usuario->nombre ?? '—' }}
                            <span class="mx-1">·</span>
                            <i class="far fa-calendar mr-1"></i>
                            @if($act->fecha_planificada)
                                {{ \Carbon\Carbon::parse($act->fecha_planificada)->format('d/m/Y') }}
                                <span class="text-muted">(plan)</span>
                            @else
                                {{ $act->fechainicio ? \Carbon\Carbon::parse($act->fechainicio)->format('d/m/Y') : '—' }}
                            @endif
                            @if($esCompletada)
                            <span class="mx-1">→</span>
                            {{ \Carbon\Carbon::parse($act->fechafin)->format('d/m/Y') }}
                            @endif
                        </div>
                        @if($act->descripcion)
                        <p class="act-desc mb-0 mt-2">{{ Str::limit($act->descripcion, 100) }}</p>
                        @endif
                    </div>
                    @if($trzUrl)
                    <a href="{{ $trzUrl }}" class="btn btn-default btn-sm btn-actions flex-shrink-0 align-self-center" title="Ver en trazabilidad">
                        <i class="fas fa-eye text-info"></i>
                    </a>
                    @endif
                </div>
            @empty
                <div class="text-center text-muted py-5">No hay actividades registradas.</div>
            @endforelse
        </div>

        @if($actividades->hasPages())
        <div class="card-footer bg-white d-flex justify-content-end py-2">
            {{ $actividades->links() }}
        </div>
        @endif
    </div>

</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#btnCardView').on('click', function () {
        $(this).addClass('active').siblings().removeClass('active');
        $('#cardView').show();
        $('#tableView').hide();
    });
    $('#btnTableView').on('click', function () {
        $(this).addClass('active').siblings().removeClass('active');
        $('#tableView').show();
        $('#cardView').hide();
    });
});
</script>
@endpush
