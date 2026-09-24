@extends('layouts.app')

@section('title', 'Mis pedidos de distribución')
@section('page_title', 'Mis pedidos de distribución')

@push('styles')
@include('punto_venta.partials.modulo-styles')
<style>
.pdv-mis-pedidos .pdv-mis-header { margin-bottom: .75rem; }
.pdv-mis-pedidos .pdv-mis-header h2 { font-size: 1.1rem; font-weight: 800; margin: 0; color: #0f172a; }
.pdv-mis-pedidos .pdv-mis-header p { font-size: .82rem; color: #64748b; margin: .2rem 0 0; }
.pdv-mis-pedidos .pdv-kpi-mini { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-bottom: .75rem; }
@media (max-width: 575.98px) { .pdv-mis-pedidos .pdv-kpi-mini { grid-template-columns: 1fr; } }
.pdv-mis-pedidos .pdv-kpi-mini .small-box { margin-bottom: 0; }
.pdv-mis-pedidos .pdv-kpi-mini .small-box .inner { padding: .5rem .75rem; }
.pdv-mis-pedidos .pdv-kpi-mini .small-box .inner h3 { font-size: 1.4rem; margin: 0; }
.pdv-mis-pedidos .pdv-kpi-mini .small-box .inner p { font-size: .75rem; margin: 0; }
.pdv-mis-pedidos .pdv-kpi-mini .icon { font-size: 2.5rem; top: .35rem; right: .5rem; }
</style>
@endpush

@section('content')
<div class="pdv-mis-pedidos">
    <div class="pdv-mis-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h2><i class="fas fa-shopping-basket text-success mr-1"></i> Mis solicitudes al mayorista</h2>
            <p>Pida producto disponible en almacén mayorista o solicite algo nuevo para su punto de venta.</p>
        </div>
    </div>

    <div class="pdv-kpi-mini">
        <div class="small-box bg-warning">
            <div class="inner"><h3>{{ $pendientes->count() }}</h3><p>En revisión</p></div>
            <div class="icon"><i class="fas fa-hourglass-half"></i></div>
        </div>
        <div class="small-box bg-primary">
            <div class="inner"><h3>{{ $enRutaTiempoReal->count() }}</h3><p>En camino</p></div>
            <div class="icon"><i class="fas fa-shipping-fast"></i></div>
        </div>
        <div class="small-box bg-success">
            <div class="inner"><h3>{{ $pedidos->where('estado', 'recibido')->count() }}</h3><p>Recibidos</p></div>
            <div class="icon"><i class="fas fa-check"></i></div>
        </div>
    </div>

    <div class="card pdv-card card-outline card-success card-modulo-main elevation-1">
        <x-modulo-index-header
            titulo="Historial de mis pedidos"
            icono="fa-truck-loading"
            :registros="$pedidos->count()"
            filtros-target="#filtrosPedidosDistPanel"
            :nuevo-href="$puedeCrear ? route('punto-venta.pedidos.create', ['ctx' => 'pdv']) : null"
            nuevo-text="Solicitar producto"
            nuevo-icon="fa-plus"
        />

        <div id="filtrosPedidosDistPanel" class="collapse {{ request()->hasAny(['q','estado_grupo','puntoventaid']) ? 'show' : '' }}">
            <div class="modulo-filtros-panel pdv-pedidos-filtros">
                <form method="GET" action="{{ route('punto-venta.pedidos.index', ['ctx' => 'pdv']) }}" class="form-row align-items-end">
                    <input type="hidden" name="ctx" value="pdv">
                    <div class="form-group col-lg-4 col-md-6 mb-2 mb-lg-0">
                        <label>Buscar</label>
                        <input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Solicitud o producto…">
                    </div>
                    <div class="form-group col-lg-4 col-md-6 mb-2 mb-lg-0">
                        @include('partials.selector-catalogo', [
                            'id' => 'ped_filtro_pdv',
                            'name' => 'puntoventaid',
                            'value' => $filtroPdvId ?? '',
                            'labelSelected' => $filtroPdvNombre ?? '',
                            'endpoint' => route('catalogo-selector.puntos-venta'),
                            'title' => 'Mis puntos de venta',
                            'label' => 'Punto de venta',
                            'icon' => 'fa-store',
                            'searchPlaceholder' => 'Nombre o dirección…',
                            'allowEmpty' => true,
                            'emptyLabel' => 'Todos mis locales',
                            'placeholderEmpty' => 'Todos mis locales',
                            'variant' => 'filtros',
                        ])
                    </div>
                    <div class="form-group col-lg-2 col-md-6 mb-2 mb-lg-0">
                        <label>Estado</label>
                        <select name="estado_grupo" class="form-control">
                            <option value="">Todos</option>
                            @foreach(\App\Support\PedidoDistribucionCatalogo::etiquetasFiltroEstado() as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected(request('estado_grupo') === $valor)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-lg-2 col-md-6 mb-2 mb-lg-0 d-flex align-items-end">
                        <button type="submit" class="btn btn-success btn-sm btn-block"><i class="fas fa-filter mr-1"></i> Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped m-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Solicitud</th>
                            <th>Punto de venta</th>
                            <th>Producto</th>
                            <th>Cantidad</th>
                            <th>Entrega</th>
                            <th>Estado</th>
                            <th class="text-center">Ver</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pedidos as $pedido)
                            @php
                                $det = $pedido->detalles->first();
                                $badge = \App\Support\PedidoDistribucionCatalogo::badgeEstado($pedido);
                                $pres = $det?->presentacion;
                                $unidad = $pres?->etiquetaUnidad() ?? 'u.';
                            @endphp
                            <tr>
                                <td>
                                    <span class="pdv-solicitud-pill"><i class="fas fa-file-alt"></i>{{ $pedido->numero_solicitud }}</span>
                                    @if(\App\Support\PedidoDistribucionCatalogo::esPedidoExterno($pedido))
                                        <span class="badge badge-warning ml-1" title="Registrado por el mayorista">Externo · {{ \App\Support\PedidoDistribucionCatalogo::etiquetaCanal($pedido->canal_origen) }}</span>
                                    @endif
                                </td>
                                <td>{{ $pedido->puntoVenta?->nombre ?? '—' }}</td>
                                <td>{{ $det?->producto_nombre ?? '—' }}</td>
                                <td>@if($det){{ number_format($det->cantidad, 0) }} <span class="text-muted small">{{ $unidad }}</span>@else — @endif</td>
                                <td class="small text-nowrap">{{ $pedido->fecha_entrega_deseada?->format('d/m/Y') ?? '—' }}</td>
                                <td>
                                    <span class="pdv-estado-pill pdv-estado-pill--{{ $badge['clase'] }}">
                                        <i class="fas {{ $badge['icono'] ?? 'fa-info-circle' }}"></i> {{ $badge['etiqueta'] }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="{{ route('punto-venta.pedidos.show', ['pedido' => $pedido, 'ctx' => 'pdv']) }}" class="btn btn-xs btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    Aún no tiene pedidos.
                                    @if($puedeCrear)
                                        <a href="{{ route('punto-venta.pedidos.create', ['ctx' => 'pdv']) }}">Solicitar producto</a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
