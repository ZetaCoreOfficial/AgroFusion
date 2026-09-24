@extends('layouts.app')

@section('title', 'Editar producto')
@section('page_title', 'Editar producto — '.$punto->nombre)

@push('styles')
@include('punto_venta.partials.modulo-styles')
@endpush

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ request('return') === 'inventario' ? route('punto-venta.inventario.index', ['puntoventaid' => $punto->puntoventaid]) : route('punto-venta.puntos.show', $punto) }}" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left mr-1"></i> Volver
            </a>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card pdv-card card-outline card-success">
                <div class="card-header bg-white py-3">
                    <h3 class="card-title mb-0 font-weight-bold">
                        <i class="fas fa-edit text-success mr-1"></i> {{ $insumo->nombre }}
                    </h3>
                </div>
                <div class="card-body">
                    @if($errors->any())
                        <div class="alert alert-danger">{{ $errors->first() }}</div>
                    @endif

                    <form method="POST" action="{{ route('punto-venta.puntos.inventario.update', [$punto, $insumo]) }}">
                        @csrf
                        @method('PUT')
                        @if(request('return') === 'inventario')
                            <input type="hidden" name="return" value="inventario">
                        @endif

                        <div class="form-group">
                            <label>Nombre del producto</label>
                            <input type="text" name="nombre" class="form-control" value="{{ old('nombre', $insumo->nombre) }}" required maxlength="150">
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Stock</label>
                                <input type="number" name="stock" class="form-control @error('stock') is-invalid @enderror"
                                    value="{{ old('stock', $insumo->stock) }}" min="0" step="0.01" required>
                                @error('stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                @if($resumenCapacidad)
                                <small class="form-text text-muted">
                                    Depósito: {{ number_format($resumenCapacidad['ocupado_kg'], 2, ',', '.') }} / {{ number_format($resumenCapacidad['capacidad_kg'], 0, ',', '.') }} kg
                                    · {{ number_format($resumenCapacidad['disponible_kg'], 2, ',', '.') }} kg libres
                                </small>
                                @endif
                            </div>
                            <div class="form-group col-md-6">
                                <label>Stock mínimo</label>
                                <input type="number" name="stockminimo" class="form-control" value="{{ old('stockminimo', $insumo->stockminimo) }}" min="0" step="0.01">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Motivo del ajuste de stock</label>
                            <textarea name="motivo_ajuste" class="form-control @error('motivo_ajuste') is-invalid @enderror" rows="2" maxlength="500"
                                placeholder="Obligatorio si cambia el stock: merma, conteo físico, devolución…">{{ old('motivo_ajuste') }}</textarea>
                            @error('motivo_ajuste')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="form-text text-muted">El cambio de stock queda registrado como movimiento con su usuario y motivo.</small>
                        </div>
                        <div class="form-group">
                            <label>Descripción</label>
                            <textarea name="descripcion" class="form-control" rows="3" maxlength="500">{{ old('descripcion', $insumo->descripcion) }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-success btn-block">
                            <i class="fas fa-save mr-1"></i> Guardar cambios
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
