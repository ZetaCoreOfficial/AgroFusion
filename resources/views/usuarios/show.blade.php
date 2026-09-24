@extends('layouts.app')

@section('title', 'Detalle de usuario | AgroFusion')
@section('page_title', 'Detalle de usuario')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Inicio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('gestion.index') }}">Gestión de usuarios</a></li>
    <li class="breadcrumb-item active">{{ $usuario->nombre }} {{ $usuario->apellido }}</li>
@endsection

@push('styles')
@include('partials.modulo-usuarios-styles')
@php
    $estadoCuenta = $usuario->estado_cuenta ?? \App\Support\CuentaEstado::APROBADO;
    $infoAdicional = \App\Support\UsuarioInformacionAdicional::lineasParaVista($usuario->informacionadicional);
@endphp
<style>
.usu-show-page { max-width: 1100px; margin: 0 auto; }
.usu-show-back {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #475569;
    font-weight: 500;
    font-size: 0.9rem;
    margin-bottom: 1rem;
    text-decoration: none;
}
.usu-show-back:hover { color: #2c5530; text-decoration: none; }
.usu-show-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(15, 23, 42, 0.08);
    border: 1px solid #e8ecef;
    overflow: hidden;
    margin-bottom: 1.25rem;
}
.usu-show-hero {
    background: linear-gradient(135deg, #1e3d22 0%, #2c5530 45%, #4a7c59 100%);
    color: #fff;
    padding: 1.75rem 2rem;
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex-wrap: wrap;
}
.usu-show-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    border: 3px solid rgba(255, 255, 255, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
    flex-shrink: 0;
}
.usu-show-hero h2 { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.25rem; }
.usu-show-hero p { margin: 0; opacity: 0.92; font-size: 0.95rem; }
.usu-show-badges { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-left: auto; }
.usu-show-badge {
    border-radius: 999px;
    padding: 0.35rem 0.9rem;
    font-size: 0.8rem;
    font-weight: 600;
    background: rgba(255, 255, 255, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.usu-show-badge.estado-activo { background: #fff; color: #2c5530; }
.usu-show-badge.estado-pendiente { background: #ffc107; color: #1a252f; border-color: #ffc107; }
.usu-show-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    background: #f8fafc;
    border-bottom: 1px solid #eef1f4;
}
.usu-show-btn {
    border-radius: 10px;
    font-weight: 500;
    font-size: 0.88rem;
    padding: 0.45rem 1rem;
}
.usu-show-btn-edit {
    background: linear-gradient(135deg, #2c5530, #4a7c59);
    border: none;
    color: #fff;
}
.usu-show-btn-edit:hover { color: #fff; opacity: 0.95; }
.usu-show-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    padding: 1.25rem 1.5rem;
}
@media (max-width: 767px) {
    .usu-show-stats { grid-template-columns: 1fr; }
    .usu-show-badges { margin-left: 0; width: 100%; }
}
.usu-show-stat {
    border-radius: 12px;
    padding: 1rem 1.1rem;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.usu-show-stat h3 { font-size: 1.75rem; font-weight: 700; margin: 0 0 0.15rem; position: relative; z-index: 1; }
.usu-show-stat p { margin: 0; font-size: 0.85rem; opacity: 0.95; position: relative; z-index: 1; }
.usu-show-stat i {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 2.5rem;
    opacity: 0.2;
}
.usu-show-stat.green { background: linear-gradient(135deg, #2c5530, #4a7c59); }
.usu-show-stat.teal { background: linear-gradient(135deg, #17a2b8, #20c997); }
.usu-show-stat.purple { background: linear-gradient(135deg, #6f42c1, #8e64e8); }
.usu-show-grid {
    display: grid;
    grid-template-columns: 1fr 1.4fr;
    gap: 1.25rem;
    padding: 0 1.5rem 1.5rem;
}
@media (max-width: 991px) {
    .usu-show-grid { grid-template-columns: 1fr; padding: 0 1rem 1rem; }
}
.usu-show-panel {
    border: 1px solid #eef1f4;
    border-radius: 12px;
    overflow: hidden;
}
.usu-show-panel-head {
    background: #f8fafc;
    padding: 0.85rem 1.1rem;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #2c5530;
    border-bottom: 1px solid #eef1f4;
}
.usu-show-panel-head i { margin-right: 0.4rem; opacity: 0.8; }
.usu-show-dl { margin: 0; padding: 0.5rem 0; }
.usu-show-dl-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 0.65rem 1.1rem;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.9rem;
}
.usu-show-dl-row:last-child { border-bottom: none; }
.usu-show-dl-row dt { color: #64748b; font-weight: 500; margin: 0; flex-shrink: 0; }
.usu-show-dl-row dd { margin: 0; text-align: right; color: #1e293b; font-weight: 500; }
.usu-show-extra {
    padding: 0.65rem 1.1rem 0.85rem;
    background: #f8fbf8;
    border-top: 1px solid #eef1f4;
}
.usu-show-extra-title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #4a7c59;
    margin-bottom: 0.5rem;
}
.usu-show-solicitud {
    margin: 0 1.5rem 1.5rem;
    border-radius: 12px;
    border: 1px solid #ffe082;
    background: #fffdf5;
    overflow: hidden;
}
.usu-show-solicitud .card-header {
    background: #fff8e1;
    border-bottom: 1px solid #ffe082;
    font-weight: 600;
    color: #856404;
}
.usu-show-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: #94a3b8;
}
.usu-show-empty i { font-size: 2rem; margin-bottom: 0.75rem; display: block; opacity: 0.5; }
</style>
@endpush

@section('content')
<div class="modulo-usu usu-show-page">

    <a href="{{ route('gestion.index') }}" class="usu-show-back">
        <i class="fas fa-arrow-left"></i> Volver al listado
    </a>

    <div class="usu-show-card">
        <div class="usu-show-hero">
            <div class="usu-show-avatar">
                {{ strtoupper(substr($usuario->nombre, 0, 1).substr($usuario->apellido, 0, 1)) }}
            </div>
            <div>
                <h2>{{ $usuario->nombre }} {{ $usuario->apellido }}</h2>
                <p>
                    @if($estadoCuenta === \App\Support\CuentaEstado::PENDIENTE)
                        {{ $usuario->email }}
                    @else
                        {{ '@'.$usuario->nombreusuario }} · {{ $usuario->email }}
                    @endif
                </p>
            </div>
            <div class="usu-show-badges">
                @if($estadoCuenta === \App\Support\CuentaEstado::PENDIENTE)
                    <span class="usu-show-badge estado-pendiente">Pendiente</span>
                @else
                    <span class="usu-show-badge estado-activo">Activo</span>
                @endif
                <span class="usu-show-badge">{{ \App\Support\UsuarioRol::etiquetasUsuario($usuario) }}</span>
            </div>
        </div>

        <div class="usu-show-toolbar">
            <a href="{{ route('gestion.index') }}" class="btn btn-outline-secondary btn-sm usu-show-btn">
                <i class="fas fa-list mr-1"></i> Listado
            </a>
            @if($estadoCuenta !== \App\Support\CuentaEstado::PENDIENTE)
                @can('usuarios.update')
                <a href="{{ route('gestion.edit', $usuario) }}" class="btn btn-sm usu-show-btn usu-show-btn-edit">
                    <i class="fas fa-edit mr-1"></i> Editar
                </a>
                @endcan
            @endif
        </div>

        @if($estadoCuenta === \App\Support\CuentaEstado::PENDIENTE)
        <div class="usu-show-solicitud card border-0 shadow-none">
            <div class="card-header py-3">
                <i class="fas fa-clock mr-1"></i> Solicitud de registro pendiente
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-3"><strong>Rol solicitado</strong><br>{{ ucfirst($usuario->rol_solicitado ?? '—') }}</div>
                    <div class="col-md-3"><strong>CI/NIT</strong><br>{{ $usuario->ci_nit ?? '—' }}</div>
                    <div class="col-md-3"><strong>Teléfono</strong><br>{{ $usuario->telefono ?? '—' }}</div>
                    @if(($usuario->rol_solicitado ?? '') === 'transportista')
                    <div class="col-md-3"><strong>Tipo de licencia</strong><br>{{ \App\Support\TiposLicenciaBolivia::etiqueta($usuario->tipo_licencia) ?? '—' }}</div>
                    @endif
                </div>
                <p class="mb-1 font-weight-bold small text-muted text-uppercase">Carta de motivación</p>
                <div class="bg-white border rounded p-3 mb-3" style="white-space:pre-wrap;">{{ $usuario->carta_motivacion }}</div>
                @can('solicitudes.approve')
                <div class="d-flex flex-wrap" style="gap:8px;">
                    <form action="{{ route('gestion.solicitud.aprobar', $usuario) }}" method="POST" class="d-flex flex-wrap align-items-center" style="gap:8px;">@csrf
                        @if(($usuario->rol_solicitado ?? '') === 'transportista')
                            <select name="ambito_flota" class="form-control form-control-sm w-auto @error('ambito_flota') is-invalid @enderror" required aria-label="Flota del transportista">
                                <option value="">Flota del transportista…</option>
                                @foreach(\App\Support\TransportistaFlotaCatalogo::etiquetas() as $valorFlota => $etiquetaFlota)
                                    <option value="{{ $valorFlota }}" @selected(old('ambito_flota') === $valorFlota)>{{ $etiquetaFlota }}</option>
                                @endforeach
                            </select>
                        @endif
                        <button type="submit" class="btn btn-success"><i class="fas fa-check mr-1"></i> Aprobar</button>
                    </form>
                    @error('ambito_flota')<div class="w-100 small text-danger">{{ $message }}</div>@enderror
                    <form action="{{ route('gestion.solicitud.rechazar', $usuario) }}" method="POST"
                        onsubmit="return confirm('¿Rechazar esta solicitud? Se eliminará del sistema.')">@csrf
                        <button type="submit" class="btn btn-outline-danger"><i class="fas fa-times mr-1"></i> Rechazar</button>
                    </form>
                </div>
                @endcan
            </div>
        </div>
        @else
        <div class="usu-show-stats">
            <div class="usu-show-stat green">
                <h3>{{ $stats['lotes'] ?? 0 }}</h3>
                <p>Lotes a cargo</p>
                <i class="fas fa-map-marked-alt"></i>
            </div>
            <div class="usu-show-stat teal">
                <h3>{{ $stats['actividades'] ?? 0 }}</h3>
                <p>Actividades</p>
                <i class="fas fa-tasks"></i>
            </div>
            <div class="usu-show-stat purple">
                <h3>#{{ $usuario->usuarioid }}</h3>
                <p>ID en sistema</p>
                <i class="fas fa-id-badge"></i>
            </div>
        </div>

        <div class="usu-show-grid">
            <div class="usu-show-panel">
                <div class="usu-show-panel-head">
                    <i class="fas fa-id-card"></i> Datos de la cuenta
                </div>
                <dl class="usu-show-dl">
                    @if($usuario->ci_nit)
                    <div class="usu-show-dl-row">
                        <dt>CI / NIT</dt>
                        <dd>{{ $usuario->ci_nit }}</dd>
                    </div>
                    @endif
                    @php
                        $tipoLicencia = $usuario->tipo_licencia
                            ?? $usuario->perfilTransportista?->tipo_licencia;
                    @endphp
                    @if($tipoLicencia && $usuario->hasRole('transportista'))
                    <div class="usu-show-dl-row">
                        <dt>Tipo de licencia</dt>
                        <dd>{{ \App\Support\TiposLicenciaBolivia::etiqueta($tipoLicencia) ?? '—' }}</dd>
                    </div>
                    @endif
                    <div class="usu-show-dl-row">
                        <dt>Teléfono</dt>
                        <dd>{{ $usuario->telefono ?: '—' }}</dd>
                    </div>
                    <div class="usu-show-dl-row">
                        <dt>Registro</dt>
                        <dd>{{ $usuario->fecharegistro ? $usuario->fecharegistro->format('d/m/Y H:i') : '—' }}</dd>
                    </div>
                    <div class="usu-show-dl-row">
                        <dt>Último acceso</dt>
                        <dd>{{ $usuario->ultimologin ? $usuario->ultimologin->format('d/m/Y H:i') : '—' }}</dd>
                    </div>
                </dl>
                @if(count($infoAdicional) > 0)
                <div class="usu-show-extra">
                    <div class="usu-show-extra-title">Información adicional</div>
                    @foreach($infoAdicional as $linea)
                    <div class="usu-show-dl-row" style="padding-left:0;padding-right:0;border:none;">
                        <dt>{{ $linea['label'] }}</dt>
                        <dd>{{ $linea['value'] }}</dd>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            <div class="usu-show-panel">
                <div class="usu-show-panel-head">
                    <i class="fas fa-map-marker-alt"></i> Lotes como responsable
                </div>
                @if($lotesRecientes->isEmpty())
                <div class="usu-show-empty">
                    <i class="fas fa-seedling"></i>
                    Este usuario no tiene lotes asignados.
                </div>
                @else
                <div class="table-responsive">
                    <table class="table table-modulo table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Lote</th>
                                <th>Cultivo</th>
                                <th>Estado</th>
                                <th style="width: 52px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($lotesRecientes as $lote)
                            <tr>
                                <td><strong>{{ $lote->nombre }}</strong></td>
                                <td>{{ $lote->cultivo->nombre ?? '—' }}</td>
                                <td>
                                    <span class="badge badge-light border text-capitalize">{{ $lote->estadoTipo->nombre ?? '—' }}</span>
                                </td>
                                <td>
                                    <a href="{{ route('lotes.show', $lote) }}" class="btn btn-sm btn-outline-success" title="Ver lote">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
