<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Support\AlmacenAcceso;
use App\Support\AlmacenAmbito;
use App\Support\UsuarioRol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Almacenes por API: auth + permiso en la ruta, ownership aquí (MAY-05). */
class AlmacenController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            AlmacenAcceso::scopeVisibles(Almacen::query(), $request->user())
                ->with(['tipoAlmacen', 'unidadMedida', 'almacenamientos'])
                ->get()
        );
    }

    public function show(Request $request, $id)
    {
        $almacen = Almacen::with(['tipoAlmacen', 'unidadMedida', 'almacenamientos'])->findOrFail($id);
        abort_unless(AlmacenAcceso::puedeVer($request->user(), $almacen), 404);

        return response()->json($almacen);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless(UsuarioRol::puedeOperar($user), 403);

        // Los almacenes de punto de venta se crean desde su PDV; el resto con ámbito explícito y el
        // creador como responsable (como en la web), nunca un almacén huérfano sin dueño.
        $ambitosPermitidos = array_values(array_filter(
            [AlmacenAmbito::AGRICOLA, AlmacenAmbito::PLANTA, AlmacenAmbito::MAYORISTA],
            fn (string $ambito) => AlmacenAmbito::usuarioPuedeVer($user, $ambito)
        ));

        $data = $request->validate([
            'nombre'        => 'required|string|max:100',
            'descripcion'   => 'nullable|string|max:250',
            'ubicacion'     => 'nullable|string|max:200',
            'capacidad'     => 'required|numeric|min:0.01',
            'unidadmedidaid'=> 'nullable|exists:unidadmedida,unidadmedidaid',
            'tipoalmacenid' => 'nullable|exists:tipoalmacen,tipoalmacenid',
            'activo'        => 'boolean',
            'ambito'        => ['required', Rule::in($ambitosPermitidos)],
        ]);

        if ($data['ambito'] === AlmacenAmbito::MAYORISTA) {
            abort_unless(UsuarioRol::esMayorista($user), 403);
        }

        if (Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            $data['responsable_usuarioid'] = (int) $user->usuarioid;
        }

        $almacen = Almacen::create($data);

        return response()->json(
            $almacen->load(['tipoAlmacen', 'unidadMedida']),
            201
        );
    }

    public function update(Request $request, $id)
    {
        $almacen = Almacen::findOrFail($id);
        AlmacenAcceso::asegurarPuedeGestionar($request->user(), $almacen);

        $data = $request->validate([
            'nombre'        => 'sometimes|string|max:100',
            'descripcion'   => 'nullable|string|max:250',
            'ubicacion'     => 'nullable|string|max:200',
            'capacidad'     => 'required|numeric|min:0.01',
            'unidadmedidaid'=> 'nullable|exists:unidadmedida,unidadmedidaid',
            'tipoalmacenid' => 'nullable|exists:tipoalmacen,tipoalmacenid',
            'activo'        => 'boolean',
        ]);

        $almacen->update($data);

        return response()->json(
            $almacen->load(['tipoAlmacen', 'unidadMedida'])
        );
    }

    public function destroy(Request $request, $id)
    {
        $almacen = Almacen::findOrFail($id);
        AlmacenAcceso::asegurarPuedeGestionar($request->user(), $almacen);
        $almacen->delete();

        return response()->json(['message' => 'Eliminado correctamente']);
    }
}
