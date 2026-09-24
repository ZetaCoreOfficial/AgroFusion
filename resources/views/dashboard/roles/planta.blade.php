@extends('layouts.app')

@section('title', 'Panel Planta | AgroFusion')
@section('page_title', 'Panel Planta')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}" style="color: #2c5530;">Inicio</a></li>
    <li class="breadcrumb-item active">Panel Planta</li>
@endsection

@push('styles')
@include('dashboard.partials.panel-accesos-styles')
<style>
.planta-panel-wrap { --rp-accent: #2c5530; }
.planta-metrics {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: .75rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 1200px) { .planta-metrics { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 576px) { .planta-metrics { grid-template-columns: repeat(2, 1fr); } }
.planta-metric {
    border-radius: 8px;
    padding: .95rem 1rem;
    color: #1e293b;
    background: #fff;
    border: 1px solid #dee2e6;
    position: relative;
    overflow: hidden;
}
.planta-metric__val { font-size: 1.45rem; font-weight: 700; line-height: 1.1; margin-bottom: .15rem; }
.planta-metric__lbl { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #64748b; margin: 0; }
</style>
@endpush

@section('content')
<section class="content px-0 planta-panel-wrap">
    <div class="container-fluid px-0">

        <div class="role-panel-hero position-relative" style="z-index:1">
            <div class="role-panel-hero__title">
                <i class="fas fa-industry"></i>Panel Planta
            </div>
            <p class="role-panel-hero__sub">
                Recepción agrícola, procesamiento industrial, almacén de planta y comercialización.
            </p>
            @include('dashboard.partials.panel-admin-vista')
        </div>

        @include('partials.dashboard-alertas')

        @include('dashboard.partials.filtros', [
            'filtros' => $filtros,
            'actionUrl' => url()->current(),
            'mostrarUsuario' => $mostrarUsuario ?? false,
            'usuariosPanel' => $usuariosPanel ?? collect(),
            'etiquetaUsuarioPanel' => 'Usuario planta',
        ])

        @if(($tareasPendientesCount ?? 0) > 0)
        <div class="alert alert-warning border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center mb-3" style="gap:.75rem;">
            <div>
                <strong><i class="fas fa-industry mr-1"></i>Tiene {{ $tareasPendientesCount }} tarea(s) de transformación pendiente(s)</strong>
                <span class="d-block small text-muted">Revise el detalle y márquelas como completadas.</span>
            </div>
            <a href="{{ route('tareas-planta.index') }}" class="btn btn-warning btn-sm font-weight-bold">
                <i class="fas fa-tasks mr-1"></i>Ver mis tareas
            </a>
        </div>
        @endif

        <div class="planta-metrics">
            <div class="planta-metric planta-metric--pedidos dash-kpi--green">
                <i class="fas fa-boxes planta-metric__icon"></i>
                <div class="planta-metric__val">{{ $stats['pedidos_totales'] }}</div>
                <p class="planta-metric__lbl">Pedidos agrícola</p>
            </div>
            <div class="planta-metric planta-metric--asig dash-kpi--blue">
                <i class="fas fa-dolly planta-metric__icon"></i>
                <div class="planta-metric__val">{{ $stats['asignaciones'] }}</div>
                <p class="planta-metric__lbl">Recepciones</p>
            </div>
            <div class="planta-metric planta-metric--rutas dash-kpi--teal">
                <i class="fas fa-route planta-metric__icon"></i>
                <div class="planta-metric__val">{{ $stats['rutas_activas'] }}</div>
                <p class="planta-metric__lbl">Rutas a PDV</p>
            </div>
            <div class="planta-metric planta-metric--inc dash-kpi--amber">
                <i class="fas fa-exclamation-triangle planta-metric__icon"></i>
                <div class="planta-metric__val">{{ $stats['incidentes_abiertos'] }}</div>
                <p class="planta-metric__lbl">Incidentes</p>
            </div>
            <div class="planta-metric planta-metric--doc dash-kpi--slate">
                <i class="fas fa-file-signature planta-metric__icon"></i>
                <div class="planta-metric__val">{{ $stats['documentos'] }}</div>
                <p class="planta-metric__lbl">Documentos</p>
            </div>
        </div>

        @if(isset($charts))
        <div class="row panel-chart-row">
            <div class="col-lg-8 mb-4">
                <div class="panel-chart-card">
                    <div class="panel-chart-card__head">
                        <h3><i class="fas fa-chart-line text-success mr-2"></i>Flujo de pedidos y recepciones · {{ $etiquetaGrafico ?? $filtros->etiquetaGrafico() }}</h3>
                    </div>
                    <div class="panel-chart-wrap"><canvas id="chartPlantaFlujo"></canvas></div>
                </div>
            </div>
            <div class="col-lg-4 mb-4">
                <div class="panel-chart-card">
                    <div class="panel-chart-card__head">
                        <h3><i class="fas fa-chart-pie text-primary mr-2"></i>Estado de recepciones</h3>
                    </div>
                    <div class="panel-chart-wrap panel-chart-wrap--sm"><canvas id="chartPlantaEstados"></canvas></div>
                </div>
            </div>
        </div>
        <div class="panel-chart-card mb-4">
            <div class="panel-chart-card__head">
                <h3><i class="fas fa-store text-warning mr-2"></i>Pedidos de distribución por estado</h3>
            </div>
            <div class="panel-chart-wrap panel-chart-wrap--sm"><canvas id="chartPlantaDistribucion"></canvas></div>
        </div>
        @endif

        <div class="card role-acc-card">
            <div class="role-acc-card__head">
                <h3><i class="fas fa-bolt text-success mr-2"></i>Accesos rápidos</h3>
            </div>

            @php $userPanel = auth()->user(); @endphp

            @canany(['pedidos.view', 'pedidos.update'])
            <div class="role-acc-grupo">
                <div class="role-acc-grupo__titulo">Recepción agrícola</div>
                <div class="role-acc-grid">
                    @can('pedidos.view')
                    <a href="{{ route('pedidos.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-truck-loading"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Pedidos agrícola</span>
                            <span class="role-acc-tile__sub">Recepción desde almacén agrícola</span>
                        </span>
                    </a>
                    @endcan
                    @can('documentos.view')
                    <a href="{{ route('logistica.documentos.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--adm"><i class="fas fa-file-alt"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Documentos</span>
                            <span class="role-acc-tile__sub">Comprobantes de recepción</span>
                        </span>
                    </a>
                    @endcan
                    @can('incidentes.view')
                    <a href="{{ route('logistica.incidentes.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--warn"><i class="fas fa-exclamation-circle"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Incidentes</span>
                            <span class="role-acc-tile__sub">{{ $stats['incidentes_abiertos'] }} abierto(s)</span>
                        </span>
                    </a>
                    @endcan
                </div>
            </div>
            @endcanany

            <div class="role-acc-grupo">
                <div class="role-acc-grupo__titulo">Producción</div>
                <div class="role-acc-grid">
                    @can('lote_produccion.view')
                    <a href="{{ route('procesamiento.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-flask"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Procesamiento de lote</span>
                            <span class="role-acc-tile__sub">Transformación y certificación</span>
                        </span>
                    </a>
                    @endcan
                    @if($userPanel && \App\Support\UsuarioRol::gestionaPlanta($userPanel))
                    <a href="{{ route('procesos-planta.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-cogs"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Procesos de planta</span>
                            <span class="role-acc-tile__sub">Etapas y flujo industrial</span>
                        </span>
                    </a>
                    <a href="{{ route('plantillas-transformacion.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-project-diagram"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Plantillas de transformación</span>
                            <span class="role-acc-tile__sub">Modelos para lotes</span>
                        </span>
                    </a>
                    <a href="{{ route('maquinas-planta.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-industry"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Máquinas de planta</span>
                            <span class="role-acc-tile__sub">Equipos de producción</span>
                        </span>
                    </a>
                    <a href="{{ route('variables-estandar.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--prod"><i class="fas fa-sliders-h"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Variables estándar</span>
                            <span class="role-acc-tile__sub">Temperatura, presión, etc.</span>
                        </span>
                    </a>
                    @endif
                    @if($userPanel && \App\Support\UsuarioRol::esOperarioPlanta($userPanel))
                    <a href="{{ route('tareas-planta.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--warn"><i class="fas fa-tasks"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Mis tareas</span>
                            <span class="role-acc-tile__sub">Etapas asignadas en maquinaria</span>
                        </span>
                    </a>
                    @endif
                </div>
            </div>

            @if($userRole && \App\Support\UsuarioRol::gestionaPlanta($userRole))
            @can('almacen.movimientos.view')
            <div class="role-acc-grupo">
                <div class="role-acc-grupo__titulo">Almacén de planta</div>
                <div class="role-acc-grid">
                    <a href="{{ route('almacen-planta.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--log"><i class="fas fa-warehouse"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Almacenes</span>
                            <span class="role-acc-tile__sub">Depósitos y capacidad</span>
                        </span>
                    </a>
                    <a href="{{ route('almacen-planta.movimientos.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--log"><i class="fas fa-exchange-alt"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Movimientos</span>
                            <span class="role-acc-tile__sub">Ingresos y salidas de stock</span>
                        </span>
                    </a>
                    @can('almacen.reportes.view')
                    <a href="{{ route('almacen-planta.movimientos.reportes') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--adm"><i class="fas fa-chart-bar"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Reportes</span>
                            <span class="role-acc-tile__sub">Resumen de almacén</span>
                        </span>
                    </a>
                    @endcan
                </div>
            </div>
            @endcan
            @endif

            @canany(['pedidos_distribucion.view', 'pedidos_distribucion.update'])
            <div class="role-acc-grupo">
                <div class="role-acc-grupo__titulo">Comercialización</div>
                <div class="role-acc-grid">
                    @can('pedidos_distribucion.view')
                    <a href="{{ route('punto-venta.pedidos.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--com"><i class="fas fa-store"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Pedidos de distribución</span>
                            <span class="role-acc-tile__sub">Solicitudes de puntos de venta</span>
                        </span>
                    </a>
                    <a href="{{ route('planta.solicitudes-produccion.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--com"><i class="fas fa-industry"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Solicitudes de producción</span>
                            <span class="role-acc-tile__sub">Pedidos del mayorista</span>
                        </span>
                    </a>
                    @endcan
                    @if(\App\Support\UsuarioRol::puedeGestionarDistribucionPlanta(auth()->user()))
                    <a href="{{ route('punto-venta.rutas.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--com"><i class="fas fa-route"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Planificar distribución</span>
                            <span class="role-acc-tile__sub">Rutas planta → minoristas</span>
                        </span>
                    </a>
                    @endif
                </div>
            </div>
            @endcanany

            @can('usuarios.view')
            <div class="role-acc-grupo">
                <div class="role-acc-grupo__titulo">Equipo</div>
                <div class="role-acc-grid">
                    <a href="{{ route('gestion.index') }}" class="role-acc-tile">
                        <span class="role-acc-tile__icon role-acc-tile__icon--adm"><i class="fas fa-users-cog"></i></span>
                        <span>
                            <span class="role-acc-tile__lbl">Gestión de usuarios</span>
                            <span class="role-acc-tile__sub">Operarios y accesos del equipo</span>
                        </span>
                    </a>
                </div>
            </div>
            @endcan
        </div>
    </div>
</section>
@endsection

@if(isset($charts))
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var charts = @json($charts);
    var grid = { color: '#f1f5f9' };
    var baseOpts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } } };
    function emptyChart(id, msg) {
        var el = document.getElementById(id);
        if (el && el.parentElement) el.parentElement.innerHTML = '<div class="panel-chart-empty"><i class="fas fa-chart-line fa-2x mb-2 d-block"></i>' + msg + '</div>';
    }
    function doughnut(id, pack, msg) {
        if (!pack.labels.length) { emptyChart(id, msg); return; }
        new Chart(document.getElementById(id), {
            type: 'doughnut',
            data: { labels: pack.labels, datasets: [{ data: pack.data, backgroundColor: pack.colors, borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } },
        });
    }
    if (charts.pedidosMes.labels.length) {
        new Chart(document.getElementById('chartPlantaFlujo'), {
            type: 'line',
            data: {
                labels: charts.pedidosMes.labels,
                datasets: [
                    { label: charts.pedidosMes.label, data: charts.pedidosMes.data, borderColor: '#22c55e', backgroundColor: '#22c55e22', fill: true, tension: .35, borderWidth: 2 },
                    { label: charts.asignacionesMes.label, data: charts.asignacionesMes.data, borderColor: '#3b82f6', backgroundColor: '#3b82f622', fill: true, tension: .35, borderWidth: 2 },
                ],
            },
            options: Object.assign({}, baseOpts, { scales: { y: { beginAtZero: true, grid: grid }, x: { grid: grid } } }),
        });
    } else { emptyChart('chartPlantaFlujo', 'Sin pedidos en el periodo'); }
    doughnut('chartPlantaEstados', charts.estadosAsignacion, 'Sin recepciones registradas');
    doughnut('chartPlantaDistribucion', charts.distribucionEstados, 'Sin pedidos de distribución');
});
</script>
@endpush
@endif
