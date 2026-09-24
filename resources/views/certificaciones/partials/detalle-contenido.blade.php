@php
    $lote = $cert->lote;
    $emitidoPor = $cert->usuario
        ? trim($cert->usuario->nombre.' '.$cert->usuario->apellido)
        : null;
@endphp

<style>
    .cert-det-v2__hero {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #22c55e 100%);
        border-radius: 14px;
        color: #fff;
        padding: 1.25rem 1.35rem;
        margin-bottom: 1.1rem;
        box-shadow: 0 8px 24px rgba(20, 83, 45, .2);
    }
    .cert-det-v2__hero-kicker {
        font-size: .68rem;
        letter-spacing: .08em;
        text-transform: uppercase;
        opacity: .85;
        margin-bottom: .35rem;
    }
    .cert-det-v2__hero-code {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 1.45rem;
        font-weight: 800;
        line-height: 1.2;
        word-break: break-all;
    }
    .cert-det-v2__hero-date {
        font-size: .82rem;
        opacity: .9;
        margin-top: .5rem;
    }
    .cert-det-v2__section {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem 1.1rem;
        margin-bottom: .85rem;
    }
    .cert-det-v2__section-title {
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: .75rem;
        display: flex;
        align-items: center;
        gap: .4rem;
    }
    .cert-det-v2__section-title i { color: #16a34a; }
    .cert-det-v2__grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .65rem .85rem;
    }
    @media (max-width: 576px) {
        .cert-det-v2__grid { grid-template-columns: 1fr; }
    }
    .cert-det-v2__field-label {
        display: block;
        font-size: .72rem;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: .15rem;
    }
    .cert-det-v2__field-value {
        font-size: .92rem;
        font-weight: 600;
        color: #1e293b;
        line-height: 1.35;
    }
    .cert-det-v2__field-value--muted { font-weight: 500; color: #475569; }
    .cert-det-v2__field--wide { grid-column: 1 / -1; }
    .cert-det-v2__emitido {
        display: flex;
        align-items: center;
        gap: .75rem;
    }
    .cert-det-v2__avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, #dcfce7, #bbf7d0);
        color: #166534;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        flex-shrink: 0;
    }
    .cert-det-v2__obs {
        background: #fff;
        border-left: 3px solid #22c55e;
        border-radius: 0 8px 8px 0;
        padding: .65rem .85rem;
        font-size: .88rem;
        color: #334155;
        margin-top: .5rem;
    }
    .cert-det-v2__actions .btn {
        padding: .5rem 1rem;
        font-weight: 600;
        border-radius: 10px;
    }
    .cert-det-v2__traz {
        font-family: ui-monospace, monospace;
        font-size: .78rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: .15rem .45rem;
        margin-left: .35rem;
    }
</style>

<div class="cert-det-v2">
    <div class="cert-det-v2__hero text-center">
        <div class="cert-det-v2__hero-kicker">Evaluación de lote de campo</div>
        <div class="mb-2">
            @if($cert->esNoConforme())
                <span class="badge badge-warning px-3 py-2" style="font-size:.95rem;">No conforme</span>
            @else
                <span class="badge badge-success px-3 py-2" style="font-size:.95rem;">Certificado</span>
            @endif
        </div>
        <div class="cert-det-v2__hero-code">{{ $cert->codigo_certificado }}</div>
        <div class="cert-det-v2__hero-date">
            <i class="far fa-clock mr-1"></i>{{ $cert->fecha_certificacion?->format('d/m/Y H:i') ?? '—' }}
        </div>
    </div>

    <div class="cert-det-v2__section">
        <div class="cert-det-v2__section-title">
            <i class="fas fa-certificate"></i> Certificación
        </div>
        <div class="cert-det-v2__emitido mb-2">
            <div class="cert-det-v2__avatar">
                {{ $emitidoPor ? mb_strtoupper(mb_substr($emitidoPor, 0, 1)) : '?' }}
            </div>
            <div>
                <span class="cert-det-v2__field-label">Emitido por</span>
                <span class="cert-det-v2__field-value d-block">
                    {{ $emitidoPor ?? '—' }}
                </span>
                @if($cert->usuario?->email)
                    <span class="small text-muted">{{ $cert->usuario->email }}</span>
                @endif
            </div>
        </div>
        @if($cert->observaciones)
            <div class="cert-det-v2__obs">
                <span class="cert-det-v2__field-label d-block mb-1">Observaciones</span>
                {{ $cert->observaciones }}
            </div>
        @endif
    </div>

    @if($cert->esCertificado() && filled($cert->blockchain_estado))
        <div class="cert-det-v2__section" style="border-color:#c5d5c8;background:#f7faf8;">
            <div class="cert-det-v2__section-title">
                <i class="fas fa-link" style="color:#2c5530"></i> Ancla blockchain
            </div>
            <div class="cert-det-v2__grid">
                <div>
                    <span class="cert-det-v2__field-label">Estado</span>
                    <span class="cert-det-v2__field-value">
                        @php
                            $bcEstado = $cert->blockchain_estado;
                            $bcLabel = match ($bcEstado) {
                                'certificada', 'confirmado' => 'Certificado en cadena',
                                'pendiente' => 'Pendiente de aprobación',
                                'rechazada' => 'Rechazado',
                                'error' => 'Error al anclar',
                                'omitida', 'omitido' => 'Omitido',
                                default => $bcEstado,
                            };
                        @endphp
                        @if(in_array($bcEstado, ['certificada', 'confirmado'], true))
                            <span class="badge badge-success">{{ $bcLabel }}</span>
                        @elseif($bcEstado === 'rechazada')
                            <span class="badge badge-warning">{{ $bcLabel }}</span>
                        @elseif($bcEstado === 'error')
                            <span class="badge badge-danger">{{ $bcLabel }}</span>
                        @elseif($bcEstado === 'pendiente')
                            <span class="badge badge-secondary">{{ $bcLabel }}</span>
                        @else
                            <span class="badge badge-light border">{{ $bcLabel }}</span>
                        @endif
                    </span>
                </div>
                <div>
                    <span class="cert-det-v2__field-label">Dato ID</span>
                    <span class="cert-det-v2__field-value" style="font-family:ui-monospace,monospace;font-size:.85rem;">
                        {{ $cert->blockchain_dato_id ?: '—' }}
                    </span>
                </div>
                <div>
                    <span class="cert-det-v2__field-label">Solicitud</span>
                    <span class="cert-det-v2__field-value" style="font-family:ui-monospace,monospace;font-size:.85rem;">
                        {{ $cert->blockchain_solicitud_id ?: '—' }}
                    </span>
                </div>
                <div class="cert-det-v2__field--wide">
                    <span class="cert-det-v2__field-label">Operación</span>
                    <span class="cert-det-v2__field-value" style="font-family:ui-monospace,monospace;font-size:.78rem;word-break:break-all;">
                        {{ $cert->blockchain_operacion_id ?: '—' }}
                    </span>
                </div>
                <div class="cert-det-v2__field--wide">
                    <span class="cert-det-v2__field-label">Hash del registro</span>
                    <span class="cert-det-v2__field-value" style="font-family:ui-monospace,monospace;font-size:.78rem;word-break:break-all;">
                        {{ $cert->blockchain_hash ?: '—' }}
                    </span>
                </div>
                <div class="cert-det-v2__field--wide">
                    <span class="cert-det-v2__field-label">Tx ID</span>
                    <span class="cert-det-v2__field-value" style="font-family:ui-monospace,monospace;font-size:.78rem;word-break:break-all;">
                        {{ $cert->blockchain_txid ?: '—' }}
                    </span>
                </div>
                @if($cert->blockchain_enviado_en)
                    <div>
                        <span class="cert-det-v2__field-label">Enviado</span>
                        <span class="cert-det-v2__field-value">{{ $cert->blockchain_enviado_en->format('d/m/Y H:i') }}</span>
                    </div>
                @endif
                @if($cert->blockchain_certificado_en)
                    <div>
                        <span class="cert-det-v2__field-label">Certificado en blockchain</span>
                        <span class="cert-det-v2__field-value">{{ $cert->blockchain_certificado_en->format('d/m/Y H:i') }}</span>
                    </div>
                @endif
                @if(in_array($cert->blockchain_estado, ['error', 'rechazada'], true) && $cert->blockchain_error)
                    <div class="cert-det-v2__field--wide">
                        <span class="cert-det-v2__field-label">Detalle</span>
                        <span class="cert-det-v2__field-value cert-det-v2__field-value--muted">{{ $cert->blockchain_error }}</span>
                    </div>
                @endif
            </div>
            @if(in_array($cert->blockchain_estado, ['pendiente', 'error'], true))
                <form method="POST" action="{{ route('certificaciones.blockchain.sincronizar', $cert) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-sync-alt mr-1"></i> Actualizar estado blockchain
                    </button>
                </form>
            @endif
        </div>
    @endif

    @if($lote)
        <div class="cert-det-v2__section">
            <div class="cert-det-v2__section-title">
                <i class="fas fa-seedling"></i> Lote certificado
            </div>
            <div class="cert-det-v2__grid">
                <div class="cert-det-v2__field--wide">
                    <span class="cert-det-v2__field-label">Nombre</span>
                    <span class="cert-det-v2__field-value">{{ $lote->nombre }}</span>
                </div>
                <div>
                    <span class="cert-det-v2__field-label">ID</span>
                    <span class="cert-det-v2__field-value">#{{ $lote->loteid }}</span>
                </div>
                <div>
                    <span class="cert-det-v2__field-label">Trazabilidad</span>
                    <span class="cert-det-v2__field-value">
                        @if($lote->codigo_trazabilidad)
                            <span class="cert-det-v2__traz">{{ $lote->codigo_trazabilidad }}</span>
                        @else
                            —
                        @endif
                    </span>
                </div>
                <div>
                    <span class="cert-det-v2__field-label">Cultivo</span>
                    <span class="cert-det-v2__field-value">{{ $lote->cultivo_etiqueta ?? '—' }}</span>
                </div>
                <div>
                    <span class="cert-det-v2__field-label">Estado</span>
                    @if($cert->esNoConforme())
                        <span class="badge badge-warning px-2 py-1">No conforme — sin envío a almacén</span>
                    @else
                        <span class="badge badge-success px-2 py-1">{{ $lote->estadoTipo->nombre ?? 'Certificado' }}</span>
                    @endif
                </div>
                <div class="cert-det-v2__field--wide">
                    <span class="cert-det-v2__field-label">Ubicación</span>
                    <span class="cert-det-v2__field-value cert-det-v2__field-value--muted">
                        <i class="fas fa-road text-success mr-1"></i>{{ $lote->ubicacion_visible }}
                    </span>
                </div>
                <div>
                    <span class="cert-det-v2__field-label">Superficie</span>
                    <span class="cert-det-v2__field-value">
                        @if($lote->superficie)
                            {{ $lote->superficie_etiqueta }}
                        @else
                            —
                        @endif
                    </span>
                </div>
                <div>
                    <span class="cert-det-v2__field-label">Fecha siembra</span>
                    <span class="cert-det-v2__field-value">{{ $lote->fechasiembra?->format('d/m/Y') ?? '—' }}</span>
                </div>
                @if($lote->actorAbastecimiento)
                    <div class="cert-det-v2__field--wide">
                        <span class="cert-det-v2__field-label">Actor</span>
                        <span class="cert-det-v2__field-value">{{ $lote->actorAbastecimiento->nombre ?? '—' }}</span>
                    </div>
                @endif
            </div>
        </div>

        @can('lotes.view')
            <div class="cert-det-v2__actions">
                <a href="{{ route('lotes.show', $lote) }}" class="btn btn-outline-success btn-block">
                    <i class="fas fa-external-link-alt mr-1"></i>Abrir ficha completa del lote
                </a>
            </div>
        @endcan
    @else
        <div class="alert alert-warning mb-0">
            El lote asociado (#{{ $cert->loteid }}) ya no está disponible.
        </div>
    @endif
</div>
