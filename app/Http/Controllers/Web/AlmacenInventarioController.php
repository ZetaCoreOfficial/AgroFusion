<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\Insumo;
use App\Models\UnidadMedida;
use App\Support\AlmacenAmbito;
use App\Support\InsumoCatalogo;
use App\Models\InsumoPresentacion;
use App\Services\InventarioAlmacenProductoService;
use App\Services\InventarioPresentacionService;
use App\Support\InsumoImagenCatalogo;
use App\Support\MayoristaAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AlmacenInventarioController extends Controller
{
    public function show(Request $request, Almacen $almacen, Insumo $insumo, InventarioPresentacionService $inventarioPresentacion): View
    {
        $ctx = AlmacenAmbito::contexto($request);
        $this->autorizarProducto($request, $almacen, $insumo, $ctx['ambito'], gestion: false);

        $insumo->load(['tipo', 'unidadMedida', 'almacen']);
        $estadoStock = InsumoCatalogo::estadoStockAlmacen($insumo);

        $inventarioPresentacion->asegurarInventarioDesdeStock((int) $almacen->almacenid, (int) $insumo->insumoid);

        $empaquetajes = InsumoPresentacion::query()
            ->with('tipoEmpaque')
            ->where('insumoid', $insumo->insumoid)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->map(function (InsumoPresentacion $presentacion) use ($almacen, $inventarioPresentacion) {
                $unidades = $inventarioPresentacion->stockTotalUnidades(
                    (int) $almacen->almacenid,
                    (int) $presentacion->insumo_presentacionid
                );
                $kg = $inventarioPresentacion->stockTotalKg(
                    (int) $almacen->almacenid,
                    (int) $presentacion->insumo_presentacionid
                );

                return [
                    'presentacion' => $presentacion,
                    'unidades' => $unidades,
                    'kg' => $kg,
                ];
            })
            ->filter(fn (array $row) => $row['unidades'] > 0 || $row['kg'] > 0)
            ->values();

        return view('almacenes.inventario.show', array_merge($ctx, [
            'almacen' => $almacen,
            'producto' => $insumo,
            'estadoStock' => $estadoStock,
            'empaquetajes' => $empaquetajes,
            'loteProduccionPedidoId' => $request->integer('lote') ?: null,
            'redirectAfter' => $this->redirectInterno($request->query('redirect')),
        ]));
    }

    public function edit(Request $request, Almacen $almacen, Insumo $insumo): View
    {
        $ctx = AlmacenAmbito::contexto($request);
        $this->autorizarProducto($request, $almacen, $insumo, $ctx['ambito'], gestion: true);

        $insumo->load(['tipo', 'unidadMedida', 'almacen']);

        return view('almacenes.inventario.edit', array_merge($ctx, [
            'almacen' => $almacen,
            'producto' => $insumo,
            'unidades' => UnidadMedida::query()->orderBy('nombre')->get(),
            'redirectAfter' => $this->redirectInterno($request->query('redirect')),
        ]));
    }

    public function update(Request $request, Almacen $almacen, Insumo $insumo): RedirectResponse
    {
        $ctx = AlmacenAmbito::contexto($request);
        $this->autorizarProducto($request, $almacen, $insumo, $ctx['ambito'], gestion: true);

        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'unidadmedidaid' => 'required|exists:unidadmedida,unidadmedidaid',
            'stock' => 'required|numeric|min:0',
            'stockminimo' => 'nullable|numeric|min:0',
            'descripcion' => 'nullable|string|max:500',
            'imagen' => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:4096',
            'quitar_imagen' => 'nullable|boolean',
        ]);

        $payload = [
            'nombre' => $data['nombre'],
            'unidadmedidaid' => $data['unidadmedidaid'],
            'stock' => (float) $data['stock'],
            'stockminimo' => (float) ($data['stockminimo'] ?? $insumo->stockminimo ?? InsumoCatalogo::UMBRAL_ALERTA_STOCK),
            'descripcion' => $data['descripcion'] ?? $insumo->descripcion,
        ];

        $payload = $this->aplicarImagenProducto($request, $payload, $insumo);

        $insumo->update($payload);

        return $this->redirigirDespuesInventario($request, $ctx, $almacen, 'Producto actualizado en el almacén.');
    }

    public function destroy(
        Request $request,
        Almacen $almacen,
        Insumo $insumo,
        InventarioAlmacenProductoService $inventarioAlmacen
    ): RedirectResponse {
        $ctx = AlmacenAmbito::contexto($request);
        $this->autorizarProducto($request, $almacen, $insumo, $ctx['ambito'], gestion: true);

        $inventarioAlmacen->eliminarProducto($almacen, $insumo);

        return $this->redirigirDespuesInventario($request, $ctx, $almacen, 'Producto eliminado del almacén.');
    }

    private function redirectInterno(mixed $redirect): ?string
    {
        if (! is_string($redirect) || $redirect === '') {
            return null;
        }

        return str_starts_with($redirect, url('/')) ? $redirect : null;
    }

    private function redirigirDespuesInventario(
        Request $request,
        array $ctx,
        Almacen $almacen,
        string $mensaje
    ): RedirectResponse {
        $redirect = $this->redirectInterno($request->input('redirect'));

        if ($redirect !== null) {
            return redirect($redirect)->with('success', $mensaje);
        }

        return redirect()
            ->route($ctx['rutaPrefijo'].'.show', $almacen)
            ->with('success', $mensaje);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function aplicarImagenProducto(Request $request, array $data, Insumo $insumo): array
    {
        if ($request->boolean('quitar_imagen')) {
            $this->eliminarImagenSubida($insumo->imagenurl);
            $data['imagenurl'] = InsumoImagenCatalogo::urlProductoTerminado($data['nombre']);

            return $data;
        }

        if ($request->hasFile('imagen')) {
            $this->eliminarImagenSubida($insumo->imagenurl);
            $data['imagenurl'] = $request->file('imagen')->store('insumos', 'public');
        }

        return $data;
    }

    private function eliminarImagenSubida(?string $imagenurl): void
    {
        $ruta = InsumoImagenCatalogo::rutaAlmacenamiento($imagenurl);
        if ($ruta !== null && Storage::disk('public')->exists($ruta)) {
            Storage::disk('public')->delete($ruta);
        }
    }

    private function autorizarProducto(Request $request, Almacen $almacen, Insumo $insumo, string $ambito, bool $gestion): void
    {
        abort_unless(
            in_array($ambito, [AlmacenAmbito::MAYORISTA, AlmacenAmbito::PLANTA], true),
            404
        );

        abort_unless(
            ($almacen->ambito ?? '') === $ambito
            && (int) $insumo->almacenid === (int) $almacen->almacenid,
            404
        );

        // Ownership (MAY-06): el inventario de un almacén mayorista ajeno no se ve, edita, ajusta ni elimina.
        if ($ambito === AlmacenAmbito::MAYORISTA) {
            if ($gestion) {
                MayoristaAccess::asegurarPuedeGestionar($request->user(), $almacen);
            } else {
                MayoristaAccess::asegurarPuedeVer($request->user(), $almacen);
            }
        }

        InsumoCatalogo::asegurarInsumoGestionable($insumo);

        abort_unless(
            InsumoCatalogo::esProductoTerminadoDistribucion($insumo)
            || InsumoCatalogo::esCosechaRecepcionPlanta($insumo),
            404,
            'El producto no pertenece al inventario de este almacén.'
        );
    }
}
