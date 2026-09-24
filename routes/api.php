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

Route::name('api.')->group(function () {

    // ENDPOINT DE PRUEBA
    Route::get('/test-api', function () {
        return response()->json(['ok' => true]);
    });

    // ========================================================
    // GRUPO: CATÁLOGOS
    // ========================================================
    Route::apiResource('tipoactividades', TipoActividadController::class)
        ->middleware(['auth:sanctum', 'action.permission:catalogos,read']);
    Route::apiResource('prioridades', PrioridadController::class)
        ->middleware(['auth:sanctum', 'action.permission:catalogos,read']);
    Route::apiResource('tipoinsumos', TipoInsumoController::class)
        ->middleware(['auth:sanctum', 'action.permission:catalogos,read']);
    Route::apiResource('unidadesmedida', UnidadMedidaController::class)
        ->middleware(['auth:sanctum', 'action.permission:catalogos,read']);
    Route::apiResource('cultivos', CultivoController::class)
        ->middleware(['auth:sanctum', 'action.permission:catalogos,read']);
    Route::apiResource('estadolote-tipos', EstadoLoteTipoController::class)
        ->middleware(['auth:sanctum', 'action.permission:catalogos,read']);
    Route::apiResource('destinoproducciones', DestinoProduccionController::class)
        ->middleware(['auth:sanctum', 'action.permission:catalogos,read']);
    Route::apiResource('estadolote-insumos', EstadoLoteInsumoController::class)
        ->middleware(['auth:sanctum', 'action.permission:catalogos,read']);

    // 🔹 nuevos catálogos de almacenamiento
    Route::apiResource('tipo-almacenes', TipoAlmacenController::class);

    // ========================================================
    // GRUPO: USUARIOS Y ROLES
    // ========================================================
    Route::apiResource('roles', RolController::class);
    Route::apiResource('usuarios', UsuarioController::class);
    Route::apiResource('usuario-roles', UsuarioRolController::class);

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
    Route::apiResource('estadolotes', EstadoLoteController::class);
    Route::apiResource('producciones', ProduccionController::class);
    Route::apiResource('historial-estados-lote', HistorialEstadoLoteController::class);

    // ========================================================
    // GRUPO: ALMACENES Y ALMACENAMIENTO
    // ========================================================
    Route::apiResource('almacenes', AlmacenController::class);
    Route::apiResource('producciones-almacenamiento', ProduccionAlmacenamientoController::class);
    Route::get('almacen-movimientos', [AlmacenMovimientoController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:almacen_movimientos,read']);
    Route::post('almacen-movimientos/{naturaleza}', [AlmacenMovimientoController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:almacen_movimientos,read']);

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
    Route::apiResource('lote-insumos', LoteInsumoController::class);

    // ACTIVIDADES (misma matriz que web: lotes,read / lotes,update)
    Route::get('actividades', [ActividadController::class, 'index'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,read']);
    Route::post('actividades', [ActividadController::class, 'store'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,read']);
    Route::get('actividades/{actividad}', [ActividadController::class, 'show'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,read']);
    Route::match(['put', 'patch'], 'actividades/{actividad}', [ActividadController::class, 'update'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,update']);
    Route::delete('actividades/{actividad}', [ActividadController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'action.permission:lotes,update']);

    // CLIMA
    Route::apiResource('climas', ClimaController::class);

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
    Route::post('/register-admin', [AuthController::class, 'registerAdmin']);
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