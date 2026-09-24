@extends('layouts.app')

@section('title', 'Logística — Catálogos y reportes | AgroFusion')
@section('page_title', 'Catálogos y reportes')

@push('styles')
@include('logistica.partials.ayuda-logistica-styles')
<style>
.log-mas-card{border:0;border-radius:14px;box-shadow:0 4px 16px rgba(18,38,63,.07);transition:transform .15s;height:100%}
.log-mas-card:hover{transform:translateY(-2px)}
.log-mas-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff}
</style>
@endpush

@section('content')
<div class="content-header">
    <div class="container-fluid">
        <p class="text-muted mb-0">Catálogos, documentos, incidentes y reportes de la operación logística.</p>
    </div>
</div>

<section class="content">
    <div class="container-fluid">
        <div class="row">
            @if(auth()->user()?->can('transportistas.view'))
            <div class="col-sm-6 col-lg-4 mb-3">
                <a href="{{ route('envios.transportistas') }}" class="text-decoration-none text-body">
                    <div class="card log-mas-card">
                        <div class="card-body d-flex align-items-center" style="gap:.85rem;">
                            <div class="log-mas-icon bg-info"><i class="fas fa-user-tie"></i></div>
                            <div>
                                <h6 class="font-weight-bold mb-0">Transportistas</h6>
                                <small class="text-muted">Choferes y perfiles</small>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            @endif
            @if(auth()->user()?->can('vehiculos.view'))
            <div class="col-sm-6 col-lg-4 mb-3">
                <a href="{{ route('envios.vehiculos') }}" class="text-decoration-none text-body">
                    <div class="card log-mas-card">
                        <div class="card-body d-flex align-items-center" style="gap:.85rem;">
                            <div class="log-mas-icon bg-secondary"><i class="fas fa-truck"></i></div>
                            <div>
                                <h6 class="font-weight-bold mb-0">Vehículos</h6>
                                <small class="text-muted">Flota y placas</small>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            @endif
            @can('documentos.view')
            <div class="col-sm-6 col-lg-4 mb-3">
                <a href="{{ route('logistica.documentos.index') }}" class="text-decoration-none text-body">
                    <div class="card log-mas-card">
                        <div class="card-body d-flex align-items-center" style="gap:.85rem;">
                            <div class="log-mas-icon bg-success"><i class="fas fa-file-alt"></i></div>
                            <div>
                                <h6 class="font-weight-bold mb-0">Documentos entrega</h6>
                                <small class="text-muted">Comprobantes y notas</small>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            @endcan
            @can('incidentes.view')
            <div class="col-sm-6 col-lg-4 mb-3">
                <a href="{{ route('logistica.incidentes.index') }}" class="text-decoration-none text-body">
                    <div class="card log-mas-card">
                        <div class="card-body d-flex align-items-center" style="gap:.85rem;">
                            <div class="log-mas-icon bg-danger"><i class="fas fa-exclamation-circle"></i></div>
                            <div>
                                <h6 class="font-weight-bold mb-0">Incidentes</h6>
                                <small class="text-muted">Reportes y seguimiento</small>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            @endcan
            @if((auth()->user()?->can('envios.view')) && !auth()->user()?->hasRole('transportista'))
            <div class="col-sm-6 col-lg-4 mb-3">
                <a href="{{ route('envios.reportes-distribucion') }}" class="text-decoration-none text-body">
                    <div class="card log-mas-card">
                        <div class="card-body d-flex align-items-center" style="gap:.85rem;">
                            <div class="log-mas-icon bg-dark"><i class="fas fa-chart-bar"></i></div>
                            <div>
                                <h6 class="font-weight-bold mb-0">Reportes distribución</h6>
                                <small class="text-muted">Indicadores de envío</small>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            @endif
        </div>
    </div>
</section>
@endsection
