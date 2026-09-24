<?php

use App\Exceptions\EliminacionBloqueadaException;
use App\Support\EliminacionSegura;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(prepend: [
            \App\Http\Middleware\SyncAppUrlWithRequest::class,
        ], append: [
            \App\Http\Middleware\AdminSoloSupervision::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'logout',
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'action.permission' => \App\Http\Middleware\ActionPermissionMiddleware::class,
            'almacen.ambito' => \App\Http\Middleware\AlmacenAmbitoMiddleware::class,
            'cuenta.aprobada' => \App\Http\Middleware\EnsureCuentaAprobada::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (EliminacionBloqueadaException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with([
                'error' => $e->getMessage(),
                'error_modal' => true,
                'error_modal_titulo' => 'No se puede eliminar',
            ]);
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            if (! EliminacionSegura::esViolacionFk($e)) {
                return null;
            }

            $mensaje = $request->isMethod('DELETE')
                ? EliminacionSegura::mensajeGenerico()
                : 'No se pudo completar la operación por una referencia de datos inconsistente. '
                    .'Revise los datos vinculados o contacte al administrador.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $mensaje], 422);
            }

            if ($request->isMethod('DELETE') || $request->isMethod('POST')) {
                return back()->with([
                    'error' => $mensaje,
                    'error_modal' => true,
                    'error_modal_titulo' => $request->isMethod('DELETE')
                        ? 'No se puede eliminar'
                        : 'No se pudo completar',
                ]);
            }

            return null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage() ?: 'Acceso denegado.'], 403);
            }

            $mensaje = trim((string) $e->getMessage());
            if ($mensaje === '') {
                return null;
            }

            $destino = $request->headers->get('referer');
            if (! is_string($destino) || $destino === '') {
                $destino = route('lotes.index', absolute: false);
            }

            return redirect()->to($destino)->with([
                'error' => $mensaje,
                'error_modal' => true,
                'error_modal_titulo' => 'Acceso restringido',
            ]);
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->expectsJson()
                || $request->ajax()
                || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json(['message' => 'Sesión renovada.', 'reload' => true], 419);
            }

            if ($request->routeIs('login.post')) {
                return redirect()
                    ->route('login', ['_sesion_limpia' => 1])
                    ->withErrors(['email' => 'La sesión expiró. Vuelva a ingresar sus credenciales.'])
                    ->withCookie(Cookie::forget(Auth::getRecallerName()));
            }

            $destino = $request->headers->get('referer');
            if (! is_string($destino) || $destino === '') {
                $destino = $request->isMethod('GET') ? url()->current() : url('/');
            }

            return redirect()->to($destino)->with('info', 'La página se renovó. Continúe donde estaba.');
        });
    })->create();