<?php

use Illuminate\Support\Facades\Route;

// CONTROLADORES WEB
use App\Http\Controllers\Web\ActividadController;
use App\Http\Controllers\Web\ClimaController;
use App\Http\Controllers\Web\CultivoController;
use App\Http\Controllers\Web\EstadoLoteController;
use App\Http\Controllers\Web\EstadoLoteInsumoController;
use App\Http\Controllers\Web\EstadoLoteTipoController;
use App\Http\Controllers\Web\HistorialEstadoLoteController;
use App\Http\Controllers\Web\InsumoController;
use App\Http\Controllers\Web\LoteController;
use App\Http\Controllers\Web\LoteInsumoController;
use App\Http\Controllers\Web\PrioridadController;
use App\Http\Controllers\Web\ProduccionController;
use App\Http\Controllers\Web\TipoActividadController;
use App\Http\Controllers\Web\TipoInsumoController;
use App\Http\Controllers\Web\UnidadMedidaController;
use App\Http\Controllers\Web\GestionUsuariosController;
use App\Http\Controllers\Web\ReporteCentroController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\PedidoController;
use App\Http\Controllers\Web\PuntoVentaController;
use App\Http\Controllers\Web\PuntoVentaInventarioController;
use App\Http\Controllers\Web\PedidoDistribucionController;
use App\Http\Controllers\Web\TrazabilidadPublicaController;
use App\Http\Controllers\Web\RecepcionQrPublicaController;
use App\Http\Controllers\Web\CierreFirmasEstadoController;
use App\Http\Controllers\Web\UserProfileController;

// 🔹 nuevos controladores web de almacenamiento
use App\Http\Controllers\Web\TipoAlmacenController;
use App\Http\Controllers\Web\AlmacenController;
use App\Http\Controllers\Web\AlmacenPlantaCosechaController;
use App\Http\Controllers\Web\AlmacenInventarioController;



// 🔹 Dashboard Controller
use App\Http\Controllers\Web\DashboardController;

// 🔹 Catálogos Controller
use App\Http\Controllers\Web\CatalogoSelectorController;

// 🔹 External API Proxy Controller
use App\Http\Controllers\Web\EnvioDashboardController;
use App\Http\Controllers\Web\EnvioDetalleController;
use App\Http\Controllers\Web\EnvioMandarController;
use App\Http\Controllers\Web\EnvioSeguimientoController;
use App\Http\Controllers\Web\Envios\EnvioDireccionController;
use App\Http\Controllers\Web\Envios\EnvioTransportistaController;
use App\Http\Controllers\Web\Envios\EnvioVehiculoController;
use App\Http\Controllers\Web\Envios\EnvioCatalogoController;
use App\Http\Controllers\Web\Envios\CargaCalculoController;
use App\Http\Controllers\Web\ExternalApiProxyController;
use App\Http\Controllers\Web\CertificacionController;
use App\Http\Controllers\Web\CertificacionPlantaController;
use App\Http\Controllers\Web\ActorAbastecimientoController;
use App\Http\Controllers\Web\ProcesoPlantaController;
use App\Http\Controllers\Web\PlantaCatalogoController;
use App\Http\Controllers\Web\MaquinaPlantaController;
use App\Http\Controllers\Web\VariableEstandarController;
use App\Http\Controllers\Web\PlantillaTransformacionController;
use App\Http\Controllers\Web\AsignacionMultipleController;
use App\Http\Controllers\Web\TransportistaIngresoController;
use App\Http\Controllers\Web\RutaTiempoRealController;
use App\Http\Controllers\Web\LogisticaHubController;
use App\Http\Controllers\Web\RutaMultiEntregaController;
use App\Http\Controllers\Web\IncidenteEnvioController;
use App\Http\Controllers\Web\DocumentoEntregaController;
use App\Http\Controllers\Web\AlmacenMovimientoController;
use App\Http\Controllers\Web\OrgTrack\TransportistaController;
use App\Http\Controllers\Web\OrgTrack\EnvioFusionController;

// ======================================================
// RUTAS PÚBLICAS (SIN LOGIN)


// Página inicial -> redirige al login
Route::get('/', function () {
    return redirect()->route('login');
});

// Formularios auth
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');

Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->name('register.post');
Route::get('/registro-enviado', [AuthController::class, 'registroEnviado'])->name('register.enviado');

Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/trazabilidad/{codigo}', [TrazabilidadPublicaController::class, 'show'])->name('trazabilidad.publica');

// El QR abre la pantalla sin sesión; firmar exige la cuenta del receptor (CROSS-A).
Route::get('/recepcion/{token}', [RecepcionQrPublicaController::class, 'show'])->name('recepcion.publica');


// RUTAS PROTEGIDAS (REQUIEREN ESTAR LOGUEADO)

Route::middleware(['auth', 'cuenta.aprobada'])->group(function () {

    Route::get('/api/cierre/firmas-estado', [CierreFirmasEstadoController::class, 'show'])->name('cierre.firmas-estado');
    Route::post('/recepcion/{token}', [RecepcionQrPublicaController::class, 'firmar'])->name('recepcion.publica.firmar');

    // Perfil de Usuario
    Route::get('/perfil', [UserProfileController::class, 'show'])->name('profile.show');
    Route::put('/perfil', [UserProfileController::class, 'update'])->name('profile.update');
    Route::post('/perfil/bienvenida-vista', [UserProfileController::class, 'marcarBienvenidaVista'])->name('profile.bienvenida.vista');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/login-notificaciones/descartar', [\App\Http\Controllers\Web\LoginNotificacionController::class, 'descartar'])->name('login-notificaciones.descartar');
    Route::post('/notificaciones/{notificacion}/leer', [DashboardController::class, 'marcarNotificacionLeida'])->name('notificaciones.leer');
    Route::post('/notificaciones/descartar-todas', [DashboardController::class, 'descartarTodasNotificaciones'])->name('notificaciones.descartar-todas');
    Route::post('/notificaciones/{notificacion}/descartar', [DashboardController::class, 'descartarNotificacion'])->name('notificaciones.descartar');
    Route::get('/dashboard/panel-planta', [DashboardController::class, 'panelPlanta'])->name('dashboard.panel-planta');
    Route::get('/dashboard/panel-transportista', [DashboardController::class, 'panelTransportista'])->name('dashboard.panel-transportista');
    Route::get('/dashboard/panel-agricola', [DashboardController::class, 'panelAgricola'])->name('dashboard.panel-agricola');
    Route::get('/dashboard/panel-minorista', [DashboardController::class, 'panelMinorista'])->name('dashboard.panel-minorista');
    Route::get('/dashboard/panel-mayorista', [DashboardController::class, 'panelMayorista'])->name('dashboard.panel-mayorista');

    Route::prefix('catalogo-selector')->name('catalogo-selector.')->group(function () {
        Route::get('/usuarios', [CatalogoSelectorController::class, 'usuarios'])->name('usuarios');
        Route::get('/vehiculos', [CatalogoSelectorController::class, 'vehiculos'])->name('vehiculos');
        Route::get('/vehiculos/{vehiculo}/preview-carga', [CatalogoSelectorController::class, 'vehiculoPreviewCarga'])->name('vehiculos.preview-carga');
        Route::get('/rutas-multi', [CatalogoSelectorController::class, 'rutasMulti'])->name('rutas-multi');
        Route::get('/cultivos', [CatalogoSelectorController::class, 'cultivos'])->name('cultivos');
        Route::get('/lotes', [CatalogoSelectorController::class, 'lotes'])->name('lotes');
        Route::get('/insumos', [CatalogoSelectorController::class, 'insumos'])->name('insumos');
        Route::get('/presentaciones-producto', [CatalogoSelectorController::class, 'presentacionesProducto'])->name('presentaciones-producto');
        Route::get('/productos-planta-pdv', [CatalogoSelectorController::class, 'productosPlantaPdv'])->name('productos-planta-pdv');
        Route::get('/productos-mayorista-pdv', [CatalogoSelectorController::class, 'productosMayoristaPdv'])->name('productos-mayorista-pdv');
        Route::get('/stock-presentacion-lote', [CatalogoSelectorController::class, 'stockPresentacionLote'])->name('stock-presentacion-lote');
        Route::get('/insumos-actividad', [CatalogoSelectorController::class, 'insumosActividad'])->name('insumos-actividad');
        Route::get('/pedidos', [CatalogoSelectorController::class, 'pedidos'])->name('pedidos');
        Route::get('/actores', [CatalogoSelectorController::class, 'actores'])->name('actores');
        Route::get('/almacenes', [CatalogoSelectorController::class, 'almacenes'])->name('almacenes');
        Route::get('/puntos-venta', [CatalogoSelectorController::class, 'puntosVenta'])->name('puntos-venta');
        Route::get('/punto-venta-capacidad-pdv', [CatalogoSelectorController::class, 'puntoVentaCapacidadPdv'])->name('punto-venta-capacidad-pdv');
        Route::get('/productos-pedido', [CatalogoSelectorController::class, 'productosPedido'])->name('productos-pedido');
        Route::get('/producciones', [CatalogoSelectorController::class, 'producciones'])->name('producciones');
        Route::get('/producciones-stock-almacen', [CatalogoSelectorController::class, 'produccionesStockAlmacen'])->name('producciones-stock-almacen');
        Route::get('/procesos-planta', [CatalogoSelectorController::class, 'procesosPlanta'])->name('procesos-planta');
        Route::get('/maquinas-planta', [CatalogoSelectorController::class, 'maquinasPlanta'])->name('maquinas-planta');
        Route::get('/plantillas-transformacion', [CatalogoSelectorController::class, 'plantillasTransformacion'])->name('plantillas-transformacion');
    });

    // API endpoints para clima (OpenWeather)
    Route::get('/api/clima', [DashboardController::class, 'getClima'])->name('api.clima');
    Route::get('/api/pronostico', [DashboardController::class, 'getPronostico'])->name('api.pronostico');

    // Actividades: consulta con lotes,read; mutaciones requieren lotes,update (separación planta solo lectura en lotes)
    Route::get('actividades/calendario', [ActividadController::class, 'calendario'])->name('actividades.calendario')->middleware('action.permission:lotes,read');
    Route::get('actividades', [ActividadController::class, 'index'])->name('actividades.index')->middleware('action.permission:lotes,read');
    Route::get('actividades/create', [ActividadController::class, 'create'])->name('actividades.create')->middleware('action.permission:lotes,read');
    Route::post('actividades', [ActividadController::class, 'store'])->name('actividades.store')->middleware('action.permission:lotes,read');
    Route::get('actividades/{actividad}', [ActividadController::class, 'show'])->name('actividades.show')->middleware('action.permission:lotes,read');
    Route::get('actividades/{actividad}/edit', [ActividadController::class, 'edit'])->name('actividades.edit')->middleware('action.permission:lotes,update');
    Route::put('actividades/{actividad}', [ActividadController::class, 'update'])->name('actividades.update')->middleware('action.permission:lotes,update');
    Route::delete('actividades/{actividad}', [ActividadController::class, 'destroy'])->name('actividades.destroy')->middleware('action.permission:lotes,update');
    Route::get('actividades/{actividad}/resumen-completar', [ActividadController::class, 'resumenCompletar'])->name('actividades.resumen-completar');
    Route::post('actividades/{actividad}/marcar-realizada', [ActividadController::class, 'marcarRealizada'])->name('actividades.marcar-realizada');
    Route::get('climas/datos-tiempo', [ClimaController::class, 'datosTiempo'])->name('climas.datos-tiempo');
    Route::get('climas', [ClimaController::class, 'index'])->name('climas.index');
    Route::get('clima', [ClimaController::class, 'index'])->name('clima.index');
    Route::resource('cultivos', CultivoController::class);
    Route::resource('estadolotes', EstadoLoteController::class);
    Route::resource('estado-lote-insumos', EstadoLoteInsumoController::class);
    Route::resource('estado-lote-tipos', EstadoLoteTipoController::class);
    Route::resource('historial-estados-lote', HistorialEstadoLoteController::class);
    Route::get('insumos', [InsumoController::class, 'index'])->name('insumos.index')->middleware('action.permission:inventario,read');
    Route::get('insumos/create', [InsumoController::class, 'create'])->name('insumos.create')->middleware('action.permission:inventario,create');
    Route::post('insumos', [InsumoController::class, 'store'])->name('insumos.store')->middleware('action.permission:inventario,create');
    Route::get('insumos/{insumo}', [InsumoController::class, 'show'])->name('insumos.show')->middleware('action.permission:inventario,read');
    Route::get('insumos/{insumo}/edit', [InsumoController::class, 'edit'])->name('insumos.edit')->middleware('action.permission:inventario,update');
    Route::put('insumos/{insumo}', [InsumoController::class, 'update'])->name('insumos.update')->middleware('action.permission:inventario,update');
    Route::delete('insumos/{insumo}', [InsumoController::class, 'destroy'])->name('insumos.destroy')->middleware('action.permission:inventario,delete');
    Route::get('actores-abastecimiento', [ActorAbastecimientoController::class, 'index'])->name('actores-abastecimiento.index')->middleware('action.permission:inventario,read');
    Route::post('actores-abastecimiento', [ActorAbastecimientoController::class, 'store'])->name('actores-abastecimiento.store')->middleware('action.permission:inventario,update');
    Route::put('actores-abastecimiento/{actores_abastecimiento}', [ActorAbastecimientoController::class, 'update'])->name('actores-abastecimiento.update')->middleware('action.permission:inventario,update');
    Route::delete('actores-abastecimiento/{actores_abastecimiento}', [ActorAbastecimientoController::class, 'destroy'])->name('actores-abastecimiento.destroy')->middleware('action.permission:inventario,update');
    Route::get('lotes/mapa', [LoteController::class, 'mapa'])->name('lotes.mapa')->middleware('action.permission:lotes,read');
    Route::get('lotes', [LoteController::class, 'index'])->name('lotes.index')->middleware('action.permission:lotes,read');
    Route::get('lotes/create', [LoteController::class, 'create'])->name('lotes.create')->middleware('action.permission:lotes,create');
    Route::get('lotes/siguiente-nombre', [LoteController::class, 'siguienteNombre'])->name('lotes.siguiente-nombre')->middleware('action.permission:lotes,create');
    Route::get('lotes/planificar-cosecha', [LoteController::class, 'planificarCosecha'])->name('lotes.planificar-cosecha')->middleware('action.permission:lotes,read');
    Route::get('lotes/validar-ubicacion', [LoteController::class, 'validarUbicacion'])->name('lotes.validar-ubicacion')->middleware('action.permission:lotes,read');
    Route::post('lotes', [LoteController::class, 'store'])->name('lotes.store')->middleware('action.permission:lotes,create');
    Route::get('lotes/{lote}/trazabilidad', [LoteController::class, 'trazabilidad'])->name('lotes.trazabilidad')->middleware('action.permission:lotes,read');
    Route::get('lotes/{lote}/siembra/completar', [ActividadController::class, 'completarSiembra'])->name('lotes.siembra.completar')->middleware('action.permission:lotes,read');
    Route::post('lotes/{lote}/siembra/completar', [ActividadController::class, 'storeCompletarSiembra'])->name('lotes.siembra.completar.store')->middleware('action.permission:lotes,read');
    Route::get('lotes/{lote}/siembra', [ActividadController::class, 'createSiembra'])->name('lotes.siembra.create')->middleware('action.permission:lotes,read');
    Route::post('lotes/{lote}/siembra', [ActividadController::class, 'storeSiembra'])->name('lotes.siembra.store')->middleware('action.permission:lotes,read');
    Route::post('lotes/{lote}/asignar-siembra', [ActividadController::class, 'asignarSiembra'])->name('lotes.siembra.asignar')->middleware('action.permission:lotes,read');
    Route::post('lotes/{lote}/enviar-almacen', [LoteController::class, 'enviarAlmacen'])->name('lotes.enviar-almacen')->middleware('action.permission:lotes,update');
    Route::get('lotes/{lote}/cambiar-estado', [LoteController::class, 'cambiarEstadoForm'])->name('lotes.cambiar-estado')->middleware('action.permission:lotes,update');
    Route::post('lotes/{lote}/cambiar-estado', [LoteController::class, 'cambiarEstadoStore'])->name('lotes.cambiar-estado.store')->middleware('action.permission:lotes,update');
    Route::get('lotes/{lote}/ubicacion', [LoteController::class, 'ubicacion'])->name('lotes.ubicacion')->middleware('action.permission:lotes,read');
    Route::get('lotes/{lote}', [LoteController::class, 'show'])->name('lotes.show')->middleware('action.permission:lotes,read');
    Route::get('lotes/{lote}/edit', [LoteController::class, 'edit'])->name('lotes.edit')->middleware('action.permission:lotes,update');
    Route::put('lotes/{lote}', [LoteController::class, 'update'])->name('lotes.update')->middleware('action.permission:lotes,update');
    Route::delete('lotes/{lote}', [LoteController::class, 'destroy'])->name('lotes.destroy')->middleware('action.permission:lotes,delete');
    Route::get('lote-insumos', fn () => redirect()->route('actividades.create')->with('info', 'Las aplicaciones de insumos se registran al crear una actividad en el lote.'))->name('lote-insumos.index')->middleware('action.permission:inventario,read');
    Route::get('lote-insumos/create', fn () => redirect()->route('actividades.create'))->name('lote-insumos.create')->middleware('action.permission:inventario,create');
    Route::post('lote-insumos', fn () => redirect()->route('actividades.create')->with('info', 'Registre la aplicación desde Actividades.'))->name('lote-insumos.store')->middleware('action.permission:inventario,create');
    Route::get('lote-insumos/{lote_insumo}', fn () => redirect()->route('actividades.index'))->name('lote-insumos.show')->middleware('action.permission:inventario,read');
    Route::get('lote-insumos/{lote_insumo}/edit', fn () => redirect()->route('actividades.index'))->name('lote-insumos.edit')->middleware('action.permission:inventario,update');
    Route::put('lote-insumos/{lote_insumo}', fn () => redirect()->route('actividades.index'))->name('lote-insumos.update')->middleware('action.permission:inventario,update');
    Route::delete('lote-insumos/{lote_insumo}', fn () => redirect()->route('actividades.index'))->name('lote-insumos.destroy')->middleware('action.permission:inventario,delete');
    Route::resource('prioridades', PrioridadController::class)
        ->parameters(['prioridades' => 'prioridad']);
    Route::resource('producciones', ProduccionController::class)
        ->parameters(['producciones' => 'produccion']);
    Route::resource('procesos-planta', ProcesoPlantaController::class)->only(['index', 'show', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('plantillas-transformacion/{plantillas_transformacion}/parametros', [PlantillaTransformacionController::class, 'parametrosJson'])
        ->name('plantillas-transformacion.parametros');
    Route::resource('plantillas-transformacion', PlantillaTransformacionController::class)
        ->parameters(['plantillas-transformacion' => 'plantillas_transformacion']);
    Route::resource('maquinas-planta', MaquinaPlantaController::class)->only(['index', 'show', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('maquinas-planta/{maquinas_plantum}/variables-sugeridas', [MaquinaPlantaController::class, 'variablesSugeridas'])
        ->name('maquinas-planta.variables-sugeridas');
    Route::patch('maquinas-planta/{maquinas_plantum}/toggle-activo', [MaquinaPlantaController::class, 'toggleActivo'])
        ->name('maquinas-planta.toggle-activo');
    Route::resource('variables-estandar', VariableEstandarController::class)
        ->parameters(['variables-estandar' => 'variables_estandar']);

    Route::prefix('produccion-planta/catalogos')->name('produccion-planta.catalogos.')->group(function () {
        Route::get('{tipo}', [PlantaCatalogoController::class, 'index'])->name('index');
        Route::get('{tipo}/create', [PlantaCatalogoController::class, 'create'])->name('create');
        Route::post('{tipo}', [PlantaCatalogoController::class, 'store'])->name('store');
        Route::get('{tipo}/{id}/edit', [PlantaCatalogoController::class, 'edit'])->name('edit')->whereNumber('id');
        Route::put('{tipo}/{id}', [PlantaCatalogoController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('{tipo}/{id}', [PlantaCatalogoController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });

    Route::get('procesamiento/calcular-planificacion', [\App\Http\Controllers\Web\LoteProduccionController::class, 'calcularPlanificacion'])
        ->name('procesamiento.calcular-planificacion')
        ->middleware('can:lote_produccion.create');
    Route::get('procesamiento', [\App\Http\Controllers\Web\LoteProduccionController::class, 'index'])
        ->name('procesamiento.index')
        ->middleware('action.permission:lote_produccion,read');
    Route::get('procesamiento/siguiente-nombre', [\App\Http\Controllers\Web\LoteProduccionController::class, 'siguienteNombre'])
        ->name('procesamiento.siguiente-nombre')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento', [\App\Http\Controllers\Web\LoteProduccionController::class, 'store'])
        ->name('procesamiento.store')
        ->middleware('action.permission:lote_produccion,create');
    Route::get('procesamiento/{loteProduccion}/edit', [\App\Http\Controllers\Web\LoteProduccionController::class, 'edit'])
        ->name('procesamiento.edit')
        ->middleware('action.permission:lote_produccion,create');
    Route::put('procesamiento/{loteProduccion}', [\App\Http\Controllers\Web\LoteProduccionController::class, 'update'])
        ->name('procesamiento.update')
        ->middleware('action.permission:lote_produccion,create');
    Route::delete('procesamiento/{loteProduccion}', [\App\Http\Controllers\Web\LoteProduccionController::class, 'destroy'])
        ->name('procesamiento.destroy')
        ->middleware('action.permission:lote_produccion,create');
    Route::get('procesamiento/{loteProduccion}', [\App\Http\Controllers\Web\LoteProduccionController::class, 'show'])
        ->name('procesamiento.show')
        ->middleware('action.permission:lote_produccion,read');
    Route::post('procesamiento/{loteProduccion}/etapa', [\App\Http\Controllers\Web\LoteProduccionController::class, 'registrarEtapa'])
        ->name('procesamiento.registrar-etapa')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/asignar-etapa', [\App\Http\Controllers\Web\LoteProduccionController::class, 'asignarEtapa'])
        ->name('procesamiento.asignar-etapa')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/asignar-plan-etapas', [\App\Http\Controllers\Web\LoteProduccionController::class, 'asignarPlanEtapas'])
        ->name('procesamiento.asignar-plan-etapas')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/cerrar-fase', [\App\Http\Controllers\Web\LoteProduccionController::class, 'cerrarFase'])
        ->name('procesamiento.cerrar-fase')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/cambiar-fase', [\App\Http\Controllers\Web\LoteProduccionController::class, 'cambiarFase'])
        ->name('procesamiento.cambiar-fase')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/reiniciar-todo-asignaciones', [\App\Http\Controllers\Web\LoteProduccionController::class, 'reiniciarTodoAsignaciones'])
        ->name('procesamiento.reiniciar-todo-asignaciones')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/reiniciar-plan-asignaciones', [\App\Http\Controllers\Web\LoteProduccionController::class, 'reiniciarTodoAsignaciones'])
        ->name('procesamiento.reiniciar-plan-asignaciones')
        ->middleware('action.permission:lote_produccion,create');
    Route::put('procesamiento/{loteProduccion}/ruta', [\App\Http\Controllers\Web\LoteProduccionController::class, 'actualizarRuta'])
        ->name('procesamiento.actualizar-ruta')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/asignaciones-etapa/{asignacion}/completar', [\App\Http\Controllers\Web\LoteProduccionController::class, 'completarEtapaAsignada'])
        ->name('procesamiento.completar-etapa-asignada')
        ->middleware('action.permission:lote_produccion,read');
    Route::get('mis-tareas-planta', [\App\Http\Controllers\Web\TareaPlantaController::class, 'index'])
        ->name('tareas-planta.index')
        ->middleware('action.permission:lote_produccion,read');
    Route::get('mis-tareas-planta/{asignacion}', [\App\Http\Controllers\Web\TareaPlantaController::class, 'show'])
        ->name('tareas-planta.show')
        ->middleware('action.permission:lote_produccion,read');
    Route::post('mis-tareas-planta/{asignacion}/completar', [\App\Http\Controllers\Web\TareaPlantaController::class, 'completar'])
        ->name('tareas-planta.completar')
        ->middleware('action.permission:lote_produccion,read');
    Route::post('procesamiento/{loteProduccion}/certificar', [\App\Http\Controllers\Web\LoteProduccionController::class, 'certificar'])
        ->name('procesamiento.certificar')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/almacenar', [\App\Http\Controllers\Web\LoteProduccionController::class, 'almacenar'])
        ->name('procesamiento.almacenar')
        ->middleware('action.permission:lote_produccion,create');
    Route::post('procesamiento/{loteProduccion}/completar', [\App\Http\Controllers\Web\LoteProduccionController::class, 'completar'])
        ->name('procesamiento.completar')
        ->middleware('action.permission:lote_produccion,create');

    Route::resource('tipo-actividad', TipoActividadController::class);
    Route::resource('tipo-insumos', TipoInsumoController::class);
    Route::resource('unidades-medida', UnidadMedidaController::class)
        ->parameters(['unidades-medida' => 'unidad']);
    Route::resource('tipoalmacenes', TipoAlmacenController::class)
        ->parameters(['tipoalmacenes' => 'tipoalmacen']);
    Route::redirect('almacenes', '/almacen-agricola');
    Route::redirect('almacen-movimientos', '/almacen-agricola/movimientos');
    Route::redirect('almacen-reportes', '/almacen-agricola/movimientos/reportes');

    $registrarModuloAlmacen = function (string $prefijo, string $ambito) {
        Route::prefix($prefijo)
            ->name($prefijo.'.')
            ->middleware(['almacen.ambito:'.$ambito])
            ->group(function () use ($ambito) {
                Route::get('/', [AlmacenController::class, 'index'])->name('index')->middleware('action.permission:inventario,read');
                Route::get('/create', [AlmacenController::class, 'create'])->name('create')->middleware('action.permission:inventario,create');
                Route::get('/selector-ubicacion', [AlmacenController::class, 'selectorUbicacion'])->name('selector-ubicacion')->middleware('action.permission:inventario,read');
                Route::get('/sugerir-nombre', [AlmacenController::class, 'sugerirNombre'])->name('sugerir-nombre')->middleware('action.permission:inventario,read');
                Route::post('/', [AlmacenController::class, 'store'])->name('store')->middleware('action.permission:inventario,create');

                Route::get('/movimientos/referencias-disponibles', [AlmacenMovimientoController::class, 'referenciasDisponibles'])
                    ->name('movimientos.referencias')
                    ->middleware('action.permission:almacen_movimientos,read');
                Route::get('/movimientos/reportes', [AlmacenMovimientoController::class, 'reportes'])
                    ->name('movimientos.reportes')
                    ->middleware('action.permission:almacen_reportes,read');
                Route::get('/movimientos', [AlmacenMovimientoController::class, 'index'])
                    ->name('movimientos.index')
                    ->middleware('action.permission:almacen_movimientos,read');
                Route::get('/movimientos/cosecha/{produccionAlmacenamiento}', [AlmacenMovimientoController::class, 'showCosecha'])
                    ->whereNumber('produccionAlmacenamiento')
                    ->name('movimientos.cosecha.show')
                    ->middleware('action.permission:almacen_movimientos,read');
                Route::get('/movimientos/{almacenMovimiento}', [AlmacenMovimientoController::class, 'show'])
                    ->whereNumber('almacenMovimiento')
                    ->name('movimientos.show')
                    ->middleware('action.permission:almacen_movimientos,read');
                Route::get('/movimientos/{naturaleza}/create', [AlmacenMovimientoController::class, 'create'])
                    ->whereIn('naturaleza', ['ingreso', 'salida'])
                    ->name('movimientos.create')
                    ->middleware('action.permission:almacen_movimientos,read');
                Route::post('/movimientos/{naturaleza}', [AlmacenMovimientoController::class, 'store'])
                    ->whereIn('naturaleza', ['ingreso', 'salida'])
                    ->name('movimientos.store')
                    ->middleware('action.permission:almacen_movimientos,read');

                Route::get('/{almacen}/inventario/{insumo}', [AlmacenInventarioController::class, 'show'])
                    ->whereNumber(['almacen', 'insumo'])
                    ->name('inventario.show')
                    ->middleware('action.permission:inventario,read');
                Route::get('/{almacen}/inventario/{insumo}/edit', [AlmacenInventarioController::class, 'edit'])
                    ->whereNumber(['almacen', 'insumo'])
                    ->name('inventario.edit')
                    ->middleware('action.permission:inventario,update');
                Route::put('/{almacen}/inventario/{insumo}', [AlmacenInventarioController::class, 'update'])
                    ->whereNumber(['almacen', 'insumo'])
                    ->name('inventario.update')
                    ->middleware('action.permission:inventario,update');
                Route::delete('/{almacen}/inventario/{insumo}', [AlmacenInventarioController::class, 'destroy'])
                    ->whereNumber(['almacen', 'insumo'])
                    ->name('inventario.destroy')
                    ->middleware('action.permission:inventario,delete');

                Route::get('/{almacen}/cosecha/{clave}', [AlmacenPlantaCosechaController::class, 'show'])
                    ->whereNumber('almacen')
                    ->where('clave', '[a-z0-9\-]+')
                    ->name('cosecha.show')
                    ->middleware('action.permission:inventario,read');
                Route::delete('/{almacen}/cosecha/{clave}/produccion/{produccionAlmacenamiento}', [AlmacenPlantaCosechaController::class, 'destroyProduccion'])
                    ->whereNumber(['almacen', 'produccionAlmacenamiento'])
                    ->where('clave', '[a-z0-9\-]+')
                    ->name('cosecha.destroy-produccion')
                    ->middleware('action.permission:inventario,delete');
                Route::delete('/{almacen}/cosecha/{clave}/recepcion/{insumo}', [AlmacenPlantaCosechaController::class, 'destroyRecepcion'])
                    ->whereNumber(['almacen', 'insumo'])
                    ->where('clave', '[a-z0-9\-]+')
                    ->name('cosecha.destroy-recepcion')
                    ->middleware('action.permission:inventario,delete');

                Route::get('/{almacen}', [AlmacenController::class, 'show'])
                    ->whereNumber('almacen')
                    ->name('show')
                    ->middleware('action.permission:inventario,read');
                Route::get('/{almacen}/edit', [AlmacenController::class, 'edit'])
                    ->whereNumber('almacen')
                    ->name('edit')
                    ->middleware('action.permission:inventario,update');
                Route::put('/{almacen}', [AlmacenController::class, 'update'])
                    ->whereNumber('almacen')
                    ->name('update')
                    ->middleware('action.permission:inventario,update');
                Route::delete('/{almacen}', [AlmacenController::class, 'destroy'])
                    ->whereNumber('almacen')
                    ->name('destroy')
                    ->middleware('action.permission:inventario,delete');
            });
    };

    Route::redirect('/almacen-agricola/almacenamiento-produccion', '/almacen-agricola/movimientos', 301);
    Route::redirect('/almacen-agricola/almacenamiento-produccion/{any}', '/almacen-agricola/movimientos')->where('any', '.*');

    $registrarModuloAlmacen('almacen-agricola', \App\Support\AlmacenAmbito::AGRICOLA);
    $registrarModuloAlmacen('almacen-planta', \App\Support\AlmacenAmbito::PLANTA);
    $registrarModuloAlmacen('almacen-mayorista', \App\Support\AlmacenAmbito::MAYORISTA);

    Route::prefix('almacen-mayorista/traslados-planta')->name('almacen-mayorista.traslados-planta.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'index'])
            ->name('index')
            ->middleware('action.permission:inventario,read');
        Route::get('/{ruta}', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'show'])
            ->name('show')
            ->whereNumber('ruta')
            ->middleware('action.permission:inventario,read');
        Route::get('/{ruta}/cierre', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'panel'])
            ->name('cierre.panel')
            ->whereNumber('ruta');
        Route::post('/{ruta}/cierre/condiciones', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'registrarCondiciones'])
            ->name('cierre.condiciones')
            ->whereNumber('ruta');
        Route::patch('/{ruta}/cierre/confirmar-llegada', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'confirmarLlegada'])
            ->name('cierre.confirmar-llegada')
            ->whereNumber('ruta');
        Route::post('/{ruta}/cierre/incidentes', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'registrarIncidentes'])
            ->name('cierre.incidentes')
            ->whereNumber('ruta');
        Route::post('/{ruta}/cierre/firma-transportista', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'firmaTransportista'])
            ->name('cierre.firma-transportista')
            ->whereNumber('ruta');
        Route::post('/{ruta}/cierre/firma-recepcion', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'firmaRecepcion'])
            ->name('cierre.firma-recepcion')
            ->whereNumber('ruta');
        Route::post('/{ruta}/cierre/finalizar', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'finalizar'])
            ->name('cierre.finalizar')
            ->whereNumber('ruta');
    });

    Route::get('/certificaciones', [CertificacionController::class, 'index'])->name('certificaciones.index')->middleware('action.permission:certificaciones,read');
    Route::post('/certificaciones/masivo', [CertificacionController::class, 'storeBatch'])->name('certificaciones.store-bulk')->middleware('action.permission:certificaciones,create');
    Route::post('/certificaciones', [CertificacionController::class, 'store'])->name('certificaciones.store')->middleware('action.permission:certificaciones,create');
    Route::get('/certificaciones/{certificacion}', [CertificacionController::class, 'show'])->name('certificaciones.show')->middleware('action.permission:certificaciones,read');
    Route::post('/certificaciones/{certificacion}/blockchain/sincronizar', [CertificacionController::class, 'sincronizarBlockchain'])->name('certificaciones.blockchain.sincronizar')->middleware('action.permission:certificaciones,read');

    Route::get('/certificaciones-planta', [CertificacionPlantaController::class, 'index'])->name('certificaciones-planta.index')->middleware('action.permission:lote_produccion,read');
    Route::get('/certificaciones-planta/{evaluacionFinalLoteProduccion}', [CertificacionPlantaController::class, 'show'])->name('certificaciones-planta.show')->middleware('action.permission:lote_produccion,read');
    // ==============================
    // PEDIDOS (CLIENTES EXTERNOS)
    // ==============================
    Route::get('pedidos', [PedidoController::class, 'index'])->name('pedidos.index')->middleware('action.permission:pedidos,read');
    Route::get('pedidos/create', [PedidoController::class, 'create'])->name('pedidos.create')->middleware('action.permission:pedidos,create');
    Route::post('pedidos/calcular-costo-envio', [PedidoController::class, 'calcularCostoEnvio'])->name('pedidos.calcular-costo-envio')->middleware('action.permission:pedidos,create');
    Route::post('pedidos', [PedidoController::class, 'store'])->name('pedidos.store')->middleware('action.permission:pedidos,create');
    Route::post('pedidos/api/carga/calcular', [\App\Http\Controllers\Web\Envios\CargaCalculoController::class, 'calcular'])->name('pedidos.api.carga.calcular')->middleware('action.permission:pedidos,create');
    Route::get('pedidos/api/calibres', [\App\Http\Controllers\Web\Envios\CargaCalculoController::class, 'calibres'])->name('pedidos.api.calibres')->middleware('action.permission:pedidos,create');
    Route::post('pedidos/api/capacidad/validar', [\App\Http\Controllers\Web\Envios\EnvioCapacidadController::class, 'validar'])->name('pedidos.api.capacidad.validar')->middleware('action.permission:pedidos,create');
    Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])->name('pedidos.show')->middleware('action.permission:pedidos,read');
    Route::get('pedidos/{pedido}/edit', [PedidoController::class, 'edit'])->name('pedidos.edit')->middleware('action.permission:pedidos,update');
    Route::put('pedidos/{pedido}', [PedidoController::class, 'update'])->name('pedidos.update')->middleware('action.permission:pedidos,update');
    Route::post('pedidos/{pedido}/asignar-transportista', [PedidoController::class, 'asignarTransportista'])->name('pedidos.asignar-transportista')->middleware('action.permission:pedidos,update');
    Route::post('pedidos/{pedido}/confirmar-carga-envio', [PedidoController::class, 'confirmarCargaEnvio'])->name('pedidos.confirmar-carga-envio')->middleware('action.permission:pedidos,update');
    Route::post('pedidos/{pedido}/confirmar-llegada-planta', [PedidoController::class, 'confirmarLlegadaPlanta'])->name('pedidos.confirmar-llegada-planta')->middleware('action.permission:recepcion_planta,confirm');
    Route::delete('pedidos/{pedido}', [PedidoController::class, 'destroy'])->name('pedidos.destroy')->middleware('action.permission:pedidos,delete');

    // Punto de venta / minoristas
    Route::prefix('punto-venta')->name('punto-venta.')->group(function () {
        Route::get('puntos', [PuntoVentaController::class, 'index'])->name('puntos.index')->middleware('action.permission:punto_venta,read');
        Route::get('puntos/create', [PuntoVentaController::class, 'create'])->name('puntos.create')->middleware('action.permission:punto_venta,create');
        Route::post('puntos', [PuntoVentaController::class, 'store'])->name('puntos.store')->middleware('action.permission:punto_venta,create');
        Route::get('puntos/{punto}', [PuntoVentaController::class, 'show'])->name('puntos.show')->middleware('action.permission:punto_venta,read');
        Route::get('puntos/{punto}/edit', [PuntoVentaController::class, 'edit'])->name('puntos.edit')->middleware('action.permission:punto_venta,update');
        Route::put('puntos/{punto}', [PuntoVentaController::class, 'update'])->name('puntos.update')->middleware('action.permission:punto_venta,update');
        Route::delete('puntos/{punto}', [PuntoVentaController::class, 'destroy'])->name('puntos.destroy')->middleware('action.permission:punto_venta,delete');

        Route::get('inventario', [PuntoVentaInventarioController::class, 'index'])->name('inventario.index')->middleware('action.permission:punto_venta,read');
        Route::get('puntos/{punto}/inventario/{insumo}/edit', [PuntoVentaInventarioController::class, 'edit'])->name('puntos.inventario.edit')->middleware('action.permission:punto_venta,update');
        Route::put('puntos/{punto}/inventario/{insumo}', [PuntoVentaInventarioController::class, 'update'])->name('puntos.inventario.update')->middleware('action.permission:punto_venta,update');
        Route::delete('puntos/{punto}/inventario/{insumo}', [PuntoVentaInventarioController::class, 'destroy'])->name('puntos.inventario.destroy')->middleware('action.permission:punto_venta,delete');
        Route::get('puntos/{punto}/inventario/{insumo}/qr', [PuntoVentaInventarioController::class, 'qr'])->name('puntos.inventario.qr')->middleware('action.permission:punto_venta,read');

        Route::get('pedidos', [PedidoDistribucionController::class, 'index'])->name('pedidos.index')->middleware('action.permission:pedidos_distribucion,read');
        Route::get('pedidos/create', [PedidoDistribucionController::class, 'create'])->name('pedidos.create')->middleware('action.permission:pedidos_distribucion,create');
        Route::post('pedidos', [PedidoDistribucionController::class, 'store'])->name('pedidos.store')->middleware('action.permission:pedidos_distribucion,create');
        Route::get('pedidos/{pedido}', [PedidoDistribucionController::class, 'show'])->name('pedidos.show')->middleware('action.permission:pedidos_distribucion,read');
        Route::get('pedidos/{pedido}/ubicacion/punto-venta', [PedidoDistribucionController::class, 'ubicacionPuntoVenta'])->name('pedidos.ubicacion.pdv')->middleware('action.permission:pedidos_distribucion,read');
        Route::get('pedidos/{pedido}/ubicacion/almacen', [PedidoDistribucionController::class, 'ubicacionAlmacen'])->name('pedidos.ubicacion.almacen')->middleware('action.permission:pedidos_distribucion,read');
        Route::post('pedidos/{pedido}/aceptar', [PedidoDistribucionController::class, 'aceptar'])->name('pedidos.aceptar')->middleware('action.permission:pedidos_distribucion,update');
        Route::post('pedidos/{pedido}/rechazar', [PedidoDistribucionController::class, 'rechazar'])->name('pedidos.rechazar')->middleware('action.permission:pedidos_distribucion,update');
        Route::post('pedidos/{pedido}/designar-transportista', [PedidoDistribucionController::class, 'designarTransportista'])->name('pedidos.designar-transportista')->middleware('action.permission:pedidos_distribucion,update');
        Route::post('pedidos/{pedido}/validar-capacidad-vehiculo', [PedidoDistribucionController::class, 'validarCapacidadVehiculo'])->name('pedidos.validar-capacidad-vehiculo')->middleware('action.permission:pedidos_distribucion,read');
        Route::post('pedidos/{pedido}/marcar-enviado', [PedidoDistribucionController::class, 'marcarEnviado'])->name('pedidos.marcar-enviado')->middleware('action.permission:pedidos_distribucion,update');
        Route::patch('pedidos/{pedido}/empezar-ruta', [PedidoDistribucionController::class, 'empezarRuta'])->name('pedidos.empezar-ruta');
        Route::put('pedidos/{pedido}', [PedidoDistribucionController::class, 'update'])->name('pedidos.update')->middleware('action.permission:pedidos_distribucion,update');
        Route::post('pedidos/{pedido}/reabrir-revision', [PedidoDistribucionController::class, 'reabrirRevision'])->name('pedidos.reabrir-revision')->middleware('action.permission:pedidos_distribucion,update');
        Route::post('pedidos/{pedido}/confirmar-recepcion', [PedidoDistribucionController::class, 'confirmarRecepcion'])->name('pedidos.confirmar-recepcion')->middleware('action.permission:pedidos_distribucion,update');
        Route::post('pedidos/{pedido}/confirmar-envio-mayorista', [PedidoDistribucionController::class, 'confirmarEnvioIniciadoMayorista'])->name('pedidos.confirmar-envio-mayorista')->middleware('action.permission:pedidos_distribucion,update');
        Route::delete('pedidos/{pedido}', [PedidoDistribucionController::class, 'destroy'])->name('pedidos.destroy')->middleware('action.permission:pedidos_distribucion,update');

        Route::get('rutas-distribucion', [\App\Http\Controllers\Web\RutaDistribucionController::class, 'index'])->name('rutas.index')->middleware('action.permission:pedidos_distribucion,update');
        Route::get('rutas-distribucion/create', [\App\Http\Controllers\Web\RutaDistribucionController::class, 'create'])->name('rutas.create')->middleware('action.permission:pedidos_distribucion,update');
        Route::post('rutas-distribucion', [\App\Http\Controllers\Web\RutaDistribucionController::class, 'store'])->name('rutas.store')->middleware('action.permission:pedidos_distribucion,update');
        Route::get('rutas-distribucion/{ruta}', [\App\Http\Controllers\Web\RutaDistribucionController::class, 'show'])->name('rutas.show')->middleware('action.permission:pedidos_distribucion,read');
        Route::get('rutas-distribucion/{ruta}/cierre', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'panel'])->name('rutas.cierre.panel')->whereNumber('ruta');
        Route::post('rutas-distribucion/{ruta}/cierre/condiciones', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'registrarCondiciones'])->name('rutas.cierre.condiciones')->whereNumber('ruta');
        Route::patch('rutas-distribucion/{ruta}/cierre/confirmar-llegada', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'confirmarLlegada'])->name('rutas.cierre.confirmar-llegada')->whereNumber('ruta');
        Route::post('rutas-distribucion/{ruta}/cierre/incidentes', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'registrarIncidentes'])->name('rutas.cierre.incidentes')->whereNumber('ruta');
        Route::post('rutas-distribucion/{ruta}/cierre/firma-transportista', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'firmaTransportista'])->name('rutas.cierre.firma-transportista')->whereNumber('ruta');
        Route::post('rutas-distribucion/{ruta}/cierre/firma-recepcion', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'firmaRecepcion'])->name('rutas.cierre.firma-recepcion')->whereNumber('ruta');
        Route::post('rutas-distribucion/{ruta}/cierre/finalizar', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'finalizar'])->name('rutas.cierre.finalizar')->whereNumber('ruta');
        Route::patch('rutas-distribucion/{ruta}/empezar-ruta', [\App\Http\Controllers\Web\RutaDistribucionController::class, 'empezarRuta'])->name('rutas.empezar-ruta')->whereNumber('ruta');
    });


    Route::prefix('planta/solicitudes-produccion')->name('planta.solicitudes-produccion.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\SolicitudProduccionPlantaController::class, 'index'])
            ->name('index')
            ->middleware('action.permission:pedidos_distribucion,read');
        Route::get('{solicitud}', [\App\Http\Controllers\Web\SolicitudProduccionPlantaController::class, 'show'])
            ->name('show')
            ->middleware('action.permission:pedidos_distribucion,read');
        Route::post('{solicitud}/aceptar', [\App\Http\Controllers\Web\SolicitudProduccionPlantaController::class, 'aceptar'])
            ->name('aceptar')
            ->middleware('action.permission:pedidos_distribucion,update');
        Route::post('{solicitud}/produccion', [\App\Http\Controllers\Web\SolicitudProduccionPlantaController::class, 'marcarEnProduccion'])
            ->name('produccion')
            ->middleware('action.permission:pedidos_distribucion,update');
        Route::post('{solicitud}/completar', [\App\Http\Controllers\Web\SolicitudProduccionPlantaController::class, 'completar'])
            ->name('completar')
            ->middleware('action.permission:pedidos_distribucion,update');
        Route::post('{solicitud}/rechazar', [\App\Http\Controllers\Web\SolicitudProduccionPlantaController::class, 'rechazar'])
            ->name('rechazar')
            ->middleware('action.permission:pedidos_distribucion,update');
    });


    // GESTIÓN UNIFICADA DE USUARIOS

    Route::get('/gestion-usuarios', [GestionUsuariosController::class, 'index'])
        ->middleware('action.permission:usuarios,read')
        ->name('gestion.index');

    Route::get('/gestion-usuarios/crear', [GestionUsuariosController::class, 'create'])
        ->middleware('action.permission:usuarios,create')
        ->name('gestion.create');

    Route::get('/gestion-usuarios/{usuario}/editar', [GestionUsuariosController::class, 'edit'])
        ->middleware('action.permission:usuarios,update')
        ->name('gestion.edit');

    Route::get('/gestion-usuarios/{usuario}', [GestionUsuariosController::class, 'show'])
        ->middleware('action.permission:usuarios,read')
        ->name('gestion.show');

    // CENTRO DE REPORTES
    Route::prefix('reportes')->name('reportes.')->group(function () {
        Route::get('/', [ReporteCentroController::class, 'index'])->name('index');
        Route::get('/envios-estado', [ReporteCentroController::class, 'enviosEstado'])->name('envios-estado');
        Route::get('/stock-ambito', [ReporteCentroController::class, 'stockAmbito'])->name('stock-ambito');
        Route::get('/transportistas', [ReporteCentroController::class, 'transportistas'])->name('transportistas');
        Route::get('/traslados-planta-mayorista', [ReporteCentroController::class, 'trasladosPlantaMayorista'])->name('traslados-planta-mayorista');
        Route::get('/pedidos-pdv', [ReporteCentroController::class, 'pedidosPdv'])->name('pedidos-pdv');
        Route::get('/productos-terminados', [ReporteCentroController::class, 'productosTerminados'])->name('productos-terminados');
    });

    // CRUD Usuarios
    Route::post('/gestion-usuarios/usuario', [GestionUsuariosController::class, 'storeUsuario'])
        ->middleware('action.permission:usuarios,create')
        ->name('gestion.usuario.store');

    Route::put('/gestion-usuarios/usuario/{usuario}', [GestionUsuariosController::class, 'updateUsuario'])
        ->middleware('action.permission:usuarios,update')
        ->name('gestion.usuario.update');

    Route::delete('/gestion-usuarios/usuario/{usuario}', [GestionUsuariosController::class, 'destroyUsuario'])
        ->middleware('action.permission:usuarios,delete')
        ->name('gestion.usuario.destroy');

    Route::post('/gestion-usuarios/{usuario}/aprobar', [GestionUsuariosController::class, 'aprobarSolicitud'])
        ->middleware('action.permission:solicitudes,approve')
        ->name('gestion.solicitud.aprobar');

    Route::post('/gestion-usuarios/{usuario}/rechazar', [GestionUsuariosController::class, 'rechazarSolicitud'])
        ->middleware('action.permission:solicitudes,approve')
        ->name('gestion.solicitud.rechazar');

    // CRUD Roles
    Route::post('/gestion-usuarios/rol', [GestionUsuariosController::class, 'storeRol'])
        ->middleware('action.permission:usuarios,admin')
        ->name('gestion.rol.store');

    Route::put('/gestion-usuarios/rol/{role}', [GestionUsuariosController::class, 'updateRol'])
        ->middleware('action.permission:usuarios,admin')
        ->name('gestion.rol.update');

    Route::delete('/gestion-usuarios/rol/{role}', [GestionUsuariosController::class, 'destroyRol'])
        ->middleware('action.permission:usuarios,admin')
        ->name('gestion.rol.destroy');


    // ENVÍOS

    Route::prefix('envios')->name('envios.')->group(function () {
        Route::get('/mandar', [EnvioMandarController::class, 'create'])->name('mandar');
        Route::get('/crear', [EnvioMandarController::class, 'create'])->name('crear');
        Route::get('/seguimiento', [EnvioSeguimientoController::class, 'index'])->name('seguimiento')->middleware('action.permission:envios,read');
        Route::get('/admin', [EnvioDashboardController::class, 'index'])->name('admin')->middleware('action.permission:envios,admin');
        Route::resource('transportistas', EnvioTransportistaController::class)
            ->parameters(['transportistas' => 'transportista'])
            ->names([
                'index' => 'transportistas',
                'create' => 'transportistas.create',
                'store' => 'transportistas.store',
                'show' => 'transportistas.show',
                'edit' => 'transportistas.edit',
                'update' => 'transportistas.update',
                'destroy' => 'transportistas.destroy',
            ]);
        Route::resource('vehiculos', EnvioVehiculoController::class)
            ->parameters(['vehiculos' => 'vehiculo'])
            ->names([
                'index' => 'vehiculos',
                'create' => 'vehiculos.create',
                'store' => 'vehiculos.store',
                'show' => 'vehiculos.show',
                'edit' => 'vehiculos.edit',
                'update' => 'vehiculos.update',
                'destroy' => 'vehiculos.destroy',
            ]);
        Route::patch('vehiculos/{vehiculo}/mantenimiento', [EnvioVehiculoController::class, 'toggleMantenimiento'])
            ->name('vehiculos.toggle-mantenimiento');

        Route::prefix('catalogos')->name('catalogos.')->group(function () {
            Route::get('{tipo}', [EnvioCatalogoController::class, 'index'])->name('index');
            Route::get('{tipo}/create', [EnvioCatalogoController::class, 'create'])->name('create');
            Route::post('{tipo}', [EnvioCatalogoController::class, 'store'])->name('store');
            Route::get('{tipo}/{id}/edit', [EnvioCatalogoController::class, 'edit'])->name('edit')->whereNumber('id');
            Route::put('{tipo}/{id}', [EnvioCatalogoController::class, 'update'])->name('update')->whereNumber('id');
            Route::delete('{tipo}/{id}', [EnvioCatalogoController::class, 'destroy'])->name('destroy')->whereNumber('id');
        });

        Route::resource('direcciones', EnvioDireccionController::class)
            ->parameters(['direcciones' => 'direccion'])
            ->names([
                'index' => 'direcciones',
                'create' => 'direcciones.create',
                'store' => 'direcciones.store',
                'show' => 'direcciones.show',
                'edit' => 'direcciones.edit',
                'update' => 'direcciones.update',
                'destroy' => 'direcciones.destroy',
            ]);
        Route::get('/reportes-distribucion', [\App\Http\Controllers\Web\OrgTrackReportController::class, 'index'])->name('reportes-distribucion')->middleware('action.permission:envios,read');
        Route::get('/{id}', [EnvioDetalleController::class, 'show'])->name('detalle')->where('id', '[0-9]+')->middleware('action.permission:envios,read');

        // ==============================
        // PROXY API EXTERNA (evita CORS)
        // ==============================
        Route::prefix('api')->name('api.')->group(function () {
            Route::get('/ping', [ExternalApiProxyController::class, 'ping'])->name('ping')->middleware('action.permission:envios,read');
            // Catálogos
            Route::get('/catalogo-categorias', [ExternalApiProxyController::class, 'getCategorias'])->name('categorias')->middleware('action.permission:envios,read');
            Route::get('/catalogo-productos', [ExternalApiProxyController::class, 'getProductos'])->name('productos')->middleware('action.permission:envios,read');
            Route::get('/catalogo-tipos-empaque', [ExternalApiProxyController::class, 'getTiposEmpaque'])->name('tipos-empaque')->middleware('action.permission:envios,read');
            Route::get('/catalogo-tamano-conteo', [ExternalApiProxyController::class, 'getTamanoConteo'])->name('tamano-conteo')->middleware('action.permission:envios,read');
            Route::get('/tipo-transporte', [ExternalApiProxyController::class, 'getTiposTransporte'])->name('tipos-transporte')->middleware('action.permission:envios,read');
            Route::post('/carga/calcular', [CargaCalculoController::class, 'calcular'])->name('carga.calcular')->middleware('action.permission:envios,read');
            Route::post('/capacidad/validar', [\App\Http\Controllers\Web\Envios\EnvioCapacidadController::class, 'validar'])->name('capacidad.validar')->middleware('action.permission:envios,read');

            // Envíos
            Route::post('/direccion', [ExternalApiProxyController::class, 'crearDireccion'])->name('direccion')->middleware('action.permission:direcciones,read');
            Route::post('/crear-envio', [ExternalApiProxyController::class, 'crearEnvioProductor'])->name('crear-envio')->middleware('action.permission:envios,create');
            Route::get('/envios', [ExternalApiProxyController::class, 'getEnvios'])->name('envios')->middleware('action.permission:envios,read');
            Route::get('/envios/{id}', [ExternalApiProxyController::class, 'getEnvioDetalle'])->name('envio-detalle')->middleware('action.permission:envios,read');
            Route::get('/transportistas', [ExternalApiProxyController::class, 'getTransportistas'])->name('transportistas')->middleware('action.permission:transportistas,read');
            Route::get('/vehiculos', [ExternalApiProxyController::class, 'getVehiculos'])->name('vehiculos')->middleware('action.permission:vehiculos,read');
        });
    });

    // ==============================
    // ORGTRACK / FUSION - CRUD locales
    Route::prefix('orgtrack')->name('orgtrack.')->group(function () {
        // Transportistas (CRUD sobre `usuario` role=transportista)
        Route::get('transportistas', [TransportistaController::class, 'index'])->name('transportistas.index')->middleware('action.permission:transportistas,read');
        Route::get('transportistas/create', [TransportistaController::class, 'create'])->name('transportistas.create')->middleware('action.permission:transportistas,create');
        Route::post('transportistas', [TransportistaController::class, 'store'])->name('transportistas.store')->middleware('action.permission:transportistas,create');
        Route::get('transportistas/{transportista}/edit', [TransportistaController::class, 'edit'])->name('transportistas.edit')->middleware('action.permission:transportistas,update');
        Route::put('transportistas/{transportista}', [TransportistaController::class, 'update'])->name('transportistas.update')->middleware('action.permission:transportistas,update');
        Route::delete('transportistas/{transportista}', [TransportistaController::class, 'destroy'])->name('transportistas.destroy')->middleware('action.permission:transportistas,delete');

        // Envios - Fusion (CRUD sobre envio_asignacion_multiple)
        Route::get('envios', [EnvioFusionController::class, 'index'])->name('envios.index')->middleware('action.permission:envios,read');
        Route::get('envios/{envio}', [EnvioFusionController::class, 'show'])->name('envios.show')->middleware('action.permission:envios,read');
        Route::get('envios/{envio}/edit', [EnvioFusionController::class, 'edit'])->name('envios.edit')->middleware('action.permission:envios,update');
        Route::put('envios/{envio}', [EnvioFusionController::class, 'update'])->name('envios.update')->middleware('action.permission:envios,update');
        Route::delete('envios/{envio}', [EnvioFusionController::class, 'destroy'])->name('envios.destroy')->middleware('action.permission:envios,delete');
    });
    // LOGISTICA OPERATIVA (SISTEMA PLANTA)
    // ==============================
    Route::prefix('logistica')->name('logistica.')->group(function () {
        Route::get('/mas', [LogisticaHubController::class, 'mas'])
            ->name('mas');
        Route::get('/rutas/planificar', [LogisticaHubController::class, 'planificarRutas'])
            ->name('rutas.planificar')
            ->middleware('action.permission:rutas_multi,read');
        Route::get('/asignaciones', [AsignacionMultipleController::class, 'index'])
            ->name('asignaciones.index')
            ->middleware('action.permission:asignaciones,read');
        Route::get('/asignaciones/listado', [AsignacionMultipleController::class, 'listado'])
            ->name('asignaciones.listado');
        Route::get('/mis-ingresos', [TransportistaIngresoController::class, 'index'])
            ->name('transportista.ingresos');
        Route::get('/rutas-tiempo-real', [RutaTiempoRealController::class, 'index'])
            ->name('rutas-tiempo-real.index')
            ->middleware('action.permission:asignaciones,read');
        Route::get('/rutas-tiempo-real/mapa', [RutaTiempoRealController::class, 'mapa'])
            ->name('rutas-tiempo-real.mapa')
            ->middleware('action.permission:asignaciones,read');
        Route::get('/rutas-tiempo-real/mapa/estado', [RutaTiempoRealController::class, 'estadoMapa'])
            ->name('rutas-tiempo-real.mapa-estado')
            ->middleware('action.permission:asignaciones,read');
        Route::get('/rutas-tiempo-real/{tipo}/{id}', [RutaTiempoRealController::class, 'show'])
            ->name('rutas-tiempo-real.show')
            ->whereIn('tipo', ['agricola', 'distribucion', 'planta_mayorista'])
            ->middleware('action.permission:asignaciones,read');
        Route::get('/simulacion/{tipo}/{id}/estado', [RutaTiempoRealController::class, 'estado'])
            ->name('simulacion.estado')
            ->whereIn('tipo', ['agricola', 'distribucion', 'planta_mayorista']);
        Route::patch('/simulacion/{tipo}/{id}/completar-manual', [RutaTiempoRealController::class, 'completarManual'])
            ->name('simulacion.completar-manual')
            ->whereIn('tipo', ['agricola', 'distribucion', 'planta_mayorista'])
            ->middleware('action.permission:asignaciones,update');
        Route::get('/traslados-planta', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'index'])
            ->name('traslados-planta.index')
            ->middleware('action.permission:asignaciones,read');
        Route::get('/traslados-planta/create', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'create'])
            ->name('traslados-planta.create')
            ->middleware('action.permission:pedidos,create');
        Route::post('/traslados-planta', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'store'])
            ->name('traslados-planta.store')
            ->middleware('action.permission:pedidos,create');
        Route::get('/traslados-planta/{ruta}', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'show'])
            ->name('traslados-planta.show')
            ->whereNumber('ruta')
            ->middleware('action.permission:asignaciones,read');
        Route::patch('/traslados-planta/{ruta}/empezar-ruta', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'empezarRuta'])
            ->name('traslados-planta.empezar-ruta')
            ->whereNumber('ruta');
        Route::patch('/traslados-planta/{ruta}/aceptar', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'aceptar'])
            ->name('traslados-planta.aceptar')
            ->whereNumber('ruta')
            ->middleware('action.permission:pedidos,update');
        Route::patch('/traslados-planta/{ruta}/rechazar', [\App\Http\Controllers\Web\TrasladoPlantaMayoristaController::class, 'rechazar'])
            ->name('traslados-planta.rechazar')
            ->whereNumber('ruta')
            ->middleware('action.permission:pedidos,update');
        Route::get('/traslados-planta/{ruta}/cierre', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'panel'])
            ->name('traslados-planta.cierre.panel')
            ->whereNumber('ruta');
        Route::post('/traslados-planta/{ruta}/cierre/condiciones', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'registrarCondiciones'])
            ->name('traslados-planta.cierre.condiciones')
            ->whereNumber('ruta');
        Route::patch('/traslados-planta/{ruta}/cierre/confirmar-llegada', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'confirmarLlegada'])
            ->name('traslados-planta.cierre.confirmar-llegada')
            ->whereNumber('ruta');
        Route::post('/traslados-planta/{ruta}/cierre/incidentes', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'registrarIncidentes'])
            ->name('traslados-planta.cierre.incidentes')
            ->whereNumber('ruta');
        Route::post('/traslados-planta/{ruta}/cierre/firma-transportista', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'firmaTransportista'])
            ->name('traslados-planta.cierre.firma-transportista')
            ->whereNumber('ruta');
        Route::post('/traslados-planta/{ruta}/cierre/firma-recepcion', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'firmaRecepcion'])
            ->name('traslados-planta.cierre.firma-recepcion')
            ->whereNumber('ruta');
        Route::post('/traslados-planta/{ruta}/cierre/finalizar', [\App\Http\Controllers\Web\EnvioCierrePlantaMayoristaController::class, 'finalizar'])
            ->name('traslados-planta.cierre.finalizar')
            ->whereNumber('ruta');
        Route::patch('/asignaciones/{asignacion}/empezar-ruta', [AsignacionMultipleController::class, 'empezarRuta'])
            ->name('asignaciones.empezar-ruta')
            ->whereNumber('asignacion');
        Route::patch('/rutas-distribucion/{ruta}/empezar-ruta', [\App\Http\Controllers\Web\RutaDistribucionController::class, 'empezarRuta'])
            ->name('rutas-distribucion.empezar-ruta')
            ->whereNumber('ruta');
        Route::get('/rutas-distribucion/{ruta}/cierre', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'panel'])
            ->name('rutas-distribucion.cierre.panel')
            ->whereNumber('ruta');
        Route::post('/rutas-distribucion/{ruta}/cierre/condiciones', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'registrarCondiciones'])
            ->name('rutas-distribucion.cierre.condiciones')
            ->whereNumber('ruta');
        Route::patch('/rutas-distribucion/{ruta}/cierre/confirmar-llegada', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'confirmarLlegada'])
            ->name('rutas-distribucion.cierre.confirmar-llegada')
            ->whereNumber('ruta');
        Route::post('/rutas-distribucion/{ruta}/cierre/incidentes', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'registrarIncidentes'])
            ->name('rutas-distribucion.cierre.incidentes')
            ->whereNumber('ruta');
        Route::post('/rutas-distribucion/{ruta}/cierre/firma-transportista', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'firmaTransportista'])
            ->name('rutas-distribucion.cierre.firma-transportista')
            ->whereNumber('ruta');
        Route::post('/rutas-distribucion/{ruta}/cierre/firma-recepcion', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'firmaRecepcion'])
            ->name('rutas-distribucion.cierre.firma-recepcion')
            ->whereNumber('ruta');
        Route::post('/rutas-distribucion/{ruta}/cierre/finalizar', [\App\Http\Controllers\Web\EnvioCierreDistribucionPdvController::class, 'finalizar'])
            ->name('rutas-distribucion.cierre.finalizar')
            ->whereNumber('ruta');
        Route::get('/asignaciones/create', [AsignacionMultipleController::class, 'create'])
            ->name('asignaciones.create')
            ->middleware('action.permission:asignaciones,create');
        Route::get('/asignaciones/seleccionar-transportista', [AsignacionMultipleController::class, 'seleccionarTransportista'])
            ->name('asignaciones.seleccionar-transportista')
            ->middleware('action.permission:asignaciones,create');
        Route::post('/asignaciones', [AsignacionMultipleController::class, 'store'])
            ->name('asignaciones.store')
            ->middleware('action.permission:asignaciones,create');
        Route::post('/asignaciones/lote', [AsignacionMultipleController::class, 'storeBatch'])
            ->name('asignaciones.store-batch')
            ->middleware('action.permission:asignaciones,multiple');
        Route::post('/asignaciones/asignar-automatica', [AsignacionMultipleController::class, 'asignarAutomatica'])
            ->name('asignaciones.asignar-automatica')
            ->middleware('action.permission:asignaciones,multiple');
        Route::get('/asignaciones/{asignacion}', [AsignacionMultipleController::class, 'show'])
            ->name('asignaciones.show')
            ->whereNumber('asignacion')
            ->middleware('action.permission:asignaciones,read');
        Route::post('/asignaciones/{asignacion}/aceptar-pedido', [AsignacionMultipleController::class, 'aceptarPedidoAgricola'])
            ->name('asignaciones.aceptar-pedido')
            ->whereNumber('asignacion')
            ->middleware('action.permission:pedidos,update');
        Route::post('/asignaciones/{asignacion}/rechazar-pedido', [AsignacionMultipleController::class, 'rechazarPedidoAgricola'])
            ->name('asignaciones.rechazar-pedido')
            ->whereNumber('asignacion')
            ->middleware('action.permission:pedidos,update');
        Route::get('/asignaciones/{asignacion}/edit', [AsignacionMultipleController::class, 'edit'])
            ->name('asignaciones.edit')
            ->whereNumber('asignacion')
            ->middleware('action.permission:asignaciones,update');
        Route::put('/asignaciones/{asignacion}', [AsignacionMultipleController::class, 'update'])
            ->name('asignaciones.update')
            ->whereNumber('asignacion')
            ->middleware('action.permission:asignaciones,update');
        Route::delete('/asignaciones/{asignacion}', [AsignacionMultipleController::class, 'destroy'])
            ->name('asignaciones.destroy')
            ->whereNumber('asignacion')
            ->middleware('action.permission:asignaciones,delete');
        Route::patch('/asignaciones/{asignacion}/en-transporte', [AsignacionMultipleController::class, 'markEnTransportePlanta'])
            ->name('asignaciones.en-transporte')
            ->whereNumber('asignacion');
        Route::patch('/asignaciones/{asignacion}/llegada-destino', [AsignacionMultipleController::class, 'markLlegadaDestino'])
            ->name('asignaciones.llegada-destino')
            ->whereNumber('asignacion');
        Route::get('/asignaciones/{asignacion}/cierre', [\App\Http\Controllers\Web\EnvioCierreAgricolaController::class, 'panel'])
            ->name('asignaciones.cierre.panel')
            ->whereNumber('asignacion');
        Route::post('/asignaciones/{asignacion}/cierre/condiciones', [\App\Http\Controllers\Web\EnvioCierreAgricolaController::class, 'registrarCondiciones'])
            ->name('asignaciones.cierre.condiciones')
            ->whereNumber('asignacion');
        Route::patch('/asignaciones/{asignacion}/cierre/confirmar-llegada', [\App\Http\Controllers\Web\EnvioCierreAgricolaController::class, 'confirmarLlegada'])
            ->name('asignaciones.cierre.confirmar-llegada')
            ->whereNumber('asignacion');
        Route::post('/asignaciones/{asignacion}/cierre/incidentes', [\App\Http\Controllers\Web\EnvioCierreAgricolaController::class, 'registrarIncidentes'])
            ->name('asignaciones.cierre.incidentes')
            ->whereNumber('asignacion');
        Route::post('/asignaciones/{asignacion}/cierre/firma-transportista', [\App\Http\Controllers\Web\EnvioCierreAgricolaController::class, 'firmaTransportista'])
            ->name('asignaciones.cierre.firma-transportista')
            ->whereNumber('asignacion');
        Route::post('/asignaciones/{asignacion}/cierre/firma-recepcion', [\App\Http\Controllers\Web\EnvioCierreAgricolaController::class, 'firmaRecepcion'])
            ->name('asignaciones.cierre.firma-recepcion')
            ->whereNumber('asignacion');
        Route::post('/asignaciones/{asignacion}/cierre/finalizar', [\App\Http\Controllers\Web\EnvioCierreAgricolaController::class, 'finalizar'])
            ->name('asignaciones.cierre.finalizar')
            ->whereNumber('asignacion');
        Route::patch('/asignaciones/{asignacion}/recepcion', [AsignacionMultipleController::class, 'markDelivered'])
            ->name('asignaciones.mark-delivered')
            ->whereNumber('asignacion')
            ->middleware('action.permission:asignaciones,create');

        Route::get('/rutas-multi', [RutaMultiEntregaController::class, 'index'])
            ->name('rutas.index')
            ->middleware('action.permission:rutas_multi,read');
        Route::get('/rutas-multi/create', [RutaMultiEntregaController::class, 'create'])
            ->name('rutas.create')
            ->middleware('action.permission:rutas_multi,create');
        Route::post('/rutas-multi', [RutaMultiEntregaController::class, 'store'])
            ->name('rutas.store')
            ->middleware('action.permission:rutas_multi,create');
        Route::get('/rutas-multi/mapa', [RutaMultiEntregaController::class, 'mapa'])
            ->name('rutas.mapa')
            ->middleware('action.permission:rutas_multi,create');
        Route::get('/rutas-multi/generar-automatica/vista-previa', [RutaMultiEntregaController::class, 'previewGenerarAutomatica'])
            ->name('rutas.generar-automatica.preview')
            ->middleware('action.permission:rutas_multi,create');
        Route::post('/rutas-multi/generar-automatica', [RutaMultiEntregaController::class, 'generarAutomatica'])
            ->name('rutas.generar-automatica')
            ->middleware('action.permission:rutas_multi,create');
        Route::get('/rutas-multi/{ruta}/trazado', [RutaMultiEntregaController::class, 'trazado'])
            ->name('rutas.trazado')
            ->middleware('action.permission:rutas_multi,read');
        Route::get('/rutas-multi/{ruta}', [RutaMultiEntregaController::class, 'show'])
            ->name('rutas.show')
            ->middleware('action.permission:rutas_multi,read');
        Route::patch('/rutas-multi/{ruta}', [RutaMultiEntregaController::class, 'update'])
            ->name('rutas.update')
            ->middleware('action.permission:rutas_multi,update');
        Route::patch('/rutas-multi/{ruta}/reordenar', [RutaMultiEntregaController::class, 'reorder'])
            ->name('rutas.reorder')
            ->middleware('action.permission:rutas_multi,reorder');

        Route::get('/incidentes', [IncidenteEnvioController::class, 'index'])
            ->name('incidentes.index')
            ->middleware('action.permission:incidentes,read');
        Route::get('/incidentes/create', [IncidenteEnvioController::class, 'create'])
            ->name('incidentes.create')
            ->middleware('action.permission:incidentes,create');
        Route::post('/incidentes', [IncidenteEnvioController::class, 'store'])
            ->name('incidentes.store')
            ->middleware('action.permission:incidentes,create');
        Route::get('/incidentes/{incidente}', [IncidenteEnvioController::class, 'show'])
            ->name('incidentes.show')
            ->middleware('action.permission:incidentes,read');
        Route::get('/incidentes/{incidente}/edit', [IncidenteEnvioController::class, 'edit'])
            ->name('incidentes.edit')
            ->middleware('action.permission:incidentes,update');
        Route::put('/incidentes/{incidente}', [IncidenteEnvioController::class, 'update'])
            ->name('incidentes.update')
            ->middleware('action.permission:incidentes,update');
        Route::delete('/incidentes/{incidente}', [IncidenteEnvioController::class, 'destroy'])
            ->name('incidentes.destroy')
            ->middleware('action.permission:incidentes,delete');
        Route::patch('/incidentes/{incidente}/resolver', [IncidenteEnvioController::class, 'resolve'])
            ->name('incidentes.resolve')
            ->middleware('action.permission:incidentes,resolve');

        Route::get('/documentos', [DocumentoEntregaController::class, 'index'])
            ->name('documentos.index')
            ->middleware('action.permission:documentos,read');
        Route::post('/documentos', [DocumentoEntregaController::class, 'store'])
            ->name('documentos.store')
            ->middleware('action.permission:documentos,create');
        Route::get('/documentos/{documento}/download', [DocumentoEntregaController::class, 'download'])
            ->name('documentos.download')
            ->middleware('action.permission:documentos,read');
        Route::get('/documentos/{documento}/preview', [DocumentoEntregaController::class, 'preview'])
            ->name('documentos.preview')
            ->middleware('action.permission:documentos,read');
        Route::get('/documentos/{documento}', [DocumentoEntregaController::class, 'show'])
            ->name('documentos.show')
            ->middleware('action.permission:documentos,read');
        Route::get('/documentos/{documento}/edit', [DocumentoEntregaController::class, 'edit'])
            ->name('documentos.edit')
            ->middleware('action.permission:documentos,update');
        Route::put('/documentos/{documento}', [DocumentoEntregaController::class, 'update'])
            ->name('documentos.update')
            ->middleware('action.permission:documentos,update');
        Route::delete('/documentos/{documento}', [DocumentoEntregaController::class, 'destroy'])
            ->name('documentos.destroy')
            ->middleware('action.permission:documentos,delete');
    });

    // ==============================
    // TRANSACCIONES AGRÍCOLAS - ELIMINADO
    // ==============================
});
