{{-- Mayorista → Punto de venta — wizard 4 pasos --}}
<form method="POST" action="{{ route('punto-venta.pedidos.store') }}" id="form-pedido-dist-pdv">
    @csrf
    <input type="hidden" name="tipo_solicitud" value="stock">

    {{-- PASO 1: RUTA (misma estructura que Agrícola → Planta) --}}
    <div class="wizard-step active" data-wizard-step="1">
    <div class="env-wizard-panel">
        <div class="env-wizard-panel__head"><i class="fas fa-map-marked-alt"></i> Ruta de entrega</div>
        <div class="env-wizard-panel__body">
            @include('pedidos.partials.selector-trayecto')

            <div class="form-row mb-3">
                <div class="form-group col-md-3 mb-md-0">
                    <label class="small font-weight-bold">Código de envío</label>
                    <input type="text" class="form-control form-control-sm bg-light" value="{{ $numeroSolicitudDist }}" readonly>
                    <small class="text-muted">Se confirma al guardar.</small>
                </div>
                <div class="form-group col-md-3 mb-md-0">
                    <label class="small font-weight-bold">Fecha de entrega deseada <span class="text-danger">*</span></label>
                    <input type="date" name="fecha_entrega_deseada" class="form-control form-control-sm" value="{{ old('fecha_entrega_deseada') }}" required>
                    <small class="text-muted">Obligatoria para programar la distribución.</small>
                </div>
                <div class="form-group col-md-3 mb-md-0">
                    <label class="small font-weight-bold">Hora de recogida</label>
                    <input type="time" name="hora_entrega_deseada" id="hora_recogida_pdv" class="form-control form-control-sm" value="{{ old('hora_entrega_deseada') }}">
                    <small class="text-muted">Cuándo debe pasar el camión por el origen.</small>
                </div>
                <div class="form-group col-md-3 mb-0">
                    <label class="small font-weight-bold">Hora entrega estimada</label>
                    <input type="time" id="hora_entrega_estimada_pdv" class="form-control form-control-sm bg-light" readonly>
                    <small class="text-muted" id="hora-entrega-pdv-ayuda">Se calcula al trazar la ruta…</small>
                </div>
            </div>

            @unless($esAdminPdv ?? false)
            <div class="form-row mb-3">
                <div class="form-group col-md-4 mb-0">
                    <label class="small font-weight-bold" for="canal_origen_pdv">Canal del pedido <span class="text-danger">*</span></label>
                    <select name="canal_origen" id="canal_origen_pdv" class="form-control form-control-sm" required>
                        @foreach(\App\Support\PedidoDistribucionCatalogo::CANALES_EXTERNOS as $valorCanal => $etiquetaCanal)
                            <option value="{{ $valorCanal }}" @selected(old('canal_origen', 'otro') === $valorCanal)>{{ $etiquetaCanal }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Por dónde le llegó el pedido. Queda registrado como pedido externo cargado por usted.</small>
                </div>
            </div>
            @endunless

            <div class="alert alert-light border py-2 mb-3 small" id="pdv-paso-ayuda">
                <strong>Paso a paso:</strong>
                @if($esOrigenMayoristaPdv ?? $esAdminPdv ?? false)
                    1) Elija uno o más almacenes mayorista (origen) en orden. 2) Luego el punto de venta (destino). En el paso 2 indique productos por cada almacén.
                @else
                    Elija el punto de venta (destino) en el mapa o en el buscador. La ruta se traza automáticamente.
                @endif
            </div>

            @if($esOrigenMayoristaPdv ?? $esAdminPdv ?? false)
            <div class="env-paso" id="bloque-recogidas-pdv">
                <div class="d-flex align-items-center mb-2">
                    <span class="env-paso__num">1</span>
                    <strong>Puntos de recogida — Almacenes mayorista</strong>
                </div>
                <div class="form-group mb-2">
                    <label class="small text-muted mb-1">Recogida 1 <span class="text-danger">*</span></label>
                    <div class="pedido-picker-field">
                        <input type="text" id="txtNombreOrigenPdv" class="picker-display text-muted" readonly
                               placeholder="Buscar almacén mayorista…" value="{{ $oldAlmacenLabel ?? '' }}">
                        <div class="picker-actions">
                            <button type="button" class="btn btn-sm btn-picker-accion" id="btnBuscarOrigenPdv" title="Buscar almacén">
                                <i class="fas fa-search"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-picker-accion d-none" id="btnVerProductosOrigenPdv" title="Ver productos en este almacén">
                                <i class="fas fa-box-open mr-1"></i> Ver
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLimpiarOrigenPdv" title="Quitar">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <small id="txtOrigenPdvCoords" class="form-text text-muted"></small>
                    <small id="pdv-producto-preseleccion-resumen" class="text-success small d-none mt-1 mb-0"></small>
                </div>
                <div id="recogidas-extra-pdv-container"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAgregarRecogidaPdv">
                    <i class="fas fa-plus mr-1"></i> Agregar otro almacén mayorista
                </button>
                <small class="text-muted d-block mt-1">Opcional: recoger en 2 o más almacenes mayorista antes de entregar al punto de venta.</small>
            </div>
            @endif

            <div class="env-paso" id="bloque-destino-pdv">
                <div class="d-flex align-items-center mb-2">
                    <span class="env-paso__num">{{ ($esOrigenMayoristaPdv ?? $esAdminPdv ?? false) ? '2' : '1' }}</span>
                    <strong>Destino — Punto de venta</strong>
                </div>
                @if($esOrigenMayoristaPdv ?? $esAdminPdv ?? false)
                <small class="text-muted d-block mb-2" id="pdv-destino-bloqueado-msg">Primero elija la recogida 1 en almacén mayorista.</small>
                @endif
                <div class="pedido-picker-field">
                    <input type="text" id="txtNombreDestinoPdv" class="picker-display text-muted" readonly
                           placeholder="Buscar punto de venta…" value="{{ $oldPuntoLabel ?? '' }}">
                    <div class="picker-actions">
                        <button type="button" class="btn btn-sm btn-picker-accion" id="btnBuscarDestinoPdv" title="Buscar punto de venta">
                            <i class="fas fa-search"></i>
                        </button>
                        @if($esOrigenMayoristaPdv ?? $esAdminPdv ?? false)
                        <button type="button" class="btn btn-sm btn-picker-accion" id="btnFiltrarDestinoPdv" title="Filtrar por minorista">
                            <i class="fas fa-filter mr-1"></i> Filtrar
                        </button>
                        @endif
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLimpiarDestinoPdv" title="Quitar">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <small id="txtDestinoPdvCoords" class="form-text text-muted"></small>
                <small id="pdv-filtro-minorista-resumen" class="text-muted small d-none mt-1 mb-0"></small>
            </div>

            <div class="d-none">
                @if($esOrigenMayoristaPdv ?? $esAdminPdv ?? false)
                @include('partials.selector-catalogo', [
                    'id' => 'pdv_unificado_minorista',
                    'name' => 'minorista_usuarioid',
                    'value' => old('minorista_usuarioid', $oldMinoristaId ?? ''),
                    'labelSelected' => $oldMinoristaLabel ?? '',
                    'endpoint' => route('catalogo-selector.usuarios'),
                    'params' => ['roles' => 'minorista'],
                    'title' => 'Buscar minorista',
                    'searchPlaceholder' => 'Nombre o usuario…',
                    'required' => true,
                ])
                @include('partials.selector-catalogo', [
                    'id' => 'pdv_unificado_almacen',
                    'name' => 'almacen_mayorista_origenid',
                    'value' => old('almacen_mayorista_origenid', ''),
                    'labelSelected' => $oldAlmacenLabel ?? '',
                    'endpoint' => route('catalogo-selector.almacenes'),
                    'params' => ['ambito' => 'mayorista'],
                    'title' => 'Almacén mayorista',
                    'searchPlaceholder' => 'Nombre…',
                    'required' => true,
                ])
                @endif
                @include('partials.selector-catalogo', [
                    'id' => 'pdv_unificado_punto',
                    'name' => 'puntoventaid',
                    'value' => old('puntoventaid', $oldPuntoId ?? ''),
                    'labelSelected' => $oldPuntoLabel ?? '',
                    'endpoint' => route('catalogo-selector.puntos-venta'),
                    'title' => 'Puntos de venta',
                    'searchPlaceholder' => 'Nombre o dirección…',
                    'required' => true,
                    'params' => [],
                ])
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <button type="button" class="btn btn-sm env-btn-mapa-toggle env-btn-mapa-toggle--pdv" id="btnVerPuntosMapaPdv">
                    <i class="fas fa-map-marked-alt mr-1"></i> Ver en mapa
                </button>
                @if($esOrigenMayoristaPdv ?? $esAdminPdv ?? false)
                <button type="button" class="btn btn-sm env-btn-mapa-toggle env-btn-mapa-toggle--pdv" id="btnFiltrarMinoristaPdv">
                    <i class="fas fa-filter mr-1"></i> Filtrar por Minorista
                </button>
                @endif
                <button type="button" class="btn btn-sm btn-outline-danger ml-auto" id="btnReiniciarRutaPdv">
                    <i class="fas fa-redo mr-1"></i> Reiniciar ruta
                </button>
            </div>
            <div id="mapaPdvDistribucion-wrap" class="mb-2">
                <div id="mapa-pdv-asignacion-flash"></div>
                <div id="mapaPdvDistribucion"></div>
            </div>
            <small id="rutaResumenPdv" class="text-muted pedido-mapa-hint d-block"></small>

            <div class="form-row mt-3">
                <div class="form-group col-md-6 mb-md-0">
                    <label class="small font-weight-bold">Instrucciones de recogida <span class="text-muted">(opcional)</span></label>
                    @if($esOrigenMayoristaPdv ?? $esAdminPdv ?? false)
                    <textarea class="form-control form-control-sm" rows="2"
                        placeholder="Contacto en almacén mayorista, acceso, horario…"></textarea>
                    @else
                    <textarea class="form-control form-control-sm bg-light" rows="2" readonly disabled
                        placeholder="Su almacén mayorista asignado."></textarea>
                    @endif
                </div>
                <div class="form-group col-md-6 mb-0">
                    <label class="small font-weight-bold">Instrucciones de entrega <span class="text-muted">(opcional)</span></label>
                    <textarea name="observaciones" class="form-control form-control-sm" rows="2"
                        placeholder="Horario en tienda, contacto en mostrador…">{{ old('observaciones') }}</textarea>
                </div>
            </div>
        </div>
    </div>
    </div>{{-- wizard step 1 --}}

    {{-- PASO 2: CARGA --}}
    <div class="wizard-step" data-wizard-step="2">
    <div class="env-wizard-panel">
        <div class="env-wizard-panel__head"><i class="fas fa-box-open"></i> Carga y productos</div>
        <div class="env-wizard-panel__body">
            <p class="small text-muted mb-3">
                Elija productos con su <strong>presentación</strong> (empaque) y <strong>lote</strong> en cada almacén mayorista de la ruta.
                La cantidad se indica en unidades de esa presentación; al confirmar, líneas del mismo producto, lote y empaque se suman.
            </p>
            <div id="pdv-productos-recogida-container"></div>
            <div id="pdv-productos-envio-container" class="d-none"></div>
            @error('detalles')
                <small class="text-danger d-block mt-2">{{ $message }}</small>
            @enderror
        </div>
    </div>
    </div>{{-- wizard step 2 --}}

    {{-- PASO 4 (solo minorista — admin/mayorista usa paso compartido) --}}
    @unless($pdvConAsignacion ?? false)
    <div class="wizard-step" data-wizard-step="4">
    <div class="env-wizard-panel">
        <div class="env-wizard-panel__head"><i class="fas fa-clipboard-check"></i> Resumen del envío</div>
        <div class="env-wizard-panel__body">
            <div class="env-confirm-grid">
                <div class="env-confirm-tile env-confirm-tile--origen">
                    <div class="env-confirm-tile__label"><i class="fas fa-warehouse text-warning mr-1"></i> Origen</div>
                    <div class="env-confirm-tile__value" id="conf-pdv-origen">—</div>
                </div>
                <div class="env-confirm-tile env-confirm-tile--destino">
                    <div class="env-confirm-tile__label"><i class="fas fa-store text-orange mr-1"></i> Punto de venta</div>
                    <div class="env-confirm-tile__value" id="conf-pdv-destino">—</div>
                </div>
                <div class="env-confirm-tile env-confirm-tile--carga">
                    <div class="env-confirm-tile__label"><i class="fas fa-box-open text-primary mr-1"></i> Producto</div>
                    <div class="env-confirm-tile__value" id="conf-pdv-producto">—</div>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6"><span class="text-muted small">Fecha entrega</span><div class="font-weight-bold" id="conf-pdv-fecha">—</div></div>
                <div class="col-md-6"><span class="text-muted small">Hora preferida</span><div class="font-weight-bold" id="conf-pdv-hora">—</div></div>
            </div>
            <p class="small text-muted mb-1">Ruta</p>
            <p class="small mb-2" id="conf-pdv-ruta">—</p>
            <div class="alert alert-light border small mb-0 py-2">
                <i class="fas fa-info-circle text-muted mr-1"></i>
                El mayorista revisará stock y preparará el envío. La asignación de chofer y vehículo se realiza al aprobar el pedido.
            </div>
        </div>
    </div>
    </div>{{-- wizard step 4 --}}
    @endunless
</form>
