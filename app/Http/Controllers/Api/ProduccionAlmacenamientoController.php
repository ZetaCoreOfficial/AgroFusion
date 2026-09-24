<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\ProduccionAlmacenamiento;
use App\Support\AlmacenAcceso;
use Illuminate\Http\Request;

/** Registros de almacenamiento por API: solo sobre almacenes visibles/operados por el usuario (MAY-05). */
class ProduccionAlmacenamientoController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            AlmacenAcceso::scopeVisibles(ProduccionAlmacenamiento::query(), $request->user())
                ->with(['produccion', 'almacen', 'unidadMedida'])
                ->get()
        );
    }

    public function show(Request $request, $id)
    {
        $registro = ProduccionAlmacenamiento::with(['produccion', 'almacen', 'unidadMedida'])->findOrFail($id);
        abort_unless(in_array((int) $registro->almacenid, AlmacenAcceso::idsVisibles($request->user()), true), 404);

        return response()->json($registro);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'produccionid'   => 'required|exists:produccion,produccionid',
            'almacenid'      => 'required|exists:almacen,almacenid',
            'cantidad'       => 'required|numeric|min:0.01',
            'unidadmedidaid' => 'nullable|exists:unidadmedida,unidadmedidaid',

            'temperatura'     => 'nullable|numeric|between:-50,80',
            'humedad'         => 'nullable|numeric|between:0,100',
            'temperatura_min' => 'nullable|numeric|between:-50,80',
            'temperatura_max' => 'nullable|numeric|between:-50,80',
            'humedad_min'     => 'nullable|numeric|between:0,100',
            'humedad_max'     => 'nullable|numeric|between:0,100',

            'fechaentrada'   => 'nullable|date',
            'fechasalida'    => 'nullable|date',
            'observaciones'  => 'nullable|string|max:250',
        ]);

        AlmacenAcceso::asegurarPuedeGestionar($request->user(), Almacen::query()->findOrFail($data['almacenid']));

        $registro = ProduccionAlmacenamiento::create($data);

        return response()->json(
            $registro->load(['produccion', 'almacen', 'unidadMedida']),
            201
        );
    }

    public function update(Request $request, $id)
    {
        $registro = ProduccionAlmacenamiento::findOrFail($id);
        AlmacenAcceso::asegurarPuedeGestionar($request->user(), Almacen::query()->findOrFail($registro->almacenid));

        $data = $request->validate([
            'produccionid'   => 'sometimes|exists:produccion,produccionid',
            'almacenid'      => 'sometimes|exists:almacen,almacenid',
            'cantidad'       => 'sometimes|numeric|min:0.01',
            'unidadmedidaid' => 'nullable|exists:unidadmedida,unidadmedidaid',

            'temperatura'     => 'nullable|numeric|between:-50,80',
            'humedad'         => 'nullable|numeric|between:0,100',
            'temperatura_min' => 'nullable|numeric|between:-50,80',
            'temperatura_max' => 'nullable|numeric|between:-50,80',
            'humedad_min'     => 'nullable|numeric|between:0,100',
            'humedad_max'     => 'nullable|numeric|between:0,100',

            'fechaentrada'   => 'nullable|date',
            'fechasalida'    => 'nullable|date',
            'observaciones'  => 'nullable|string|max:250',
        ]);

        if (isset($data['almacenid'])) {
            AlmacenAcceso::asegurarPuedeGestionar($request->user(), Almacen::query()->findOrFail($data['almacenid']));
        }

        $registro->update($data);

        return response()->json(
            $registro->load(['produccion', 'almacen', 'unidadMedida'])
        );
    }

    public function destroy(Request $request, $id)
    {
        $registro = ProduccionAlmacenamiento::findOrFail($id);
        AlmacenAcceso::asegurarPuedeGestionar($request->user(), Almacen::query()->findOrFail($registro->almacenid));
        $registro->delete();

        return response()->json(['message' => 'Eliminado correctamente']);
    }
}