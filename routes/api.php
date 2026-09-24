<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\TipoActividadController;
use App\Http\Controllers\Api\PrioridadController;
use App\Http\Controllers\Api\TipoInsumoController;
use App\Http\Controllers\Api\UnidadMedidaController;
use App\Http\Controllers\Api\CultivoController;
use App\Http\Controllers\Api\EstadoLoteTipoController;
use App\Http\Controllers\Api\DestinoProduccionController;
use App\Http\Controllers\Api\EstadoLoteInsumoController;
use App\Http\Controllers\Api\HistorialEstadoLoteController;
use App\Http\Controllers\Api\PedidoController;

use App\Http\Controllers\Api\RolController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\UsuarioRolController;

use App\Http\Controllers\Api\LoteController;
use App\Http\Controllers\Api\EstadoLoteController;
use App\Http\Controllers\Api\ProduccionController;

use App\Http\Controllers\Api\InsumoController;
use App\Http\Controllers\Api\LoteInsumoController;
use App\Http\Controllers\Api\ActividadController;

use App\Http\Controllers\Api\ClimaController;
use App\Http\Controllers\Api\CertificacionController;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncidenteEnvioController;
use App\Http\Controllers\Api\RutaMultiEntregaController;
use App\Http\Controllers\Api\AsignacionMultipleController;
use App\Http\Controllers\Api\DocumentoEntregaController;
use App\Http\Controllers\Api\AlmacenMovimientoController;

// 🔹 nuevos controladores API
use App\Http\Controllers\Api\TipoAlmacenController;
use App\Http\Controllers\Api\AlmacenController;
use App\Http\Controllers\Api\ProduccionAlmacenamientoController;

/**
 * Registra un apiResource exigiendo auth:sanctum y el permiso de la matriz según el verbo:
 * index/show → read, store → create, update → update, destroy → delete.
 * Si $accionUnica se indica, todos los verbos exigen esa acción (p. ej. usuarios,admin).
 */
$recursoProtegido = function (string $uri, string $controller, string $modulo, ?string $accionUnica = null): void {
    $verbos = [
        'read' => ['index', 'show'],
        'create' => ['store'],
        'update' => ['update'],
        'delete' => ['destroy'],
    ];

    foreach ($verbos as $accion => $metodos) {
        Route::apiResource($uri, $controller)
            ->only($metodos)
            ->middleware(['auth:sanctum', 'action.permission:'.$modulo.','.($accionUnica ?? $accion)]);
    }
};

Route::name('api.')->group(function () use ($recursoProtegido) {

    // ENDPOINT DE PRUEBA
    Route::get('/test-api', function () {
        return response()->json(['ok' => true]);
    });

    // ========================================================
    // GRUPO: CATÁLOGOS
    // ========================================================
    $recursoProtegido('tipoactividades', TipoActividadController::class, 'catalogos');
    $recursoProtegido('prioridades', PrioridadController::class, 'catalogos');
    $recursoProtegido('tipoinsumos', TipoInsumoController::class, 'catalogos');
    $recursoProtegido('unidadesmedida', UnidadMedidaController::class, 'catalogos');
    $recursoProtegido('cultivos', CultivoController::class, 'catalogos');
    $recursoProtegido('estadolote-tipos', EstadoLoteTipoController::class, 'catalogos');
    $recursoProtegido('destinoproducciones', DestinoProduccionController::class, 'catalogos');
    $recursoProtegido('estadolote-insumos', EstadoLoteInsumoController::class, 'catalogos');

    // 🔹 nuevos catálogos de almacenamiento
    $recursoProtegido('tipo-almacenes', TipoAlmacenController::class, 'catalogos');

    // ========================================================
    // GRUPO: USUARIOS Y ROLES
    // ========================================================
    // Solo administración de usuarios (usuarios.admin): la API no acota por jefe/empleado.
    $recursoProtegido('roles', RolController::class, 'usuarios', 'admin');
    $recursoProtegido('usuarios', UsuarioController::class, 'usuarios', 'admin');
    $recursoProtegido('usuario-roles', UsuarioRolController::class, 'usuarios', 'admin');

    // ========================================================
    // GRUPO: LOTES Y PRODUCCIÓN
    // ========================================================
    Route::get('lotes', [LoteController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,read']);
    Route::post('lotes', [LoteController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,create']);
    Route::get('lotes/{lote}', [LoteController::class, 'show'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,read']);
    Route::match(['put', 'patch'], 'lotes/{lote}', [LoteController::class, 'update'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,update']);
    Route::delete('lotes/{lote}', [LoteController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,delete']);
    $recursoProtegido('estadolotes', EstadoLoteController::class, 'lotes');
    $recursoProtegido('producciones', ProduccionController::class, 'lotes');
    $recursoProtegido('historial-estados-lote', HistorialEstadoLoteController::class, 'lotes');

    // ========================================================
    // GRUPO: ALMACENES Y ALMACENAMIENTO
    // ========================================================
    $recursoProtegido('almacenes', AlmacenController::class, 'inventario');
    $recursoProtegido('producciones-almacenamiento', ProduccionAlmacenamientoController::class, 'inventario');
    Route::get('almacen-movimientos', [AlmacenMovimientoController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:almacen_movimientos,read']);
    // Escritura: exige el permiso de creación de ingreso/salida en la ruta (no el de lectura).
    Route::post('almacen-movimientos/ingreso', [AlmacenMovimientoController::class, 'store'])
        ->defaults('naturaleza', 'ingreso')
        ->middleware(['auth:sanctum', 'action.permission:almacen_ingresos,create']);
    Route::post('almacen-movimientos/salida', [AlmacenMovimientoController::class, 'store'])
        ->defaults('naturaleza', 'salida')
        ->middleware(['auth:sanctum', 'action.permission:almacen_salidas,create']);

    // ========================================================
    // GRUPO: INSUMOS Y APLICACIONES
    // ========================================================
    Route::get('insumos', [InsumoController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:inventario,read']);
    Route::post('insumos', [InsumoController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:inventario,create']);
    Route::get('insumos/{insumo}', [InsumoController::class, 'show'])
        ->middleware(['auth:sanctum', 'action.permission:inventario,read']);
    Route::match(['put', 'patch'], 'insumos/{insumo}', [InsumoController::class, 'update'])
        ->middleware(['auth:sanctum', 'action.permission:inventario,update']);
    Route::delete('insumos/{insumo}', [InsumoController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'action.permission:inventario,delete']);
    $recursoProtegido('lote-insumos', LoteInsumoController::class, 'lotes');

    // ACTIVIDADES
    $recursoProtegido('actividades', ActividadController::class, 'lotes');

    // CLIMA
    $recursoProtegido('climas', ClimaController::class, 'lotes');

    // ========================================================
    // GRUPO: PEDIDOS (CLIENTE EXTERNO) - control granular API
    // ========================================================
    Route::get('pedidos', [PedidoController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:pedidos,read']);
    Route::post('pedidos', [PedidoController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:pedidos,create']);
    Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])
        ->middleware(['auth:sanctum', 'action.permission:pedidos,read']);
    Route::match(['put', 'patch'], 'pedidos/{pedido}', [PedidoController::class, 'update'])
        ->middleware(['auth:sanctum', 'action.permission:pedidos,update']);
    Route::delete('pedidos/{pedido}', [PedidoController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'action.permission:pedidos,delete']);

    // CERTIFICACIONES - control granular API
    Route::get('certificaciones', [CertificacionController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:certificaciones,read']);
    Route::post('certificaciones', [CertificacionController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:certificaciones,create']);


    // AUTH
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login',    [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me',     [AuthController::class, 'me'])->name('me');
        Route::post('/logout',[AuthController::class, 'logout'])->name('logout');
    });

    // LOGISTICA OPERATIVA - API GRANULAR
    Route::get('incidentes', [IncidenteEnvioController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:incidentes,read']);
    Route::post('incidentes', [IncidenteEnvioController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:incidentes,create']);
    Route::patch('incidentes/{incidente}/resolver', [IncidenteEnvioController::class, 'resolve'])
        ->middleware(['auth:sanctum', 'action.permission:incidentes,resolve']);

    Route::get('rutas-multi', [RutaMultiEntregaController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:rutas_multi,read']);
    Route::post('rutas-multi', [RutaMultiEntregaController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:rutas_multi,create']);
    Route::get('rutas-multi/{ruta}', [RutaMultiEntregaController::class, 'show'])
        ->middleware(['auth:sanctum', 'action.permission:rutas_multi,read']);
    Route::patch('rutas-multi/{ruta}', [RutaMultiEntregaController::class, 'update'])
        ->middleware(['auth:sanctum', 'action.permission:rutas_multi,update']);
    Route::patch('rutas-multi/{ruta}/reordenar', [RutaMultiEntregaController::class, 'reorder'])
        ->middleware(['auth:sanctum', 'action.permission:rutas_multi,reorder']);

    Route::get('asignaciones-multiples', [AsignacionMultipleController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:asignaciones,read']);
    Route::post('asignaciones-multiples', [AsignacionMultipleController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:asignaciones,create']);
    Route::post('asignaciones-multiples/lote', [AsignacionMultipleController::class, 'storeBatch'])
        ->middleware(['auth:sanctum', 'action.permission:asignaciones,multiple']);

    Route::get('documentos-entrega', [DocumentoEntregaController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:documentos,read']);
    Route::post('documentos-entrega', [DocumentoEntregaController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:documentos,create']);
    Route::get('documentos-entrega/{documento}/download', [DocumentoEntregaController::class, 'download'])
        ->middleware(['auth:sanctum', 'action.permission:documentos,read']);
});