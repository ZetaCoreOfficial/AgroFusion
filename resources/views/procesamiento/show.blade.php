@extends('layouts.app')

@section('title', 'Trazabilidad — '.$lote->nombre.' | AgroFusion')
@section('page_title', $lote->nombre)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Inicio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('procesamiento.index') }}">Procesamiento de Lote</a></li>
    <li class="breadcrumb-item active">{{ $lote->nombre }}</li>
@endsection

@push('styles')
    @include('lotes.partials.trazabilidad-styles')
    @include('partials.almacen-envio-styles')
    <style>
    .lp-header { background: linear-gradient(135deg, #1e4620, #4a7c59); color: #fff; border-radius: 14px; padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; }
    .lp-header-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; margin: -.25rem; }
    .lp-header-actions > * { margin: .35rem; }
    .lp-header-actions .btn { font-weight: 600; box-shadow: 0 2px 8px rgba(0,0,0,.12); border: 0; }
    .lp-header-actions .btn-warning { background: #f59e0b; color: #fff; }
    .lp-header-actions .btn-warning:hover { background: #d97706; color: #fff; }
    .lp-header-actions .btn-danger { background: #dc2626; color: #fff; }
    .lp-header-actions .btn-danger:hover { background: #b91c1c; color: #fff; }
    .lp-header-actions .btn-light { background: #fff; color: #1e4620; }
    .lp-header-actions .btn-light:hover { background: #f0fdf4; color: #1e4620; }
    .lp-paso { border: 1px solid #e2ebe3; border-radius: 10px; padding: .75rem 1rem; margin-bottom: .5rem; background: #fff; }
    .lp-paso.done { border-color: #28a745; background: #f0fdf4; }
    .lp-paso.cierre { border-color: #0ea5e9; background: #f0f9ff; }
    .lp-paso.pending { opacity: .85; }
    .maq-etapa-card {
        border: 2px solid #e5e7eb; border-radius: 12px; overflow: hidden; background: #fff;
        cursor: pointer; transition: all .2s ease; height: 100%;
        display: flex; flex-direction: column;
    }
    .maq-etapa-card:hover { border-color: #28a745; box-shadow: 0 4px 14px rgba(40,167,69,.15); }
    .maq-etapa-card.is-selected { border-color: #28a745; background: #f0fdf4; }
    .maq-etapa-card__img {
        height: 220px; background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
        display: flex; align-items: center; justify-content: center;
        overflow: hidden; padding: .65rem;
    }
    .maq-etapa-card__img img {
        width: 100%; height: 100%; object-fit: contain; object-position: center;
        image-rendering: auto;
    }
    .maq-etapa-card__img i { font-size: 2.5rem; color: #94a3b8; }
    .maq-etapa-card__body { padding: .75rem .85rem; flex: 1; }
    .maq-etapa-card__nombre { font-weight: 700; color: #1e293b; font-size: .9rem; }
    .maq-etapa-card__meta { font-size: .75rem; color: #64748b; margin-top: .2rem; }
    .maq-etapa-card__desc { font-size: .78rem; color: #475569; margin-top: .45rem; line-height: 1.4; }
    .lp-etapa-asignar-row { align-items: stretch; }
    .lp-etapa-field {
        display: flex; flex-direction: column; margin-bottom: 1rem;
    }
    .lp-etapa-field .lp-etapa-label {
        min-height: 1.2rem; margin-bottom: .35rem; line-height: 1.2;
    }
    .lp-etapa-field .lp-etapa-control { flex: 0 0 auto; }
    .lp-etapa-field .lp-etapa-hint {
        font-size: .75rem; color: #6c757d; min-height: 2.55rem;
        margin-top: .35rem; margin-bottom: 0; line-height: 1.35;
    }
    .lp-maquina-slot { min-height: calc(1.8125rem + 2px); }
    .lp-maquina-filled {
        display: flex; gap: .55rem; align-items: center;
        border: 1px solid #ced4da; border-radius: .2rem;
        padding: .3rem .4rem; background: #fff;
        min-height: calc(1.8125rem + 2px);
    }
    .lp-maquina-filled__media {
        width: 52px; height: 52px; flex-shrink: 0;
        background: #f8fafc; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        overflow: hidden; border: 1px solid #e2e8f0;
    }
    .lp-maquina-filled__media img {
        width: 100%; height: 100%; object-fit: contain; object-position: center;
    }
    .lp-maquina-filled__body { min-width: 0; flex: 1; }
    .lp-maquina-filled__body .btn { font-size: .72rem; }
    #selector_wrap_etapa_operario.is-bloqueado .input-group-append button { pointer-events: none; opacity: .55; }

    .lp-timeline-num {
        width: 28px; height: 28px; border-radius: 50%; background: #2c5530; color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; margin-right: .65rem;
    }
    .lp-form-etapa { background: #f8faf8; border: 1px solid #e2ebe3; border-radius: 12px; padding: 1rem 1.15rem; }
    .lp-fase-card { border: 0; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.06); margin-bottom: 1.25rem; overflow: hidden; }
    .lp-fase-card .card-header { border-bottom: 1px solid #e8f0e9; padding: .9rem 1.15rem; }
    .lp-resumen-card {
        border: 1px solid #e2ebe3; border-radius: 12px; overflow: hidden; height: 100%;
        background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.05);
    }
    .lp-resumen-card .lp-resumen-head {
        padding: .7rem 1rem; font-weight: 700; font-size: .82rem;
        text-transform: uppercase; letter-spacing: .04em;
        display: flex; align-items: center; gap: .5rem;
    }
    .lp-resumen-card .lp-resumen-body { padding: 1rem 1.1rem; }
    .lp-resumen-mp .lp-resumen-head { background: linear-gradient(135deg, #e8f5e9, #f0fdf4); color: #1e4620; }
    .lp-resumen-cert .lp-resumen-head { background: linear-gradient(135deg, #5b21b6, #7c3aed); color: #fff; }
    .lp-resumen-alm .lp-resumen-head { background: linear-gradient(135deg, #c2410c, #ea580c); color: #fff; }
    .lp-resumen-item {
        display: flex; justify-content: space-between; align-items: flex-start;
        gap: .75rem; padding: .55rem 0; border-bottom: 1px solid #f1f5f1;
    }
    .lp-resumen-item:last-child { border-bottom: 0; padding-bottom: 0; }
    .lp-resumen-item .nombre { font-weight: 600; color: #1a252f; font-size: .9rem; }
    .lp-resumen-item .dato { font-weight: 700; color: #2c5530; white-space: nowrap; }
    .lp-resumen-empty { color: #94a3b8; font-size: .88rem; text-align: center; padding: .5rem 0; }
    .lp-resumen-badge { font-size: .72rem; font-weight: 700; padding: .35rem .65rem; border-radius: 6px; }
    .lp-fases-toolbar {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
        gap: .75rem; margin-bottom: 1rem;
    }
    .lp-fases-toolbar .lp-fase-activa-hint {
        font-size: .88rem; color: #495057; margin: 0;
    }
    .lp-fases-toolbar .lp-fase-activa-hint strong { color: #2c5530; }
    #lp-fases-workspace.lp-modo-todas-fases .lp-fase-panel--historial {
        display: block;
    }
    .lp-fase-panel { display: none; margin-bottom: 1.25rem; }
    .lp-fase-panel--activa { display: block; }
    .lp-fase-panel--historial { display: none; }
    .lp-fase-panel--historial .card-header { background: #f8faf8 !important; }
    .lp-fase-panel--historial .lp-form-etapa,
    .lp-fase-panel--historial form:not(.lp-historial-readonly) { display: none !important; }
    .lp-historial-badge {
        font-size: .7rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .04em; color: #6c757d;
    }
    .fase-step.fase-step--scroll { cursor: pointer; transition: transform .15s ease, box-shadow .15s ease; }
    .fase-step.fase-step--scroll:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(44,85,48,.2); }
    .lp-ruta-paso { transition: opacity .2s ease, filter .2s ease; }
    .lp-stepper {
        display: flex; align-items: flex-start; gap: 0; overflow-x: auto;
        padding: .35rem .15rem .65rem; margin: 0 -.15rem;
    }
    .lp-stepper__item {
        display: flex; flex-direction: column; align-items: center; min-width: 88px; max-width: 120px;
        flex: 1 1 0; text-align: center; position: relative;
    }
    .lp-stepper__circle {
        width: 2.1rem; height: 2.1rem; border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: .78rem; font-weight: 800; border: 2px solid #d1d5db; background: #fff; color: #6b7280;
        position: relative; z-index: 1; transition: all .2s ease;
    }
    .lp-stepper__item--hecho .lp-stepper__circle {
        background: #16a34a; border-color: #16a34a; color: #fff;
    }
    .lp-stepper__item--actual .lp-stepper__circle {
        background: #f59e0b; border-color: #f59e0b; color: #fff; box-shadow: 0 0 0 4px rgba(245,158,11,.2);
    }
    .lp-stepper__item--en-curso .lp-stepper__circle {
        background: #2563eb; border-color: #2563eb; color: #fff; box-shadow: 0 0 0 4px rgba(37,99,235,.18);
    }
    .lp-stepper__item--bloqueado { opacity: .45; }
    .lp-stepper__label {
        font-size: .68rem; line-height: 1.25; margin-top: .45rem; color: #374151; font-weight: 600;
        max-width: 100%; word-break: break-word;
    }
    .lp-stepper__item--bloqueado .lp-stepper__label { color: #9ca3af; }
    .lp-stepper__lock { display: block; font-size: .62rem; color: #9ca3af; font-weight: 500; margin-top: .15rem; }
    .lp-stepper__connector {
        flex: 1 1 0; min-width: 12px; height: 2px; background: #e5e7eb; margin-top: 1.05rem; align-self: flex-start;
    }
    .lp-stepper__connector--hecho { background: #86efac; }
    .lp-ruta-paso.is-bloqueado {
        opacity: .42;
        filter: grayscale(.25);
    }
    .lp-ruta-paso.is-actual { box-shadow: 0 0 0 2px rgba(245,158,11,.35); }
    .lp-ruta-paso.is-en-curso { box-shadow: 0 0 0 2px rgba(59,130,246,.35); }
    .lp-ruta-paso__lock { font-size: .68rem; display: block; margin-top: 2px; }
    .lp-asignaciones-pendientes { margin-top: 0; }
    .lp-asignaciones-pendientes__titulo {
        font-size: .78rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .04em; color: #92400e; margin-bottom: .65rem;
    }
    .lp-accion-actual {
        background: linear-gradient(180deg, #fff 0%, #f8fbf8 100%);
        border: 1px solid #d1e7d4; border-radius: 14px;
        padding: 1rem 1.15rem; margin-top: 1rem;
        box-shadow: 0 4px 18px rgba(30,70,32,.06);
    }
    .lp-accion-actual__head {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: .5rem; margin-bottom: .85rem;
    }
    .lp-accion-actual__head h6 {
        margin: 0; font-weight: 700; color: #1e4620; font-size: .95rem;
    }
    .lp-accion-actual__badge {
        font-size: .72rem; font-weight: 700; padding: .3rem .65rem;
        border-radius: 999px; background: #fef3c7; color: #92400e;
    }
    .lp-transform-toolbar {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: .65rem; margin-bottom: .85rem;
    }
    .lp-transform-toolbar__title {
        font-weight: 700; color: #1e4620; font-size: .92rem; margin: 0;
    }
    .lp-transform-toolbar__meta { font-size: .78rem; color: #64748b; }
    .lp-timeline-shell {
        background: linear-gradient(180deg, #f8fbf8 0%, #fff 100%);
        border: 1px solid #e2ebe3; border-radius: 14px; padding: .85rem 1rem;
    }
    .lp-preview-params {
        background: linear-gradient(135deg, #f0fdf4 0%, #fff 100%);
        border: 1px solid #bbf7d0; border-radius: 12px;
        padding: .85rem 1rem;
    }
    .lp-preview-params__title { font-size: .85rem; font-weight: 700; color: #1e4620; margin-bottom: .5rem; }
    .lp-preview-params__note { font-size: .75rem; color: #64748b; margin-top: .55rem; margin-bottom: 0; line-height: 1.45; }
    .lp-preview-params__grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
        gap: .5rem;
    }
    .lp-preview-params__card {
        background: #fff; border: 1px solid #d1e7d4; border-radius: 10px;
        padding: .55rem .65rem; display: flex; flex-direction: column; gap: .2rem;
    }
    .lp-preview-params__card-label { font-size: .72rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: .02em; }
    .lp-preview-params__card-range { font-size: .82rem; color: #1e4620; line-height: 1.35; }
    .lp-preview-params__card-range strong { color: #2c5530; font-weight: 800; }
    .lp-paso--pendiente {
        border-color: #fbbf24;
        background: linear-gradient(135deg, #fffbeb 0%, #fff 100%);
        align-items: center;
        gap: .75rem;
    }
    .lp-paso--pendiente .lp-timeline-num {
        background: #f59e0b;
        flex-shrink: 0;
    }
    .lp-paso--pendiente__accion { flex-shrink: 0; }
    .lp-paso--pendiente .lp-paso-meta {
        font-size: .8rem; color: #64748b; line-height: 1.45;
    }
    .lp-paso--pendiente .lp-paso-meta strong { color: #1e293b; }
    .lp-paso--entrada { animation: lpPasoEntrada .4s ease; }
    @keyframes lpPasoEntrada {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .lp-ajax-loading { opacity: .65; pointer-events: none; }
    </style>
@endpush

@section('content')
<script>
(function () {
    var key = 'lp_procesamiento_scroll';
    var saved = sessionStorage.getItem(key);
    var irCertificacion = window.location.hash === '#certificacion';
    if (saved === null && !irCertificacion) return;
    var y = saved !== null ? parseInt(saved, 10) : 0;
    if (saved !== null) sessionStorage.removeItem(key);
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

    function restaurarScroll() {
        var html = document.documentElement;
        var prev = html.style.scrollBehavior;
        html.style.scrollBehavior = 'auto';
        if (irCertificacion) {
            var cert = document.getElementById('certificacion');
            if (cert) {
                cert.scrollIntoView({ block: 'start' });
            } else if (!isNaN(y) && y > 0) {
                window.scrollTo(0, y);
            }
        } else if (!isNaN(y)) {
            window.scrollTo(0, y);
        }
        html.style.scrollBehavior = prev;
    }

    restaurarScroll();
    document.addEventListener('DOMContentLoaded', restaurarScroll);
    window.addEventListener('load', function () {
        restaurarScroll();
        setTimeout(restaurarScroll, 120);
    });
})();
</script>
<div class="trz-dash">
    <div class="lp-header d-flex flex-wrap justify-content-between align-items-start">
        <div>
            <p class="mb-1 small opacity-90"><code class="text-white">{{ $lote->codigo_lote }}</code></p>
            <h4 class="mb-1 font-weight-bold">{{ $lote->nombre }}</h4>
            <p class="mb-0 small opacity-90">
                @if($lote->producto) Producto: {{ $lote->producto }} · @endif
                Pedido: {{ $lote->pedido?->numero_solicitud ?? '—' }}
                @if(!empty($produccionEstimada['entrada_kg']))
                    · MP: {{ number_format($produccionEstimada['entrada_kg'], 2) }} kg
                    → {{ number_format($produccionEstimada['cantidad'], 0) }} {{ $produccionEstimada['unidad'] }}
                    (~{{ number_format($produccionEstimada['kg'], 2) }} kg)
                @elseif($lote->cantidad_objetivo)
                    · Objetivo: {{ number_format((float) $lote->cantidad_objetivo, 2) }} {{ $lote->unidadMedida?->abreviatura ?? '' }}
                @endif
            </p>
        </div>
        <div class="lp-header-actions">
            @can('lote_produccion.create')
                <a href="{{ route('procesamiento.edit', $lote) }}" class="btn btn-warning btn-sm"><i class="fas fa-edit mr-1"></i>Editar</a>
                @if(!empty($puedeEliminar))
                <form action="{{ route('procesamiento.destroy', $lote) }}" method="POST" class="d-inline m-0">
                    @csrf
                    @method('DELETE')
                    <button type="button" class="btn btn-danger btn-sm"
                            data-confirm-modal
                            data-confirm-title="Eliminar lote"
                            data-confirm-message="¿Eliminar el lote «{{ $lote->nombre }}»? Se revertirá el stock de materias primas.">
                        <i class="fas fa-trash mr-1"></i>Eliminar
                    </button>
                </form>
                @endif
            @endcan
            <a href="{{ route('procesamiento.index') }}" class="btn btn-light btn-sm"><i class="fas fa-arrow-left mr-1"></i>Volver</a>
        </div>
    </div>

    <div class="card lote-section-card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                <div>
                    <h5 class="mb-1 text-success">
                        <i class="fas fa-route mr-2"></i>Estado actual:
                        <span class="badge badge-success">{{ $fase_actual_label }}</span>
                    </h5>
                    <p class="text-muted small mb-0">Cadena de industrialización en planta</p>
                </div>
                <span class="h4 mb-0 text-success font-weight-bold">{{ $progreso }}%</span>
            </div>
            <div class="progress-fase mb-3">
                <div class="bar" style="width: {{ $progreso }}%"></div>
            </div>

            <div class="fase-pipeline">
                @foreach($fases_pipeline as $step)
                    @php
                        $scrollFase = $step['key'] === 'transformacion'
                            && ($step['estado'] === 'active' || $step['estado'] === 'done' || $step['estado'] === 'next');
                    @endphp
                    <div class="fase-step {{ $step['estado'] }}{{ $scrollFase ? ' fase-step--scroll' : '' }}"
                         @if($scrollFase) data-fase-scroll="{{ $step['key'] }}" role="button" tabindex="0" @endif
                         title="{{ $step['label'] }}{{ $scrollFase ? ' — clic para ir al formulario' : '' }}">
                        <i class="fas fa-{{ $step['icon'] }} d-block mb-1"></i>
                        {{ $step['label'] }}
                        @if($step['estado'] === 'skipped')
                            <span class="d-block small mt-1 text-muted">Omitido</span>
                        @elseif(!empty($step['fase_unica']) && !empty($step['completada']))
                            <span class="d-block small mt-1"><i class="fas fa-check"></i></span>
                        @endif
                    </div>
                @endforeach
            </div>

            @php $pend = $pendiente ?? []; @endphp
            @if(empty($pend['completo']))
                <div class="alert alert-light border trz-pasos-panel mb-0 mt-3">
                    <h6 class="text-success mb-2"><i class="fas fa-list-check mr-1"></i> Qué falta para llegar al 100 %</h6>
                    <p class="small text-muted mb-2">{{ $pend['resumen'] ?? '' }}</p>
                    @if(!empty($pend['acciones']))
                        <ol class="mb-0 pl-3 small">
                            @foreach($pend['acciones'] as $paso)
                                <li class="mb-1">{{ $paso }}</li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            @else
                <p class="small text-success mb-0 mt-3"><i class="fas fa-check-circle mr-1"></i> {{ $pend['resumen'] ?? 'Lote completado' }}</p>
            @endif
        </div>
    </div>

    @php
        $panelActivo = $panel_fase_activo ?? 'transformacion';
        $fasesHechas = $fases_completadas ?? [];
        $mostrarTransformacion = $panelActivo === 'transformacion' || in_array('transformacion', $fasesHechas) || count($etapas_transformacion ?? []) > 0;
        $mostrarCertificacion = $panelActivo === 'certificacion' || in_array('certificacion', $fasesHechas);
        $mostrarAlmacenaje = in_array('almacenaje', $fasesHechas)
            || ($panelActivo === 'almacenaje' && empty($lote_rechazado));
        $hayHistorial = count(array_intersect(['transformacion', 'certificacion', 'almacenaje'], $fasesHechas)) > 0
            && $panelActivo !== 'completado';
    @endphp

    <div class="lp-fases-toolbar">
        <p class="lp-fase-activa-hint mb-0">
            <i class="fas fa-crosshairs mr-1 text-success"></i>
            Trabajando en: <strong>{{ $fase_actual_label }}</strong>
        </p>
        @if($hayHistorial || count($fasesHechas) > 1)
        <button type="button" class="btn btn-outline-success btn-sm" id="btnVerTodasFases" aria-expanded="false">
            <i class="fas fa-layer-group mr-1"></i>
            <span class="btn-label">Ver todas las fases</span>
        </button>
        @endif
    </div>

    <div id="lp-fases-workspace">

    @if($panelActivo === 'completado')
    <div class="card lp-fase-card mb-3 lp-fase-panel lp-fase-panel--activa" data-fase="completado">
        <div class="card-body text-center py-4">
            @if(!empty($lote_rechazado))
                <i class="fas fa-times-circle fa-3x text-warning mb-3"></i>
                <h5 class="font-weight-bold text-warning mb-2">Lote cerrado — no conforme</h5>
                <p class="text-muted mb-0">La transformación se completó, pero la evaluación final fue <strong>no conforme</strong>. El producto no ingresó a almacén.</p>
            @else
                <i class="fas fa-flag-checkered fa-3x text-success mb-3"></i>
                <h5 class="font-weight-bold text-success mb-2">Lote completado</h5>
                <p class="text-muted mb-0">Trazabilidad cerrada al 100 %. Todas las fases fueron registradas correctamente.</p>
            @endif
        </div>
    </div>
    @endif

    @if($mostrarTransformacion)
    <div id="lp-seccion-transformacion" class="card mb-3 lp-fase-panel {{ $panelActivo === 'transformacion' ? 'lp-fase-panel--activa' : 'lp-fase-panel--historial' }}" data-fase="transformacion">
        <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center">
            <span><i class="fas fa-cogs text-info mr-2"></i>Línea de procesos</span>
            <div>
                @if($panelActivo !== 'transformacion')
                    <span class="lp-historial-badge mr-2"><i class="fas fa-check mr-1"></i>Fase completada</span>
                @endif
                @if(!empty($transformacion_completa))
                    <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Finalizada con Empaquetado</span>
                @endif
            </div>
        </div>
        <div class="card-body">
            <div id="lp-ajax-flash" class="d-none"></div>

            @if($panelActivo === 'transformacion')
            <div class="lp-transform-toolbar">
                <div>
                    <p class="lp-transform-toolbar__title mb-0">
                        <i class="fas fa-seedling mr-1 text-success"></i>
                        {{ $lote->plantillaTransformacion?->nombre ?? 'Línea manual' }}
                    </p>
                    <p class="lp-transform-toolbar__meta mb-0" id="lp-toolbar-etapa-meta">
                        @if(!empty($transformacion_completa))
                            <i class="fas fa-check-circle text-success mr-1"></i>Ruta de transformación completada
                        @else
                            @php $totalRuta = count($rutaPlantilla ?? []); @endphp
                            Etapa {{ min($ordenEtapaActual ?? 1, max(1, $totalRuta)) }} de {{ $totalRuta ?: '—' }}
                            @if(!empty($siguientePasoPlantilla)) · Siguiente: <strong>{{ $siguientePasoPlantilla->proceso?->nombre }}</strong>@endif
                        @endif
                    </p>
                </div>
                <div class="d-flex flex-wrap align-items-center" style="gap:.5rem">
                @if(!empty($puedeReiniciarTodoAsignaciones))
                <form method="POST" action="{{ route('procesamiento.reiniciar-todo-asignaciones', $lote) }}" class="mb-0 js-lp-guardar-scroll">
                    @csrf
                    <button type="submit"
                            class="btn btn-sm btn-outline-warning font-weight-bold"
                            data-confirm-modal
                            data-confirm-title="Reiniciar todo"
                            data-confirm-message="Se quitarán los operarios de todas las fases no completadas. Los parámetros de la ruta se conservan."
                            data-confirm-tone="warning"
                            data-confirm-btn="Reiniciar todo">
                        <i class="fas fa-undo mr-1"></i> Reiniciar todo
                    </button>
                </form>
                @endif
                </div>
            </div>
            @endif

            @if(!empty($timelineVisual))
            <div class="lp-timeline-shell mb-0">
                @if(!empty($puedeAsignarPlanEtapas))
                <div class="mb-3 p-3 border rounded bg-light">
                    <form method="POST" action="{{ route('procesamiento.asignar-todas-pendientes', $lote) }}" class="form-inline flex-wrap" style="gap:.75rem;">
                        @csrf
                        <label class="mb-0 font-weight-bold small text-muted mr-1">
                            <i class="fas fa-users mr-1"></i> Asignar todas las pendientes a
                        </label>
                        <select name="operador_usuarioid" class="form-control form-control-sm" required style="min-width:14rem;">
                            <option value="">— Operario —</option>
                            @foreach($operadoresPlanta ?? [] as $op)
                                <option value="{{ $op->usuarioid }}">{{ $op->nombreCompleto() }}</option>
                            @endforeach
                        </select>
                        <button type="submit"
                                class="btn btn-sm btn-outline-success font-weight-bold"
                                data-confirm-modal
                                data-confirm-title="Asignar todas"
                                data-confirm-message="¿Asignar todas las etapas pendientes a este operario? Se conservará el orden; ninguna se completará."
                                data-confirm-tone="success"
                                data-confirm-btn="Asignar todas">
                            <i class="fas fa-check-double mr-1"></i> Asignar todas
                        </button>
                    </form>
                    <p class="small text-muted mb-0 mt-2">
                        Un operario para todas las etapas sin asignar. También puede cerrar fase por etapa abajo.
                    </p>
                </div>
                <form method="POST" action="{{ route('procesamiento.asignar-plan-etapas', $lote) }}" id="formAsignarPlanEtapas" class="js-lp-guardar-scroll">
                    @csrf
                @endif
                <div id="lp-timeline-visual">
                    @include('planta.partials.timeline-transformacion', [
                        'items' => $timelineVisual,
                        'sortable' => $panelActivo === 'transformacion' && ! empty($puedeAsignarPlanEtapas),
                        'modoPlan' => !empty($puedeAsignarPlanEtapas),
                        'puedeGestionarPlan' => !empty($puedeAsignarEtapa) && empty($transformacion_completa),
                        'puedeMarcarCompletada' => !empty($puedeAsignarEtapa) && empty($transformacion_completa),
                        'usuarioActualId' => (int) (auth()->id() ?? 0),
                        'esOperarioPlanta' => \App\Support\UsuarioRol::esOperarioPlanta(auth()->user()),
                        'lote' => $lote,
                        'formPlanActivo' => !empty($puedeAsignarPlanEtapas),
                        'cerrarFaseUrl' => route('procesamiento.cerrar-fase', $lote),
                        'cambiarFaseUrl' => route('procesamiento.cambiar-fase', $lote),
                        'rutaUrl' => route('procesamiento.actualizar-ruta', $lote),
                        'rutaPasosJson' => $rutaPasosJson ?? [],
                        'etapasCompletadas' => $etapasCompletadasRuta ?? 0,
                    ])
                </div>
                @if(!empty($puedeAsignarPlanEtapas))
                    <div id="lp-cerrar-todos-wrap" class="d-none mt-3">
                        <button type="submit"
                                class="btn btn-success font-weight-bold"
                                data-confirm-modal
                                data-confirm-title="Cerrar todas las fases"
                                data-confirm-message="¿Cerrar todas las etapas pendientes con los operarios y parámetros indicados?"
                                data-confirm-tone="success"
                                data-confirm-btn="Cerrar todos">
                            <i class="fas fa-check-double mr-1"></i> Cerrar todos
                        </button>
                    </div>
                </form>
                @endif
            </div>
            @elseif(!empty($rutaPlantilla))
            <div class="lp-timeline-shell mb-0">
                <div class="lp-stepper" id="lp-ruta-plantilla-pasos">
                    @foreach($rutaPlantilla as $idx => $paso)
                    @php
                        $itemClass = match ($paso['estado']) {
                            'hecho' => 'lp-stepper__item--hecho',
                            'actual' => 'lp-stepper__item--actual',
                            'en_curso' => 'lp-stepper__item--en-curso',
                            default => 'lp-stepper__item--bloqueado',
                        };
                    @endphp
                    @if($idx > 0)
                        <div class="lp-stepper__connector {{ $paso['estado'] === 'hecho' || in_array($rutaPlantilla[$idx - 1]['estado'] ?? '', ['hecho'], true) ? 'lp-stepper__connector--hecho' : '' }}"></div>
                    @endif
                    <div class="lp-stepper__item {{ $itemClass }}" title="{{ $paso['estado'] === 'bloqueado' ? 'Complete la etapa anterior' : $paso['proceso'] }}">
                        <div class="lp-stepper__circle">
                            @if($paso['estado'] === 'hecho')<i class="fas fa-check"></i>@else{{ $paso['orden'] }}@endif
                        </div>
                        <div class="lp-stepper__label">{{ $paso['proceso'] }}</div>
                        @if($paso['estado'] === 'bloqueado')
                            <span class="lp-stepper__lock"><i class="fas fa-lock"></i> Etapa {{ max(1, $paso['orden'] - 1) }}</span>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            @php
                $timelineGestionPlan = !empty($timelineVisual)
                    && !empty($asignacionesPlanLote)
                    && $asignacionesPlanLote->isNotEmpty();
            @endphp

            @if($panelActivo === 'transformacion' && in_array($fase_actual, ['transformacion', 'creacion']) && empty($transformacion_completa) && !$timelineGestionPlan)
            <div class="lp-accion-actual" id="lp-accion-actual">

                @include('procesamiento.partials.resumen-plan-asignaciones', [
                    'lote' => $lote,
                ])

                @if((empty($asignacionesPendientesLote) || $asignacionesPendientesLote->isEmpty()) && !empty($mensajeBloqueoAsignacion) && empty($puedeAsignarNuevaEtapa))
                <div class="alert alert-warning py-2 px-3 mb-0 small" id="lp-bloqueo-asignacion">
                    <i class="fas fa-lock mr-1"></i>{{ $mensajeBloqueoAsignacion }}
                </div>
                @endif

                @if(!empty($asignacionesPendientesLote) && $asignacionesPendientesLote->count())
                <div class="lp-asignaciones-pendientes" id="lp-asignaciones-pendientes">
                    <div class="lp-accion-actual__head">
                        <h6><i class="fas fa-user-clock mr-1 text-warning"></i> Etapa en curso — completar asignación</h6>
                        <span class="lp-accion-actual__badge">{{ $asignacionesPendientesLote->count() }} pendiente{{ $asignacionesPendientesLote->count() > 1 ? 's' : '' }}</span>
                    </div>
                    @foreach($asignacionesPendientesLote as $asig)
                    <div class="lp-paso lp-paso--pendiente d-flex flex-wrap border-0 shadow-sm" data-asignacion-id="{{ $asig->asignacionetapaplantaid }}" style="background:#fffbeb;border-color:#fde68a!important">
                        <div class="flex-grow-1 min-width-0 mb-2 mb-md-0">
                            <strong class="d-block text-dark">{{ $asig->proceso?->nombre }}</strong>
                            <div class="lp-paso-meta small text-muted">
                                <i class="fas fa-industry mr-1"></i>{{ $asig->maquina?->nombre }}
                                <span class="mx-1">·</span>
                                <i class="fas fa-user-cog mr-1"></i>{{ $asig->operador?->nombreCompleto() }}
                            </div>
                        </div>
                        @if(!empty($puedeAsignarEtapa))
                        <div class="w-100">
                            <form method="POST" action="{{ route('procesamiento.completar-etapa-asignada', [$lote, $asig]) }}" class="mb-0 js-lp-guardar-scroll"
                                  data-ajax-lp-action="completar-etapa">
                                @csrf
                                <p class="small text-muted mb-2">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    El operario registrará los valores reales de los parámetros desde <strong>Mis tareas de planta</strong>.
                                </p>
                                <button type="submit" class="btn btn-success btn-sm font-weight-bold">
                                    <i class="fas fa-check mr-1"></i> Marcar completada
                                </button>
                            </form>
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>

                @elseif(!empty($puedeAsignarEtapa) && !empty($puedeAsignarNuevaEtapa) && empty($puedeAsignarPlanEtapas))
                <div class="lp-accion-actual__head">
                    <h6 id="lp-titulo-asignar-etapa"><i class="fas fa-user-plus mr-1"></i> Asignar etapa {{ $ordenEtapaActual ?? 1 }}</h6>
                </div>
                <div class="lp-form-etapa border-0 p-0 bg-transparent" id="lp-form-registrar-etapa">
                @php $empaquePlan = \App\Support\ProductoPlantaCatalogo::empaquePlanificadoResumen($lote); @endphp
                @if($empaquePlan)
                <div class="alert alert-light border small py-2 px-3 mb-3">
                    <i class="fas fa-box-open text-success mr-1"></i>
                    <strong>Empaquetado planificado:</strong> {{ $empaquePlan }}
                </div>
                @endif
                <form method="POST" action="{{ route('procesamiento.asignar-etapa', $lote) }}" id="formAsignarEtapa" class="js-lp-guardar-scroll">
                    @csrf
                    <div class="form-row lp-etapa-asignar-row">
                        <div class="col-md-3 lp-etapa-field">
                            <label class="small font-weight-bold lp-etapa-label">Proceso de planta</label>
                            <div class="lp-etapa-control">
                                <select name="procesoplantaid" id="selectProcesoEtapa" class="form-control form-control-sm" required>
                                    <option value="">Seleccionar…</option>
                                    @foreach($procesosDisponibles as $proc)
                                        <option value="{{ $proc->procesoplantaid }}">{{ $proc->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <small class="lp-etapa-hint" id="lp-hint-proceso-etapa">
                                @if(!empty($siguientePasoPlantilla))
                                    <span class="text-success"><i class="fas fa-magic mr-1"></i>Etapa {{ $ordenEtapaActual ?? 1 }}: {{ $siguientePasoPlantilla->proceso?->nombre }}</span>
                                @elseif(!empty($rutaPlantilla))
                                    <span class="text-muted">Solo puede asignar la etapa actual del proceso.</span>
                                @else
                                    Registre las etapas en orden secuencial.
                                @endif
                            </small>
                        </div>
                        <div class="col-md-3 lp-etapa-field">
                            <label class="small font-weight-bold lp-etapa-label">Maquinaria</label>
                            <div class="lp-etapa-control lp-maquina-slot">
                                <input type="hidden" name="maquinaplantaid" id="inputMaquinaEtapa" required>
                                <div id="maquinaEtapaEmpty">
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="labelMaquinaEtapa" class="form-control" readonly
                                               placeholder="Primero elija un proceso…">
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnAbrirMaquinariaEtapa" disabled>
                                                <i class="fas fa-search mr-1"></i> Elegir
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div id="maquinaEtapaFilled" class="lp-maquina-filled d-none">
                                    <div class="lp-maquina-filled__media">
                                        <img id="imgMaquinaEtapaSel" src="" alt="Maquinaria seleccionada">
                                    </div>
                                    <div class="lp-maquina-filled__body">
                                        <div id="txtMaquinaEtapaSel" class="small font-weight-bold text-truncate"></div>
                                        <button type="button" class="btn btn-link btn-sm p-0" id="btnCambiarMaquinaEtapa">Cambiar</button>
                                    </div>
                                </div>
                            </div>
                            <small class="lp-etapa-hint" id="hintMaquinaEtapa">Solo equipos compatibles con el proceso.</small>
                        </div>
                        <div class="col-md-3 lp-etapa-field">
                            <div class="lp-etapa-control">
                                @include('partials.selector-catalogo', [
                                    'id' => 'etapa_operario',
                                    'name' => 'operador_usuarioid',
                                    'label' => 'Operario',
                                    'icon' => 'fa-user-cog',
                                    'endpoint' => route('catalogo-selector.usuarios'),
                                    'params' => ['operarios_planta' => '1'],
                                    'title' => 'Elegir operario',
                                    'searchPlaceholder' => 'Nombre, correo…',
                                    'colNombre' => 'Operario',
                                    'colDetalle' => 'Contacto',
                                    'rowIcon' => 'fa-user-cog',
                                    'theme' => 'planta',
                                    'required' => true,
                                    'inputGroup' => true,
                                    'showLabel' => true,
                                    'size' => 'sm',
                                ])
                            </div>
                            <small class="lp-etapa-hint" id="hintOperadorEtapa">Primero elija maquinaria…</small>
                        </div>
                        <div class="col-md-3 lp-etapa-field">
                            <label class="small font-weight-bold lp-etapa-label">Observaciones</label>
                            <div class="lp-etapa-control">
                                <input type="text" name="observaciones" class="form-control form-control-sm" maxlength="500" placeholder="Ej. Pelado y corte en cubos">
                            </div>
                            <small class="lp-etapa-hint">&nbsp;</small>
                        </div>
                    </div>

                    <div id="lpPreviewParamsAsignar" class="lp-preview-params mb-3" style="display:none">
                        <div class="lp-preview-params__title"><i class="fas fa-clipboard-check mr-1"></i> Lo que debe controlar el operario</div>
                        <div id="lpPreviewParamsAsignarBody" class="lp-preview-params__grid"></div>
                        <p class="lp-preview-params__note">
                            <i class="fas fa-info-circle mr-1"></i>
                            En su panel de tareas registrará el valor medido en planta. Debe quedar dentro del rango indicado.
                        </p>
                    </div>

                    <div class="form-row">
                        <div class="col-md-12 form-group mb-0 d-flex justify-content-end">
                            <button type="submit" class="btn btn-success btn-sm font-weight-bold">
                                <i class="fas fa-paper-plane mr-1"></i>Asignar a operario
                            </button>
                        </div>
                    </div>
                </form>
                </div>
                @elseif(\App\Support\UsuarioRol::esOperarioPlanta(auth()->user()) && empty($timelineVisual))
                <div class="alert alert-info py-2 px-3 mb-0 small">
                    <i class="fas fa-info-circle mr-1"></i>
                    Las etapas las asigna el jefe de planta. Revise
                    <a href="{{ route('tareas-planta.index') }}" class="alert-link font-weight-bold">mis tareas</a>.
                </div>
                @endif
            </div>
            @endif

            @if($panelActivo !== 'transformacion' && count($etapas_transformacion ?? []) > 0)
            <div id="lp-lista-etapas-completadas" class="mt-3">
            @foreach($etapas_transformacion ?? [] as $etapa)
                <div class="lp-paso done {{ !empty($etapa['es_cierre']) ? 'cierre' : '' }} d-flex align-items-start">
                    <span class="lp-timeline-num">{{ $etapa['numero'] }}</span>
                    <div class="flex-grow-1">
                        <strong>{{ $etapa['proceso'] }}</strong>
                        <br><small class="text-muted">
                            <i class="fas fa-industry mr-1"></i>{{ $etapa['maquina'] }}
                            · {{ optional($etapa['inicio'])->format('d/m/Y H:i') }} → {{ optional($etapa['fin'])->format('d/m/Y H:i') }}
                        </small>
                    </div>
                    <span class="badge badge-success"><i class="fas fa-check"></i></span>
                </div>
            @endforeach
            </div>
            @else
            <div id="lp-lista-etapas-completadas" class="d-none" aria-hidden="true"></div>
            @endif
        </div>
    </div>
    @endif

    @if($mostrarCertificacion)
    <div id="certificacion" class="card lp-fase-card mb-3 lp-fase-panel {{ $panelActivo === 'certificacion' ? 'lp-fase-panel--activa' : 'lp-fase-panel--historial' }}" data-fase="certificacion">
        <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center">
            <span><i class="fas fa-certificate mr-2" style="color:#7c3aed"></i>Certificación</span>
            @if($panelActivo !== 'certificacion' && $evaluacion)
                <span class="lp-historial-badge"><i class="fas fa-check mr-1"></i>Fase completada</span>
            @endif
        </div>
        <div class="card-body">
            @if($evaluacion && $panelActivo !== 'certificacion')
                <div class="lp-historial-readonly">
                    <span class="badge badge-{{ $evaluacion->razon === 'Certificado' ? 'success' : 'warning' }} mb-2">{{ $evaluacion->razon }}</span>
                    <p class="small text-muted mb-0">
                        <i class="far fa-clock mr-1"></i>{{ optional($evaluacion->fecha_evaluacion)->format('d/m/Y H:i') }}
                        @if($evaluacion->observaciones) — {{ $evaluacion->observaciones }} @endif
                    </p>
                </div>
            @elseif($panelActivo === 'certificacion' && !empty($puedeCertificar))
            <p class="small text-muted mb-3">
                Registre el resultado del control de calidad. Solo los lotes <strong>conformes</strong> pueden pasar a almacenaje.
            </p>
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <form action="{{ route('procesamiento.certificar', $lote) }}" method="POST" class="border rounded p-3 h-100" style="background:#f0fdf4;border-color:#bbf7d0!important">
                        @csrf
                        <input type="hidden" name="razon" value="{{ \App\Models\EvaluacionFinalLoteProduccion::RAZON_CERTIFICADO }}">
                        <h6 class="font-weight-bold text-success mb-2"><i class="fas fa-check-circle mr-1"></i>Conforme</h6>
                        <input type="text" name="observaciones" class="form-control form-control-sm mb-2" maxlength="500" placeholder="Observación (opcional)">
                        <button type="submit" class="btn btn-success btn-sm font-weight-bold">
                            <i class="fas fa-stamp mr-1"></i>Registrar conforme
                        </button>
                    </form>
                </div>
                <div class="col-lg-6 mb-3">
                    <form action="{{ route('procesamiento.certificar', $lote) }}" method="POST" class="border rounded p-3 h-100" style="background:#fffbeb;border-color:#fde68a!important" onsubmit="return confirm('¿Marcar este lote como no conforme? No podrá almacenarse.');">
                        @csrf
                        <input type="hidden" name="razon" value="{{ \App\Models\EvaluacionFinalLoteProduccion::RAZON_NO_CONFORME }}">
                        <h6 class="font-weight-bold text-warning mb-2"><i class="fas fa-times-circle mr-1"></i>No conforme</h6>
                        <input type="text" name="observaciones" class="form-control form-control-sm mb-2" maxlength="500" placeholder="Motivo obligatorio: daños, calidad…" required>
                        <input type="text" name="recomendaciones" class="form-control form-control-sm mb-2" maxlength="2000" placeholder="Recomendaciones (opcional)">
                        <button type="submit" class="btn btn-warning btn-sm font-weight-bold text-dark">
                            <i class="fas fa-ban mr-1"></i>Registrar no conforme
                        </button>
                    </form>
                </div>
            </div>
            @elseif($panelActivo === 'certificacion')
            <div class="alert alert-light border small mb-0">
                <i class="fas fa-info-circle mr-1 text-muted"></i>
                La transformación finalizó. El jefe de planta o administración debe registrar la certificación (conforme o no conforme).
            </div>
            @endif
        </div>
    </div>
    @endif

    @if($mostrarAlmacenaje)
    <div class="card lp-fase-card mb-3 lp-fase-panel {{ $panelActivo === 'almacenaje' ? 'lp-fase-panel--activa' : 'lp-fase-panel--historial' }}" data-fase="almacenaje">
        <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center">
            <span><i class="fas fa-warehouse text-warning mr-2"></i>Almacenaje del producto terminado</span>
            @if($panelActivo !== 'almacenaje' && $almacenaje)
                <span class="lp-historial-badge"><i class="fas fa-check mr-1"></i>Fase completada</span>
            @endif
        </div>
        <div class="card-body {{ $panelActivo === 'almacenaje' && !$almacenaje ? 'p-0' : '' }}">
            @if($almacenaje && $panelActivo !== 'almacenaje')
                <div class="lp-historial-readonly">
                    <p class="mb-1"><strong>{{ $almacenaje->ubicacion }}</strong></p>
                    <p class="small text-muted mb-0">
                        {{ \App\Support\ProductoPlantaCatalogo::formatearCantidadAlmacenaje((float) ($cantidadAlmacenajeMostrar ?? $almacenaje->cantidad), $lote) }} {{ $unidadProductoAlmacen ?? 'kg' }}
                        · {{ $almacenaje->condicion }}
                        · {{ optional($almacenaje->fecha_almacenaje)->format('d/m/Y H:i') }}
                    </p>
                </div>
            @elseif($panelActivo === 'almacenaje')
            @if(!empty($produccionEstimada['entrada_kg']))
            <div class="alert alert-light border small mx-3 mt-3 mb-0">
                <i class="fas fa-calculator text-success mr-1"></i>
                <strong>Producción calculada</strong>
                @if(!empty($produccionEstimada['empaque']))
                    · {{ $produccionEstimada['empaque'] }}
                @endif
                <br>
                {{ number_format($produccionEstimada['entrada_kg'], 2) }} kg de materia prima
                × {{ number_format($produccionEstimada['rendimiento'] * 100, 0) }}&nbsp;% rendimiento
                → <strong>{{ number_format($produccionEstimada['cantidad'], 0) }} {{ $produccionEstimada['unidad'] }}</strong>
                (~{{ number_format($produccionEstimada['kg'], 2) }} kg).
            </div>
            @endif
            <form method="POST" action="{{ route('procesamiento.almacenar', $lote) }}" id="formAlmacenajeLote" class="p-3">
                @csrf
                @push('almacen-envio-extra-almacenSectionLote')
                <div class="almacen-section-extra">
                    <h6 class="small font-weight-bold text-success mb-3 mb-md-2">
                        <i class="fas fa-thermometer-half mr-1"></i> Detalles del ingreso
                    </h6>
                    <div class="form-row">
                        <div class="col-md-6 form-group mb-md-0">
                            <label class="small font-weight-bold">Condición de conservación <span class="text-danger">*</span></label>
                            <select name="condicion" class="form-control form-control-sm" required>
                                <option value="">Seleccionar condición…</option>
                                @foreach($condicionesAlmacenaje ?? [] as $cond)
                                    <option value="{{ $cond }}" @selected($cond === 'A temperatura controlada (2–8 °C)')>{{ $cond }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-0">
                            <label class="small font-weight-bold">Observaciones <span class="text-muted font-weight-normal">(opcional)</span></label>
                            <input type="text" name="observaciones" class="form-control form-control-sm" maxlength="500" placeholder="Ej. Estante A — cámara 2" value="{{ old('observaciones') }}">
                        </div>
                    </div>
                </div>
                <div class="almacen-section-actions">
                    <button type="submit" class="btn btn-warning btn-sm font-weight-bold text-dark">
                        <i class="fas fa-warehouse mr-1"></i>Registrar almacenaje
                    </button>
                </div>
                @endpush
                @include('partials.almacen-envio-selector', [
                    'almacenes' => $almacenesMasUsados ?? $almacenesPlanta ?? collect(),
                    'almacenesMasUsados' => $almacenesMasUsados ?? $almacenesPlanta ?? collect(),
                    'almacenesMenosUsados' => $almacenesMenosUsados ?? collect(),
                    'almacenesTodos' => $almacenesTodos ?? $almacenesPlanta ?? collect(),
                    'resumenesCapacidad' => ! empty($resumenesCapacidad) ? $resumenesCapacidad : ($resumenesCapacidadPlanta ?? []),
                    'sectionId' => 'almacenSectionLote',
                    'hiddenInputId' => 'almacenidLote',
                    'selectedAlmacenId' => old('almacenid'),
                    'etiquetaAmbito' => 'de planta',
                    'almacenRequerido' => true,
                    'modoPreview' => true,
                    'guiaTexto' => 'Toda la producción terminada del lote debe ingresar al inventario del almacén elegido. El sistema valida la capacidad disponible y puede sugerir un almacén según el producto.',
                    'instruccion' => 'Seleccione el almacén de planta donde guardar el producto terminado',
                    'crearAlmacenUrl' => route('almacen-planta.create'),
                    'emptyTexto' => 'No hay almacenes de planta registrados.',
                    'productoResumen' => trim($lote->nombre . ($lote->producto ? ' ('.$lote->producto.')' : '')),
                    'cantidadResumen' => number_format((float) ($cantidadProductoAlmacen ?? 0), 0).' '.($unidadProductoAlmacen ?? 'kg').' (~'.number_format((float) ($cantidadProductoAlmacenKg ?? 0), 2).' kg según materia prima)',
                ])
            </form>
            @endif
        </div>
    </div>
    @endif

    </div>{{-- #lp-fases-workspace --}}

    <div class="row mb-3">
        <div class="col-12 mb-2">
            <h5 class="font-weight-bold text-success mb-0"><i class="fas fa-clipboard-list mr-2"></i>Resumen del lote</h5>
            <p class="small text-muted mb-0">Materias consumidas, certificación y almacenaje registrados</p>
        </div>
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="lp-resumen-card lp-resumen-mp">
                <div class="lp-resumen-head"><i class="fas fa-boxes"></i> Materias primas usadas</div>
                <div class="lp-resumen-body">
                    @forelse($lote->materiasPrimas as $mp)
                        <div class="lp-resumen-item">
                            <span class="nombre">{{ $mp->insumo?->nombre ?? 'Materia prima' }}</span>
                            <span class="dato">
                                {{ number_format((float) $mp->cantidad_usada, 2) }}
                                <small class="text-muted font-weight-normal">{{ $mp->insumo?->unidadMedida?->abreviatura ?? $mp->insumo?->unidadMedida?->nombre ?? 'ud' }}</small>
                            </span>
                        </div>
                    @empty
                        <p class="lp-resumen-empty mb-0"><i class="fas fa-inbox d-block mb-2 opacity-25"></i>Sin materias registradas</p>
                    @endforelse
                    @php $estimadosMp = \App\Support\ProductoPlantaCatalogo::estimadosUnidadesMateriaPrimaUsadas($lote, app(\App\Services\AlmacenCapacidadService::class)); @endphp
                    @foreach($estimadosMp as $estMp)
                        <p class="small text-success font-weight-bold mb-0 mt-2 border-top pt-2">
                            <i class="fas fa-seedling mr-1"></i>Cantidad estimada usada: {{ number_format($estMp['cantidad'], 0) }} {{ $estMp['etiqueta'] }}
                        </p>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="lp-resumen-card lp-resumen-cert">
                <div class="lp-resumen-head"><i class="fas fa-certificate"></i> Certificación</div>
                <div class="lp-resumen-body">
                    @if($evaluacion)
                        <div class="text-center py-1">
                            <span class="lp-resumen-badge badge-{{ $evaluacion->razon === 'Certificado' ? 'success' : 'warning' }} mb-2 d-inline-block">
                                {{ $evaluacion->razon }}
                            </span>
                            <small class="text-muted d-block">
                                <i class="far fa-clock mr-1"></i>{{ optional($evaluacion->fecha_evaluacion)->format('d/m/Y H:i') }}
                            </small>
                            @if($evaluacion->observaciones)
                                <p class="small text-secondary mt-2 mb-0 text-left border-top pt-2">{{ $evaluacion->observaciones }}</p>
                            @endif
                        </div>
                    @else
                        <p class="lp-resumen-empty mb-0">
                            <i class="fas fa-hourglass-half d-block mb-2"></i>
                            Pendiente de evaluación final
                        </p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="lp-resumen-card lp-resumen-alm">
                <div class="lp-resumen-head"><i class="fas fa-warehouse"></i> Almacenaje</div>
                <div class="lp-resumen-body">
                    @if(!empty($lote_rechazado))
                        <p class="lp-resumen-empty mb-0">
                            <i class="fas fa-ban d-block mb-2 text-warning"></i>
                            Sin ingreso a almacén<br><small class="text-muted">Lote no conforme</small>
                        </p>
                    @elseif($almacenaje)
                        <div class="lp-resumen-item">
                            <span class="nombre"><i class="fas fa-map-marker-alt text-warning mr-1"></i>{{ $almacenaje->ubicacion }}</span>
                        </div>
                        <div class="lp-resumen-item">
                            <span class="nombre">Producto almacenado</span>
                            <span class="dato">{{ \App\Support\ProductoPlantaCatalogo::formatearCantidadAlmacenaje((float) ($cantidadAlmacenajeMostrar ?? $almacenaje->cantidad), $lote) }} {{ $unidadProductoAlmacen ?? 'kg' }}</span>
                        </div>
                        <div class="lp-resumen-item">
                            <span class="nombre">Condición</span>
                            <span class="dato" style="white-space:normal;font-size:.8rem">{{ $almacenaje->condicion }}</span>
                        </div>
                        <small class="text-muted d-block mt-2 text-center">
                            <i class="far fa-clock mr-1"></i>{{ optional($almacenaje->fecha_almacenaje)->format('d/m/Y H:i') }}
                        </small>
                        @if($almacenaje->almacenid)
                        <a href="{{ route('almacen-planta.show', $almacenaje->almacenid) }}" class="btn btn-outline-warning btn-sm btn-block mt-3 font-weight-bold">
                            <i class="fas fa-external-link-alt mr-1"></i>Ir al almacén
                        </a>
                        @endif
                    @else
                        <p class="lp-resumen-empty mb-0">
                            <i class="fas fa-warehouse d-block mb-2"></i>
                            Sin ingreso a almacén
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@include('partials.modal-confirmar-accion')
@include('partials.modal-maquinaria-etapa')
@endsection

@push('scripts')
@if($panelActivo === 'almacenaje' && empty($almacenaje))
    @include('partials.almacen-envio-scripts', [
        'sectionId' => 'almacenSectionLote',
        'hiddenInputId' => 'almacenidLote',
        'formSelector' => '#formAlmacenajeLote',
        'almacenesCatalogo' => $almacenesCatalogo ?? [],
        'cantidadFija' => $cantidadProductoAlmacenKg ?? 0,
        'productoHint' => trim(($lote->producto ?? '').' '.($lote->nombre ?? '')),
    ])
@endif
<script>
(function () {
    const mapa = @json($mapaCompatibilidad ?? ['proceso_maquina' => [], 'maquina_proceso' => []]);
    const selectProceso = document.getElementById('selectProcesoEtapa');
    const inputMaquina = document.getElementById('inputMaquinaEtapa');
    const labelMaquina = document.getElementById('labelMaquinaEtapa');
    const btnMaquina = document.getElementById('btnAbrirMaquinariaEtapa');
    const hintMaquina = document.getElementById('hintMaquinaEtapa');
    const hintOperador = document.getElementById('hintOperadorEtapa');
    const wrapOperario = document.getElementById('selector_wrap_etapa_operario');
    const maquinaEtapaEmpty = document.getElementById('maquinaEtapaEmpty');
    const maquinaEtapaFilled = document.getElementById('maquinaEtapaFilled');
    const imgMaquinaSel = document.getElementById('imgMaquinaEtapaSel');
    const txtMaquinaSel = document.getElementById('txtMaquinaEtapaSel');
    const btnCambiarMaquina = document.getElementById('btnCambiarMaquinaEtapa');
    const endpointMaquinas = @json(route('catalogo-selector.maquinas-planta'));
    const modalMaquina = document.getElementById('modalMaquinariaEtapa');
    const gridMaquina = document.getElementById('modalMaquinariaGrid');
    const vacioMaquina = document.getElementById('modalMaquinariaVacio');
    const buscarMaquina = document.getElementById('modalMaquinariaBuscar');
    const resMaquina = document.getElementById('modalMaquinariaResultados');
    let maquinasCache = [];
    let debounceMaq = null;

    if (!selectProceso || !inputMaquina) return;

    function limpiarMaquina() {
        inputMaquina.value = '';
        if (labelMaquina) {
            labelMaquina.value = '';
            labelMaquina.placeholder = selectProceso.value ? 'Clic en Elegir…' : 'Primero elija un proceso…';
        }
        if (maquinaEtapaEmpty) maquinaEtapaEmpty.classList.remove('d-none');
        if (maquinaEtapaFilled) maquinaEtapaFilled.classList.add('d-none');
        if (imgMaquinaSel) imgMaquinaSel.removeAttribute('src');
        if (txtMaquinaSel) txtMaquinaSel.textContent = '';
        if (btnMaquina) btnMaquina.disabled = !selectProceso.value;
        bloquearOperario(true);
    }

    function mostrarMaquinaElegida(item) {
        const extra = item.extra || {};
        const etiqueta = item.label + (extra.codigo ? ' (' + extra.codigo + ')' : '');
        if (labelMaquina) labelMaquina.value = etiqueta;
        if (txtMaquinaSel) txtMaquinaSel.textContent = etiqueta;
        if (imgMaquinaSel) {
            if (extra.imagen) {
                imgMaquinaSel.src = extra.imagen;
                imgMaquinaSel.alt = item.label;
            } else {
                imgMaquinaSel.removeAttribute('src');
            }
        }
        if (maquinaEtapaEmpty) maquinaEtapaEmpty.classList.add('d-none');
        if (maquinaEtapaFilled) maquinaEtapaFilled.classList.remove('d-none');
    }

    function bloquearOperario(bloquear) {
        if (!wrapOperario) return;
        wrapOperario.classList.toggle('is-bloqueado', bloquear);
        if (bloquear && window.CatalogoSelector) {
            CatalogoSelector.clear('etapa_operario');
        }
        if (hintOperador) {
            hintOperador.textContent = bloquear
                ? 'Primero elija maquinaria…'
                : 'El operario recibirá una alerta en su panel.';
        }
    }

    function seleccionarMaquina(item) {
        inputMaquina.value = item.id;
        inputMaquina.dispatchEvent(new Event('change'));
        mostrarMaquinaElegida(item);
        bloquearOperario(false);
        if (window.jQuery && modalMaquina) window.jQuery(modalMaquina).modal('hide');
    }

    function renderMaquinas(items) {
        if (!gridMaquina) return;
        gridMaquina.innerHTML = '';
        const seleccionado = inputMaquina.value;
        if (!items.length) {
            vacioMaquina?.classList.remove('d-none');
            if (resMaquina) resMaquina.textContent = '0 equipos encontrados';
            return;
        }
        vacioMaquina?.classList.add('d-none');
        if (resMaquina) resMaquina.textContent = items.length + ' equipo(s) encontrado(s)';
        items.forEach(function (item) {
            const col = document.createElement('div');
            col.className = 'col-md-4 col-sm-6 mb-3';
            const extra = item.extra || {};
            const imgHtml = extra.imagen
                ? '<img src="' + extra.imagen + '" alt="' + (item.label || 'Equipo') + '" loading="lazy" decoding="async">'
                : '<i class="fas fa-cogs"></i>';
            col.innerHTML =
                '<div class="maq-etapa-card' + (String(seleccionado) === String(item.id) ? ' is-selected' : '') + '" data-id="' + item.id + '">' +
                    '<div class="maq-etapa-card__img">' + imgHtml + '</div>' +
                    '<div class="maq-etapa-card__body">' +
                        '<div class="maq-etapa-card__nombre">' + item.label + '</div>' +
                        '<div class="maq-etapa-card__meta">' + (extra.codigo || item.meta || 'Equipo de planta') + '</div>' +
                        '<div class="maq-etapa-card__desc">' + (extra.descripcion || '') + '</div>' +
                    '</div>' +
                '</div>';
            col.querySelector('.maq-etapa-card').addEventListener('click', function () {
                seleccionarMaquina(item);
            });
            gridMaquina.appendChild(col);
        });
    }

    function cargarMaquinas() {
        const procesoId = selectProceso.value;
        if (!procesoId) {
            maquinasCache = [];
            renderMaquinas([]);
            return;
        }
        const q = buscarMaquina ? buscarMaquina.value.trim() : '';
        const params = new URLSearchParams({ per_page: '50', page: '1', activo: '1', procesoplantaid: procesoId });
        if (q) params.set('q', q);
        if (gridMaquina) gridMaquina.innerHTML = '<div class="col-12 text-muted small py-3"><i class="fas fa-spinner fa-spin mr-1"></i> Cargando…</div>';
        fetch(endpointMaquinas + '?' + params.toString(), { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                maquinasCache = json.data || [];
                renderMaquinas(maquinasCache);
            })
            .catch(function () {
                if (gridMaquina) gridMaquina.innerHTML = '<div class="col-12 text-danger small">No se pudo cargar la maquinaria.</div>';
            });
    }

    function actualizarEstadoProceso() {
        const procesoId = selectProceso.value;
        limpiarMaquina();
        if (!procesoId) {
            if (hintMaquina) hintMaquina.textContent = 'Solo equipos compatibles con el proceso.';
            return;
        }
        const permitidas = (mapa.proceso_maquina && mapa.proceso_maquina[procesoId]) ? mapa.proceso_maquina[procesoId].length : 0;
        if (btnMaquina) btnMaquina.disabled = permitidas === 0;
        if (hintMaquina) {
            hintMaquina.textContent = permitidas
                ? permitidas + ' equipo(s) compatible(s) con este proceso.'
                : 'Configure equipos en catálogo o ejecute el seeder MaquinasProcesoPlantaSeeder.';
        }
    }

    selectProceso.addEventListener('change', actualizarEstadoProceso);
    btnMaquina?.addEventListener('click', function () {
        if (!selectProceso.value) return;
        if (window.jQuery && modalMaquina) {
            window.jQuery(modalMaquina).modal('show');
            cargarMaquinas();
        }
    });
    btnCambiarMaquina?.addEventListener('click', function () {
        if (!selectProceso.value) return;
        if (window.jQuery && modalMaquina) {
            window.jQuery(modalMaquina).modal('show');
            cargarMaquinas();
        }
    });
    buscarMaquina?.addEventListener('input', function () {
        clearTimeout(debounceMaq);
        debounceMaq = setTimeout(cargarMaquinas, 320);
    });

    bloquearOperario(true);
    actualizarEstadoProceso();

    @php
        $sugeridoPasoJs = $siguientePasoPlantilla ? [
            'procesoplantaid' => $siguientePasoPlantilla->procesoplantaid,
            'proceso_nombre' => $siguientePasoPlantilla->proceso?->nombre,
            'maquinaplantaid' => $siguientePasoPlantilla->maquinaplantaid,
            'maquina_nombre' => $siguientePasoPlantilla->maquina?->nombre,
            'maquina_codigo' => $siguientePasoPlantilla->maquina?->codigo,
            'notas' => $siguientePasoPlantilla->notas,
        ] : null;
    @endphp
    const sugerido = @json($sugeridoPasoJs);
    let parametrosPreviewBase = @json($parametrosPreviewEtapa ?? []);
    const urlVariablesMaquinaPreview = @json(url('/maquinas-planta/__ID__/variables-sugeridas'));

    function fmtRango(n) {
        const v = Number(n);
        return Number.isInteger(v) ? String(v) : v.toFixed(1);
    }

    function renderPreviewParams(params) {
        const box = document.getElementById('lpPreviewParamsAsignar');
        const body = document.getElementById('lpPreviewParamsAsignarBody');
        if (!box || !body) return;
        if (!params || !params.length) {
            box.style.display = 'none';
            body.innerHTML = '';
            return;
        }
        let html = '';
        params.forEach(function (p) {
            const unidad = p.unidad ? ' ' + p.unidad : '';
            html += '<div class="lp-preview-params__card">' +
                '<span class="lp-preview-params__card-label">' + (p.nombre || '—') + '</span>' +
                '<span class="lp-preview-params__card-range">Entre <strong>' + fmtRango(p.valor_minimo) + '</strong> y <strong>' + fmtRango(p.valor_maximo) + '</strong>' + unidad + '</span>' +
                '</div>';
        });
        body.innerHTML = html;
        box.style.display = 'block';
    }

    function syncSelectProcesoEtapa(procesoId, procesoNombre) {
        if (!selectProceso || !procesoId) return;
        const id = String(procesoId);
        selectProceso.innerHTML = '';
        const vacio = document.createElement('option');
        vacio.value = '';
        vacio.textContent = 'Seleccionar…';
        selectProceso.appendChild(vacio);
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = procesoNombre || 'Proceso';
        selectProceso.appendChild(opt);
        selectProceso.value = id;
    }

    window.LpActualizarEtapaAsignar = function (datos) {
        if (!datos) return;

        const titulo = document.getElementById('lp-titulo-asignar-etapa');
        if (titulo) {
            titulo.innerHTML = '<i class="fas fa-user-plus mr-1"></i> Asignar etapa ' + (datos.orden || 1);
        }

        const meta = document.getElementById('lp-toolbar-etapa-meta');
        if (meta) {
            const total = datos.total_etapas || '—';
            const proc = datos.proceso_nombre ? ' · Siguiente: <strong>' + datos.proceso_nombre + '</strong>' : '';
            meta.innerHTML = 'Etapa ' + (datos.orden || 1) + ' de ' + total + proc;
        }

        const hint = document.getElementById('lp-hint-proceso-etapa');
        if (hint && datos.proceso_nombre) {
            hint.innerHTML = '<span class="text-success"><i class="fas fa-magic mr-1"></i>Etapa ' + datos.orden + ': ' + datos.proceso_nombre + '</span>';
        }

        if (datos.procesoplantaid) {
            syncSelectProcesoEtapa(datos.procesoplantaid, datos.proceso_nombre);
            actualizarEstadoProceso();
        }

        if (datos.maquinaplantaid && inputMaquina) {
            inputMaquina.value = String(datos.maquinaplantaid);
            inputMaquina.dispatchEvent(new Event('change'));
            if (labelMaquina) {
                labelMaquina.value = (datos.maquina_nombre || 'Maquinaria sugerida')
                    + (datos.maquina_codigo ? ' (' + datos.maquina_codigo + ')' : '');
            }
            bloquearOperario(false);
        }

        const obs = document.querySelector('#formAsignarEtapa input[name="observaciones"]');
        if (obs && datos.notas) {
            obs.value = datos.notas;
        }

        parametrosPreviewBase = datos.parametros_preview || [];
        actualizarPreviewParams(datos.maquinaplantaid || null);
    };

    function actualizarPreviewParams(maquinaId) {
        if (!maquinaId) {
            renderPreviewParams(parametrosPreviewBase);
            return;
        }
        const url = urlVariablesMaquinaPreview.replace('__ID__', String(maquinaId));
        fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!parametrosPreviewBase.length) {
                    const desdeMaq = (data.variables || []).map(function (v) {
                        return {
                            nombre: v.nombre,
                            unidad: v.unidad,
                            valor_minimo: parseFloat(v.valor_minimo || 0),
                            valor_maximo: parseFloat(v.valor_maximo || 0),
                            maq_minimo: parseFloat(v.valor_minimo || 0),
                            maq_maximo: parseFloat(v.valor_maximo || 0),
                        };
                    });
                    renderPreviewParams(desdeMaq);
                    return;
                }
                const mapaMaq = {};
                (data.variables || []).forEach(function (v) {
                    mapaMaq[v.variableestandarid] = v;
                });
                const merged = parametrosPreviewBase.map(function (p) {
                    const m = mapaMaq[p.variableestandarid];
                    return Object.assign({}, p, {
                        maq_minimo: m ? parseFloat(m.valor_minimo) : p.maq_minimo,
                        maq_maximo: m ? parseFloat(m.valor_maximo) : p.maq_maximo,
                    });
                });
                renderPreviewParams(merged);
            })
            .catch(function () { renderPreviewParams(parametrosPreviewBase); });
    }

    if (sugerido && sugerido.procesoplantaid) {
        syncSelectProcesoEtapa(sugerido.procesoplantaid, sugerido.proceso_nombre);
        actualizarEstadoProceso();
        if (sugerido.maquinaplantaid) {
            inputMaquina.value = String(sugerido.maquinaplantaid);
            inputMaquina.dispatchEvent(new Event('change'));
            if (labelMaquina) {
                labelMaquina.value = (sugerido.maquina_nombre || 'Maquinaria sugerida')
                    + (sugerido.maquina_codigo ? ' (' + sugerido.maquina_codigo + ')' : '');
            }
            bloquearOperario(false);
        }
        const obs = document.querySelector('#formAsignarEtapa input[name="observaciones"]');
        if (obs && sugerido.notas && !obs.value) obs.value = sugerido.notas;
        actualizarPreviewParams(sugerido.maquinaplantaid || null);
    } else {
        actualizarPreviewParams(null);
    }

    const inputMaquinaObs = document.getElementById('inputMaquinaEtapa');
    if (inputMaquinaObs) {
        inputMaquinaObs.addEventListener('change', function () {
            actualizarPreviewParams(inputMaquinaObs.value ? parseInt(inputMaquinaObs.value, 10) : null);
        });
    }
})();

(function () {
    const btn = document.getElementById('btnVerTodasFases');
    const workspace = document.getElementById('lp-fases-workspace');
    if (!btn || !workspace) return;

    btn.addEventListener('click', function () {
        const expandido = workspace.classList.toggle('lp-modo-todas-fases');
        btn.setAttribute('aria-expanded', expandido ? 'true' : 'false');
        const label = btn.querySelector('.btn-label');
        if (label) {
            label.textContent = expandido ? 'Ver solo fase actual' : 'Ver todas las fases';
        }
        btn.classList.toggle('btn-success', expandido);
        btn.classList.toggle('btn-outline-success', !expandido);
    });
})();

(function () {
    const workspace = document.getElementById('lp-fases-workspace');
    const btnVerTodas = document.getElementById('btnVerTodasFases');

    function irASeccionFase(fase) {
        if (!fase) return;

        if (workspace && !workspace.classList.contains('lp-modo-todas-fases')) {
            workspace.classList.add('lp-modo-todas-fases');
            if (btnVerTodas) {
                btnVerTodas.setAttribute('aria-expanded', 'true');
                const label = btnVerTodas.querySelector('.btn-label');
                if (label) label.textContent = 'Ver solo fase actual';
                btnVerTodas.classList.add('btn-success');
                btnVerTodas.classList.remove('btn-outline-success');
            }
        }

        const panel = document.querySelector('.lp-fase-panel[data-fase="' + fase + '"]');
        const destino = fase === 'transformacion'
            ? (document.getElementById('lp-form-registrar-etapa') || panel)
            : panel;

        if (destino) {
            setTimeout(function () {
                destino.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 80);
        }
    }

    document.querySelectorAll('[data-fase-scroll]').forEach(function (step) {
        step.addEventListener('click', function () {
            irASeccionFase(step.dataset.faseScroll);
        });
        step.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                irASeccionFase(step.dataset.faseScroll);
            }
        });
    });

    if (window.location.hash === '#transformacion') {
        irASeccionFase('transformacion');
    }

    const scrollKey = 'lp_procesamiento_scroll';

    function guardarScrollProcesamiento() {
        try {
            sessionStorage.setItem(scrollKey, String(window.scrollY));
        } catch (err) {}
    }

    document.querySelectorAll('.js-lp-guardar-scroll').forEach(function (form) {
        form.addEventListener('submit', guardarScrollProcesamiento);
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-confirm-modal]');
        if (!btn || !btn.closest('.js-lp-guardar-scroll')) return;
        guardarScrollProcesamiento();
    }, true);
})();
</script>
<script>
(function () {
    function escHtml(text) {
        const d = document.createElement('div');
        d.textContent = text == null ? '' : String(text);
        return d.innerHTML;
    }

    function mostrarFlashAjax(mensaje, tipo) {
        const cont = document.getElementById('lp-ajax-flash');
        if (!cont) return;
        const icon = tipo === 'error' ? 'fa-ban' : 'fa-check';
        const clase = tipo === 'error' ? 'ag-flash--error' : 'ag-flash--success';
        cont.className = '';
        cont.innerHTML =
            '<div class="ag-flash ' + clase + ' mb-3" role="status">' +
                '<span class="ag-flash__icon"><i class="fas ' + icon + '"></i></span>' +
                '<span class="ag-flash__text">' + escHtml(mensaje) + '</span>' +
                '<button type="button" class="ag-flash__close" aria-label="Cerrar"><i class="fas fa-times"></i></button>' +
            '</div>';
        cont.classList.remove('d-none');
        cont.querySelector('.ag-flash__close')?.addEventListener('click', function () {
            cont.classList.add('d-none');
            cont.innerHTML = '';
        });
    }

    function htmlEtapaCompletada(etapa) {
        if (!etapa) return '';
        const cierre = etapa.es_cierre ? '<span class="badge badge-info ml-1">Cierre</span>' : '';
        const obs = etapa.observaciones
            ? '<br><small class="text-secondary">' + escHtml(etapa.observaciones) + '</small>' : '';
        const operador = etapa.operador ? ' · ' + escHtml(etapa.operador) : '';
        let params = '';
        if (Array.isArray(etapa.parametros_medidos) && etapa.parametros_medidos.length) {
            params = '<div class="mt-1">';
            etapa.parametros_medidos.forEach(function (pm) {
                const unidad = pm.unidad ? ' ' + escHtml(pm.unidad) : '';
                params += '<span class="badge badge-light border text-success mr-1">' +
                    escHtml(pm.nombre) + ': ' + escHtml(Number(pm.valor).toFixed(1)) + unidad + '</span>';
            });
            params += '</div>';
        }

        return '<div class="lp-paso done lp-paso--entrada d-flex align-items-start">' +
            '<span class="lp-timeline-num">' + escHtml(etapa.numero) + '</span>' +
            '<div class="flex-grow-1"><strong>' + escHtml(etapa.proceso) + '</strong>' + cierre + '<br>' +
            '<small class="text-muted"><i class="fas fa-industry mr-1"></i>' + escHtml(etapa.maquina) +
            ' · <i class="far fa-clock mr-1"></i>' + escHtml(etapa.inicio_fmt) + ' → ' + escHtml(etapa.fin_fmt) +
            operador + '</small>' + obs + params + '</div>' +
            '<span class="badge badge-success"><i class="fas fa-check"></i></span></div>';
    }

    function claseRutaPaso(estado) {
        if (estado === 'hecho') return 'lp-stepper__item--hecho';
        if (estado === 'actual') return 'lp-stepper__item--actual';
        if (estado === 'en_curso') return 'lp-stepper__item--en-curso';
        return 'lp-stepper__item--bloqueado';
    }

    function htmlRutaPaso(paso, idx, prevEstado) {
        const itemClass = claseRutaPaso(paso.estado);
        let connector = '';
        if (idx > 0) {
            const connClass = paso.estado === 'hecho' || prevEstado === 'hecho' ? 'lp-stepper__connector--hecho' : '';
            connector = '<div class="lp-stepper__connector ' + connClass + '"></div>';
        }
        const circle = paso.estado === 'hecho'
            ? '<i class="fas fa-check"></i>'
            : escHtml(paso.orden);
        let lock = '';
        if (paso.estado === 'bloqueado') {
            lock = '<span class="lp-stepper__lock"><i class="fas fa-lock"></i> Etapa ' + Math.max(1, paso.orden - 1) + '</span>';
        }
        return connector +
            '<div class="lp-stepper__item ' + itemClass + '">' +
            '<div class="lp-stepper__circle">' + circle + '</div>' +
            '<div class="lp-stepper__label">' + escHtml(paso.proceso) + '</div>' + lock +
            '</div>';
    }

    window.LpPlanEtapas = {
        acotarInput: function (input) {
            const minL = parseFloat(input.dataset.rangoMin);
            const maxL = parseFloat(input.dataset.rangoMax);
            if (isNaN(minL) || isNaN(maxL)) return;
            let v = parseFloat(input.value);
            if (isNaN(v)) return;
            if (v < minL) input.value = minL;
            if (v > maxL) input.value = maxL;
        },
        operarioSeleccionadoEnCard: function (card) {
            const hidden = card.querySelector('.selector-catalogo-value, input[name*="operador_usuarioid"]');
            return hidden && String(hidden.value || '').trim() !== '';
        },
        actualizarBotones: function () {
            const form = document.getElementById('formAsignarPlanEtapas');
            const wrapCerrarTodos = document.getElementById('lp-cerrar-todos-wrap');
            if (!form) return;
            const cards = form.querySelectorAll('[data-plan-etapa="1"]');
            if (wrapCerrarTodos) {
                if (!cards.length) {
                    wrapCerrarTodos.classList.add('d-none');
                } else {
                    let todas = true;
                    cards.forEach(function (card) {
                        if (!window.LpPlanEtapas.operarioSeleccionadoEnCard(card)) todas = false;
                    });
                    wrapCerrarTodos.classList.toggle('d-none', !todas);
                }
            }
            cards.forEach(function (card) {
                const btn = card.querySelector('.js-cerrar-fase-single');
                if (!btn) return;
                const ok = window.LpPlanEtapas.operarioSeleccionadoEnCard(card);
                btn.disabled = !ok;
                btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
                btn.title = ok ? '' : 'Seleccione un operario para cerrar la fase';
            });
        },
        refresh: function () {
            window.LpPlanEtapas.actualizarBotones();
        },
        init: function () {
            const form = document.getElementById('formAsignarPlanEtapas');
            if (!form || form.dataset.lpPlanBound === '1') return;
            form.dataset.lpPlanBound = '1';
            form.addEventListener('focusout', function (e) {
                const inp = e.target;
                if (!inp.classList) return;
                if (!inp.classList.contains('tl-plan-var-min') && !inp.classList.contains('tl-plan-var-max')) return;
                window.LpPlanEtapas.acotarInput(inp);
            });
            form.addEventListener('submit', function () {
                form.querySelectorAll('.tl-plan-var-min, .tl-plan-var-max').forEach(function (inp) {
                    window.LpPlanEtapas.acotarInput(inp);
                });
            });
            document.addEventListener('selector-catalogo:change', function () {
                window.LpPlanEtapas.actualizarBotones();
            });
            form.addEventListener('change', function () {
                window.LpPlanEtapas.actualizarBotones();
            });
            window.LpPlanEtapas.actualizarBotones();
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.LpPlanEtapas.init();
    });

    function postJsonForm(url, formData) {
        return fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        }).then(function (r) {
            return r.text().then(function (text) {
                let data = null;
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (parseErr) {
                    throw {
                        message: r.ok
                            ? 'Respuesta inválida del servidor.'
                            : 'Error del servidor (' + r.status + '). Recargue la página e intente de nuevo.',
                    };
                }
                if (!r.ok || !data || data.ok === false) {
                    throw { message: (data && data.message) || 'No se pudo completar la operación.' };
                }
                return data;
            });
        });
    }

    function actualizarTimelineDesdeAjax(data) {
        const timeline = document.getElementById('lp-timeline-visual');
        if (timeline && data.timeline_html) {
            timeline.innerHTML = data.timeline_html;
            if (window.CatalogoSelector && typeof window.CatalogoSelector.registerAll === 'function') {
                window.CatalogoSelector.registerAll(timeline);
            }
            if (window.LpPlanEtapas) {
                window.LpPlanEtapas.refresh();
            }
            document.dispatchEvent(new CustomEvent('lp-timeline-updated'));
        }

        const toolbar = document.getElementById('lp-toolbar-etapa-meta');
        if (toolbar && data.toolbar_meta) {
            toolbar.innerHTML = data.toolbar_meta;
        }

        const ruta = document.getElementById('lp-ruta-plantilla-pasos');
        if (ruta && Array.isArray(data.ruta_plantilla)) {
            let html = '';
            data.ruta_plantilla.forEach(function (paso, idx) {
                const prev = idx > 0 ? data.ruta_plantilla[idx - 1].estado : '';
                html += htmlRutaPaso(paso, idx, prev);
            });
            ruta.innerHTML = html;
        }

        const formAsignar = document.getElementById('lp-form-registrar-etapa');
        if (formAsignar && data.puede_asignar_nueva) {
            formAsignar.classList.remove('d-none');
        }

        const bloqueo = document.getElementById('lp-bloqueo-asignacion');
        if (bloqueo) {
            if (data.mensaje_bloqueo) {
                bloqueo.classList.remove('d-none');
                bloqueo.innerHTML = '<i class="fas fa-lock mr-1"></i>' + escHtml(data.mensaje_bloqueo);
            } else {
                bloqueo.classList.add('d-none');
            }
        }

        const seccion = document.getElementById('lp-asignaciones-pendientes');
        if (seccion && (data.asignaciones_pendientes_count || 0) <= 0) {
            seccion.style.transition = 'opacity .35s ease';
            seccion.style.opacity = '0';
            setTimeout(function () { seccion.remove(); }, 350);
        }
    }

    function aplicarRespuestaAjaxProcesamiento(data, btn, card) {
        if (data.transformacion_completa) {
            window.location.assign(window.location.pathname + '#certificacion');
            window.location.reload();
            return;
        }

        if (data.timeline_html && document.getElementById('lp-timeline-visual')) {
            actualizarTimelineDesdeAjax(data);
            mostrarFlashAjax(data.message || 'Operación realizada correctamente.', 'success');
            return;
        }

        if (card) {
            card.style.transition = 'opacity .3s ease';
            card.style.opacity = '0';
            setTimeout(function () { card.remove(); }, 300);
        }
        const lista = document.getElementById('lp-lista-etapas-completadas');
        const vacio = document.getElementById('lp-etapas-vacio');
        if (vacio) vacio.remove();
        if (lista && data.etapa) lista.insertAdjacentHTML('beforeend', htmlEtapaCompletada(data.etapa));
        actualizarTimelineDesdeAjax(data);
        mostrarFlashAjax(data.message || 'Operación realizada correctamente.', 'success');
    }

    window.LpProcesamientoAjax = {
        cerrarFase: function (form, submitter) {
            const btn = submitter;
            if (btn) btn.classList.add('lp-ajax-loading');
            const url = btn ? (btn.getAttribute('formaction') || '') : '';
            const fd = new FormData(form);
            if (btn && btn.name) {
                fd.set(btn.name, btn.value || '');
            }
            postJsonForm(url, fd)
                .then(function (data) {
                    if (btn) btn.classList.remove('lp-ajax-loading');
                    aplicarRespuestaAjaxProcesamiento(data, btn, null);
                })
                .catch(function (err) {
                    if (btn) btn.classList.remove('lp-ajax-loading');
                    mostrarFlashAjax((err && err.message) || 'No se pudo cerrar la fase.', 'error');
                });
        },
        completarEtapa: function (form, triggerBtn) {
            const btn = triggerBtn || form.querySelector('button[type="submit"]');
            const card = btn
                ? btn.closest('[data-asignacion-id]')
                : form.closest('[data-asignacion-id]');
            if (btn) btn.classList.add('lp-ajax-loading');

            postJsonForm(form.action, new FormData(form))
                .then(function (data) {
                    if (btn) btn.classList.remove('lp-ajax-loading');
                    aplicarRespuestaAjaxProcesamiento(data, btn, card);
                })
                .catch(function (err) {
                    if (btn) btn.classList.remove('lp-ajax-loading');
                    mostrarFlashAjax((err && err.message) || 'No se pudo completar la etapa.', 'error');
                });
        },
    };

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !window.LpProcesamientoAjax) return;
        if (form.id === 'formAsignarPlanEtapas') {
            const submitter = e.submitter;
            if (submitter && submitter.classList.contains('js-cerrar-fase-single')) {
                e.preventDefault();
                window.LpProcesamientoAjax.cerrarFase(form, submitter);
                return;
            }
        }
        if (form.dataset.ajaxLpAction !== 'completar-etapa') return;
        e.preventDefault();
        window.LpProcesamientoAjax.completarEtapa(form);
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-tl-marcar-completada');
        if (!btn || btn.classList.contains('lp-ajax-loading') || !window.LpProcesamientoAjax) return;
        e.preventDefault();
        const form = document.createElement('form');
        form.action = btn.getAttribute('data-completar-url') || '';
        form.method = 'POST';
        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = btn.getAttribute('data-csrf') || '';
        form.appendChild(token);
        window.LpProcesamientoAjax.completarEtapa(form, btn);
    });
})();
</script>
@endpush
