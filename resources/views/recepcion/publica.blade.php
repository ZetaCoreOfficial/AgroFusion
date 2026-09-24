@extends('layouts.public-trazabilidad')

@section('title', $titulo.' | AgroFusion')

@push('styles')
<style>
.rcp-page { text-align: center; }
.rcp-brand { font-weight: 800; color: #166534; font-size: .9rem; margin-bottom: .35rem; }
.rcp-title { font-size: 1.35rem; font-weight: 800; color: #0f172a; margin-bottom: .25rem; }
.rcp-codigo {
    display: inline-block; background: #f5f3ff; color: #5b21b6; border: 1px solid #ddd6fe;
    border-radius: 999px; padding: .25rem .75rem; font-size: .72rem; font-weight: 700; margin-bottom: 1rem;
}
.rcp-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 1.15rem; text-align: left; box-shadow: 0 4px 18px rgba(15,23,42,.06);
}
.rcp-label { font-size: .78rem; font-weight: 700; color: #475569; margin-bottom: .35rem; display: block; }
.rcp-input {
    width: 100%; border: 1px solid #cbd5e1; border-radius: 10px;
    padding: .7rem .85rem; font-size: .95rem; margin-bottom: 1rem;
}
.rcp-input:focus { outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.15); }
.rcp-firma-box {
    border: 2px dashed #cbd5e1; border-radius: 10px; background: #fff;
    touch-action: none; cursor: crosshair; width: 100%; height: 180px;
}
.rcp-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
.rcp-btn {
    border: 0; border-radius: 10px; padding: .65rem 1rem; font-weight: 700; font-size: .88rem; cursor: pointer;
}
.rcp-btn--ghost { background: #f1f5f9; color: #334155; }
.rcp-btn--primary {
    background: linear-gradient(135deg, #5b21b6, #7c3aed); color: #fff;
    box-shadow: 0 3px 10px rgba(91,33,182,.28);
}
.rcp-alert {
    border-radius: 10px; padding: .85rem 1rem; font-size: .88rem; margin-bottom: 1rem;
}
.rcp-alert--ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.rcp-alert--warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.rcp-alert--info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.rcp-modal-backdrop {
    position: fixed; inset: 0; background: rgba(15,23,42,.45);
    display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 1rem;
}
.rcp-modal-backdrop[hidden] { display: none !important; }
.rcp-modal {
    background: #fff; border-radius: 14px; padding: 1.25rem 1.35rem; max-width: 320px; width: 100%;
    box-shadow: 0 12px 40px rgba(15,23,42,.18); text-align: center;
}
.rcp-modal h2 { font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0 0 .5rem; }
.rcp-modal p { font-size: .9rem; color: #475569; margin: 0 0 1rem; }
</style>
@endpush

@section('content')
<div class="rcp-page">
    <div class="rcp-brand"><i class="fas fa-truck-loading mr-1"></i> AgroFusion</div>
    <h1 class="rcp-title">{{ $titulo }}</h1>
    <span class="rcp-codigo">{{ $codigo }}</span>

    @if(session('exito'))
        <div class="rcp-alert rcp-alert--ok"><i class="fas fa-check-circle mr-1"></i> {{ session('exito') }}</div>
    @endif

    @if($errors->any())
        <div class="rcp-alert rcp-alert--warn">{{ $errors->first() }}</div>
    @endif

    <div class="rcp-card">
        @if($sinFirmaTransportista)
            <div class="rcp-alert rcp-alert--info mb-0">
                <i class="fas fa-hourglass-half mr-1"></i>
                El transportista aún no ha firmado en el sistema. Espere un momento e intente de nuevo.
            </div>
        @elseif($yaFirmado)
            <div class="rcp-alert rcp-alert--ok mb-0">
                <i class="fas fa-check-circle mr-1"></i>
                La recepción ya fue firmada. Puede cerrar esta página.
            </div>
        @elseif($requiereSesion)
            <div class="rcp-alert rcp-alert--info">
                <i class="fas fa-user-lock mr-1"></i>
                Para firmar la recepción inicie sesión con la cuenta del receptor del destino.
            </div>
            <a href="{{ route('login') }}" class="rcp-btn rcp-btn--primary d-inline-block text-decoration-none">
                <i class="fas fa-sign-in-alt mr-1"></i> Iniciar sesión para firmar
            </a>
        @elseif(! $puedeFirmar)
            <div class="rcp-alert rcp-alert--warn mb-0">
                <i class="fas fa-ban mr-1"></i>
                La cuenta con la que inició sesión no es la del receptor de este envío.
                El transportista no puede firmar la recepción.
            </div>
        @else
            <p class="small text-muted mb-3">
                Firme para confirmar la recepción de la carga como
                <strong>{{ trim(($usuario->nombre ?? '').' '.($usuario->apellido ?? '')) }}</strong>.
            </p>
            <form method="POST" action="{{ route('recepcion.publica.firmar', $token) }}" id="form-recepcion-publica">
                @csrf
                @if(($lineasTraslado ?? collect())->isNotEmpty())
                    <label class="rcp-label">Cantidad recibida por producto</label>
                    <p class="small text-muted mb-2">Si llegó menos de lo despachado, indique cuánto y por qué. Solo se acredita lo recibido.</p>
                    @foreach($lineasTraslado as $linea)
                        @php $despachado = $linea->cantidadDespachada(); @endphp
                        <div class="mb-2" data-linea-recepcion="{{ $linea->detalletrasladoid }}">
                            <div class="small font-weight-bold">{{ $linea->producto_nombre }} — despachado {{ rtrim(rtrim(number_format($despachado, 2, '.', ''), '0'), '.') }}</div>
                            <input type="number" class="rcp-input mb-1" step="0.01" min="0" max="{{ $despachado }}"
                                   data-recibido value="{{ $despachado }}" aria-label="Cantidad recibida de {{ $linea->producto_nombre }}">
                            <input type="text" class="rcp-input" maxlength="255" data-motivo
                                   placeholder="Motivo de la diferencia (si corresponde)">
                        </div>
                    @endforeach
                @endif

                <label class="rcp-label">Firma</label>
                <canvas class="rcp-firma-box" data-firma-canvas="recepcion" width="400" height="180"></canvas>
                <input type="hidden" name="imagen_firma" id="imagen_firma">

                <div class="rcp-actions">
                    <button type="button" class="rcp-btn rcp-btn--ghost btn-limpiar-firma" data-target="recepcion">Limpiar</button>
                    <button type="submit" class="rcp-btn rcp-btn--primary" id="btn-enviar-firma">
                        <i class="fas fa-file-signature mr-1"></i> Confirmar recepción
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>

@endsection

@push('scripts')
@if(! $yaFirmado && ! $sinFirmaTransportista && $puedeFirmar)
<script src="{{ asset('js/firma-canvas.js') }}?v=3"></script>
<script>
(function () {
    const form = document.getElementById('form-recepcion-publica');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const canvas = document.querySelector('[data-firma-canvas="recepcion"]');
        if (!canvas) return;

        let imagen = '';
        if (window.AgroFusionFirmaPads && window.AgroFusionFirmaPads.get) {
            const pad = window.AgroFusionFirmaPads.get('recepcion');
            if (pad) imagen = pad.toDataUrl();
        }

        if (!imagen || imagen.length < 100) {
            alert('Dibuje su firma antes de confirmar.');
            return;
        }

        document.getElementById('imagen_firma').value = imagen;
        const btn = document.getElementById('btn-enviar-firma');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Guardando…';

        fetch(form.action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || form.querySelector('[name=_token]')?.value,
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                imagen_firma: imagen,
                recepcion: (function () {
                    const lineas = {};
                    document.querySelectorAll('[data-linea-recepcion]').forEach(function (fila) {
                        lineas[fila.getAttribute('data-linea-recepcion')] = {
                            recibido: fila.querySelector('[data-recibido]').value,
                            motivo: fila.querySelector('[data-motivo]').value,
                        };
                    });
                    return lineas;
                })(),
            }),
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (res.ok) {
                    window.location.reload();
                    return;
                }

                const mensaje = String(res.j.mensaje || '');
                if (mensaje.toLowerCase().includes('ya fue registrada')) {
                    window.location.reload();
                    return;
                }

                alert(mensaje || 'No se pudo guardar la firma.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-signature mr-1"></i> Confirmar recepción';
            })
            .catch(function () {
                alert('No se pudo conectar con el servidor. Intente de nuevo en unos segundos.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-signature mr-1"></i> Confirmar recepción';
            });
    });
})();
</script>
@endif
@endpush
