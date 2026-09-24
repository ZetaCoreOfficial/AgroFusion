<?php

namespace App\Http\Middleware;

use App\Support\UsuarioRol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El administrador es un rol de supervisión: puede consultar todo (GET), pero solo
 * puede escribir en funciones de administración de la plataforma. Cualquier otra
 * escritura (lotes, actividades, cosechas, planta, envíos, firmas, pedidos…) es un
 * flujo de negocio que corresponde a los roles operativos.
 */
class AdminSoloSupervision
{
    /** Rutas web de escritura permitidas al admin (patrones de nombre de ruta). */
    public const RUTAS_ADMINISTRACION = [
        // Sesión, perfil y notificaciones propias
        'login',
        'logout',
        'profile.*',
        'login-notificaciones.*',
        'notificaciones.*',

        // Usuarios, roles y solicitudes de cuenta
        'gestion.*',

        // Catálogos maestros
        'cultivos.*',
        'tipo-actividad.*',
        'tipo-insumos.*',
        'unidades-medida.*',
        'tipoalmacenes.*',
        'prioridades.*',
        'estado-lote-tipos.*',
        'estado-lote-insumos.*',
        'envios.catalogos.*',

        // Datos maestros de flota
        'envios.transportistas.*',
        'envios.vehiculos.*',
        'envios.direcciones.*',
        'orgtrack.transportistas.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null || ! UsuarioRol::esAdminGlobal($user)) {
            return $next($request);
        }

        if ($request->routeIs(...self::RUTAS_ADMINISTRACION)) {
            return $next($request);
        }

        $mensaje = 'El administrador tiene acceso de supervisión: puede consultar, pero no ejecutar operaciones de negocio.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $mensaje], 403);
        }

        abort(403, $mensaje);
    }
}
