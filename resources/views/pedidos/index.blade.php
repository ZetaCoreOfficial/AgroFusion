@extends('layouts.app')

@section('title', ($esTransportista ?? false) ? 'Mis pedidos asignados | AgroFusion' : 'Gestión de Pedidos | AgroFusion')
@section('page_title', ($esTransportista ?? false) ? 'Mis pedidos asignados' : 'Gestión de Pedidos')

@push('styles')
@include('partials.logistica-modulo-styles')
<style>
.pedidos-wrap { padding: 0 .25rem; }
.pedidos-alert { border-radius: 12px; padding: .65rem 1rem; margin-bottom: .75rem; }
.pedidos-stats .small-box {
    border-radius: 10px;
    margin-bottom: .65rem;
    min-height: 0;
}
.pedidos-stats .small-box .inner { padding: .55rem .75rem; }
.pedidos-stats .small-box h3 { font-size: 1.35rem; margin: 0 0 .1rem; font-weight: 700; }
.pedidos-stats .small-box p { font-size: .78rem; margin: 0; }
.pedidos-stats .small-box .icon { font-size: 2.25rem; top: 6px; right: 8px; }
.pedidos-stats .small-box .icon > i { font-size: inherit; }
.pedidos-filtros-card,
.pedidos-table-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 2px 12px rgba(18, 38, 63, .06);
}
.pedidos-filtros-card .form-check-input {
    width: .85rem;
    height: .85rem;
    margin-top: .15rem;
}
.pedidos-filtros-card .form-check-label {
    font-size: .78rem;
    color: #6c757d;
    cursor: pointer;
}
.pedido-estado-badge {
    display: inline-block;
    font-size: .75rem;
    font-weight: 600;
    padding: .25rem .65rem;
    border-radius: 50rem;
    color: #fff;
    line-height: 1.35;
    white-space: nowrap;
}
.pedidos-table-card .td-estado {
    white-space: nowrap;
}
.pedidos-table-card .td-acciones {
    white-space: nowrap;
    position: sticky;
    right: 0;
    background: #fff;
    box-shadow: -6px 0 10px rgba(255, 255, 255, .95);
    z-index: 2;
}
.pedido-estado-agricola { background: #64748b; }
.pedido-estado-logistica { background: #6366f1; }
.pedido-estado-confirmado { background: #16a34a; }
.pedido-estado-produccion { background: #d97706; }
.pedido-estado-rechazado { background: #dc2626; }
.pedido-estado-camino { background: #0284c7; }
.pedido-estado-recibido { background: #0d9488; }
.pedidos-actions .btn {
    padding: .2rem .45rem;
    margin: 0 .1rem;
}
.btn-asignar-transportista {
    font-size: .78rem;
    text-decoration: none;
    vertical-align: baseline;
}
.btn-asignar-transportista:hover {
    text-decoration: underline;
}
</style>
@endpush

@section('content')
<div class="pedidos-wrap">
    @if($esTransportista ?? false)
        <div class="alert alert-info pedidos-alert mb-3">
            <i class="fas fa-truck mr-1"></i> Pedidos con envío asignado a su cuenta.
        </div>
    @else
        <div class="alert alert-light border pedidos-alert mb-3">
            <i class="fas fa-leaf text-success mr-1"></i>
            Los envíos agrícola → planta quedan <strong>pendientes de aprobación</strong> hasta que producción agrícola confirme y reserve stock en
            <a href="{{ route('logistica.asignaciones.listado') }}">Envíos → Listado de envíos</a>.
        </div>
    @endif

    <div class="row pedidos-stats mb-2">
        @foreach([
            ['sin asignacion', 'secondary', 'inbox', 'Pendiente agrícola'],
            ['pendiente', 'info', 'clock', 'Pendientes'],
            ['confirmado', 'success', 'check-circle', 'Confirmados'],
            ['en produccion', 'warning', 'cogs', 'En producción'],
        ] as [$est, $color, $icon, $label])
        <div class="col-lg-3 col-6">
            <div class="small-box bg-{{ $color }}">
                <div class="inner">
                    <h3>{{ $pedidos->where('estado', $est)->count() }}</h3>
                    <p>{{ $label }}</p>
                </div>
                <div class="icon"><i class="fas fa-{{ $icon }}"></i></div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Filtros (mismo patrón que Lotes de producción) --}}
    <div class="card pedidos-filtros-card mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('pedidos.index') }}" class="form-row align-items-end">
                <div class="col-md-3 mb-2 mb-md-0">
                    <label class="small text-muted mb-1">Buscar</label>
                    <input type="search" name="q" class="form-control form-control-sm"
                           value="{{ request('q') }}" placeholder="Solicitud, cultivo, planta…">
                </div>

                @unless($esTransportista ?? false)
                <div class="col-md-2 mb-2 mb-md-0">
                    <label class="small text-muted mb-1">Transportista</label>
                    <input type="text" name="transportista_nombre" class="form-control form-control-sm"
                           value="{{ request('transportista_nombre') }}" placeholder="Ej: Carlos Mamani">
                </div>
                @if(($transportistas ?? collect())->isNotEmpty())
                <div class="col-md-2 mb-2 mb-md-0">
                    <label class="small text-muted mb-1">Lista transportistas</label>
                    <select name="transportista" class="custom-select custom-select-sm">
                        <option value="">Todos</option>
                        @foreach($transportistas as $t)
                            <option value="{{ $t->usuarioid }}" @selected((string) request('transportista') === (string) $t->usuarioid)>
                                {{ trim($t->nombre.' '.$t->apellido) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @endif
                @endunless

                <div class="col-md-2 mb-2 mb-md-0">
                    <label class="small text-muted mb-1">Estado</label>
                    <select name="estado" class="custom-select custom-select-sm">
                        <option value="">Todos</option>
                        @foreach($estadosPedido ?? [] as $estVal => $estLabel)
                            <option value="{{ $estVal }}" @selected(request('estado') === $estVal)>{{ $estLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2 mb-2 mb-md-0">
                    <label class="small text-muted mb-1">Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm" value="{{ request('desde') }}">
                </div>

                <div class="col-md-2 mb-2 mb-md-0">
                    <label class="small text-muted mb-1">Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm" value="{{ request('hasta') }}">
                </div>

                @unless($esTransportista ?? false)
                <div class="col-auto mb-2 mb-md-0">
                    <label class="small text-muted mb-1 d-block">&nbsp;</label>
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input" id="sin_asignar" name="sin_asignar" value="1"
                               @checked(request()->boolean('sin_asignar'))>
                        <label class="form-check-label" for="sin_asignar">Sin asignar Transportista</label>
                    </div>
                </div>
                @endunless

                <div class="col-auto mb-2 mb-md-0">
                    <label class="small text-muted mb-1 d-block">&nbsp;</label>
                    <button type="submit" class="btn btn-success btn-sm px-3" title="Filtrar">
                        <i class="fas fa-filter mr-1"></i> Filtrar
                    </button>
                </div>
            </form>

            @if(request()->except('page'))
                <p class="small text-muted mb-0 mt-2">
                    Filtros activos.
                    <a href="{{ route('pedidos.index') }}">Limpiar</a>
                </p>
            @endif
        </div>
    </div>

    {{-- Tabla --}}
    <div class="card pedidos-table-card">
        <div class="card-header bg-white py-3" style="border-radius:14px 14px 0 0;">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <h3 class="card-title mb-2 mb-md-0 font-weight-bold">
                    <i class="fas fa-shopping-cart text-success mr-2"></i>
                    {{ ($esTransportista ?? false) ? 'Mis pedidos' : 'Pedidos' }}
                </h3>
                @can('pedidos.create')
                <a href="{{ route('pedidos.create') }}" class="btn btn-success btn-sm px-3">
                    <i class="fas fa-plus mr-1"></i> Nuevo
                </a>
                @endcan
            </div>
        </div>

        <div class="card-body p-0 table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>#ID</th>
                        <th>Solicitud</th>
                        <th>Cultivo / producto</th>
                        <th>Ítems</th>
                        <th>Total (kg)</th>
                        @unless($esTransportista ?? false)
                        <th>Transportista</th>
                        @endunless
                        <th>Estado</th>
                        <th>Fecha</th>
                        <th class="text-center" style="width:148px">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pedidos as $pedido)
                        @php
                            $itemsCount = $pedido->detalles?->count() ?? 0;
                            $totalKg = $pedido->detalles?->sum('cantidad') ?? 0;
                            $transportistaAsignado = $pedido->envioAsignacion?->transportista;
                            $logisticaEnvio = \App\Support\EnvioPedidoService::datosLogistica($pedido->envioAsignacion);
                            $faseLogistica = \App\Support\PedidoCatalogo::faseLogistica($logisticaEnvio);
                            $estadoVisual = \App\Support\PedidoCatalogo::badgeEstadoLista($logisticaEnvio, $pedido);
                        @endphp
                        <tr>
                            <td class="font-weight-bold">#{{ $pedido->pedidoid }}</td>
                            <td><span class="badge badge-dark px-2 py-1">{{ $pedido->numero_solicitud }}</span></td>
                            <td>
                                <span class="text-primary font-weight-bold">
                                    {{ $pedido->detalles->first()?->cultivo_personalizado ?? '—' }}
                                </span>
                                @if($pedido->detalles->first()?->insumo)
                                    <br><small class="text-muted">{{ $pedido->detalles->first()->insumo->nombre }}</small>
                                @endif
                            </td>
                            <td><span class="badge badge-info px-2 py-1">{{ $itemsCount }} ítem(s)</span></td>
                            <td><strong>{{ number_format($totalKg, 2) }}</strong> <small class="text-muted">kg</small></td>
                            @unless($esTransportista ?? false)
                            <td>
                                @if($transportistaAsignado)
                                    <span class="badge badge-light border text-dark px-2 py-1 d-inline-block">
                                        <i class="fas fa-user mr-1"></i>
                                        {{ trim($transportistaAsignado->nombre.' '.$transportistaAsignado->apellido) }}
                                    </span>
                                    @if($logisticaEnvio)
                                        <br><small class="text-muted">{{ $logisticaEnvio['vehiculo_nombre'] }} · {{ $logisticaEnvio['placa'] }}</small>
                                    @endif
                                @elseif(\App\Support\PedidoCatalogo::puedeAsignarTransportista($pedido))
                                    @can('pedidos.update')
                                    @php
                                        $pesoPedidoAsign = app(\App\Services\TransporteCapacidadService::class)->pesoPedido($pedido);
                                    @endphp
                                    <button type="button"
                                            class="btn btn-link btn-sm p-0 text-primary btn-asignar-transportista"
                                            data-pedido-id="{{ $pedido->pedidoid }}"
                                            data-pedido-label="{{ $pedido->numero_solicitud }}"
                                            data-peso-kg="{{ number_format((float) $pesoPedidoAsign, 2, '.', '') }}"
                                            title="Asignar transportista">
                                        <i class="fas fa-user-plus mr-1"></i>Sin asignar
                                    </button>
                                    @else
                                    <span class="text-muted small">Sin asignar</span>
                                    @endcan
                                @else
                                    <span class="text-muted small" title="Pendiente de aceptación agrícola">Sin asignar</span>
                                @endif
                            </td>
                            @endunless
                            <td class="td-estado">
                                <span class="pedido-estado-badge {{ $estadoVisual['clase'] }}"
                                      title="{{ $estadoVisual['titulo'] ?? $estadoVisual['etiqueta'] }}">
                                    {{ $estadoVisual['etiqueta'] }}
                                </span>
                            </td>
                            <td class="text-muted">{{ \Carbon\Carbon::parse($pedido->fechapedido)->format('d/m/Y') }}</td>
                            <td class="text-center pedidos-actions td-acciones text-nowrap">
                                @if($faseLogistica === 'en_camino_planta')
                                    @can('recepcion_planta.confirm')
                                    <a href="{{ route('pedidos.show', $pedido) }}#pesaje-recepcion"
                                       class="btn btn-sm btn-outline-success"
                                       title="Confirmar llegada con pesaje">
                                        <i class="fas fa-weight"></i>
                                    </a>
                                    @endcan
                                @endif
                                <a href="{{ route('pedidos.show', $pedido) }}" class="btn btn-sm btn-outline-info" title="Ver">
                                    <i class="fas fa-eye"></i>
                                </a>
                                @can('pedidos.update')
                                <a href="{{ route('pedidos.edit', $pedido) }}" class="btn btn-sm btn-outline-warning" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                                @endcan
                                @can('pedidos.delete')
                                <form action="{{ route('pedidos.destroy', $pedido) }}" method="POST" class="d-inline m-0"
                                      onsubmit="return confirm('¿Está seguro de eliminar este pedido?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ ($esTransportista ?? false) ? 8 : 9 }}" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                                No hay pedidos con esos filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@can('pedidos.update')
<form id="formAsignarTransportista" method="POST" class="d-none">
    @csrf
    <input type="hidden" name="transportista_usuarioid" id="inputTransportistaAsignar">
    <input type="hidden" name="vehiculoid" id="inputVehiculoAsignar">
</form>

@once
    @push('styles')
    <style>
        #modalSelectorCatalogo .selector-catalogo-row { cursor: pointer; }
        #modalSelectorCatalogo .selector-catalogo-row:hover { background: #f4f9f4; }
        #modalSelectorCatalogo .modal-body { max-height: 65vh; overflow-y: auto; }
    </style>
    @endpush
    @push('scripts')
    <script src="{{ asset('js/selector-catalogo.js') }}"></script>
    @endpush
@endonce

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.CatalogoSelector) return;

    let pedidoIdAsignar = null;
    let transportistaPendiente = null;
    let transportistaLabelPendiente = '';
    const form = document.getElementById('formAsignarTransportista');
    const inputTransportista = document.getElementById('inputTransportistaAsignar');
    const inputVehiculo = document.getElementById('inputVehiculoAsignar');
    const urlAsignar = @json(route('pedidos.asignar-transportista', ['pedido' => '__PEDIDO__']));

    function abrirSelectorVehiculo() {
        const cfg = CatalogoSelector.instances.asignar_vehiculo_pedido;
        cfg.params = {
            transportista_usuarioid: transportistaPendiente,
            solo_transportista: '1',
        };
        cfg.title = transportistaLabelPendiente
            ? 'Asignar vehículo — ' + transportistaLabelPendiente
            : 'Asignar vehículo';
        CatalogoSelector.open('asignar_vehiculo_pedido');
    }

    CatalogoSelector.register('asignar_transportista_pedido', {
        endpoint: @json(route('catalogo-selector.usuarios')),
        title: 'Asignar transportista',
        searchPlaceholder: 'Buscar por nombre, usuario o correo…',
        params: { roles: 'transportista' },
        onSelect(item) {
            if (!pedidoIdAsignar || !item.id) return;
            transportistaPendiente = item.id;
            transportistaLabelPendiente = item.label;
            window.jQuery('#modalSelectorCatalogo').one('hidden.bs.modal', function () {
                setTimeout(abrirSelectorVehiculo, 200);
            });
        },
    });

    CatalogoSelector.register('asignar_vehiculo_pedido', {
        endpoint: @json(route('catalogo-selector.vehiculos')),
        title: 'Asignar vehículo',
        searchPlaceholder: 'Buscar por placa, marca o modelo…',
        theme: 'vehiculo',
        colNombre: 'Placa',
        colDetalle: 'Vehículo',
        params: {},
        filter: {
            param: 'solo_transportista',
            options: [
                { value: '1', label: 'Vehículos del transportista' },
                { value: '0', label: 'Toda la flota activa' },
            ],
        },
        onSelect(item) {
            if (!pedidoIdAsignar || !transportistaPendiente || !item.id) return;
            inputTransportista.value = transportistaPendiente;
            inputVehiculo.value = item.id;
            form.action = urlAsignar.replace('__PEDIDO__', pedidoIdAsignar);
            form.submit();
        },
    });

    document.querySelectorAll('.btn-asignar-transportista').forEach(function (btn) {
        btn.addEventListener('click', function () {
            pedidoIdAsignar = this.dataset.pedidoId;
            window.AsignacionPedidoCargaContext = {
                peso_kg: parseFloat(this.dataset.pesoKg) || 0,
                volumen_m3: null,
            };
            transportistaPendiente = null;
            transportistaLabelPendiente = '';
            const label = this.dataset.pedidoLabel || '';
            CatalogoSelector.instances.asignar_transportista_pedido.title = label
                ? 'Asignar transportista — ' + label
                : 'Asignar transportista';
            CatalogoSelector.open('asignar_transportista_pedido');
        });
    });
});
</script>
@endpush
@endcan

@include('partials.modal-confirmar-accion')
@endsection
