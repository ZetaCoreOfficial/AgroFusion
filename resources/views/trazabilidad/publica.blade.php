@extends('layouts.public-trazabilidad')

@section('title', 'Trazabilidad — '.$reporte['producto'])

@push('styles')
<style>
.trz-public-header { text-align: center; margin-bottom: 1.5rem; padding-top: .5rem; }
.trz-public-header .brand-logo {
    width: 48px; height: 48px; margin: 0 auto .75rem; border-radius: 12px;
    background: linear-gradient(135deg, #059669, #10b981);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(5, 150, 105, .25);
}
.trz-public-header .brand-logo i { color: #fff; font-size: 1.2rem; }
.trz-public-header h1 { font-size: clamp(1.25rem, 4vw, 1.55rem); font-weight: 700; color: #0f172a; margin: 0 0 .35rem; }
.trz-cert-badge {
    display: inline-flex; align-items: center; gap: .4rem;
    margin: .5rem auto .65rem; padding: .4rem 1rem;
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 45%, #0f766e 100%);
    color: #f8fafc; border-radius: 999px; font-size: .72rem; font-weight: 800;
    letter-spacing: .06em; text-transform: uppercase;
    box-shadow: 0 6px 20px rgba(15, 23, 42, .22);
}
.trz-cert-badge i { color: #fde68a; font-size: .78rem; }
.trz-public-header .codigo {
    display: inline-block; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;
    border-radius: 999px; padding: .3rem .9rem; font-size: .75rem; font-weight: 700;
}

.trz-public-meta {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 1.15rem 1.25rem; margin-bottom: 1.25rem;
    display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 1.5rem;
}
@media (max-width: 520px) { .trz-public-meta { grid-template-columns: 1fr; } }
.trz-public-meta > div { display: flex; flex-direction: column; gap: .35rem; }
.trz-public-meta dt {
    font-size: .68rem; text-transform: uppercase; letter-spacing: .04em;
    color: #64748b; font-weight: 600; margin: 0;
}
.trz-public-meta dd { font-weight: 600; color: #1e293b; font-size: .9rem; margin: 0; line-height: 1.35; }

.trz-buscar-wrap { margin-bottom: 1.25rem; }
.trz-buscar-wrap .trz-buscar-label {
    font-size: .78rem; font-weight: 600; color: #64748b; margin-bottom: .5rem;
    display: block;
}
.trz-search-field { position: relative; }
.trz-search-field .search-icon {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: .95rem; pointer-events: none;
}
.trz-search-field input {
    width: 100%; border: 1px solid #cbd5e1; border-radius: 12px;
    padding: .75rem 1rem .75rem 2.65rem; font-size: .92rem;
    background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    transition: border-color .15s, box-shadow .15s;
}
.trz-search-field input:focus {
    outline: none; border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, .15);
}
.trz-buscar-hint {
    font-size: .72rem; color: #94a3b8; margin-top: .45rem;
}

.trz-cat-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    margin-bottom: .85rem; overflow: hidden;
    border-left: 4px solid var(--trz-mod-main, #059669);
}
.trz-cat-head {
    width: 100%; border: 0; background: var(--trz-mod-head-bg, #f8fafc); padding: .9rem 1.1rem;
    display: flex; align-items: center; justify-content: space-between; gap: .75rem;
    font-weight: 700; color: #1e293b; font-size: .9rem; cursor: pointer; text-align: left;
}
.trz-cat-head:hover { filter: brightness(.98); }
.trz-cat-head .cat-left {
    display: flex; align-items: center; gap: .85rem; min-width: 0;
}
.trz-cat-head .cat-icon {
    width: 36px; height: 36px; border-radius: 10px;
    background: var(--trz-mod-bg, #ecfdf5); color: var(--trz-mod-main, #059669);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: .95rem;
}
.trz-cat-head .badge-count {
    background: var(--trz-mod-bg, #dcfce7); color: var(--trz-mod-text, #166534);
    border-radius: 999px; font-size: .72rem; padding: .2rem .6rem; font-weight: 700; flex-shrink: 0;
}
.trz-mod--agricola {
    --trz-mod-main: #059669; --trz-mod-light: #10b981; --trz-mod-bg: #ecfdf5;
    --trz-mod-text: #047857; --trz-mod-head-bg: #f0fdf4;
    --trz-mod-line-a: #86efac; --trz-mod-line-b: #d1fae5;
}
.trz-mod--distribucion {
    --trz-mod-main: #2563eb; --trz-mod-light: #3b82f6; --trz-mod-bg: #eff6ff;
    --trz-mod-text: #1d4ed8; --trz-mod-head-bg: #f8fafc;
    --trz-mod-line-a: #93c5fd; --trz-mod-line-b: #dbeafe;
}
.trz-mod--planta {
    --trz-mod-main: #7c3aed; --trz-mod-light: #8b5cf6; --trz-mod-bg: #f5f3ff;
    --trz-mod-text: #6d28d9; --trz-mod-head-bg: #faf5ff;
    --trz-mod-line-a: #c4b5fd; --trz-mod-line-b: #ede9fe;
}
.trz-mod--mayorista {
    --trz-mod-main: #0d9488; --trz-mod-light: #14b8a6; --trz-mod-bg: #ecfdf5;
    --trz-mod-text: #0f766e; --trz-mod-head-bg: #f0fdfa;
    --trz-mod-line-a: #5eead4; --trz-mod-line-b: #ccfbf1;
}
.trz-mod--pdv {
    --trz-mod-main: #d97706; --trz-mod-light: #f59e0b; --trz-mod-bg: #fffbeb;
    --trz-mod-text: #b45309; --trz-mod-head-bg: #fffbeb;
    --trz-mod-line-a: #fcd34d; --trz-mod-line-b: #fef3c7;
}
.trz-cat-body { display: none; padding: .25rem 1rem 1.1rem; border-top: 1px solid #f1f5f9; }
.trz-cat-card.is-open .trz-cat-body { display: block; }

.trz-timeline { position: relative; padding-left: 0; }
.trz-timeline-item {
    display: flex; gap: .9rem; margin-bottom: 0; position: relative;
    padding-bottom: 1.1rem;
}
.trz-timeline-item:last-child { padding-bottom: 0; }
.trz-timeline-rail {
    display: flex; flex-direction: column; align-items: center; flex-shrink: 0;
    width: 36px; gap: .35rem; position: relative; z-index: 1;
}
.trz-timeline-item:not(:last-child)::before {
    content: ''; position: absolute; left: 17px; top: 36px; bottom: 0;
    width: 2px;
    background: linear-gradient(180deg, var(--trz-mod-line-a, #86efac) 0%, var(--trz-mod-line-b, #d1fae5) 100%);
}
.trz-timeline-marker {
    width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--trz-mod-main, #059669), var(--trz-mod-light, #10b981));
    color: #fff; font-size: .78rem; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px color-mix(in srgb, var(--trz-mod-main, #059669) 35%, transparent);
}
.trz-timeline-next {
    width: 28px; height: 28px; border-radius: 50%; border: 0; padding: 0;
    background: linear-gradient(135deg, var(--trz-mod-main, #059669), var(--trz-mod-light, #10b981));
    color: #fff; font-size: .72rem; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px color-mix(in srgb, var(--trz-mod-main, #059669) 35%, transparent);
    cursor: pointer; transition: transform .15s ease, box-shadow .15s ease;
    touch-action: manipulation; -webkit-tap-highlight-color: transparent;
}
.trz-timeline-next--salto {
    width: 22px; height: 22px; font-size: .58rem;
    background: linear-gradient(135deg, #64748b, #94a3b8);
    box-shadow: 0 1px 5px rgba(100, 116, 139, .35);
    border: 2px solid #fff;
}
.trz-timeline-next:hover,
.trz-timeline-next:focus {
    outline: none; transform: translateY(1px);
}
.trz-timeline-next:not(.trz-timeline-next--salto):hover,
.trz-timeline-next:not(.trz-timeline-next--salto):focus {
    box-shadow: 0 4px 12px color-mix(in srgb, var(--trz-mod-main, #059669) 45%, transparent);
}
.trz-timeline-next--salto:hover,
.trz-timeline-next--salto:focus {
    box-shadow: 0 3px 10px rgba(100, 116, 139, .45);
}
.trz-timeline-item.has-next,
.trz-timeline-item.has-salto { padding-bottom: 1.35rem; }
.trz-timeline-item.is-nav-target .trz-timeline-marker {
    box-shadow: 0 0 0 3px #fbbf24, 0 2px 8px color-mix(in srgb, var(--trz-mod-main, #059669) 35%, transparent);
    transform: scale(1.06);
}
.trz-timeline-item.is-nav-target .trz-timeline-content {
    background: #fffbeb; border-color: #fde68a;
    box-shadow: 0 0 0 2px rgba(245, 158, 11, .25);
}
.trz-timeline-content {
    flex: 1; min-width: 0; background: #fafafa; border: 1px solid #f1f5f9;
    border-radius: 12px; padding: .85rem 1rem;
}
.trz-timeline-item.is-match .trz-timeline-content {
    background: #fffbeb; border-color: #fde68a;
    box-shadow: 0 0 0 2px rgba(245, 158, 11, .2);
}
.trz-timeline-content .etapa-mini {
    font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em;
    color: var(--trz-mod-text, #047857); background: var(--trz-mod-bg, #ecfdf5);
    border-radius: 6px; padding: .2rem .5rem;
    display: inline-block; margin-bottom: .45rem;
}
.trz-timeline-content h3 {
    font-size: .92rem; font-weight: 700; margin: 0 0 .5rem; color: #0f172a; line-height: 1.3;
}
.trz-descripcion-list {
    margin: 0 0 .55rem; padding: 0; list-style: none;
}
.trz-descripcion-list li {
    font-size: .82rem; color: #475569; line-height: 1.5;
    padding: .15rem 0 .15rem 1rem; position: relative;
}
.trz-descripcion-list li::before {
    content: ''; position: absolute; left: 0; top: .55rem;
    width: 5px; height: 5px; border-radius: 50%; background: #94a3b8;
}
.trz-meta-rows { display: flex; flex-wrap: wrap; gap: .65rem 1.25rem; margin-top: .35rem; }
.trz-meta-row {
    display: inline-flex; align-items: center; gap: .45rem;
    font-size: .78rem; color: #64748b;
}
.trz-meta-row i { color: #94a3b8; font-size: .82rem; width: 14px; text-align: center; flex-shrink: 0; }
.trz-meta-row span { line-height: 1.3; }

.trz-sin-resultados { text-align: center; color: #94a3b8; font-size: .85rem; padding: 1.5rem 0; display: none; }

.trz-evidencia-wrap { margin: .65rem 0 .45rem; }
.trz-evidencia-galeria {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: .65rem;
}
.trz-evidencia-card {
    display: inline-block;
    max-width: 148px;
    width: 148px;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #d8e2ec;
    background: linear-gradient(145deg, #ffffff 0%, #f4f7fb 100%);
    box-shadow: 0 4px 14px rgba(15, 23, 42, .08);
    cursor: zoom-in;
    text-decoration: none;
    color: inherit;
    line-height: 0;
}
.trz-evidencia-card img {
    width: 100%;
    height: 108px;
    object-fit: cover;
    display: block;
    border-bottom: 1px solid rgba(15, 23, 42, .06);
}
.trz-evidencia-caption {
    display: flex; align-items: center; justify-content: space-between;
    padding: .4rem .6rem; font-size: .72rem; font-weight: 600; color: #047857;
    background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
    line-height: 1.3;
}
.trz-evidencia-caption i { opacity: .85; }

.trz-lightbox {
    position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, .92);
    display: none; align-items: center; justify-content: center; padding: 1rem;
}
.trz-lightbox.is-open { display: flex; }
.trz-lightbox-inner { max-width: 96vw; max-height: 92vh; text-align: center; }
.trz-lightbox-inner img {
    max-width: 100%; max-height: 82vh; border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,.4);
}
.trz-lightbox-title { color: #e2e8f0; font-size: .85rem; margin-top: .75rem; font-weight: 600; }
.trz-lightbox-close {
    position: absolute; top: 14px; right: 14px; border: 0; background: rgba(255,255,255,.15);
    color: #fff; width: 40px; height: 40px; border-radius: 50%; font-size: 1.2rem; cursor: pointer;
}

.trz-mapa-btn {
    display: inline-flex; align-items: center; gap: .45rem;
    margin-top: .85rem; padding: .55rem .95rem;
    border: 1.5px solid #4a7c59; border-radius: 8px;
    background: #f8faf8; color: #2c5530;
    font-size: .8rem; font-weight: 700; cursor: pointer;
    transition: background .15s, box-shadow .15s;
}
.trz-mapa-btn:hover {
    background: #eef5f0;
    box-shadow: 0 2px 6px rgba(44, 85, 48, .12);
}
.trz-mapa-btn i { color: #4a7c59; }

.trz-mapa-modal {
    position: fixed; inset: 0; z-index: 10000;
    display: none; align-items: center; justify-content: center;
    padding: 1rem; background: rgba(15, 23, 42, .55);
}
.trz-mapa-modal.is-open { display: flex; }
.trz-mapa-panel {
    width: min(720px, 100%);
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 12px 36px rgba(15, 23, 42, .2);
}
.trz-mapa-panel-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: .75rem; padding: .85rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    background: #f8f9fa;
}
.trz-mapa-panel-head h2 {
    margin: 0; font-size: .95rem; font-weight: 700; color: #2c5530;
    display: flex; align-items: center; gap: .5rem;
}
.trz-mapa-panel-close {
    border: 1px solid #dee2e6; background: #fff; color: #64748b;
    width: 34px; height: 34px; border-radius: 8px; cursor: pointer; font-size: 1.1rem;
}
.trz-mapa-panel-body { padding: .65rem; }
#trzMapaRuta {
    height: min(420px, 62vh); width: 100%;
    border-radius: 8px; border: 1px solid #dee2e6;
    background: #e8eef4; z-index: 1;
}
#trzMapaRuta.leaflet-container { font-family: inherit; }
.leaflet-div-icon.ruta-parada-marker {
    width: auto !important; height: auto !important; margin: 0 !important;
    padding: 0 !important; background: transparent !important; border: none !important;
}
.trz-mapa-hint {
    margin: .55rem .15rem 0; font-size: .72rem; color: #64748b;
}
</style>
@endpush

@section('content')
    <header class="trz-public-header">
        <div class="brand-logo"><i class="fas fa-seedling"></i></div>
        <div class="font-weight-bold text-success mb-1">AgroFusion</div>
        <h1>{{ $reporte['producto'] }}</h1>
        <div class="trz-cert-badge"><i class="fas fa-certificate"></i> Producto Certificado</div>
        <span class="codigo">{{ $reporte['codigo'] }}</span>
        <p class="text-muted small mt-2 mb-0">Trazabilidad desde campo hasta punto de venta</p>
    </header>

    <dl class="trz-public-meta">
        @if($reporte['punto_venta'])
        <div><dt>Punto de venta</dt><dd>{{ $reporte['punto_venta'] }}</dd></div>
        @endif
        @if($reporte['minorista'] && $reporte['minorista'] !== '—')
        <div><dt>Minorista</dt><dd>{{ $reporte['minorista'] }}</dd></div>
        @endif
        @if($reporte['lote_agricola'])
        <div><dt>Lote agrícola</dt><dd>{{ $reporte['lote_agricola'] }} @if($reporte['lote_codigo'])<small class="text-muted">({{ $reporte['lote_codigo'] }})</small>@endif</dd></div>
        @endif
        @if($reporte['pedido'])
        <div><dt>Pedido</dt><dd>{{ $reporte['pedido'] }}</dd></div>
        @endif
        <div><dt>Stock en tienda</dt><dd>{{ number_format($reporte['stock_actual'], 2) }} {{ $reporte['unidad'] }}</dd></div>
    </dl>

    <div class="trz-buscar-wrap">
        <span class="trz-buscar-label">Buscar en el recorrido</span>
        <div class="trz-search-field">
            <i class="fas fa-search search-icon"></i>
            <input type="search" id="trzBuscarEvento" placeholder="Ej: siembra, cosecha, planta, recepción…" autocomplete="off">
        </div>
        @if(($reporte['total_eventos'] ?? 0) > 0)
        <p class="trz-buscar-hint mb-0">{{ $reporte['total_eventos'] }} eventos en orden del ciclo: campo → planta → mayorista → distribución → tienda.</p>
        @endif
    </div>

    @php
        $pasosGlobales = [];
        foreach ($reporte['eventos'] ?? [] as $evGlobal) {
            $p = (int) ($evGlobal['paso'] ?? 0);
            if ($p > 0) {
                $pasosGlobales[] = $p;
            }
        }
        sort($pasosGlobales);
        $siguientePasoPorPaso = [];
        foreach ($pasosGlobales as $i => $p) {
            $siguientePasoPorPaso[$p] = $pasosGlobales[$i + 1] ?? null;
        }
        $pasoModuloLabel = [];
        foreach ($reporte['eventos_agrupados'] ?? [] as $catInfo) {
            foreach ($catInfo['eventos'] ?? [] as $evInfo) {
                $p = (int) ($evInfo['paso'] ?? 0);
                if ($p > 0) {
                    $pasoModuloLabel[$p] = $catInfo['label'];
                }
            }
        }
    @endphp

    <section id="trzCategorias">
        @foreach($reporte['eventos_agrupados'] ?? [] as $idx => $categoria)
        <div class="trz-cat-card trz-mod--{{ $categoria['key'] }} {{ $idx === 0 ? 'is-open' : '' }}" data-cat="{{ $categoria['key'] }}" id="trz-cat-{{ $categoria['key'] }}">
            <button type="button" class="trz-cat-head" data-toggle-cat>
                <span class="cat-left">
                    <span class="cat-icon"><i class="fas fa-{{ $categoria['icon'] }}"></i></span>
                    <span>{{ $categoria['label'] }}</span>
                </span>
                <span class="badge-count">{{ $categoria['total'] }}</span>
            </button>
            <div class="trz-cat-body">
                <div class="trz-timeline">
                    @foreach($categoria['eventos'] as $evIdx => $evento)
                    @php
                        $tituloNorm = mb_strtolower(trim($evento['titulo'] ?? ''));
                        $lineas = $evento['descripcion_lineas'] ?? [];
                        if ($lineas === [] && !empty($evento['descripcion'])) {
                            $lineas = [$evento['descripcion']];
                        }
                        $lineas = array_values(array_filter(
                            $lineas,
                            fn (string $linea) => mb_strtolower(trim($linea)) !== $tituloNorm
                        ));
                        $pasoActual = (int) ($evento['paso'] ?? ($evIdx + 1));
                        $pasoSiguiente = $siguientePasoPorPaso[$pasoActual] ?? null;
                        $esUltimoDeCategoria = $evIdx === count($categoria['eventos']) - 1;
                        $siguienteEnCat = $categoria['eventos'][$evIdx + 1] ?? null;
                        $siguienteEnCatPaso = $siguienteEnCat ? (int) ($siguienteEnCat['paso'] ?? 0) : 0;
                        $tieneSalto = $pasoSiguiente
                            && ! $esUltimoDeCategoria
                            && $siguienteEnCatPaso !== (int) $pasoSiguiente;
                        $tieneSiguienteFinal = $esUltimoDeCategoria && $pasoSiguiente;
                        $moduloDestinoSalto = $tieneSalto ? ($pasoModuloLabel[(int) $pasoSiguiente] ?? 'siguiente módulo') : '';
                    @endphp
                    <article class="trz-timeline-item trz-evento-item{{ $tieneSiguienteFinal ? ' has-next' : '' }}{{ $tieneSalto ? ' has-salto' : '' }}"
                             id="trz-paso-{{ $pasoActual }}"
                             data-paso="{{ $pasoActual }}"
                             data-search="{{ strtolower($evento['titulo'].' '.implode(' ', $lineas).' '.$evento['etapa_label'].' '.($evento['ubicacion'] ?? '').' foto evidencia') }}">
                        <div class="trz-timeline-rail">
                            <div class="trz-timeline-marker" title="Paso {{ $pasoActual }} del recorrido">
                                {{ $pasoActual }}
                            </div>
                            @if($tieneSalto && $pasoSiguiente)
                            <button type="button"
                                    class="trz-timeline-next trz-timeline-next--salto"
                                    data-next-paso="{{ $pasoSiguiente }}"
                                    title="Continúa en {{ $moduloDestinoSalto }} (paso {{ $pasoSiguiente }})"
                                    aria-label="Ir al paso {{ $pasoSiguiente }} en {{ $moduloDestinoSalto }}">
                                <i class="fas fa-external-link-alt"></i>
                            </button>
                            @endif
                            @if($tieneSiguienteFinal && $pasoSiguiente)
                            <button type="button"
                                    class="trz-timeline-next"
                                    data-next-paso="{{ $pasoSiguiente }}"
                                    title="Ir al paso {{ $pasoSiguiente }}"
                                    aria-label="Ir al paso {{ $pasoSiguiente }}">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            @endif
                        </div>
                        <div class="trz-timeline-content">
                            <span class="etapa-mini">{{ $evento['etapa_label'] }}</span>
                            <h3>{{ $evento['titulo'] }}</h3>
                            @if(count($lineas) > 0)
                            <ul class="trz-descripcion-list">
                                @foreach($lineas as $linea)
                                <li>{{ $linea }}</li>
                                @endforeach
                            </ul>
                            @endif
                            @php
                                $esFotoReferenciaPub = false;
                                foreach (['evidencia_url', 'evidencia_foto_url'] as $campoFotoPub) {
                                    $referenciaPub = \App\Support\TrazabilidadFotoReferencia::paraUrl($evento[$campoFotoPub] ?? null);
                                    if ($referenciaPub) {
                                        $evento[$campoFotoPub] = $referenciaPub;
                                        $esFotoReferenciaPub = true;
                                    }
                                }
                                $tipoEvidenciaPub = (string) ($evento['evidencia_tipo'] ?? '');
                                $urlInsumoPub = ($tipoEvidenciaPub === 'insumo' || $tipoEvidenciaPub === 'insumo_foto')
                                    ? ($evento['evidencia_url'] ?? null)
                                    : null;
                                $urlFotoPub = match ($tipoEvidenciaPub) {
                                    'insumo_foto' => $evento['evidencia_foto_url'] ?? null,
                                    'foto', 'maquina' => $evento['evidencia_url'] ?? null,
                                    default => null,
                                };
                            @endphp
                            @if(filled($urlInsumoPub) || filled($urlFotoPub))
                            <div class="trz-evidencia-wrap">
                                <div class="trz-evidencia-galeria">
                                    @if(filled($urlInsumoPub))
                                    <a href="{{ $urlInsumoPub }}" class="trz-evidencia-card trz-evidencia-open" target="_blank" rel="noopener"
                                       data-url="{{ $urlInsumoPub }}" data-titulo="{{ $evento['titulo'] }}">
                                        <img src="{{ $urlInsumoPub }}" alt="Insumo: {{ $evento['titulo'] }}" decoding="async">
                                        <span class="trz-evidencia-caption">
                                            <span><i class="fas fa-flask mr-1"></i> Insumo utilizado</span>
                                            <span><i class="fas fa-expand-alt"></i></span>
                                        </span>
                                    </a>
                                    @endif
                                    @if(filled($urlFotoPub))
                                    <a href="{{ $urlFotoPub }}" class="trz-evidencia-card trz-evidencia-open" target="_blank" rel="noopener"
                                       data-url="{{ $urlFotoPub }}" data-titulo="{{ $evento['titulo'] }}">
                                        <img src="{{ $urlFotoPub }}" alt="{{ $tipoEvidenciaPub === 'maquina' ? 'Equipo' : ($esFotoReferenciaPub ? 'Referencia' : 'Evidencia') }}: {{ $evento['titulo'] }}"
                                             decoding="async" loading="lazy" referrerpolicy="no-referrer">
                                        <span class="trz-evidencia-caption">
                                            <span><i class="fas fa-{{ $tipoEvidenciaPub === 'maquina' ? 'cogs' : 'camera' }} mr-1"></i>
                                                {{ $tipoEvidenciaPub === 'maquina' ? 'Equipo de planta' : ($esFotoReferenciaPub ? 'Imagen de referencia' : 'Evidencia fotográfica') }}</span>
                                            <span><i class="fas fa-expand-alt"></i></span>
                                        </span>
                                    </a>
                                    @endif
                                </div>
                            </div>
                            @elseif(!empty($evento['evidencia_url']))
                            <div class="trz-evidencia-wrap">
                                <a href="{{ $evento['evidencia_url'] }}" class="trz-evidencia-card trz-evidencia-open" target="_blank" rel="noopener"
                                   data-url="{{ $evento['evidencia_url'] }}" data-titulo="{{ $evento['titulo'] }}">
                                    <img src="{{ $evento['evidencia_url'] }}" alt="Evidencia: {{ $evento['titulo'] }}" decoding="async">
                                    <span class="trz-evidencia-caption">
                                        <span><i class="fas fa-camera mr-1"></i> Evidencia fotográfica</span>
                                        <span><i class="fas fa-expand-alt"></i></span>
                                    </span>
                                </a>
                            </div>
                            @endif
                            <div class="trz-meta-rows">
                                @if($evento['ubicacion'])
                                <span class="trz-meta-row">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span>{{ $evento['ubicacion'] }}</span>
                                </span>
                                @endif
                                <span class="trz-meta-row">
                                    <i class="far fa-clock"></i>
                                    <span>{{ $evento['fecha_fmt'] }}</span>
                                </span>
                            </div>
                            @if(!empty($evento['mapa_ruta']) && count($evento['mapa_ruta']) >= 2)
                            <button type="button"
                                    class="trz-mapa-btn"
                                    data-trz-mapa='@json($evento['mapa_ruta'])'
                                    data-trz-mapa-titulo="{{ $evento['titulo'] }}">
                                <i class="fas fa-map-marked-alt"></i>
                                Ver ruta en mapa
                            </button>
                            @endif
                        </div>
                    </article>
                    @endforeach
                </div>
            </div>
        </div>
        @endforeach
    </section>

    <p class="trz-sin-resultados" id="trzSinResultados">No hay eventos que coincidan con su búsqueda.</p>

    <div class="trz-lightbox" id="trzLightbox" aria-hidden="true">
        <button type="button" class="trz-lightbox-close" id="trzLightboxClose" aria-label="Cerrar">&times;</button>
        <div class="trz-lightbox-inner">
            <img src="" alt="" id="trzLightboxImg">
            <div class="trz-lightbox-title" id="trzLightboxTitle"></div>
        </div>
    </div>

    <div class="trz-mapa-modal" id="trzMapaModal" aria-hidden="true" role="dialog" aria-labelledby="trzMapaTitulo">
        <div class="trz-mapa-panel">
            <div class="trz-mapa-panel-head">
                <h2 id="trzMapaTitulo"><i class="fas fa-route"></i> <span>Ruta del envío</span></h2>
                <button type="button" class="trz-mapa-panel-close" id="trzMapaClose" aria-label="Cerrar">&times;</button>
            </div>
            <div class="trz-mapa-panel-body">
                <div id="trzMapaRuta"></div>
                <p class="trz-mapa-hint" id="trzMapaHint">Ruta trazada por calles · OpenStreetMap</p>
            </div>
        </div>
    </div>

    <footer class="text-center mt-4 small text-muted">© {{ date('Y') }} AgroFusion</footer>
@endsection

@push('scripts')
<script>
(function () {
    var input = document.getElementById('trzBuscarEvento');

    document.querySelectorAll('[data-toggle-cat]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.closest('.trz-cat-card').classList.toggle('is-open');
        });
    });

    function irAPasoSiguiente(btn) {
        var paso = parseInt(btn.getAttribute('data-next-paso') || '', 10);
        if (!paso) return;

        var destino = document.getElementById('trz-paso-' + paso);
        if (!destino || destino.style.display === 'none') {
            return;
        }

        document.querySelectorAll('.trz-timeline-item').forEach(function (item) {
            item.classList.remove('is-nav-target');
        });

        var cat = destino.closest('.trz-cat-card');
        if (cat) {
            cat.classList.add('is-open');
        }

        destino.classList.add('is-nav-target');
        requestAnimationFrame(function () {
            destino.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        window.setTimeout(function () {
            destino.classList.remove('is-nav-target');
        }, 2200);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.trz-timeline-next');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        irAPasoSiguiente(btn);
    });

    function normalizar(s) {
        return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function actualizarFlechasVisibles() {
        document.querySelectorAll('.trz-timeline-next').forEach(function (btn) {
            var paso = btn.getAttribute('data-next-paso');
            var destino = paso ? document.getElementById('trz-paso-' + paso) : null;
            var item = btn.closest('.trz-timeline-item');
            var visible = !!(destino && destino.style.display !== 'none');
            btn.style.display = visible ? '' : 'none';
            if (!item) return;
            if (btn.classList.contains('trz-timeline-next--salto')) {
                item.classList.toggle('has-salto', visible);
            } else {
                item.classList.toggle('has-next', visible);
            }
        });
    }

    if (input) {
        input.addEventListener('input', function () {
            var term = normalizar(input.value.trim());
            var alguno = false;
            var primera = null;

            document.querySelectorAll('.trz-evento-item').forEach(function (item) {
                var texto = normalizar(item.getAttribute('data-search'));
                var match = !term || texto.indexOf(term) !== -1;
                item.style.display = match ? '' : 'none';
                item.classList.toggle('is-match', match && term !== '');
                if (match) alguno = true;
                if (match && !primera) primera = item;
            });

            document.querySelectorAll('.trz-cat-card').forEach(function (card) {
                var visibles = Array.from(card.querySelectorAll('.trz-evento-item')).some(function (el) {
                    return el.style.display !== 'none';
                });
                card.style.display = visibles || !term ? '' : 'none';
                card.classList.toggle('is-highlight', false);
                if (term && visibles) {
                    card.classList.add('is-open');
                }
            });

            if (primera && term) {
                var cat = primera.closest('.trz-cat-card');
                if (cat) {
                    cat.classList.add('is-open', 'is-highlight');
                    window.setTimeout(function () {
                        primera.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 120);
                }
            }

            var sinResultados = document.getElementById('trzSinResultados');
            if (sinResultados) {
                sinResultados.style.display = (term && !alguno) ? 'block' : 'none';
            }

            actualizarFlechasVisibles();
        });
    }

    var lightbox = document.getElementById('trzLightbox');
    var lightboxImg = document.getElementById('trzLightboxImg');
    var lightboxTitle = document.getElementById('trzLightboxTitle');
    var lightboxClose = document.getElementById('trzLightboxClose');

    function abrirLightbox(url, titulo) {
        if (!lightbox || !lightboxImg) return;
        lightboxImg.src = url;
        lightboxImg.alt = titulo || 'Evidencia fotográfica';
        if (lightboxTitle) lightboxTitle.textContent = titulo || '';
        lightbox.classList.add('is-open');
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function cerrarLightbox() {
        if (!lightbox) return;
        lightbox.classList.remove('is-open');
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (lightboxImg) lightboxImg.src = '';
    }

    document.querySelectorAll('.trz-evidencia-open').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (window.matchMedia('(max-width: 768px)').matches) return;
            e.preventDefault();
            abrirLightbox(link.getAttribute('data-url'), link.getAttribute('data-titulo'));
        });
    });

    if (lightboxClose) lightboxClose.addEventListener('click', cerrarLightbox);
    if (lightbox) {
        lightbox.addEventListener('click', function (e) {
            if (e.target === lightbox) cerrarLightbox();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            cerrarLightbox();
            cerrarMapa();
        }
    });

    /* —— Mapa interactivo por envío —— */
    var mapaModal = document.getElementById('trzMapaModal');
    var mapaClose = document.getElementById('trzMapaClose');
    var mapaTituloSpan = document.querySelector('#trzMapaTitulo span');
    var mapaHint = document.getElementById('trzMapaHint');
    var mapaLeaflet = null;
    var mapaCapas = null;
    var mapaLibsPromise = null;

    function cargarMapaLibs() {
        if (window.L && window.RutaPorCalles) {
            return Promise.resolve();
        }
        if (mapaLibsPromise) return mapaLibsPromise;

        mapaLibsPromise = new Promise(function (resolve, reject) {
            function loadScript(src) {
                return new Promise(function (res, rej) {
                    var s = document.createElement('script');
                    s.src = src;
                    s.onload = res;
                    s.onerror = rej;
                    document.head.appendChild(s);
                });
            }
            function loadCss(href) {
                if (document.querySelector('link[href="' + href + '"]')) return;
                var l = document.createElement('link');
                l.rel = 'stylesheet';
                l.href = href;
                document.head.appendChild(l);
            }

            loadCss('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
            loadScript('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js')
                .then(function () {
                    return loadScript(@json(asset('js/ruta-por-calles.js')));
                })
                .then(resolve)
                .catch(reject);
        });

        return mapaLibsPromise;
    }

    async function dibujarMapa(paradas) {
        await cargarMapaLibs();
        var el = document.getElementById('trzMapaRuta');
        if (!el || !window.L || !paradas.length) return;

        if (!mapaLeaflet) {
            mapaLeaflet = L.map(el, { scrollWheelZoom: true }).setView([paradas[0].lat, paradas[0].lng], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap',
            }).addTo(mapaLeaflet);
            mapaCapas = L.layerGroup().addTo(mapaLeaflet);
        }

        mapaLeaflet.invalidateSize({ animate: false, pan: false });

        var routeResult = null;
        if (paradas.length >= 2 && window.RutaPorCalles) {
            routeResult = await RutaPorCalles.fetchRoute(paradas);
        }

        if (window.RutaPorCalles) {
            RutaPorCalles.drawOnMap(mapaLeaflet, mapaCapas, paradas, routeResult);
        }

        if (mapaHint) {
            mapaHint.textContent = routeResult && !routeResult.straight
                ? 'Ruta trazada por calles · OpenStreetMap / OSRM'
                : 'Trayecto aproximado (línea recta) · OpenStreetMap';
        }

        [50, 150, 350, 700].forEach(function (ms) {
            setTimeout(function () {
                if (mapaLeaflet) mapaLeaflet.invalidateSize({ animate: false, pan: false });
            }, ms);
        });
    }

    function abrirMapa(paradas, titulo) {
        if (!mapaModal || !paradas || paradas.length < 2) return;
        if (mapaTituloSpan) mapaTituloSpan.textContent = titulo || 'Ruta del envío';
        mapaModal.classList.add('is-open');
        mapaModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                dibujarMapa(paradas);
            });
        });
    }

    function cerrarMapa() {
        if (!mapaModal) return;
        mapaModal.classList.remove('is-open');
        mapaModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.trz-mapa-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var raw = btn.getAttribute('data-trz-mapa') || '[]';
            var paradas = [];
            try { paradas = JSON.parse(raw); } catch (e) { paradas = []; }
            abrirMapa(paradas, btn.getAttribute('data-trz-mapa-titulo'));
        });
    });

    if (mapaClose) mapaClose.addEventListener('click', cerrarMapa);
    if (mapaModal) {
        mapaModal.addEventListener('click', function (e) {
            if (e.target === mapaModal) cerrarMapa();
        });
    }
})();
</script>
@endpush
