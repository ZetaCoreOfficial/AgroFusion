<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EnvioAsignacionMultiple;
use App\Models\PedidoDistribucion;
use App\Models\RutaDistribucion;
use App\Support\RutaTiempoRealCierreAdmin;
use App\Services\SimulacionRutaService;
use App\Support\EnvioAsignacionEstadoCatalogo;
use App\Support\PuntoVentaAccess;
use App\Support\RutaDistribucionCatalogo;
use App\Support\RutaTiempoRealAcceso;
use App\Support\SimulacionRutaCatalogo;
use App\Support\UsuarioRol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RutaTiempoRealController extends Controller
{
    public function __construct(
        private readonly SimulacionRutaService $simulacion
    ) {}

    public function index(): View
    {
        $this->autorizarSupervisor();

        $user = auth()->user();
        $busqueda = request()->string('q')->trim()->toString();
        $variante = RutaTiempoRealAcceso::normalizarVarianteFiltro(
            $user,
            request()->string('variante')->trim()->toString() ?: null
        );

        $rutas = RutaTiempoRealAcceso::filtrarActivas(
            $this->simulacion->listarActivasFiltradas(
                $busqueda !== '' ? $busqueda : null,
                $variante
            ),
            $user
        );

        $variantes = RutaTiempoRealAcceso::catalogoVariantes($user);

        return view('logistica.rutas-tiempo-real.index', [
            'rutas' => $rutas,
            'totalActivas' => $rutas->count(),
            'busqueda' => $busqueda,
            'variante' => $variante,
            'variantes' => $variantes,
            'esVistaGlobal' => RutaTiempoRealAcceso::esVistaGlobal($user),
            'subtituloModulo' => RutaTiempoRealAcceso::subtituloModulo($user),
            'filtroTipoUnico' => count($variantes) === 1,
        ]);
    }

    public function mapa(): View
    {
        $this->autorizarSupervisor();

        $user = auth()->user();

        return view('logistica.rutas-tiempo-real.mapa', [
            'variantes' => RutaTiempoRealAcceso::catalogoVariantes($user),
            'estadoUrl' => route('logistica.rutas-tiempo-real.mapa-estado'),
            'volverUrl' => route('logistica.rutas-tiempo-real.index'),
            'esVistaGlobal' => RutaTiempoRealAcceso::esVistaGlobal($user),
        ]);
    }

    public function estadoMapa(): JsonResponse
    {
        $this->autorizarSupervisor();

        $rutas = $this->simulacion->estadosMapaGlobal();
        $user = auth()->user();

        if ($user !== null && ! RutaTiempoRealAcceso::esVistaGlobal($user)) {
            $rutas = array_values(array_filter(
                $rutas,
                fn (array $item) => RutaTiempoRealAcceso::puedeVerItem($user, $item)
            ));
        }

        return response()->json([
            'actualizado_at' => now()->toIso8601String(),
            'rutas' => $rutas,
        ]);
    }

    public function show(string $tipo, int $id): View
    {
        $this->autorizarVerIndividual($tipo, $id);

        [$titulo, $estado, $paradas, $trayecto, $volverUrl] = match ($tipo) {
            SimulacionRutaCatalogo::TIPO_AGRICOLA => [...$this->resolverAgricola($id), $this->urlVolverAgricola($id)],
            SimulacionRutaCatalogo::TIPO_DISTRIBUCION => [...$this->resolverDistribucion($id), $this->urlVolverDistribucion($id)],
            SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA => [...$this->resolverPlantaMayorista($id), $this->urlVolverPlantaMayorista($id)],
            default => abort(404),
        };

        $puedeConfirmarLlegada = false;
        $confirmarLlegadaUrl = null;
        $mensajeConfirmarLlegada = '';

        $user = auth()->user();
        if ($user) {
            $confirmacionAdmin = RutaTiempoRealCierreAdmin::resolverConfirmarLlegada($user, $tipo, $id, $estado);
            $puedeConfirmarLlegada = $confirmacionAdmin['puede'];
            $confirmarLlegadaUrl = $confirmacionAdmin['url'];
            $mensajeConfirmarLlegada = $confirmacionAdmin['mensaje'];
        }

        return view('logistica.rutas-tiempo-real.show', [
            'tipo' => $tipo,
            'id' => $id,
            'titulo' => $titulo,
            'estado' => $estado,
            'paradas' => $paradas,
            'trayecto' => $trayecto,
            'volverUrl' => $volverUrl,
            'puedeCerrarManual' => $this->puedeCerrarManual() && ! ($estado['completada'] ?? false),
            'puedeConfirmarLlegada' => $puedeConfirmarLlegada,
            'confirmarLlegadaUrl' => $confirmarLlegadaUrl,
            'mensajeConfirmarLlegada' => $mensajeConfirmarLlegada,
        ]);
    }

    public function completarManual(string $tipo, int $id): JsonResponse|RedirectResponse
    {
        $this->autorizarCierreManual();

        try {
            match ($tipo) {
                SimulacionRutaCatalogo::TIPO_AGRICOLA => $this->cerrarManualAgricola($id),
                SimulacionRutaCatalogo::TIPO_DISTRIBUCION => $this->cerrarManualDistribucion($id),
                SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA => $this->cerrarManualPlantaMayorista($id),
                default => abort(404),
            };
        } catch (\InvalidArgumentException $e) {
            if (request()->expectsJson()) {
                return response()->json(['mensaje' => $e->getMessage()], 422);
            }

            return back()->with('warning', $e->getMessage());
        }

        $mensaje = 'Seguimiento GPS cerrado. El transportista debe confirmar la llegada y completar el cierre con firmas.';

        if (request()->expectsJson()) {
            return response()->json([
                'mensaje' => $mensaje,
                'completada' => true,
            ]);
        }

        return redirect()
            ->route('logistica.rutas-tiempo-real.index')
            ->with('success', $mensaje);
    }

    public function estado(string $tipo, int $id): JsonResponse
    {
        $this->autorizarVerIndividual($tipo, $id);

        if ($tipo === SimulacionRutaCatalogo::TIPO_AGRICOLA) {
            $envio = EnvioAsignacionMultiple::query()->findOrFail($id);

            return response()->json($this->simulacion->estadoAgricola($envio));
        }

        if ($tipo === SimulacionRutaCatalogo::TIPO_DISTRIBUCION
            || $tipo === SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA) {
            $ruta = RutaDistribucion::query()->findOrFail($id);

            return response()->json($this->simulacion->estadoDistribucion($ruta));
        }

        abort(404);
    }

    private function autorizarSupervisor(): void
    {
        abort_unless(RutaTiempoRealAcceso::puedeAccederModulo(auth()->user()), 403);
    }

    private function autorizarVerIndividual(string $tipo, int $id): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        if (RutaTiempoRealAcceso::esVistaGlobal($user)) {
            return;
        }

        if ($tipo === SimulacionRutaCatalogo::TIPO_AGRICOLA) {
            abort_unless(RutaTiempoRealAcceso::puedeVerEnvioAgricola($user, $id), 403);

            return;
        }

        if ($tipo === SimulacionRutaCatalogo::TIPO_DISTRIBUCION) {
            abort_unless(
                RutaTiempoRealAcceso::puedeVerRutaDistribucion($user, $id)
                || $this->puedeVerRutaDistribucionMinorista($id, $user),
                403
            );

            return;
        }

        if ($tipo === SimulacionRutaCatalogo::TIPO_PLANTA_MAYORISTA) {
            abort_unless(RutaTiempoRealAcceso::puedeVerTrasladoPlantaMayorista($user, $id), 403);

            return;
        }

        abort(403);
    }

    private function puedeVerRutaDistribucionMinorista(int $id, $user): bool
    {
        if (! UsuarioRol::esMinorista($user)) {
            return false;
        }

        $ruta = RutaDistribucion::query()
            ->with(['pedidos.puntoVenta'])
            ->find($id);

        if ($ruta === null || ! SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta)) {
            return false;
        }

        return $ruta->pedidos->contains(
            fn (PedidoDistribucion $p) => (int) $p->puntoVenta?->usuarioid === (int) $user->usuarioid
        );
    }

    private function autorizarCierreManual(): void
    {
        abort_unless($this->puedeCerrarManual(), 403);
    }

    private function puedeCerrarManual(): bool
    {
        $user = auth()->user();

        // El admin supervisa el mapa en tiempo real pero no cierra envíos.
        return UsuarioRol::puedeOperar($user) && (
            UsuarioRol::esJefePlanta($user)
            || UsuarioRol::esJefeAgricultor($user)
            || ($user->can('asignaciones.update') && ! UsuarioRol::esTransportista($user))
        );
    }

    private function urlVolverAgricola(int $id): string
    {
        return route('logistica.asignaciones.show', $id);
    }

    private function urlVolverDistribucion(int $id): string
    {
        $user = auth()->user();
        $ruta = RutaDistribucion::query()->with('pedidos')->find($id);

        if ($user && UsuarioRol::esMinorista($user) && $ruta) {
            $pedido = $ruta->pedidos->first(
                fn (PedidoDistribucion $p) => PuntoVentaAccess::puedeVerPedido($user, $p)
            );
            if ($pedido) {
                return route('punto-venta.pedidos.show', ['pedido' => $pedido, 'paso' => 4]);
            }
        }

        if ($ruta) {
            return \App\Support\RutaDistribucionNavegacion::urlVer($ruta);
        }

        return route('logistica.rutas-tiempo-real.index');
    }

    private function cerrarManualAgricola(int $id): void
    {
        $envio = EnvioAsignacionMultiple::query()->findOrFail($id);
        $this->simulacion->completarManualAgricola($envio);
    }

    private function cerrarManualDistribucion(int $id): void
    {
        $ruta = RutaDistribucion::query()->findOrFail($id);
        $this->simulacion->completarManualDistribucion($ruta);
    }

    private function cerrarManualPlantaMayorista(int $id): void
    {
        $ruta = RutaDistribucion::query()->findOrFail($id);
        abort_unless(RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta), 404);
        $this->simulacion->completarManualDistribucion($ruta);
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<int, mixed>, 3: ?string} */
    private function resolverAgricola(int $id): array
    {
        $envio = EnvioAsignacionMultiple::query()
            ->with(['pedido.detalles', 'transportista'])
            ->findOrFail($id);

        if (SimulacionRutaCatalogo::simulacionActivaAgricola($envio)) {
            $estado = $this->simulacion->estadoAgricola($envio);
            $envio->refresh();
        } elseif (! EnvioAsignacionEstadoCatalogo::llegoADestino($envio)) {
            abort(404, 'La simulación de este envío ya no está activa.');
        } else {
            $estado = $this->simulacion->estadoAgricola($envio);
        }

        $trayecto = \App\Support\EnvioPedidoService::trayectoTexto($envio);

        return [
            $envio->externo_envio_id ?? 'Envío #'.$id,
            $estado,
            $estado['paradas'] ?? [],
            $trayecto,
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<int, mixed>, 3: ?string} */
    private function resolverDistribucion(int $id): array
    {
        $ruta = RutaDistribucion::query()
            ->with(['transportista', 'almacenOrigen'])
            ->findOrFail($id);

        if (SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta)) {
            $estado = $this->simulacion->estadoDistribucion($ruta);
            $ruta->refresh();
        } elseif ($ruta->estado !== RutaDistribucionCatalogo::ESTADO_COMPLETADA) {
            abort(404, 'La simulación de esta ruta ya no está activa.');
        } else {
            $estado = $this->simulacion->estadoDistribucion($ruta);
        }

        $trayecto = app(\App\Services\DistribucionRutaService::class)->trayectoTexto($ruta);

        return [$ruta->codigo, $estado, $estado['paradas'] ?? [], $trayecto];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<int, mixed>, 3: ?string} */
    private function resolverPlantaMayorista(int $id): array
    {
        $ruta = RutaDistribucion::query()
            ->with(['transportista', 'almacenPlantaOrigen', 'almacenMayoristaDestino'])
            ->findOrFail($id);

        abort_unless(RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta), 404);

        if (SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta)) {
            $estado = $this->simulacion->estadoDistribucion($ruta);
            $ruta->refresh();
        } elseif ($ruta->estado !== RutaDistribucionCatalogo::ESTADO_COMPLETADA) {
            abort(404, 'La simulación de este traslado ya no está activa.');
        } else {
            $estado = $this->simulacion->estadoDistribucion($ruta);
        }

        $trayecto = app(\App\Services\TrasladoPlantaMayoristaService::class)->trayectoTexto($ruta);

        return [$ruta->codigo, $estado, $estado['paradas'] ?? [], $trayecto];
    }

    private function urlVolverPlantaMayorista(int $id): string
    {
        $ruta = RutaDistribucion::query()->find($id);
        if ($ruta) {
            return \App\Support\RutaDistribucionNavegacion::urlVer($ruta);
        }

        return route('logistica.rutas-tiempo-real.index');
    }
}
