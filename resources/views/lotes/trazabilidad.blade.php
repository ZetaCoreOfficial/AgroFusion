@extends('layouts.app')

@section('title', 'Trazabilidad — '.$lote->nombre)
@section('page_title', 'Trazabilidad: '.$lote->nombre)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Inicio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('lotes.index') }}">Lotes</a></li>
    <li class="breadcrumb-item"><a href="{{ route('lotes.show', $lote) }}">{{ $lote->nombre }}</a></li>
    <li class="breadcrumb-item active">Detalle</li>
@endsection

@push('styles')
    @include('lotes.partials.detalle-styles')
    @include('lotes.partials.trazabilidad-styles')
    @if($puede_enviar_almacen ?? false)
        @include('partials.almacen-envio-styles')
    @endif
@endpush

@section('content')
<div class="trz-dash">
    @if(!empty($solo_lectura_lote))
        <div class="alert alert-info border-0 shadow-sm mb-3">
            <i class="fas fa-eye mr-1"></i>
            Está consultando este lote en modo solo lectura porque participó en actividades del mismo.
            Solo podrá ejecutar tareas que le hayan sido asignadas explícitamente.
        </div>
    @endif
    @include('lotes.partials.detalle-header')
    @include('lotes.partials.detalle-stats')
    @include('lotes.partials.detalle-nav')

    <div class="card lote-section-card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                <div>
                    <h5 class="mb-1 text-success">
                        <i class="fas fa-route mr-2"></i>Fase actual:
                        <span class="badge badge-success">{{ $fase_actual_label }}</span>
                    </h5>
                    <p class="text-muted small mb-0">Progreso estimado en la cadena productiva</p>
                </div>
                <span class="h4 mb-0 text-success font-weight-bold">{{ $progreso }}%</span>
            </div>
            <div class="progress-fase mb-3">
                <div class="bar" style="width: {{ $progreso }}%"></div>
            </div>

            <div class="fase-pipeline">
                @foreach($fases_pipeline as $step)
                    @php
                        $titulo = !empty($step['fase_unica'])
                            ? ($step['completada'] ? 'Completada' : 'Pendiente — ocurre una sola vez')
                            : ($step['eventos'].' evento(s)');
                        if (!empty($step['url'])) {
                            $titulo = 'Ir a '.$step['label'].' — '.$titulo;
                        } elseif (($step['key'] ?? '') === 'siembra' && ($step['estado'] ?? '') === 'next' && ($puede_completar_siembra_directa ?? false)) {
                            $titulo = 'Completar siembra con foto de evidencia';
                        } elseif (($step['key'] ?? '') === 'siembra' && ($step['estado'] ?? '') === 'next' && ($puede_asignar_siembra ?? false)) {
                            $titulo = 'Asignar quién va a sembrar';
                        } elseif (($step['key'] ?? '') === 'siembra' && in_array($step['estado'] ?? '', ['active', 'next'], true) && !empty($actividad_siembra_pendiente)) {
                            $titulo = 'Siembra asignada — pendiente de realizar';
                        } elseif (($step['key'] ?? '') === 'certificacion' && ($puede_certificar_campo ?? false)) {
                            $titulo = 'Certificar el lote — formulario abajo';
                        } elseif (($step['key'] ?? '') === 'envio_almacen' && ($puede_enviar_almacen ?? false)) {
                            $titulo = 'Ir al formulario de almacenaje';
                        }
                        $siembraPendienteId = (int) ($actividad_siembra_pendiente['actividadid'] ?? 0);
                        $puedeCompletarSiembra = (bool) ($puede_completar_siembra ?? false);
                    @endphp
                    @if(!empty($step['url']))
                        <a href="{{ $step['url'] }}" class="fase-step {{ $step['estado'] }} fase-step-link" title="{{ $titulo }}">
                            <i class="fas fa-{{ $step['icon'] }} d-block mb-1"></i>
                            {{ $step['label'] }}
                            @if($step['estado'] === 'next')
                                <span class="d-block small mt-1"><i class="fas fa-arrow-right"></i> Siguiente</span>
                            @endif
                            @if(!empty($step['completada']) && !empty($step['fase_unica']))
                                <span class="d-block small mt-1"><i class="fas fa-check"></i></span>
                            @elseif(!empty($step['mostrar_contador']))
                                <span class="badge badge-fase-count">{{ $step['eventos'] }}</span>
                            @endif
                        </a>
                    @elseif(($step['key'] ?? '') === 'siembra' && ($step['estado'] ?? '') === 'next' && $puedeCompletarSiembra)
                        <button type="button" class="fase-step {{ $step['estado'] }} fase-step-link border-0"
                                data-toggle="modal" data-target="#modalCompletarSiembra" title="{{ $titulo }}">
                            <i class="fas fa-{{ $step['icon'] }} d-block mb-1"></i>
                            {{ $step['label'] }}
                            <span class="d-block small mt-1"><i class="fas fa-camera"></i> Completar</span>
                        </button>
                    @elseif(($step['key'] ?? '') === 'siembra' && ($step['estado'] ?? '') === 'next' && ($puede_asignar_siembra ?? false))
                        <a href="#" role="button" class="fase-step {{ $step['estado'] }} fase-step-link"
                           data-toggle="modal" data-target="#modalAsignarSiembra" title="{{ $titulo }}">
                            <i class="fas fa-{{ $step['icon'] }} d-block mb-1"></i>
                            {{ $step['label'] }}
                            <span class="d-block small mt-1"><i class="fas fa-arrow-right"></i> Siguiente</span>
                        </a>
                    @elseif(($step['key'] ?? '') === 'siembra' && !empty($actividad_siembra_pendiente) && $puedeCompletarSiembra)
                        <button type="button" class="fase-step {{ $step['estado'] }} fase-step-link border-0"
                                data-toggle="modal" data-target="#modalCompletarSiembra"
                                title="Completar siembra con foto de evidencia">
                            <i class="fas fa-{{ $step['icon'] }} d-block mb-1"></i>
                            {{ $step['label'] }}
                            <span class="d-block small mt-1"><i class="fas fa-camera"></i> Completar</span>
                        </button>
                    @elseif(($step['key'] ?? '') === 'certificacion' && ($puede_certificar_campo ?? false))
                        <a href="#panel-certificacion-campo" class="fase-step {{ $step['estado'] }} fase-step-link" title="{{ $titulo }}">
                            <i class="fas fa-{{ $step['icon'] }} d-block mb-1"></i>
                            {{ $step['label'] }}
                            <span class="d-block small mt-1"><i class="fas fa-certificate"></i> Certificar</span>
                        </a>
                    @elseif(($step['key'] ?? '') === 'envio_almacen' && ($puede_enviar_almacen ?? false))
                        <a href="#panel-enviar-almacen-campo" class="fase-step {{ $step['estado'] }} fase-step-link" title="{{ $titulo }}">
                            <i class="fas fa-{{ $step['icon'] }} d-block mb-1"></i>
                            {{ $step['label'] }}
                            <span class="d-block small mt-1"><i class="fas fa-warehouse"></i> Almacenar</span>
                        </a>
                    @else
                        <div class="fase-step {{ $step['estado'] }}" title="{{ $titulo }}">
                            <i class="fas fa-{{ $step['icon'] }} d-block mb-1"></i>
                            {{ $step['label'] }}
                            @if(!empty($step['completada']) && !empty($step['fase_unica']))
                                <span class="d-block small mt-1"><i class="fas fa-check"></i></span>
                            @elseif(!empty($step['mostrar_contador']))
                                <span class="badge badge-fase-count">{{ $step['eventos'] }}</span>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>

            @if($fase_actual === 'en_crecimiento' && ! empty($url_asignar_actividad))
                <div class="text-center mb-3 d-flex flex-wrap justify-content-center" style="gap: 8px;">
                    <a href="{{ $url_asignar_actividad }}" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-tasks mr-1"></i> Asignar actividad
                    </a>
                    @if(!empty($url_siguiente_fase) && !empty($siguiente_fase_label))
                        @if(!empty($puede_ir_a_cosecha))
                        <a href="{{ $url_siguiente_fase }}" class="btn btn-success btn-sm">
                            <i class="fas fa-forward mr-1"></i> Ir a {{ $siguiente_fase_label }}
                        </a>
                        @else
                        <span class="d-inline-block" tabindex="0"
                              title="Complete todas las actividades pendientes antes de registrar la cosecha">
                            <button type="button" class="btn btn-success btn-sm" disabled>
                                <i class="fas fa-forward mr-1"></i> Ir a {{ $siguiente_fase_label }}
                            </button>
                        </span>
                        @endif
                    @endif
                </div>
                @if($fase_actual === 'en_crecimiento' && !empty($url_siguiente_fase) && empty($puede_ir_a_cosecha))
                <p class="text-center small text-muted mb-3">
                    <i class="fas fa-lock mr-1"></i>
                    @if(($actividades_pendientes_count ?? 0) > 0)
                        Hay {{ $actividades_pendientes_count }} actividad(es) esencial(es) pendiente(s) (riego, plagas o fertilización). Complételas para habilitar «Ir a {{ $siguiente_fase_label }}».
                    @else
                        Complete y marque como realizadas las actividades de riego, control de plagas y fertilización para habilitar «Ir a {{ $siguiente_fase_label }}».
                    @endif
                </p>
                @endif
            @elseif($puede_completar_siembra ?? false)
                <div class="text-center mb-3">
                    @if(!empty($actividad_siembra_pendiente))
                        <p class="small text-success mb-2">
                            <i class="fas fa-user-check mr-1"></i>
                            Siembra asignada a <strong>{{ $actividad_siembra_pendiente['responsable'] ?? 'usted' }}</strong>.
                            Suba la foto del trabajo en campo para completarla.
                        </p>
                    @endif
                    <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#modalCompletarSiembra">
                        <i class="fas fa-camera mr-1"></i> Completar {{ $siguiente_fase_label ?: 'Siembra' }}
                    </button>
                </div>
            @elseif(($puede_asignar_siembra ?? false) && ($siguiente_fase ?? '') === 'siembra')
                <div class="text-center mb-3">
                    <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#modalAsignarSiembra">
                        <i class="fas fa-user-plus mr-1"></i> Asignar {{ $siguiente_fase_label }}
                    </button>
                </div>
            @elseif(!empty($actividad_siembra_pendiente) && !($puede_completar_siembra ?? false))
                <p class="text-center small text-muted mb-3">
                    <i class="fas fa-hourglass-half mr-1"></i>
                    Siembra asignada a <strong>{{ $actividad_siembra_pendiente['responsable'] ?? '—' }}</strong> — pendiente de realizar.
                </p>
            @elseif($puede_enviar_almacen ?? false)
                @include('lotes.partials.panel-enviar-almacen-campo')
            @elseif(!empty($url_siguiente_fase) && !empty($siguiente_fase_label))
                <div class="text-center mb-3">
                    <a href="{{ $url_siguiente_fase }}" class="btn btn-success btn-sm">
                        <i class="fas fa-forward mr-1"></i> Ir a {{ $siguiente_fase_label }}
                    </a>
                </div>
            @endif

            @if($puede_certificar_campo ?? false)
                @include('lotes.partials.panel-certificar-campo')
            @elseif(($certificacion_campo ?? null) && ($certificacion_campo->esNoConforme() ?? false))
                <div class="alert alert-warning border mb-3" id="panel-certificacion-campo">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Lote marcado como <strong>No conforme</strong>.
                    @if($certificacion_campo->observaciones)
                        Motivo: {{ $certificacion_campo->observaciones }}
                    @endif
                </div>
            @elseif(($certificacion_campo ?? null) && ($certificacion_campo->esCertificado() ?? false))
                <div class="border rounded p-3 mb-3" id="panel-certificacion-campo" style="background:#f7faf8;border-color:#c5d5c8!important;">
                    <div class="d-flex flex-wrap align-items-center justify-content-between">
                        <div>
                            <strong style="color:#2c5530;"><i class="fas fa-certificate mr-1"></i>Certificado</strong>
                            <span class="text-muted small ml-2">{{ $certificacion_campo->codigo_certificado }}</span>
                        </div>
                        @if($certificacion_campo->blockchainConfirmada())
                            <span class="badge badge-success">Blockchain certificado</span>
                        @elseif(($certificacion_campo->blockchain_estado ?? null) === 'pendiente')
                            <span class="badge badge-secondary">Blockchain pendiente de aprobación</span>
                        @elseif(($certificacion_campo->blockchain_estado ?? null) === 'rechazada')
                            <span class="badge badge-warning">Blockchain rechazada</span>
                        @elseif(($certificacion_campo->blockchain_estado ?? null) === 'error')
                            <span class="badge badge-danger">Blockchain error</span>
                        @endif
                    </div>
                    @if(filled($certificacion_campo->blockchain_solicitud_id))
                        <div class="small text-muted mt-2" style="font-family:ui-monospace,monospace;word-break:break-all;">
                            Solicitud: {{ $certificacion_campo->blockchain_solicitud_id }}
                        </div>
                    @endif
                    @if(filled($certificacion_campo->blockchain_txid))
                        <div class="small text-muted mt-2" style="font-family:ui-monospace,monospace;word-break:break-all;">
                            Tx: {{ $certificacion_campo->blockchain_txid }}
                        </div>
                    @endif
                    @if(in_array(($certificacion_campo->blockchain_estado ?? null), ['pendiente', 'error'], true))
                        <form method="POST" action="{{ route('certificaciones.blockchain.sincronizar', $certificacion_campo) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-sync-alt mr-1"></i> Actualizar blockchain
                            </button>
                        </form>
                    @endif
                    @can('certificaciones.view')
                        <a href="{{ route('certificaciones.show', $certificacion_campo) }}" class="small d-inline-block mt-2" style="color:#2c5530;">
                            Ver detalle de certificación
                        </a>
                    @endcan
                </div>
            @endif

            @php
                $pend = $pendiente ?? [];
                $panel = $panel_pendientes ?? [];
            @endphp
            @if(empty($pend['completo']))
                <div class="alert alert-light border trz-pasos-panel mb-0 mt-3">
                    <h6 class="text-success mb-2"><i class="fas fa-route mr-1"></i> Qué falta para continuar</h6>

                    @if(!empty($panel['meta_label']))
                    <div class="trz-meta-siguiente mb-3">
                        <span class="text-muted small d-block mb-1">Próxima etapa del recorrido</span>
                        <strong class="text-dark">{{ $panel['meta_label'] }}</strong>
                        @if(!empty($panel['meta_progreso']))
                            <span class="badge badge-success ml-1">{{ $panel['meta_progreso'] }} %</span>
                        @endif
                        @if(!empty($panel['fases_despues']))
                            <p class="small text-muted mb-0 mt-1">
                                Después: {{ implode(' → ', $panel['fases_despues']) }}
                            </p>
                        @endif
                    </div>
                    @endif

                    @if(!empty($panel['hitos']))
                    <p class="small font-weight-bold text-muted mb-2">Hitos de crecimiento</p>
                    <ul class="list-unstyled trz-checklist mb-3">
                        @foreach($panel['hitos'] as $hito)
                        <li class="{{ !empty($hito['ok']) ? 'is-done' : 'is-pending' }}">
                            <i class="fas {{ !empty($hito['ok']) ? 'fa-check-circle text-success' : 'fa-circle text-muted' }} mr-2"></i>
                            {{ $hito['label'] }}
                        </li>
                        @endforeach
                    </ul>
                    @endif

                    @if(!empty($panel['actividades_abiertas']))
                    <p class="small font-weight-bold text-warning mb-2">
                        <i class="fas fa-exclamation-circle mr-1"></i>
                        Actividades pendientes ({{ count($panel['actividades_abiertas']) }})
                    </p>
                    <ul class="list-unstyled trz-checklist mb-3">
                        @foreach($panel['actividades_abiertas'] as $actPend)
                        <li class="is-pending d-flex flex-wrap align-items-center justify-content-between {{ empty($actPend['en_turno']) ? 'opacity-75' : '' }}" style="gap:.35rem;">
                            <span>
                                @if(!empty($actPend['orden_secuencia']))
                                    <span class="badge badge-light border mr-1" title="Orden de ejecución">#{{ $actPend['orden_secuencia'] }}</span>
                                @endif
                                <i class="fas fa-hourglass-half {{ !empty($actPend['en_turno']) ? 'text-warning' : 'text-muted' }} mr-2"></i>
                                {{ $actPend['titulo'] }}
                                @if(!empty($actPend['prioridad']))
                                    <span class="badge badge-{{ $actPend['prioridad_badge'] ?? 'secondary' }} ml-1">
                                        {{ ucfirst($actPend['prioridad']) }}
                                    </span>
                                @endif
                                @if(!empty($actPend['responsable']))
                                    <span class="text-muted">— {{ $actPend['responsable'] }}</span>
                                @endif
                                @if(empty($actPend['en_turno']))
                                    <span class="badge badge-secondary ml-1">En espera</span>
                                @endif
                            </span>
                            @if(!empty($actPend['actividadid']) && in_array((int) $actPend['actividadid'], $actividades_marcables_ids ?? [], true) && !empty($actPend['es_siembra']) && ($puede_completar_siembra ?? false))
                                <button type="button" class="btn btn-success btn-sm"
                                        data-toggle="modal" data-target="#modalCompletarSiembra">
                                    <i class="fas fa-camera mr-1"></i> Completar siembra
                                </button>
                            @elseif(!empty($actPend['actividadid']) && in_array((int) $actPend['actividadid'], $actividades_marcables_ids ?? [], true) && empty($actPend['es_siembra']))
                                <button type="button" class="btn btn-success btn-sm btn-completar-evidencia"
                                        data-action="{{ route('actividades.marcar-realizada', $actPend['actividadid']) }}"
                                        data-resumen-url="{{ route('actividades.resumen-completar', $actPend['actividadid']) }}"
                                        data-titulo="{{ $actPend['titulo'] }}"
                                        data-lote="{{ $lote->nombre }}"
                                        data-scroll-to="historial-eventos">
                                    <i class="fas fa-camera mr-1"></i> Completar
                                </button>
                            @endif
                        </li>
                        @endforeach
                    </ul>
                    @endif

                    @if(!empty($panel['acciones']))
                    <ul class="list-unstyled trz-checklist mb-0">
                        @foreach($panel['acciones'] as $paso)
                        <li class="is-pending">
                            <i class="fas fa-arrow-right text-success mr-2"></i>{{ $paso }}
                        </li>
                        @endforeach
                    </ul>
                    @elseif(!empty($panel['resumen_corto']))
                    <p class="small text-muted mb-0">{{ $panel['resumen_corto'] }}</p>
                    @endif

                </div>
            @else
                <p class="small text-success mb-0 mt-3"><i class="fas fa-check-circle mr-1"></i> {{ $pend['resumen'] ?? 'Trazabilidad completa' }}</p>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card lote-section-card" id="historial-eventos">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="mb-0"><i class="fas fa-history mr-2 text-success"></i>Historial de eventos</h5>
                    <span class="badge badge-secondary" id="trz-event-count">{{ $trazabilidad->count() }} eventos</span>
                </div>
                <div class="card-body border-bottom pb-3">
                    <h6 class="text-muted small text-uppercase mb-2">
                        <i class="fas fa-filter mr-1"></i> Filtrar historial de eventos
                    </h6>
                    @include('lotes.partials.trazabilidad-filtros', [
                        'action' => route('lotes.trazabilidad', $lote),
                        'anchor' => '#historial-eventos',
                        'cultivos' => collect(),
                        'estados' => collect(),
                        'responsables' => collect(),
                        'fases' => $fases,
                        'filtros' => $filtros,
                        'hideBuscar' => true,
                    ])
                </div>
                <div class="card-body timeline-trz" id="trz-timeline">
                    @forelse($trazabilidad as $evento)
                        @php
                            $puedeCompletarEvento = !empty($evento['actividadid'])
                                && isset($evento['completada'])
                                && ! $evento['completada']
                                && ($evento['fase'] ?? '') !== 'siembra'
                                && in_array((int) $evento['actividadid'], $actividades_marcables_ids ?? [], true);
                            $tieneEvidenciaVisual = !empty($evento['evidencia_url'])
                                || !empty($evento['evidencia_foto_url'])
                                || !empty($evento['evidencia_icono']);
                            $colorFaseEvento = ($fases_evento[$evento['fase']] ?? $fases[$evento['fase']])['color'] ?? '#6c757d';
                        @endphp
                        <div class="evento-trz-wrap" style="--paso-color: {{ $colorFaseEvento }};">
                            <div class="evento-trz-paso" aria-hidden="true">
                                <span class="evento-trz-paso__num">{{ $evento['paso'] ?? $loop->iteration }}</span>
                            </div>
                        <div class="evento-trz{{ $puedeCompletarEvento ? ' tiene-accion' : '' }}"
                            data-tipo="{{ $evento['tipo'] }}"
                            data-fase="{{ $evento['fase'] }}"
                            data-paso="{{ $loop->iteration }}"
                            style="border-left-color: {{ $colorFaseEvento }}">
                            <div class="evento-trz-cuerpo">
                                <div class="d-flex justify-content-between flex-wrap">
                                    <div>
                                        <span class="badge badge-fase mr-1" style="background:{{ ($fases_evento[$evento['fase']] ?? $fases[$evento['fase']])['color'] ?? '#6c757d' }}">
                                            {{ $evento['fase_label'] }}
                                        </span>
                                        @if(! in_array($evento['tipo'] ?? '', ['actividad', 'siembra', 'estado'], true))
                                            <span class="badge badge-light text-uppercase">{{ $evento['tipo'] }}</span>
                                        @endif
                                    </div>
                                    <small class="text-muted"><i class="fas fa-calendar-alt mr-1"></i>{{ $evento['fecha_fmt'] }}</small>
                                </div>
                                <strong class="d-block mt-2">{{ $evento['titulo'] }}</strong>
                                @php
                                    $descEvento = trim((string) ($evento['descripcion'] ?? ''));
                                    $tituloEvento = trim((string) ($evento['titulo'] ?? ''));
                                    $mostrarDesc = $descEvento !== ''
                                        && mb_strtolower($descEvento) !== mb_strtolower($tituloEvento);
                                @endphp
                                @if($mostrarDesc)
                                    <p class="mb-1 small text-muted">{{ $descEvento }}</p>
                                @endif
                                @if($tieneEvidenciaVisual)
                                    @include('lotes.partials.trazabilidad-evidencia', ['evento' => $evento])
                                @endif
                                <div class="d-flex flex-wrap align-items-center" style="gap: .35rem;">
                                    @if(!empty($evento['usuario']))
                                        <small class="text-muted"><i class="fas fa-user mr-1"></i>{{ $evento['usuario'] }}</small>
                                    @endif
                                    @if(isset($evento['completada']))
                                        <span class="badge badge-{{ $evento['completada'] ? 'success' : 'warning' }}">
                                            {{ $evento['completada'] ? 'Completada' : 'Pendiente' }}
                                        </span>
                                    @endif
                                    @if(($evento['tipo'] ?? '') === 'almacenamiento' && !empty($evento['almacen_url']) && ($puede_ver_almacen_historial ?? false))
                                        <a href="{{ $evento['almacen_url'] }}" class="btn btn-sm btn-outline-primary ml-auto">
                                            <i class="fas fa-warehouse mr-1"></i> Ver almacén
                                        </a>
                                    @endif
                                </div>
                            </div>
                            @if($puedeCompletarEvento)
                                <div class="evento-trz-accion">
                                    <button type="button" class="btn btn-success btn-sm btn-completar-evidencia"
                                            data-action="{{ route('actividades.marcar-realizada', $evento['actividadid']) }}"
                                            data-resumen-url="{{ route('actividades.resumen-completar', $evento['actividadid']) }}"
                                            data-titulo="{{ $evento['titulo'] }}"
                                            data-lote="{{ $lote->nombre }}"
                                            data-scroll-to="historial-eventos"
                                            title="Ver resumen y subir foto">
                                        <i class="fas fa-check mr-1"></i> Completar
                                    </button>
                                </div>
                            @endif
                        </div>
                        </div>
                    @empty
                        <div class="empty-timeline">
                            <i class="fas fa-inbox d-block"></i>
                            <p class="mb-0">No hay eventos con los filtros aplicados.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @include('lotes.partials.detalle-actions', ['ocultarGestion' => true])
</div>

@include('partials.modal-completar-evidencia')
@include('partials.modal-ver-evidencia')
@if(($puede_completar_siembra ?? false) || $errors->has('evidencia_foto') || $errors->has('observaciones') || session('abrir_modal_completar_siembra'))
    @include('lotes.partials.modal-completar-siembra')
@endif
@if(($puede_asignar_siembra ?? false) || $errors->has('usuarioid'))
    @include('lotes.partials.modal-asignar-siembra')
@endif
@endsection

@push('scripts')
<script>
(function () {
    function scrollToHistorial() {
        var el = document.getElementById('historial-eventos');
        if (!el) return;
        requestAnimationFrame(function () {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }
    if (window.location.hash === '#historial-eventos') {
        setTimeout(scrollToHistorial, 150);
    }
    window.addEventListener('hashchange', function () {
        if (window.location.hash === '#historial-eventos') scrollToHistorial();
    });

    @if(session('abrir_modal_completar_siembra') || ($errors->has('siembra') && ($puede_completar_siembra ?? false)))
    if (window.jQuery) {
        window.jQuery('#modalCompletarSiembra').modal('show');
    }
    @elseif($errors->has('usuarioid') || ($errors->has('siembra') && ($puede_asignar_siembra ?? false)))
    if (window.jQuery) {
        window.jQuery('#modalAsignarSiembra').modal('show');
    }
    @endif

    @if($errors->has('almacenid') || $errors->has('produccionid'))
    var panelAlmacen = document.getElementById('panel-enviar-almacen-campo');
    if (panelAlmacen) {
        panelAlmacen.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    @endif
})();
</script>
@endpush
