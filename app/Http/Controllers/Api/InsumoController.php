<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Insumo;
use App\Support\AlmacenAcceso;
use App\Support\AlmacenAmbito;
use App\Support\InsumoCatalogo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InsumoController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $this->scopeVisibles(Insumo::query(), $request)
                ->with(['tipo', 'unidadMedida'])
                ->get()
                ->makeVisible(['tipo', 'unidadMedida'])
        );
    }

    public function show(Request $request, $id)
    {
        $insumo = $this->scopeVisibles(Insumo::query(), $request)
            ->with(['tipo', 'unidadMedida', 'loteInsumos'])
            ->findOrFail($id)
            ->makeVisible(['tipo', 'unidadMedida']);

        return response()->json($insumo);
    }

    public function store(Request $request)
    {
        $data = $this->validarInsumo($request);
        $data['stockminimo'] = InsumoCatalogo::UMBRAL_ALERTA_STOCK;

        $insumo = Insumo::create($data);

        return response()->json($insumo->load(['tipo', 'unidadMedida'])->makeVisible(['tipo', 'unidadMedida']), 201);
    }

    public function update(Request $request, $id)
    {
        $insumo = Insumo::findOrFail($id);
        $this->asegurarPuedeModificar($request, $insumo);

        $data = $this->validarInsumo($request, partial: true);

        // El stock de almacenes mayoristas/PDV solo cambia por movimientos auditados, no por edición directa.
        if (array_key_exists('stock', $data) && $this->esInventarioComercial($insumo)
            && abs((float) $data['stock'] - (float) $insumo->stock) > 0.0001) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'stock' => 'El stock de este almacén se modifica mediante movimientos de ingreso/salida.',
            ]);
        }
        $data['stockminimo'] = InsumoCatalogo::UMBRAL_ALERTA_STOCK;

        $insumo->update($data);

        return response()->json($insumo->load(['tipo', 'unidadMedida'])->makeVisible(['tipo', 'unidadMedida']));
    }

    public function destroy(Request $request, $id)
    {
        $insumo = Insumo::findOrFail($id);
        $this->asegurarPuedeModificar($request, $insumo);
        $insumo->delete();

        return response()->json(['message' => 'Eliminado correctamente']);
    }

    /**
     * Insumos sin almacén (catálogo agrícola) siguen visibles según el permiso de la ruta; los que
     * pertenecen a un almacén solo si ese almacén es visible para el usuario (MAY-05).
     */
    private function scopeVisibles($query, Request $request)
    {
        $ids = AlmacenAcceso::idsVisibles($request->user());

        return $query->where(function ($q) use ($ids) {
            $q->whereNull('almacenid')->orWhereIn('almacenid', $ids ?: [-1]);
        });
    }

    private function asegurarPuedeModificar(Request $request, Insumo $insumo): void
    {
        $insumo->loadMissing('almacen');
        if ($insumo->almacen !== null) {
            AlmacenAcceso::asegurarPuedeGestionar($request->user(), $insumo->almacen);
        }
    }

    private function esInventarioComercial(Insumo $insumo): bool
    {
        $insumo->loadMissing('almacen');

        return $insumo->almacen !== null && in_array(
            AlmacenAmbito::resolverAmbito($insumo->almacen),
            [AlmacenAmbito::MAYORISTA, AlmacenAmbito::PUNTO_VENTA],
            true
        );
    }

    private function validarInsumo(Request $request, bool $partial = false): array
    {
        InsumoCatalogo::asegurarCatalogosBase();
        $tiposIds = InsumoCatalogo::tiposOrdenados()->pluck('tipoinsumoid')->all();

        $rules = [
            'nombre' => ($partial ? 'sometimes|' : '').'required|string|max:100',
            'tipoinsumoid' => ($partial ? 'sometimes|' : '').'required|'.Rule::in($tiposIds),
            'unidadmedidaid' => ($partial ? 'sometimes|' : '').'required|exists:unidadmedida,unidadmedidaid',
            'stock' => ($partial ? 'sometimes|' : '').'required|numeric|min:0',
            'descripcion' => 'nullable|string',
        ];

        $data = $request->validate($rules);

        if (isset($data['tipoinsumoid'], $data['unidadmedidaid'])) {
            $this->validarUnidadParaTipo((int) $data['tipoinsumoid'], (int) $data['unidadmedidaid']);
        } elseif (isset($data['unidadmedidaid']) && $request->filled('tipoinsumoid')) {
            $this->validarUnidadParaTipo((int) $request->input('tipoinsumoid'), (int) $data['unidadmedidaid']);
        }

        return $data;
    }

    private function validarUnidadParaTipo(int $tipoinsumoid, int $unidadmedidaid): void
    {
        $tipo = InsumoCatalogo::tiposOrdenados()->firstWhere('tipoinsumoid', $tipoinsumoid);
        $slug = InsumoCatalogo::slugFromNombreTipo($tipo?->nombre);
        $permitidas = collect(InsumoCatalogo::unidadesPorTipoParaJs()[$slug] ?? [])->pluck('id')->all();

        if ($permitidas !== [] && ! in_array($unidadmedidaid, $permitidas, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'unidadmedidaid' => 'La unidad no corresponde al tipo de insumo seleccionado.',
            ]);
        }
    }
}
