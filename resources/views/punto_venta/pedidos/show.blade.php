@extends('layouts.app')

@section('title', $pedido->numero_solicitud)
@section('page_title', 'Pedido '.$pedido->numero_solicitud)

@push('styles')
@include('punto_venta.partials.modulo-styles')
<style>
.pdv-pedido-flujo {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: .65rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 1199px) {
    .pdv-pedido-flujo { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
    .pdv-pedido-flujo { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
.pdv-paso-card {
    background: #fff;
    border: 2px solid #d1fae5;
    border-radius: 14px;
    padding: .9rem .65rem;
    text-align: center;
    box-shadow: 0 2px 10px rgba(5, 150, 105, .1);
    transition: transform .2s ease, box-shadow .2s ease;
}
.pdv-paso-card .paso-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #ecfdf5;
    color: #059669;
    font-weight: 700;
    font-size: .78rem;
    margin-bottom: .4rem;
}
.pdv-paso-card .paso-icon {
    display: block;
    font-size: 1.15rem;
    color: #10b981;
    margin-bottom: .35rem;
}
.pdv-paso-card .paso-label {
    display: block;
    font-size: .72rem;
    font-weight: 600;
    color: #047857;
    line-height: 1.35;
}
.pdv-paso-card.pendiente {
    opacity: .65;
    border-color: #e5e7eb;
    box-shadow: none;
    background: #fafafa;
}
.pdv-paso-card.pendiente .paso-num { background: #f3f4f6; color: #9ca3af; }
.pdv-paso-card.pendiente .paso-icon,
.pdv-paso-card.pendiente .paso-label { color: #9ca3af; }
.pdv-paso-card.activo {
    background: linear-gradient(145deg, #047857, #10b981);
    border-color: #065f46;
    box-shadow: 0 6px 18px rgba(5, 150, 105, .35);
    transform: translateY(-2px);
}
.pdv-paso-card.activo .paso-num {
    background: rgba(255, 255, 255, .22);
    color: #fff;
}
.pdv-paso-card.activo .paso-icon,
.pdv-paso-card.activo .paso-label { color: #fff; }
.pdv-paso-card.hecho {
    background: linear-gradient(180deg, #ecfdf5, #d1fae5);
    border-color: #6ee7b7;
}
.pdv-paso-card.hecho .paso-num {
    background: #059669;
    color: #fff;
}
.pdv-paso-card.hecho .paso-icon { color: #059669; }
.pdv-paso-card.hecho .paso-label { color: #065f46; }
.pdv-paso-card.es-navegable {
    cursor: pointer;
}
.pdv-paso-card.es-navegable.activo {
    cursor: pointer;
}
.pdv-paso-card.es-navegable:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(5, 150, 105, .22);
}
.pdv-paso-card.vista-activa:not(.activo) {
    outline: 3px solid #10b981;
    outline-offset: 2px;
}
.pdv-estado-pill {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .45rem .95rem;
    border-radius: 999px;
    font-size: .82rem;
    font-weight: 700;
    letter-spacing: .01em;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .08);
}
.pdv-estado-pill i { font-size: .9rem; opacity: .95; }
.pdv-estado-pill--revision { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e; border: 1px solid #fcd34d; }
.pdv-estado-pill--preparacion { background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #075985; border: 1px solid #7dd3fc; }
.pdv-estado-pill--asignado { background: linear-gradient(135deg, #ffedd5, #fed7aa); color: #9a3412; border: 1px solid #fdba74; }
.pdv-estado-pill--ruta { background: linear-gradient(135deg, #dbeafe, #93c5fd); color: #1e3a8a; border: 1px solid #60a5fa; }
.pdv-estado-pill--recibido { background: linear-gradient(135deg, #d1fae5, #6ee7b7); color: #065f46; border: 1px solid #34d399; }
.pdv-estado-pill--rechazado { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border: 1px solid #f87171; }
.pdv-estado-pill--cancelado, .pdv-estado-pill--neutral { background: #f3f4f6; color: #4b5563; border: 1px solid #d1d5db; }
.pdv-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .85rem;
}
@media (max-width: 767px) {
    .pdv-info-grid { grid-template-columns: 1fr; }
}
.pdv-info-tile {
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    background: #fff;
    padding: 1rem 1.05rem;
    box-shadow: 0 2px 10px rgba(15, 23, 42, .04);
    height: 100%;
}
.pdv-info-tile--wide { grid-column: 1 / -1; }
.pdv-info-tile__icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .95rem; margin-bottom: .55rem;
}
.pdv-info-tile__icon--pdv { background: #ecfdf5; color: #059669; }
.pdv-info-tile__icon--prod { background: #eff6ff; color: #2563eb; }
.pdv-info-tile__icon--alm { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }
.pdv-info-tile__icon--stock { background: #ecfdf5; color: #047857; }
.pdv-info-tile__icon--meta { background: #f5f3ff; color: #6d28d9; }
.pdv-info-tile__label {
    display: block;
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #94a3b8;
    margin-bottom: .25rem;
}
.pdv-info-tile__value {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.35;
}
.pdv-info-tile__sub { font-size: .82rem; color: #64748b; margin-top: .15rem; }
.pdv-info-tile__badge {
    display: inline-flex; align-items: center; gap: .35rem;
    margin-top: .4rem; padding: .25rem .6rem; border-radius: 999px;
    font-size: .75rem; font-weight: 600;
}
.pdv-info-tile__badge--ok { background: #ecfdf5; color: #047857; }
.pdv-info-tile__badge--bad { background: #fef2f2; color: #b91c1c; }
.pdv-almacen-caja {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .55rem .85rem; border-radius: 10px;
    background: #f8fafc; border: 1px solid #e2e8f0;
    font-weight: 600; color: #334155;
}
.pdv-almacen-caja i { color: #64748b; }
.pdv-btn-ubicacion {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    margin-top: .55rem;
    padding: .4rem .8rem;
    font-size: .76rem;
    font-weight: 600;
    line-height: 1.2;
    color: #fff !important;
    background: linear-gradient(135deg, #4a7c59 0%, #2c5530 100%);
    border: 1px solid #1e4620;
    border-radius: 8px;
    text-decoration: none !important;
    box-shadow: 0 2px 8px rgba(44, 85, 48, .22);
    transition: transform .12s ease, box-shadow .12s ease;
}
.pdv-btn-ubicacion:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(44, 85, 48, .32);
    color: #fff !important;
    text-decoration: none !important;
}
.pdv-btn-ubicacion i { font-size: .72rem; opacity: .95; }
.pdv-ubicacion-stack {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}
.pdv-meta-list { display: grid; gap: .55rem; }
.pdv-meta-item {
    display: flex; justify-content: space-between; gap: 1rem;
    padding: .55rem .7rem; border-radius: 10px; background: #f8fafc;
    font-size: .84rem;
}
.pdv-meta-item span:first-child { color: #64748b; font-weight: 600; }
.pdv-meta-item span:last-child { color: #0f172a; font-weight: 600; text-align: right; }
.pdv-paso-panel { display: none; }
.pdv-paso-panel.is-visible { display: block; }
.pdv-edit-solicitud {
    border-radius: 14px; border: 1px solid #dbeafe; background: linear-gradient(160deg, #f8fbff, #eff6ff);
    padding: 1rem 1.1rem; margin-top: 1rem;
}
.pdv-sidebar-paso { display: none; }
.pdv-sidebar-paso.is-visible { display: block; }
.pdv-detalle-grid dt { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: #6c757d; }
.pdv-detalle-grid dd { margin-bottom: .85rem; }
.pdv-banner-llegada {
    border-radius: 14px;
    border: 2px solid #6ee7b7;
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
}
.pdv-flota-panel {
    border-radius: 12px;
    padding: 1rem;
    border: 1px solid #e5e7eb;
    background: #fafafa;
    margin-bottom: 1rem;
}
.pdv-flota-panel--chofer { border-color: #bfdbfe; background: linear-gradient(160deg, #f8fbff 0%, #eff6ff 100%); }
.pdv-flota-panel--vehiculo { border-color: #a7f3d0; background: linear-gradient(160deg, #f6fffb 0%, #ecfdf5 100%); }
.pdv-flota-panel .panel-icon {
    width: 30px; height: 30px; border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.85rem; margin-right: 0.4rem;
}
.pdv-flota-panel--chofer .panel-icon { background: #dbeafe; color: #1d4ed8; }
.pdv-flota-panel--vehiculo .panel-icon { background: #d1fae5; color: #047857; }
.pdv-vehiculo-sugerido-chip {
    display: inline-block;
    margin-left: .45rem;
    padding: .12rem .5rem;
    font-size: .65rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #047857;
    background: #d1fae5;
    border: 1px solid #6ee7b7;
    border-radius: 999px;
    vertical-align: middle;
}

/* Contenido bajo el flujo de 5 pasos — estilo ejecutivo (sin colores LED) */
.pdv-paso-panel .pdv-info-grid { gap: .75rem; margin-bottom: 0; }
.pdv-paso-panel .pdv-info-tile {
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #fafbfc;
    padding: .9rem 1rem;
    box-shadow: none;
}
.pdv-paso-panel .pdv-info-tile__icon {
    width: 32px; height: 32px; border-radius: 8px;
    font-size: .85rem; margin-bottom: .5rem;
}
.pdv-paso-panel .pdv-info-tile__icon--pdv { background: #ecfdf5; color: #059669; }
.pdv-paso-panel .pdv-info-tile__icon--prod { background: #eff6ff; color: #2563eb; }
.pdv-paso-panel .pdv-info-tile__icon--alm { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }
.pdv-paso-panel .pdv-info-tile__icon--stock { background: #ecfdf5; color: #047857; }
.pdv-paso-panel .pdv-info-tile__icon--meta { background: #f5f3ff; color: #6d28d9; }
.pdv-paso-panel .pdv-info-tile__label {
    color: #64748b; font-size: .65rem; letter-spacing: .06em;
}
.pdv-paso-panel .pdv-info-tile__value {
    font-size: .92rem; font-weight: 700; color: #0f172a;
}
.pdv-paso-panel .pdv-info-tile__value.text-success { color: #047857 !important; }
.pdv-paso-panel .pdv-info-tile__sub { color: #64748b; font-size: .78rem; }
.pdv-paso-panel .pdv-info-tile__badge {
    margin-top: .45rem; padding: .22rem .55rem;
    font-size: .72rem; font-weight: 600; border-radius: 999px;
}
.pdv-paso-panel .pdv-info-tile__badge--ok {
    background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7;
}
.pdv-paso-panel .pdv-info-tile__badge--bad {
    background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
}
.pdv-paso-panel .pdv-info-tile__badge--warn {
    background: #fffbeb; color: #92400e; border: 1px solid #fde68a;
}
.pdv-paso-panel .pdv-almacen-caja {
    background: #f8fafc; border-color: #e2e8f0; color: #334155; font-weight: 600;
}
.pdv-paso-panel .pdv-almacen-caja i { color: #64748b; }
.pdv-paso-panel .pdv-meta-list { gap: .45rem; }
.pdv-paso-panel .pdv-meta-item {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: .5rem .65rem; font-size: .8rem;
}
.pdv-paso-panel .pdv-edit-solicitud {
    border-radius: 10px; border: 1px solid #e2e8f0;
    background: #fff; padding: .9rem 1rem; margin-top: .85rem;
}
.pdv-paso-panel .pdv-edit-solicitud h6 { color: #334155; font-size: .85rem; }
.pdv-paso-panel .pdv-revision-banner {
    display: flex; align-items: flex-start; gap: .65rem;
    padding: .85rem 1rem; border-radius: 10px;
    border: 1px solid #fde68a; background: linear-gradient(135deg, #fffbeb, #fef3c7);
}
.pdv-paso-panel .pdv-revision-banner__icon {
    flex-shrink: 0; width: 36px; height: 36px; border-radius: 8px;
    background: #fef3c7; color: #d97706;
    display: flex; align-items: center; justify-content: center; font-size: .9rem;
}
.pdv-paso-panel .pdv-revision-banner__title {
    font-size: .68rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; color: #b45309; margin-bottom: .15rem;
}
.pdv-paso-panel .pdv-revision-banner__text {
    font-size: .88rem; font-weight: 600; color: #0f172a; line-height: 1.35;
}
.pdv-paso-panel .pdv-revision-banner__sub { font-size: .78rem; color: #64748b; margin-top: .2rem; }
.pdv-capacidad-vehiculo {
    border: 1px solid #bbf7d0; border-radius: 12px;
    background: linear-gradient(160deg, #f0fdf4 0%, #ecfdf5 100%);
    padding: 1rem 1.1rem;
}
.pdv-capacidad-vehiculo.is-excedido { border-color: #fecaca; background: linear-gradient(160deg, #fef2f2 0%, #fff1f2 100%); }
.pdv-capacidad-vehiculo__title { font-weight: 700; color: #166534; font-size: .92rem; margin-bottom: .35rem; }
.pdv-capacidad-vehiculo.is-excedido .pdv-capacidad-vehiculo__title { color: #b91c1c; }
.pdv-capacidad-vehiculo__stats { font-size: .82rem; color: #475569; }
.pdv-capacidad-vehiculo__bar { height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: .5rem; }
.pdv-capacidad-vehiculo__bar-fill { height: 100%; border-radius: 999px; transition: width .25s ease; background: #22c55e; }
.pdv-capacidad-vehiculo.is-excedido .pdv-capacidad-vehiculo__bar-fill { background: #ef4444; }
.pdv-capacidad-vehiculo__hint { font-size: .78rem; color: #64748b; margin: .45rem 0 0; }
.pdv-capacidad-vehiculo__alerta { display: none; font-size: .82rem; color: #b91c1c; margin-top: .45rem; }
.pdv-capacidad-vehiculo.is-excedido .pdv-capacidad-vehiculo__alerta { display: block; }
.pdv-espera-mayorista {
    display: flex; gap: .85rem; align-items: flex-start;
    padding: .85rem 1rem; border-radius: 10px;
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1px solid #fde68a;
}
.pdv-espera-mayorista__icon {
    flex-shrink: 0; width: 2.25rem; height: 2.25rem;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; background: #f59e0b; color: #fff; font-size: .95rem;
}
.pdv-espera-mayorista__title { font-weight: 700; color: #92400e; font-size: .9rem; margin-bottom: .15rem; }
.pdv-espera-mayorista__text { font-size: .82rem; color: #78350f; margin: 0; line-height: 1.45; }
.pdv-paso-panel .pdv-stock-row {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem;
    padding: .75rem 1rem; border-radius: 10px;
    border: 1px solid #bbf7d0; background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
}
.pdv-paso-panel .pdv-stock-row__label {
    font-size: .65rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; color: #047857;
}
.pdv-paso-panel .pdv-stock-row__value {
    font-size: 1rem; font-weight: 700; color: #065f46;
}
.pdv-sidebar-paso .card.card-outline.card-success .card-body.text-success,
.pdv-sidebar-paso .card.card-outline.card-success .card-body.small.text-success {
    color: #334155 !important; background: #fafbfc;
}
.pdv-sidebar-paso .card.card-outline.card-success .card-body.text-success i {
    color: #475569;
}
.pdv-sidebar-paso .card.card-outline.card-warning .card-body {
    background: #fffbeb; color: #78350f; border-left: 3px solid #d97706;
}
</style>
@endpush

@section('content')
    @php
        $det = $pedido->detalles->first();
        $badge = \App\Support\PedidoDistribucionCatalogo::badgeEstado($pedido);
        $pendienteMayorista = \App\Support\PedidoDistribucionCatalogo::pendienteAprobacionMayorista($pedido);
        $unidad = $det?->presentacion?->etiquetaUnidad() ?? $det?->insumo?->unidadMedida?->abreviatura ?? 'unidades';
        $stockOk = $pendienteMayorista || $erroresStock === [] || ($det?->es_solicitud_custom ?? false);
        $transportistaEfectivo = $pedido->transportista ?? $pedido->rutaDistribucion?->transportista;
        $vehiculoEfectivo = $pedido->vehiculo ?? $pedido->rutaDistribucion?->vehiculo;
        $puedeDesignarTransportista = $puedeDesignarTransportista ?? false;
        $transportistaDesignado = $transportistaDesignado ?? false;
        $puedeEmpezarRuta = $puedeEmpezarRuta ?? false;
        $simulacionActiva = $simulacionActiva ?? false;
        $esTransportistaAsignado = $esTransportistaAsignado ?? false;
        $puedeEditarFlujo = $puedeEditarFlujo ?? false;
        $puedeEditarSolicitud = $puedeEditarSolicitud ?? false;
        $puedeReabrirRevision = $puedeReabrirRevision ?? false;
        $pasoInicial = $pasoInicial ?? 3;
        $pasoActualFlujo = $pasoActualFlujo ?? 3;
        $pendienteConfirmacionMinorista = $pendienteConfirmacionMinorista ?? false;
        $puedeConfirmarEnvioMayorista = $puedeConfirmarEnvioMayorista ?? false;
        $esMinoristaDueño = $esMinoristaDueño ?? false;
        $esperaConfirmacionMinorista = $pendienteConfirmacionMinorista;
        $pasos = $pasosFlujo ?? [];
        $mostrarPanelCapacidadVehiculo = $mostrarPanelCapacidadVehiculo ?? false;
        $esAdmin = $esAdmin ?? false;
        $puedeVerRutaTiempoReal = $puedeVerRutaTiempoReal ?? false;
        $urlTiempoRealPedido = $urlTiempoRealPedido ?? null;
        // Solo el transportista asignado opera la salida en ruta (el admin supervisa).
        $puedeOperarSalidaRuta = ($esTransportistaAsignado ?? false);
        $urlUbicacionPdv = $pedido->puntoVenta
            ? route('punto-venta.pedidos.ubicacion.pdv', $pedido)
            : null;
        $almacenContexto = $pedido->almacenMayoristaOrigen ?? $det?->insumo?->almacen;
        $urlUbicacionAlmacen = $almacenContexto
            ? route('punto-venta.pedidos.ubicacion.almacen', $pedido)
            : null;
        if ($pasos === []) {
            $pasos = \App\Support\PedidoDistribucionCatalogo::pasosFlujoUi(
                $pedido,
                $esMinoristaDueño,
                $puedeEditarFlujo,
                $transportistaDesignado,
            );
        }
    @endphp

    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ route('punto-venta.pedidos.index', ['ctx' => $ctxVolver ?? 'pdv']) }}" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left mr-1"></i> {{ ($esBandejaMayorista ?? false) ? 'Volver a bandeja mayorista' : 'Volver a mis pedidos' }}
            </a>
        </div>
    </div>

    @if($puedeAnunciarLlegada ?? false)
    <div class="alert pdv-banner-llegada d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3 py-3 px-4">
        <div>
            <strong class="text-success d-block mb-1">
                <i class="fas fa-truck-loading mr-1"></i> El pedido está en camino a su tienda
            </strong>
            <span class="text-muted small">Cuando reciba el producto, anuncie la llegada para ingresarlo a su inventario.</span>
        </div>
        <form method="POST" action="{{ route('punto-venta.pedidos.confirmar-recepcion', $pedido) }}" class="mb-0">
            @csrf
            <button type="button" class="btn btn-success btn-lg font-weight-bold px-4"
                    data-confirm-modal
                    data-confirm-tone="success"
                    data-confirm-title="Anunciar llegada del pedido"
                    data-confirm-message="¿Confirma que el pedido ya llegó a su punto de venta? Se ingresará al inventario.">
                <i class="fas fa-bullhorn mr-1"></i> Anunciar llegada
            </button>
        </form>
    </div>
    @endif

    <div class="row align-items-start">
        <div class="col-lg-8">
            <div class="card pdv-card card-outline card-success mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h3 class="card-title mb-0 font-weight-bold">
                        <i class="fas fa-truck-loading text-success mr-2"></i>Detalle del pedido
                    </h3>
                    <span class="pdv-estado-pill pdv-estado-pill--{{ $badge['clase'] }}">
                        <i class="fas {{ $badge['icono'] ?? 'fa-info-circle' }}"></i>
                        {{ $badge['etiqueta'] }}
                    </span>
                </div>
                <div class="card-body">
                    @if(! in_array($pedido->estado, ['rechazado', 'cancelado'], true))
                    <div class="pdv-pedido-flujo" id="pdvPedidoFlujo" data-paso-inicial="{{ $pasoInicial }}" data-paso-flujo="{{ $pasoActualFlujo }}">
                        @foreach($pasos as $paso)
                            @php
                                $clasePaso = $paso['activo']
                                    ? 'activo'
                                    : ($paso['hecho'] ? 'hecho' : 'pendiente');
                                $esNavegable = $paso['num'] <= $pasoActualFlujo && (
                                    in_array($paso['num'], [1, 2], true)
                                        ? (($paso['navegable'] ?? false) && $puedeEditarFlujo)
                                        : ($paso['navegable'] ?? false)
                                );
                            @endphp
                            <div class="pdv-paso-card {{ $clasePaso }} {{ $esNavegable ? 'es-navegable' : '' }}"
                                 data-paso="{{ $paso['num'] }}"
                                 data-navegable="{{ $esNavegable ? '1' : '0' }}"
                                 title="{{ $esNavegable ? 'Ver y editar este paso' : '' }}">
                                <span class="paso-num">{{ $paso['num'] }}</span>
                                <i class="fas {{ $paso['icon'] }} paso-icon"></i>
                                <span class="paso-label">{{ $paso['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    @endif

                    <div class="pdv-paso-panel" data-panel="1">
                        <div class="pdv-info-grid mb-0">
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--pdv"><i class="fas fa-store"></i></span>
                                <span class="pdv-info-tile__label">Punto de venta</span>
                                <div class="pdv-info-tile__value text-success">{{ $pedido->puntoVenta?->nombre ?? '—' }}</div>
                                <div class="pdv-info-tile__sub">{{ $pedido->puntoVenta?->resumenUbicacion() ?: 'Sin calle de referencia' }}</div>
                                @if($simulacionActiva && $urlTiempoRealPedido)
                                    @include('logistica.partials.enlace-tiempo-real', ['href' => $urlTiempoRealPedido])
                                @else
                                    @include('punto_venta.pedidos.partials.enlace-ubicacion', ['href' => $urlUbicacionPdv])
                                @endif
                            </div>
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--prod"><i class="fas fa-box"></i></span>
                                <span class="pdv-info-tile__label">Producto solicitado</span>
                                <div class="pdv-info-tile__value">{{ $det?->producto_nombre ?? '—' }}</div>
                                <div class="pdv-info-tile__sub">{{ $det ? number_format($det->cantidad, 2).' '.$unidad : '—' }}</div>
                            </div>
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--alm"><i class="fas fa-warehouse"></i></span>
                                <span class="pdv-info-tile__label">Almacén mayorista</span>
                                <div class="pdv-ubicacion-stack">
                                    <div class="pdv-almacen-caja">
                                        <i class="fas fa-store-alt"></i>
                                        {{ $almacenContexto?->nombre ?? '—' }}
                                    </div>
                                    @include('punto_venta.pedidos.partials.enlace-ubicacion', ['href' => $urlUbicacionAlmacen])
                                </div>
                            </div>
                            @if($det?->insumo)
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--stock"><i class="fas fa-cubes"></i></span>
                                <span class="pdv-info-tile__label">Stock en mayorista</span>
                                <div class="pdv-info-tile__value">{{ number_format((float) $det->insumo->stock, 2) }} {{ $unidad }}</div>
                                <span class="pdv-info-tile__badge {{ $stockOk ? 'pdv-info-tile__badge--ok' : 'pdv-info-tile__badge--bad' }}">
                                    <i class="fas {{ $stockOk ? 'fa-check-circle' : 'fa-exclamation-circle' }}"></i>
                                    {{ $stockOk ? 'Disponible para despacho' : 'Stock insuficiente' }}
                                </span>
                            </div>
                            @endif
                        </div>
                        @if($puedeEditarSolicitud)
                        <div class="pdv-edit-solicitud">
                            <h6 class="font-weight-bold mb-2"><i class="fas fa-edit text-primary mr-1"></i> Editar solicitud</h6>
                            <form method="POST" action="{{ route('punto-venta.pedidos.update', $pedido) }}">
                                @csrf
                                @method('PUT')
                                @if($esAdmin)
                                <input type="hidden" name="minorista_usuarioid" value="{{ $pedido->puntoVenta?->usuarioid }}">
                                <input type="hidden" name="puntoventaid" value="{{ $pedido->puntoventaid }}">
                                <input type="hidden" name="almacen_mayorista_origenid" value="{{ $pedido->almacen_mayorista_origenid }}">
                                @endif
                                <input type="hidden" name="insumoid" value="{{ $det?->insumoid }}">
                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label class="small font-weight-bold">Cantidad ({{ $unidad }})</label>
                                        <input type="number" name="cantidad" class="form-control" step="0.01" min="0.01"
                                               max="{{ $det?->insumo?->stock ?? 999999 }}" value="{{ old('cantidad', $det?->cantidad) }}" required>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label class="small font-weight-bold">Entrega deseada</label>
                                        <input type="date" name="fecha_entrega_deseada" class="form-control"
                                               value="{{ old('fecha_entrega_deseada', $pedido->fecha_entrega_deseada?->format('Y-m-d')) }}">
                                    </div>
                                    <div class="form-group col-md-4 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary btn-block">
                                            <i class="fas fa-save mr-1"></i> Guardar cambios
                                        </button>
                                    </div>
                                </div>
                                <div class="form-group mb-0">
                                    <label class="small font-weight-bold">Observaciones</label>
                                    <textarea name="observaciones" class="form-control" rows="2" maxlength="2000" placeholder="Notas para el centro mayorista…">{{ old('observaciones', $pedido->observaciones) }}</textarea>
                                </div>
                                @if($transportistaDesignado)
                                <p class="small text-warning mb-0 mt-2"><i class="fas fa-info-circle mr-1"></i>Si cambia la solicitud, se quitará el transportista asignado y deberá designarlo de nuevo.</p>
                                @endif
                            </form>
                        </div>
                        @endif
                    </div>

                    <div class="pdv-paso-panel" data-panel="2">
                        <div class="pdv-revision-banner mb-3">
                            <div class="pdv-revision-banner__icon"><i class="fas fa-clipboard-check"></i></div>
                            <div>
                                <div class="pdv-revision-banner__title">Estado de revisión</div>
                                <div class="pdv-revision-banner__text">
                                    @if($pendienteMayorista && $pedido->espera_stock)
                                        Esperando stock — el minorista solicitó entrega para {{ $pedido->fecha_entrega_deseada?->format('d/m/Y') ?? 'fecha por confirmar' }}
                                    @elseif($pendienteMayorista)
                                        Pendiente de decisión del centro mayorista
                                    @elseif($pedido->fecha_aceptacion)
                                        Aceptado — {{ $pedido->fecha_aceptacion->format('d/m/Y H:i') }}
                                    @else
                                        Sin revisión registrada
                                    @endif
                                </div>
                                @if($pedido->fecha_aceptacion && $pedido->aceptadoPor)
                                    <div class="pdv-revision-banner__sub">
                                        por {{ trim($pedido->aceptadoPor->nombre.' '.$pedido->aceptadoPor->apellido) }}
                                    </div>
                                @elseif($pendienteMayorista && $pedido->espera_stock)
                                    <div class="pdv-revision-banner__sub">
                                        Cantidad solicitada: {{ $det ? number_format($det->cantidad, 0).' '.$unidad : '—' }}.
                                        El mayorista aceptará o rechazará según disponibilidad para la fecha indicada.
                                    </div>
                                @endif
                            </div>
                        </div>
                        @if($det?->insumo)
                        <div class="pdv-stock-row">
                            <div>
                                <div class="pdv-stock-row__label">Stock verificado en mayorista</div>
                                <div class="pdv-stock-row__value">{{ number_format((float) $det->insumo->stock, 2) }} {{ $unidad }}</div>
                            </div>
                            <span class="pdv-info-tile__badge {{ $stockOk ? 'pdv-info-tile__badge--ok' : 'pdv-info-tile__badge--bad' }}">
                                <i class="fas {{ $stockOk ? 'fa-check' : 'fa-exclamation-triangle' }} mr-1"></i>
                                {{ $stockOk ? 'Cubre la solicitud' : 'No alcanza' }}
                            </span>
                        </div>
                        @endif
                    </div>

                    <div class="pdv-paso-panel" data-panel="3">
                        @if($mostrarPanelCapacidadVehiculo)
                        <div class="pdv-capacidad-vehiculo mb-3" id="panelCapacidadVehiculoPdv"
                             data-peso-kg="{{ number_format((float) ($resumenCargaPedido['peso_kg'] ?? 0), 2, '.', '') }}"
                             data-volumen-m3="{{ $resumenCargaPedido['volumen_m3'] ?? '' }}"
                             data-endpoint="{{ route('punto-venta.pedidos.validar-capacidad-vehiculo', $pedido) }}">
                            <div class="pdv-capacidad-vehiculo__title">
                                <i class="fas fa-truck-loading mr-1"></i> Recomendación de capacidad del vehículo
                            </div>
                            <div class="pdv-capacidad-vehiculo__stats">
                                Carga del pedido:
                                <strong>{{ number_format((float) ($resumenCargaPedido['peso_kg'] ?? 0), 2) }} kg</strong>
                                @if(! empty($resumenCargaPedido['volumen_m3']))
                                    · <strong>{{ number_format((float) $resumenCargaPedido['volumen_m3'], 3) }} m³</strong>
                                @endif
                                ({{ $det ? number_format($det->cantidad, 0).' '.$unidad : '—' }})
                            </div>
                            <p class="pdv-capacidad-vehiculo__hint mb-0" id="txtCapacidadVehiculoPdv">
                                Seleccione chofer y vehículo a la derecha para ver si la carga cabe (peso y volumen).
                            </p>
                            <div class="d-none" id="wrapCapacidadVehiculoPdv">
                                <div class="d-flex justify-content-between align-items-center mt-2 small">
                                    <span id="txtCapacidadVehiculoResumen"></span>
                                    <strong id="txtCapacidadVehiculoPct"></strong>
                                </div>
                                <div class="pdv-capacidad-vehiculo__bar">
                                    <div class="pdv-capacidad-vehiculo__bar-fill" id="barCapacidadVehiculoPdv" style="width:0%"></div>
                                </div>
                                <p class="pdv-capacidad-vehiculo__hint mb-0" id="txtCapacidadVehiculoRecomendacion"></p>
                                <p class="pdv-capacidad-vehiculo__alerta mb-0" id="alertaCapacidadVehiculoPdv"></p>
                            </div>
                        </div>
                        @endif
                        <div class="pdv-info-grid mb-0">
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--prod"><i class="fas fa-box"></i></span>
                                <span class="pdv-info-tile__label">Producto a despachar</span>
                                <div class="pdv-info-tile__value">{{ $det?->producto_nombre ?? '—' }}</div>
                                <div class="pdv-info-tile__sub">{{ $det ? number_format($det->cantidad, 2).' '.$unidad : '' }}</div>
                            </div>
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--pdv"><i class="fas fa-store"></i></span>
                                <span class="pdv-info-tile__label">Destino</span>
                                <div class="pdv-info-tile__value text-success">{{ $pedido->puntoVenta?->nombre ?? '—' }}</div>
                                <div class="pdv-info-tile__sub">{{ $pedido->puntoVenta?->resumenUbicacion() ?: 'Sin calle de referencia' }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="pdv-paso-panel" data-panel="resumen">
                        <div class="pdv-info-grid mb-3">
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--pdv"><i class="fas fa-store"></i></span>
                                <span class="pdv-info-tile__label">Punto de venta</span>
                                <div class="pdv-info-tile__value text-success">{{ $pedido->puntoVenta?->nombre ?? '—' }}</div>
                                <div class="pdv-info-tile__sub">{{ $pedido->puntoVenta?->resumenUbicacion() ?: 'Sin calle de referencia' }}</div>
                                @if($simulacionActiva && $urlTiempoRealPedido)
                                    @include('logistica.partials.enlace-tiempo-real', ['href' => $urlTiempoRealPedido])
                                @else
                                    @include('punto_venta.pedidos.partials.enlace-ubicacion', ['href' => $urlUbicacionPdv])
                                @endif
                            </div>
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--prod"><i class="fas fa-box"></i></span>
                                <span class="pdv-info-tile__label">Producto</span>
                                <div class="pdv-info-tile__value">{{ $det?->producto_nombre ?? '—' }}</div>
                                <div class="pdv-info-tile__sub">{{ $det ? number_format($det->cantidad, 2).' '.$unidad : '' }}</div>
                            </div>
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--alm"><i class="fas fa-warehouse"></i></span>
                                <span class="pdv-info-tile__label">Origen mayorista</span>
                                <div class="pdv-ubicacion-stack">
                                    <div class="pdv-almacen-caja">
                                        <i class="fas fa-store-alt"></i>
                                        {{ $almacenContexto?->nombre ?? '—' }}
                                    </div>
                                    @include('punto_venta.pedidos.partials.enlace-ubicacion', ['href' => $urlUbicacionAlmacen])
                                </div>
                            </div>
                            @if($transportistaEfectivo)
                            <div class="pdv-info-tile">
                                <span class="pdv-info-tile__icon pdv-info-tile__icon--meta"><i class="fas fa-id-card"></i></span>
                                <span class="pdv-info-tile__label">Transportista</span>
                                <div class="pdv-info-tile__value">{{ trim($transportistaEfectivo->nombre.' '.$transportistaEfectivo->apellido) }}</div>
                                @if($vehiculoEfectivo)
                                <div class="pdv-info-tile__sub">{{ $vehiculoEfectivo->placa }}</div>
                                @endif
                            </div>
                            @endif
                        </div>
                        <div class="pdv-meta-list">
                            <div class="pdv-meta-item"><span>Fecha solicitud</span><span>{{ $pedido->fechapedido?->format('d/m/Y H:i') ?? '—' }}</span></div>
                            @if(\App\Support\PedidoDistribucionCatalogo::esPedidoExterno($pedido))
                            <div class="pdv-meta-item"><span>Origen</span><span><span class="badge badge-warning">Pedido externo</span> {{ \App\Support\PedidoDistribucionCatalogo::etiquetaCanal($pedido->canal_origen) }}</span></div>
                            @php $registrador = $pedido->registradoManualPor ?? $pedido->creadoPor; @endphp
                            @if($registrador)
                            <div class="pdv-meta-item"><span>Registrado por (mayorista)</span><span>{{ trim($registrador->nombre.' '.$registrador->apellido) }}</span></div>
                            @endif
                            @elseif($pedido->creadoPor)
                            <div class="pdv-meta-item"><span>Solicitado por</span><span>{{ trim($pedido->creadoPor->nombre.' '.$pedido->creadoPor->apellido) }}</span></div>
                            @endif
                            @if($pedido->fecha_entrega_deseada)
                            <div class="pdv-meta-item"><span>Entrega deseada</span><span>{{ $pedido->fecha_entrega_deseada->format('d/m/Y') }}</span></div>
                            @endif
                            @if($pedido->fecha_envio)
                            <div class="pdv-meta-item"><span>Salida en ruta</span><span>{{ $pedido->fecha_envio->format('d/m/Y H:i') }}</span></div>
                            @endif
                            @if($pedido->fecha_recepcion)
                            <div class="pdv-meta-item"><span>Recibido en PDV</span><span>{{ $pedido->fecha_recepcion->format('d/m/Y H:i') }}</span></div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if(($puedeGestionarMayorista ?? false) && $pendienteMayorista && $erroresStock !== [])
            <div class="alert alert-warning">
                <strong><i class="fas fa-info-circle mr-1"></i>Stock mayorista limitado:</strong>
                <ul class="mb-0 pl-3 mt-1">@foreach($erroresStock as $err)<li>{{ $err }}</li>@endforeach</ul>
                <p class="mb-0 mt-2 small">Revise la cantidad o rechace la solicitud si no puede abastecer desde su almacén.</p>
            </div>
            @endif
        </div>

        <div class="col-lg-4" id="pdvPedidoSidebar">
            <div class="pdv-sidebar-paso" data-sidebar-paso="1">
                <div class="card pdv-card card-outline card-secondary mb-3">
                    <div class="card-body small text-muted mb-0">
                        <i class="fas fa-edit mr-1"></i>
                        Revise los datos de la solicitud a la izquierda. Los cambios se guardan sin salir de esta pantalla.
                    </div>
                </div>
            </div>

            <div class="pdv-sidebar-paso" data-sidebar-paso="2">
            @if(($puedeGestionarMayorista ?? false) && $pendienteMayorista)
            <div class="card pdv-card card-outline card-success mb-3">
                <div class="card-header bg-white py-2">
                    <h3 class="card-title mb-0 font-weight-bold"><i class="fas fa-warehouse text-success mr-1"></i> Decisión mayorista</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        @if($pedido->espera_stock)
                            El minorista solicitó una cantidad mayor al stock actual para el {{ $pedido->fecha_entrega_deseada?->format('d/m/Y') ?? '—' }}.
                            Acepte solo si puede abastecer a tiempo, o rechace la solicitud.
                        @else
                            Al aceptar se confirmará el almacén mayorista de origen y podrá asignar transporte.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('punto-venta.pedidos.aceptar', $pedido) }}" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-success btn-block btn-lg">
                            <i class="fas fa-check mr-1"></i> Aceptar y preparar envío
                        </button>
                    </form>
                    <hr>
                    <form method="POST" action="{{ route('punto-venta.pedidos.rechazar', $pedido) }}">
                        @csrf
                        <div class="form-group mb-2">
                            <label class="small text-muted">Motivo (opcional)</label>
                            <textarea name="motivo_rechazo" class="form-control form-control-sm" rows="2" maxlength="500" placeholder="Ej: stock insuficiente"></textarea>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-block"
                                data-confirm-modal
                                data-confirm-title="Rechazar solicitud"
                                data-confirm-message="¿Rechazar esta solicitud del minorista? Esta acción no se puede deshacer.">
                            <i class="fas fa-times mr-1"></i> Rechazar solicitud
                        </button>
                    </form>
                </div>
            </div>
            @elseif($puedeReabrirRevision)
            <div class="card pdv-card card-outline card-warning mb-3">
                <div class="card-header bg-white py-2">
                    <h3 class="card-title mb-0 font-weight-bold"><i class="fas fa-undo text-warning mr-1"></i> Volver a revisar</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Si necesita cambiar cantidad, producto o destino, puede devolver el pedido a revisión. Se quitará el transportista asignado si lo hubiera.
                    </p>
                    <form method="POST" action="{{ route('punto-venta.pedidos.reabrir-revision', $pedido) }}">
                        @csrf
                        <button type="button" class="btn btn-warning btn-block"
                                data-confirm-modal
                                data-confirm-title="Reabrir revisión mayorista"
                                data-confirm-message="¿Devolver este pedido a revisión? Deberá aceptarlo nuevamente antes de designar transportista."
                                data-confirm-tone="warning">
                            <i class="fas fa-undo mr-1"></i> Reabrir paso 2
                        </button>
                    </form>
                </div>
            </div>
            @elseif($pendienteMayorista && ($esMinoristaDueño ?? false))
            <div class="card pdv-card card-outline card-warning mb-3">
                <div class="card-body py-3">
                    <div class="pdv-espera-mayorista mb-3 mb-md-2">
                        <div class="pdv-espera-mayorista__icon"><i class="fas fa-hourglass-half"></i></div>
                        <div>
                            <div class="pdv-espera-mayorista__title">Solicitud en revisión</div>
                            <p class="pdv-espera-mayorista__text mb-0">
                                Su solicitud está en revisión. El mayorista confirmará el stock y despacho disponible.
                            </p>
                        </div>
                    </div>
                    @if($puedeEliminarSolicitud ?? false)
                    <form method="POST" action="{{ route('punto-venta.pedidos.destroy', $pedido) }}">
                        @csrf
                        @method('DELETE')
                        <button type="button" class="btn btn-outline-danger btn-sm btn-block"
                            data-confirm-modal
                            data-confirm-title="Eliminar solicitud"
                            data-confirm-message="¿Eliminar esta solicitud? Aún no fue aceptada por el mayorista."
                            data-confirm-tone="danger">
                            <i class="fas fa-trash mr-1"></i> Eliminar solicitud
                        </button>
                    </form>
                    @endif
                </div>
            </div>
            @else
            <div class="card pdv-card card-outline card-secondary mb-3">
                <div class="card-body small text-muted mb-0">
                    <i class="fas fa-check mr-1"></i> Revisión mayorista completada.
                </div>
            </div>
            @endif
            </div>

            <div class="pdv-sidebar-paso" data-sidebar-paso="3">
            @if($puedeConfirmarEnvioMayorista ?? false)
            @include('punto_venta.pedidos.partials.confirmar-envio-mayorista-minorista', ['pedido' => $pedido])
            @elseif($puedeDesignarTransportista)
            <div class="card pdv-card card-outline card-success mb-3">
                <div class="card-header bg-white py-2"><h3 class="card-title mb-0 font-weight-bold"><i class="fas fa-user-tie text-success mr-1"></i> Designar transportista</h3></div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Asigne un <strong>transportista de flota mayorista</strong> y su <strong>vehículo</strong>.
                        Luego el transportista completará el cierre operativo (condiciones, incidentes y firmas) antes de salir en ruta.
                    </p>
                    <form method="POST" action="{{ route('punto-venta.pedidos.designar-transportista', $pedido) }}" id="formDespachoPedidoPdv">
                        @csrf
                        <div class="pdv-flota-panel pdv-flota-panel--chofer">
                            <label class="small font-weight-bold mb-2 d-block">
                                <span class="panel-icon"><i class="fas fa-id-card"></i></span>
                                Chofer mayorista <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="text" id="txtTransportistaPedidoPdv" class="form-control bg-white" readonly
                                       placeholder="Buscar transportista mayorista…" value="{{ old('transportista_label') }}" required>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-primary" id="btnBuscarTransportistaPedidoPdv" title="Buscar chofer">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            <input type="hidden" name="transportista_usuarioid" id="transportista_pedido_pdv_id"
                                   value="{{ old('transportista_usuarioid') }}" required>
                        </div>
                        <div class="pdv-flota-panel pdv-flota-panel--vehiculo">
                            <label class="small font-weight-bold mb-2 d-block">
                                <span class="panel-icon"><i class="fas fa-truck"></i></span>
                                Vehículo <span class="text-danger">*</span>
                                <span class="pdv-vehiculo-sugerido-chip d-none" id="chipVehiculoSugeridoPdv">Sugerido</span>
                            </label>
                            <div class="input-group">
                                <input type="text" id="txtVehiculoPedidoPdv" class="form-control bg-white" readonly
                                       placeholder="Seleccione el vehículo del chofer…" required>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-success" id="btnBuscarVehiculoPedidoPdv" disabled title="Buscar vehículo">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            <input type="hidden" name="vehiculoid" id="vehiculo_pedido_pdv_id" value="{{ old('vehiculoid') }}" required>
                            <p class="small text-muted mb-0 mt-2"><i class="fas fa-info-circle mr-1"></i>Primero elija el chofer; luego asigne su vehículo mayorista.</p>
                        </div>
                        <button type="button" class="btn btn-success btn-block btn-lg"
                                id="btnConfirmarDespachoPedidoPdv"
                                data-confirm-title="Designar transportista"
                                data-confirm-message="¿Asignar este chofer y vehículo al pedido? El transportista deberá completar el cierre operativo antes de salir en ruta."
                                data-confirm-tone="success">
                            <i class="fas fa-user-check mr-1"></i> Designar transportista
                        </button>
                    </form>
                </div>
            </div>
            @else
            <div data-tiene-salida-ruta="{{ ($ruta && $puedeOperarSalidaRuta && ($puedeEmpezarRuta || $simulacionActiva)) ? '1' : '0' }}">
                @if($transportistaDesignado && $ruta && $puedeOperarSalidaRuta && ($puedeEmpezarRuta || $simulacionActiva))
                    @include('punto_venta.pedidos.partials.sidebar-salida-ruta', compact(
                        'ruta', 'pedido', 'puedeOperarSalidaRuta', 'puedeEmpezarRuta', 'simulacionActiva',
                        'puedeGestionarMayorista', 'transportistaEfectivo', 'urlTiempoRealPedido', 'esAdmin'
                    ))
                @else
            <div class="card pdv-card card-outline card-secondary mb-3">
                <div class="card-body small text-muted mb-0">
                    @if($pendienteConfirmacionMinorista ?? false)
                        <i class="fas fa-hourglass-half mr-1 text-warning"></i>
                        Confirme el envío en el paso anterior para habilitar la salida en ruta del transportista.
                    @elseif($transportistaDesignado && ($puedeGestionarMayorista ?? false) && $ruta && $pedido->estado === 'confirmado')
                            <i class="fas fa-user-clock mr-1 text-warning"></i>
                            Transportista asignado, esperando confirmación del transportista.
                        @else
                            <i class="fas fa-user-tie mr-1"></i> El transportista ya fue designado o el pedido avanzó a otro paso.
                        @endif
                    </div>
                </div>
                @endif
            </div>
            @endif
            </div>

            <div class="pdv-sidebar-paso" data-sidebar-paso="4">
            @if($puedeConfirmarEnvioMayorista ?? false)
            <div class="card pdv-card card-outline card-warning mb-3">
                <div class="card-body small text-muted mb-0">
                    <i class="fas fa-arrow-left mr-1"></i> Confirme el envío en el paso «Confirmar envío».
                </div>
            </div>
            @elseif($simulacionActiva && !empty($urlTiempoRealPedido))
            <div class="card pdv-card card-outline card-info mb-3">
                <div class="card-body">
                    <p class="small text-muted mb-2">Siga el vehículo en vivo hasta su punto de venta.</p>
                    @include('logistica.partials.enlace-tiempo-real', ['href' => $urlTiempoRealPedido, 'compacto' => true])
                </div>
            </div>
            @else
            <div class="card pdv-card card-outline card-secondary mb-3">
                <div class="card-body small text-muted mb-0">
                    @if($pendienteConfirmacionMinorista ?? false)
                        <i class="fas fa-hourglass-half mr-1 text-warning"></i>
                        Confirme el envío en el paso «Confirmar envío» para habilitar la salida en ruta del transportista.
                    @elseif($transportistaDesignado && ($puedeGestionarMayorista ?? false) && $pedido->estado === 'confirmado')
                        <i class="fas fa-user-clock mr-1 text-warning"></i>
                        Transportista asignado, esperando confirmación del transportista.
                    @else
                        <i class="fas fa-route mr-1"></i> El transportista debe completar el cierre operativo y marcar en ruta desde el panel de salida.
                    @endif
                </div>
            </div>
            @endif
            </div>

            <div class="pdv-sidebar-paso" data-sidebar-paso="resumen">
            @if($puedeAnunciarLlegada ?? false)
            <div class="card pdv-card card-outline card-success mb-3">
                <div class="card-header bg-white py-2"><h3 class="card-title mb-0 font-weight-bold"><i class="fas fa-store text-success mr-1"></i> Recepción en tienda (POD)</h3></div>
                <div class="card-body">
                    @if(!empty($estadoRecepcionPdv))
                        @include('logistica.partials.accion-recepcion-pdv-minorista-traslado', [
                            'estadoRecepcion' => $estadoRecepcionPdv,
                            'ruta' => $ruta,
                            'simulacionActiva' => $simulacionActiva ?? false,
                        ])
                    @else
                    <p class="text-muted small mb-3">El pedido ya salió del centro mayorista. Anuncie la llegada cuando lo reciba en su punto de venta.</p>
                    <form method="POST" action="{{ route('punto-venta.pedidos.confirmar-recepcion', $pedido) }}">
                        @csrf
                        <button type="button" class="btn btn-success btn-block btn-lg"
                                data-confirm-modal
                                data-confirm-tone="success"
                                data-confirm-title="Anunciar llegada del pedido"
                                data-confirm-message="¿Confirma que el pedido ya llegó a su punto de venta? Se ingresará al inventario.">
                            <i class="fas fa-check mr-1"></i> Anunciar llegada
                        </button>
                    </form>
                    @endif
                </div>
            </div>
            @elseif(!empty($estadoRecepcionPdv) && ($estadoRecepcionPdv['clave'] ?? '') !== 'programado')
            <div class="card pdv-card card-outline card-info mb-3">
                <div class="card-body">
                    @include('logistica.partials.accion-recepcion-pdv-minorista-traslado', [
                        'estadoRecepcion' => $estadoRecepcionPdv,
                        'ruta' => $ruta,
                        'simulacionActiva' => $simulacionActiva ?? false,
                    ])
                </div>
            </div>
            @elseif($pedido->estado === 'recibido')
            <div class="card pdv-card card-outline card-success mb-3">
                <div class="card-body text-success small mb-0">
                    <i class="fas fa-check-circle mr-1"></i> Pedido completado. El stock ya está en el inventario del punto de venta.
                </div>
            </div>
            @elseif($pedido->estado === 'rechazado')
            <div class="card pdv-card card-outline card-danger mb-3">
                <div class="card-body text-danger small mb-0">
                    <i class="fas fa-ban mr-1"></i> Solicitud rechazada por el centro mayorista.
                </div>
            </div>
            @elseif($pendienteMayorista && $esMinoristaDueño)
            <div class="card pdv-card card-outline card-warning mb-3">
                <div class="card-body py-3">
                    <div class="pdv-espera-mayorista mb-0">
                        <div class="pdv-espera-mayorista__icon"><i class="fas fa-hourglass-half"></i></div>
                        <div>
                            <div class="pdv-espera-mayorista__title">Solicitud en revisión</div>
                            <p class="pdv-espera-mayorista__text mb-0">
                                Su solicitud está en revisión. El mayorista confirmará el stock y despacho disponible.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            @elseif($pedido->estado === 'confirmado' && ! $transportistaDesignado)
            <div class="card pdv-card card-outline card-success mb-3">
                <div class="card-body small mb-0 text-success">
                    <i class="fas fa-box mr-1"></i> El centro mayorista aceptó el pedido. Pendiente de designar transportista.
                </div>
            </div>
            @elseif($pedido->estado === 'en_transito' && ! ($puedeAnunciarLlegada ?? false))
            <div class="card pdv-card card-outline card-success mb-3">
                <div class="card-body small mb-0 text-success">
                    <i class="fas fa-shipping-fast mr-1"></i> Producto en ruta hacia el punto de venta. Al llegar, el inventario se actualizará automáticamente.
                </div>
            </div>
            @endif
            </div>
        </div>
    </div>

    @include('partials.selector-catalogo-assets')
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    window.PdvPedidoCargaContext = {
        peso_kg: {{ number_format((float) ($resumenCargaPedido['peso_kg'] ?? 0), 2, '.', '') }},
        volumen_m3: @json($resumenCargaPedido['volumen_m3'] ?? null),
    };

    const flujo = document.getElementById('pdvPedidoFlujo');
    const pasoInicial = flujo ? (flujo.dataset.pasoInicial || 'resumen') : 'resumen';
    const pasoFlujo = flujo ? parseInt(flujo.dataset.pasoFlujo || '3', 10) : 3;

    function sidebarKeyForPaso(paso) {
        var key = String(paso);
        var salidaPendiente = document.querySelector('[data-tiene-salida-ruta="1"]');
        if ((key === '4' || key === '5') && salidaPendiente) {
            return '3';
        }
        return key;
    }

    function mostrarPaso(paso) {
        const key = String(paso);
        const sidebarKey = sidebarKeyForPaso(paso);

        document.querySelectorAll('.pdv-paso-panel').forEach(function (panel) {
            const pk = panel.getAttribute('data-panel');
            var showPanel = false;
            if (key === '1') showPanel = pk === '1';
            else if (key === '2') showPanel = pk === '2';
            else if (key === '3') showPanel = pk === '3';
            else showPanel = pk === 'resumen';
            panel.classList.toggle('is-visible', showPanel);
        });

        document.querySelectorAll('.pdv-sidebar-paso').forEach(function (side) {
            const sk = side.getAttribute('data-sidebar-paso');
            var showSide = false;
            if (key === '1') showSide = sk === '1';
            else if (key === '2') showSide = sk === '2';
            else if (sidebarKey === '3') showSide = sk === '3';
            else if (sidebarKey === '4') showSide = sk === '4';
            else if (sidebarKey === '5') showSide = sk === '5';
            else if (pasoFlujo === 2) showSide = sk === '2';
            else if (pasoFlujo === 3) showSide = sk === '3';
            else if (pasoFlujo === 4) showSide = sk === '4';
            else showSide = sk === 'resumen';
            side.classList.toggle('is-visible', showSide);
        });

        document.querySelectorAll('.pdv-paso-card[data-paso]').forEach(function (card) {
            card.classList.toggle('vista-activa', card.getAttribute('data-paso') === key);
        });

        if (flujo) {
            flujo.dataset.pasoVista = key;
        }
    }

    function pasoVistaActual() {
        if (flujo && flujo.dataset.pasoVista) {
            return flujo.dataset.pasoVista;
        }
        var activa = flujo ? flujo.querySelector('.pdv-paso-card.vista-activa') : null;
        return activa ? activa.getAttribute('data-paso') : String(pasoInicial);
    }

    document.querySelectorAll('.js-link-ubicacion-pedido').forEach(function (link) {
        link.addEventListener('click', function () {
            try {
                var url = new URL(link.href, window.location.origin);
                url.searchParams.set('paso', pasoVistaActual());
                link.href = url.toString();
            } catch (e) { /* noop */ }
        });
    });

    if (flujo) {
        flujo.querySelectorAll('.pdv-paso-card[data-navegable="1"]').forEach(function (card) {
            card.addEventListener('click', function () {
                mostrarPaso(card.getAttribute('data-paso'));
            });
        });
        mostrarPaso(String(pasoInicial));
        if (flujo) {
            flujo.dataset.pasoVista = String(pasoInicial);
        }
    } else {
        document.querySelectorAll('.pdv-paso-panel[data-panel="resumen"]').forEach(function (p) {
            p.classList.add('is-visible');
        });
        document.querySelectorAll('.pdv-sidebar-paso[data-sidebar-paso="resumen"]').forEach(function (s) {
            s.classList.add('is-visible');
        });
    }

    const form = document.getElementById('formDespachoPedidoPdv');
    if (!form || !window.CatalogoSelector) return;

    if (!CatalogoSelector.modalEl) {
        CatalogoSelector.init();
    }

    const inputTransportista = document.getElementById('transportista_pedido_pdv_id');
    const inputVehiculo = document.getElementById('vehiculo_pedido_pdv_id');
    const btnVehiculo = document.getElementById('btnBuscarVehiculoPedidoPdv');
    const btnConfirmar = document.getElementById('btnConfirmarDespachoPedidoPdv');
    const chipSugerido = document.getElementById('chipVehiculoSugeridoPdv');
    let vehiculoSugeridoIdActivo = null;

    function mostrarChipSugerido(mostrar) {
        chipSugerido?.classList.toggle('d-none', !mostrar);
    }

    function esVehiculoSugerido(id) {
        return vehiculoSugeridoIdActivo != null && String(id) === String(vehiculoSugeridoIdActivo);
    }

    CatalogoSelector.register('pdv_pedido_transportista', {
        endpoint: @json(route('catalogo-selector.usuarios')),
        title: 'Transportista mayorista',
        searchPlaceholder: 'Nombre o correo…',
        searchLabel: 'Buscar chofer',
        modalIcon: 'fa-id-card',
        rowIcon: 'fa-user-tie',
        theme: 'mayorista',
        colNombre: 'Chofer',
        colDetalle: 'Contacto',
        params: { roles: 'transportista', ambito_flota: 'mayorista' },
        onSelect: function (item) {
            inputTransportista.value = item.id;
            document.getElementById('txtTransportistaPedidoPdv').value = item.label;
            inputVehiculo.value = '';
            document.getElementById('txtVehiculoPedidoPdv').value = '';
            btnVehiculo.disabled = false;
            mostrarChipSugerido(false);
            vehiculoSugeridoIdActivo = null;
            const vehiculoSugerido = item.extra?.vehiculoid || null;
            CatalogoSelector.instances.pdv_pedido_vehiculo.params = {
                transportista_usuarioid: item.id,
                solo_transportista: '1',
                ambito_flota: 'mayorista',
            };
            CatalogoSelector.instances.pdv_pedido_vehiculo.suggestedItemId = vehiculoSugerido;
            vehiculoSugeridoIdActivo = vehiculoSugerido;
            if (vehiculoSugerido && item.extra?.placa) {
                inputVehiculo.value = vehiculoSugerido;
                document.getElementById('txtVehiculoPedidoPdv').value = item.extra.placa;
                mostrarChipSugerido(true);
                validarCapacidadVehiculoPdv();
            }
        },
    });

    CatalogoSelector.register('pdv_pedido_vehiculo', {
        endpoint: @json(route('catalogo-selector.vehiculos')),
        title: 'Vehículo de reparto',
        searchPlaceholder: 'Placa, marca o modelo…',
        searchLabel: 'Buscar vehículo',
        modalIcon: 'fa-truck',
        rowIcon: 'fa-truck',
        theme: 'vehiculo',
        colNombre: 'Placa',
        colDetalle: 'Vehículo',
        params: { transportista_usuarioid: inputTransportista?.value || '', solo_transportista: '1', ambito_flota: 'mayorista' },
        beforeOpen: function (cfg) {
            const tid = inputTransportista?.value || '';
            cfg.params = {
                transportista_usuarioid: tid,
                solo_transportista: '1',
                ambito_flota: 'mayorista',
            };
            cfg.suggestedItemId = vehiculoSugeridoIdActivo;
        },
        onSelect: function (item) {
            inputVehiculo.value = item.id;
            document.getElementById('txtVehiculoPedidoPdv').value = item.label;
            mostrarChipSugerido(esVehiculoSugerido(item.id) || item.sugerido === true);
            validarCapacidadVehiculoPdv();
        },
    });

    function validarCapacidadVehiculoPdv() {
        const panel = document.getElementById('panelCapacidadVehiculoPdv');
        const vehiculoId = inputVehiculo?.value;
        if (!panel || !vehiculoId) return;

        const wrap = document.getElementById('wrapCapacidadVehiculoPdv');
        const resumen = document.getElementById('txtCapacidadVehiculoResumen');
        const pctEl = document.getElementById('txtCapacidadVehiculoPct');
        const barra = document.getElementById('barCapacidadVehiculoPdv');
        const hint = document.getElementById('txtCapacidadVehiculoPdv');
        const recom = document.getElementById('txtCapacidadVehiculoRecomendacion');
        const alerta = document.getElementById('alertaCapacidadVehiculoPdv');

        fetch(panel.dataset.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ vehiculoid: parseInt(vehiculoId, 10) }),
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                const pct = data.porcentaje_uso ?? 0;
                panel.classList.toggle('is-excedido', !data.ok);
                if (hint) hint.classList.add('d-none');
                if (wrap) wrap.classList.remove('d-none');
                if (resumen) {
                    resumen.textContent = (data.vehiculo || 'Vehículo') + ' — '
                        + (data.capacidad_kg ? Number(data.capacidad_kg).toFixed(0) + ' kg' : '')
                        + (data.capacidad_m3 ? ' / ' + Number(data.capacidad_m3).toFixed(1) + ' m³' : '');
                }
                if (pctEl) {
                    pctEl.textContent = Number(pct).toFixed(1) + '% usado';
                    pctEl.className = 'font-weight-bold ' + (pct >= 100 ? 'text-danger' : pct >= 85 ? 'text-warning' : 'text-success');
                }
                if (barra) barra.style.width = Math.min(100, Math.max(0, pct)) + '%';
                if (recom) recom.textContent = data.recomendacion || '';
                if (alerta) alerta.textContent = data.ok ? '' : (data.mensaje || 'La carga supera la capacidad del vehículo.');
            })
            .catch(function () {
                if (hint) hint.textContent = 'No se pudo validar la capacidad. Intente de nuevo.';
            });
    }

    if (inputVehiculo?.value) {
        vehiculoSugeridoIdActivo = CatalogoSelector.instances.pdv_pedido_vehiculo?.suggestedItemId || null;
        if (esVehiculoSugerido(inputVehiculo.value)) {
            mostrarChipSugerido(true);
        }
        validarCapacidadVehiculoPdv();
    }

    document.getElementById('btnBuscarTransportistaPedidoPdv')?.addEventListener('click', function () {
        CatalogoSelector.open('pdv_pedido_transportista');
    });
    btnVehiculo?.addEventListener('click', function () {
        if (inputTransportista.value) {
            CatalogoSelector.open('pdv_pedido_vehiculo');
        }
    });

    btnConfirmar?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (!inputTransportista.value) {
            window.ModalConfirmar?.aviso({ titulo: 'Falta chofer', mensaje: 'Asigne un transportista de flota mayorista.', tono: 'warning' });
            return;
        }
        if (!inputVehiculo.value) {
            window.ModalConfirmar?.aviso({ titulo: 'Falta vehículo', mensaje: 'Asigne el vehículo del chofer antes de designar el transportista.', tono: 'warning' });
            return;
        }
        window.ModalConfirmar.abrir(
            form,
            btnConfirmar.getAttribute('data-confirm-title'),
            btnConfirmar.getAttribute('data-confirm-message'),
            btnConfirmar.getAttribute('data-confirm-tone') || 'success'
        );
    });

    form?.addEventListener('submit', function () {
        if (!btnConfirmar) return;
        btnConfirmar.disabled = true;
        btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Asignando transportista…';
        form.querySelectorAll('button, input:not([type="hidden"]), select').forEach(function (el) {
            if (el !== btnConfirmar) el.disabled = true;
        });
    });
});
</script>
@endpush
