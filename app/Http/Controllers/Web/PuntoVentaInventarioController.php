<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AlmacenMovimiento;
use App\Models\Insumo;
use App\Models\PuntoVenta;
use App\Models\TipoMovimientoAlmacen;
use App\Services\InventarioAlmacenProductoService;
use App\Services\AlmacenCapacidadService;
use App\Services\PuntoVentaInventarioPresentacionService;
use App\Support\EliminacionSegura;
use App\Support\PuntoVentaAccess;
use App\Support\TrazabilidadProductoPdvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PuntoVentaInventarioController extends Controller
{
    public function index(Request $request, PuntoVentaInventarioPresentacionService $presentaciones): View
    {
        $user = $request->user();

        $puntos = PuntoVentaAccess::scopePuntosDelUsuario(
            PuntoVenta::query()->where('activo', true)->orderBy('nombre'),
            $user
        )->get();

        $puntosFiltrados = $puntos;
        if ($request->filled('puntoventaid')) {
            $puntosFiltrados = $puntos->where('puntoventaid', (int) $request->puntoventaid)->values();
        }

        $termino = $request->filled('q') ? $request->string('q')->trim()->toString() : null;
        $lineas = $presentaciones->lineasParaPuntos($puntosFiltrados, $termino);

        return view('punto_venta.inventario.index', [
            'puntos' => $puntos,
            'lineas' => $lineas,
            'esAdmin' => $user && \App\Support\UsuarioRol::esAdminGlobal($user),
            'filtroPdv' => $request->integer('puntoventaid') ?: null,
            'filtroPdvNombre' => $request->filled('puntoventaid')
                ? ($puntos->firstWhere('puntoventaid', (int) $request->puntoventaid)?->nombre ?? '')
                : '',
            'filtroQ' => $request->string('q')->toString(),
        ]);
    }

    public function edit(PuntoVenta $punto, Insumo $insumo, AlmacenCapacidadService $capacidadService): View
    {
        $this->autorizarInsumo($punto, $insumo);

        $resumenCapacidad = $punto->almacen
            ? $capacidadService->resumen($punto->almacen)
            : null;

        return view('punto_venta.puntos.inventario.edit', compact('punto', 'insumo', 'resumenCapacidad'));
    }

    public function update(
        Request $request,
        PuntoVenta $punto,
        Insumo $insumo,
        AlmacenCapacidadService $capacidadService
    ): RedirectResponse {
        $this->autorizarInsumo($punto, $insumo);

        $data = $request->validate([
            'nombre' => 'required|string|max:150',
            'stock' => 'required|numeric|min:0',
            'stockminimo' => 'nullable|numeric|min:0',
            'descripcion' => 'nullable|string|max:500',
            'motivo_ajuste' => 'nullable|string|max:500',
        ], [
            'stock.min' => 'El stock no puede ser negativo.',
            'stockminimo.min' => 'El stock mínimo no puede ser negativo.',
        ]);

        $almacen = $punto->almacen;
        abort_unless($almacen !== null, 404);

        $stockNuevo = (float) $data['stock'];
        $cambiaStock = abs($stockNuevo - (float) $insumo->stock) > 0.0001;

        // El stock del PDV proviene de recepciones; un ajuste manual es explícito, con permiso,
        // motivo y movimiento auditado (MIN-06, MIN-11). Nunca una edición «mágica» de la cantidad.
        if ($cambiaStock) {
            abort_unless($request->user()?->can('punto_venta.ajuste_stock'), 403, 'No tiene permiso para ajustar el stock del punto de venta.');

            if (trim((string) ($data['motivo_ajuste'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'motivo_ajuste' => 'Indique el motivo del ajuste de stock (merma, conteo físico, devolución…).',
                ]);
            }

            $capacidadService->validarOcupacionTrasCambioInsumo($almacen, $insumo, $stockNuevo, 'stock');
        }

        DB::transaction(function () use ($insumo, $data, $stockNuevo, $cambiaStock, $almacen, $request) {
            $bloqueado = Insumo::query()->whereKey($insumo->insumoid)->lockForUpdate()->firstOrFail();

            if ($cambiaStock) {
                $this->registrarAjuste($bloqueado, $almacen->almacenid, $stockNuevo, trim((string) $data['motivo_ajuste']), (int) $request->user()->usuarioid);
            }

            $bloqueado->update([
                'nombre' => $data['nombre'],
                'stock' => $cambiaStock ? $stockNuevo : (float) $bloqueado->stock,
                'stockminimo' => (float) ($data['stockminimo'] ?? $bloqueado->stockminimo ?? 0),
                'descripcion' => $data['descripcion'] ?? $bloqueado->descripcion,
            ]);
        });

        $destino = $request->input('return') === 'inventario'
            ? route('punto-venta.inventario.index', ['puntoventaid' => $punto->puntoventaid])
            : route('punto-venta.puntos.show', $punto);

        return redirect()
            ->to($destino)
            ->with('success', 'Producto del inventario actualizado.');
    }

    public function destroy(
        PuntoVenta $punto,
        Insumo $insumo,
        InventarioAlmacenProductoService $inventarioAlmacen
    ): RedirectResponse {
        $this->autorizarInsumo($punto, $insumo);

        $almacen = $punto->almacen;
        abort_unless($almacen !== null, 404);

        $insumoObjetivo = Insumo::query()
            ->whereKey((int) $insumo->insumoid)
            ->where('almacenid', (int) $almacen->almacenid)
            ->firstOrFail();

        EliminacionSegura::ejecutar(
            fn () => $inventarioAlmacen->eliminarProducto($almacen, $insumoObjetivo),
            'No se pudo eliminar el producto. Revise movimientos o referencias vinculadas.'
        );

        $destino = request()->input('return') === 'inventario'
            ? route('punto-venta.inventario.index', ['puntoventaid' => $punto->puntoventaid])
            : route('punto-venta.puntos.show', $punto);

        return redirect()
            ->to($destino)
            ->with('success', 'Producto eliminado del inventario.');
    }

    public function qr(PuntoVenta $punto, Insumo $insumo, TrazabilidadProductoPdvService $service): JsonResponse
    {
        $this->autorizarInsumo($punto, $insumo);

        $url = $service->urlPublica($insumo);
        $insumo->refresh();

        return response()->json([
            'url' => $url,
            'codigo' => $insumo->codigo_trazabilidad,
            'producto' => $insumo->nombre,
        ]);
    }

    /** Movimiento de ingreso/salida por la diferencia, con usuario, fecha, cantidades y motivo. */
    private function registrarAjuste(Insumo $insumo, int $almacenId, float $stockNuevo, string $motivo, int $usuarioId): void
    {
        $anterior = (float) $insumo->stock;
        $delta = round($stockNuevo - $anterior, 4);
        $naturaleza = $delta > 0 ? 'ingreso' : 'salida';

        $tipo = TipoMovimientoAlmacen::activosPorNaturaleza($naturaleza)->first();
        if ($tipo === null) {
            throw ValidationException::withMessages([
                'stock' => 'No hay un tipo de movimiento de '.$naturaleza.' activo para registrar el ajuste.',
            ]);
        }

        AlmacenMovimiento::create([
            'almacenid' => $almacenId,
            'insumoid' => $insumo->insumoid,
            'tipo_movimiento_almacenid' => $tipo->tipo_movimiento_almacenid,
            'usuarioid' => $usuarioId,
            'fecha' => now()->toDateString(),
            'cantidad' => abs($delta),
            'referencia' => 'AJUSTE-PDV',
            'destino_motivo' => mb_substr($motivo, 0, 150),
            'observaciones' => '[Ajuste PDV] '.number_format($anterior, 2, '.', '').' → '
                .number_format($stockNuevo, 2, '.', '').' · Motivo: '.$motivo,
        ]);
    }

    private function autorizarInsumo(PuntoVenta $punto, Insumo $insumo): void
    {
        abort_unless(PuntoVentaAccess::puedeEditarPunto(auth()->user(), $punto), 403);
        abort_unless(
            $punto->almacenid && (int) $insumo->almacenid === (int) $punto->almacenid,
            404
        );
    }
}
