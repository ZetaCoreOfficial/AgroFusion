<?php

namespace App\Http\Middleware;

use App\Support\AlmacenAmbito;
use App\Support\DocumentoEntregaAcceso;
use App\Support\RutaTiempoRealAcceso;
use App\Support\UsuarioRol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActionPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        // El admin no tiene bypass: se evalúa con sus permisos de la matriz (supervisión).
        // Además, una ruta de escritura protegida solo con permiso de lectura es un flujo
        // operativo (completar actividad, registrar movimiento, etc.): el admin no la ejecuta.
        if ($action === 'read' && ! $request->isMethodSafe() && UsuarioRol::esAdminGlobal($user)) {
            abort(403, 'El administrador tiene acceso de supervisión: no ejecuta operaciones de este módulo.');
        }

        $permission = config("permission_matrix.modules.{$module}.{$action}");
        if (! $permission) {
            abort(403, "Permiso no definido para {$module}.{$action}");
        }

        if (! $user->can($permission)) {
            if ($module === 'documentos' && $action === 'read' && DocumentoEntregaAcceso::puedeAccederModulo($user)) {
                return $next($request);
            }
            if ($module === 'asignaciones' && $action === 'read' && RutaTiempoRealAcceso::puedeAccederModulo($user)) {
                return $next($request);
            }
            if ($this->puedeAccederAlmacenPorAmbito($user, $request, $module)) {
                return $next($request);
            }
            abort(403);
        }

        return $next($request);
    }

    private function puedeAccederAlmacenPorAmbito($user, Request $request, string $module): bool
    {
        if (! in_array($module, ['inventario', 'almacen_movimientos', 'almacen_reportes'], true)) {
            return false;
        }

        $ambito = $request->route('ambito');
        if (! is_string($ambito) || ! AlmacenAmbito::esValido($ambito)) {
            $routeName = (string) ($request->route()?->getName() ?? '');
            $ambito = match (true) {
                str_starts_with($routeName, 'almacen-planta.') => AlmacenAmbito::PLANTA,
                str_starts_with($routeName, 'almacen-agricola.') => AlmacenAmbito::AGRICOLA,
                str_starts_with($routeName, 'almacen-mayorista.') => AlmacenAmbito::MAYORISTA,
                str_starts_with($routeName, 'almacen-punto-venta.') => AlmacenAmbito::PUNTO_VENTA,
                default => null,
            };
        }

        if ($ambito === null) {
            return false;
        }

        return AlmacenAmbito::usuarioPuedeVer($user, $ambito);
    }
}

