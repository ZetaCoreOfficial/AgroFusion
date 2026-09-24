@if($item['asignacion'])
    @include('logistica.partials.acciones-tabla-asignacion', ['asignacion' => $item['asignacion']])
@elseif($item['ruta'])
    <a href="{{ $item['ver_url'] }}" class="btn btn-sm btn-outline-primary" title="Ver ruta">
        <i class="fas fa-eye mr-1"></i> Ver
    </a>
@elseif($item['pedido'])
    <a href="{{ route('pedidos.show', $item['pedido']) }}" class="btn btn-sm btn-outline-primary" title="Ver">
        <i class="fas fa-eye mr-1"></i> Ver
    </a>
@endif
@if(($item['fase_logistica'] ?? null) === 'en_camino_planta' && ! $item['asignacion'] && $item['pedido'])
    @can('recepcion_planta.confirm')
    <a href="{{ route('pedidos.show', $item['pedido']) }}#pesaje-recepcion"
       class="btn btn-sm btn-outline-success" title="Confirmar llegada con pesaje">
        <i class="fas fa-weight mr-1"></i> Pesaje
    </a>
    @endcan
@endif
