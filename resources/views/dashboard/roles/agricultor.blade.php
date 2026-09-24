@extends('layouts.app')

@section('title', 'Mis trabajos | AgroFusion')
@section('page_title', 'Inicio')

@section('breadcrumbs')
    <li class="breadcrumb-item active">Inicio</li>
@endsection

@push('styles')
@include('dashboard.partials.panel-accesos-styles')
@endpush

@section('content')
<section class="content px-0">
    <div class="container-fluid px-0">

        <div class="role-panel-hero position-relative" style="z-index:1">
            <div class="role-panel-hero__title">
                <i class="fas fa-hard-hat"></i>Mis trabajos
            </div>
            <p class="role-panel-hero__sub">
                Hola, <strong>{{ auth()->user()->nombre }}</strong> · Tus lotes y actividades asignadas · {{ now()->format('d/m/Y') }}
            </p>
        </div>

        @include('partials.dashboard-alertas')

        @include('dashboard.partials.filtros', [
            'filtros' => $filtros,
            'lotes' => $lotes ?? collect(),
            'mostrarLote' => true,
            'actionUrl' => url()->current(),
        ])

        <div class="role-metrics">
            <div class="role-metric" style="background:linear-gradient(135deg,#15803d,#22c55e)">
                <i class="fas fa-map-marked-alt role-metric__icon"></i>
                <div class="role-metric__val">{{ $stats['lotes_asignados'] }}</div>
                <p class="role-metric__lbl">Lotes</p>
            </div>
            <div class="role-metric" style="background:linear-gradient(135deg,#c2410c,#f59e0b)">
                <i class="fas fa-tasks role-metric__icon"></i>
                <div class="role-metric__val">{{ $stats['actividades_pendientes'] }}</div>
                <p class="role-metric__lbl">Pendientes</p>
            </div>
            <div class="role-metric" style="background:linear-gradient(135deg,#0369a1,#0ea5e9)">
                <i class="fas fa-calendar-day role-metric__icon"></i>
                <div class="role-metric__val">{{ $stats['actividades_hoy'] }}</div>
                <p class="role-metric__lbl">Hoy</p>
            </div>
            <div class="role-metric" style="background:linear-gradient(135deg,#059669,#10b981)">
                <i class="fas fa-check-circle role-metric__icon"></i>
                <div class="role-metric__val">{{ $stats['completadas_mes'] }}</div>
                <p class="role-metric__lbl">Completadas ({{ $filtros->etiquetaPeriodo() }})</p>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-7 mb-3">
                <div class="role-block-card mb-0">
                    <div class="role-block-card__head">
                        <h3><i class="fas fa-tasks text-warning mr-2"></i>Actividades pendientes</h3>
                        <a href="{{ route('actividades.index') }}" class="btn btn-sm btn-outline-success">Ver todas</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table role-x-table mb-0">
                            <thead><tr><th>Actividad</th><th>Lote</th><th>Fecha</th><th></th></tr></thead>
                            <tbody>
                                @forelse($actividadesPendientes as $act)
                                <tr>
                                    <td>{{ $act->descripcion }}</td>
                                    <td>{{ $act->lote?->nombre ?? '—' }}</td>
                                    <td class="text-muted small">{{ $act->fecha_planificada?->format('d/m/Y') ?? $act->fechainicio?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('actividades.show', $act) }}" class="btn btn-sm btn-success">Reportar</a>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">No tienes actividades pendientes.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mb-3">
                <div class="role-block-card mb-0 h-100">
                    <div class="role-block-card__head">
                        <h3><i class="fas fa-map text-success mr-2"></i>Mis lotes</h3>
                        <a href="{{ route('lotes.index') }}" class="btn btn-sm btn-outline-success">Ver lotes</a>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse($lotesRecientes as $lote)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>{{ $lote->nombre }}</strong>
                                <div class="small text-muted">{{ $lote->cultivo?->nombre ?? 'Sin cultivo' }} · {{ $lote->estadoTipo?->nombre ?? '—' }}</div>
                            </div>
                            <a href="{{ route('lotes.show', $lote) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                        </li>
                        @empty
                        <li class="list-group-item text-muted text-center">Aún no tienes lotes asignados.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

        <div class="card role-acc-card">
            <div class="role-acc-card__head">
                <h3><i class="fas fa-bolt text-success mr-2"></i>Accesos rápidos</h3>
            </div>
            <div class="role-acc-grupo">
                <div class="role-acc-grupo__titulo">Mi trabajo</div>
                <div class="role-acc-grid">
                    @can('lotes.view')
                    <a href="{{ route('lotes.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-map"></i></span>
                        <span><span class="role-acc-tile__lbl">Mis lotes</span><span class="role-acc-tile__sub">Parcelas asignadas</span></span>
                    </a>
                    @endcan
                    <a href="{{ route('actividades.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-tasks"></i></span>
                        <span><span class="role-acc-tile__lbl">Actividades</span><span class="role-acc-tile__sub">Tareas del campo</span></span>
                    </a>
                    <a href="{{ route('actividades.calendario') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-calendar-alt"></i></span>
                        <span><span class="role-acc-tile__lbl">Calendario</span><span class="role-acc-tile__sub">Agenda semanal</span></span>
                    </a>
                    @can('certificaciones.view')
                    <a href="{{ route('certificaciones.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--com"><i class="fas fa-certificate"></i></span>
                        <span><span class="role-acc-tile__lbl">Certificaciones</span><span class="role-acc-tile__sub">Calidad de lote</span></span>
                    </a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
