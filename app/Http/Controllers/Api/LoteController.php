<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lote;
use App\Support\LoteAcceso;
use App\Support\LoteCultivoResolver;
use App\Support\LoteDefaults;
use App\Support\UbicacionGpsParser;
use Illuminate\Http\Request;

class LoteController extends Controller
{
    public function index(Request $request)
    {
        $query = Lote::query()->with(['usuario', 'cultivo', 'estadoTipo', 'insumoSemilla']);
        LoteAcceso::aplicarScopeVisibles($query, $request->user());

        return response()->json(
            $query->get()->makeVisible(['usuario', 'cultivo', 'estadoTipo', 'insumoSemilla'])
        );
    }

    public function show(Request $request, $lote)
    {
        $lote = $lote instanceof Lote ? $lote : Lote::query()->findOrFail($lote);
        abort_unless(LoteAcceso::puedeVer($request->user(), $lote), 403, 'No tienes acceso a este lote.');

        return response()->json(
            $lote->load(['usuario', 'cultivo', 'estadoTipo', 'producciones', 'actividades', 'insumoSemilla'])
                ->makeVisible(['usuario', 'cultivo', 'estadoTipo', 'insumoSemilla', 'producciones', 'actividades'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuarioid' => 'required|exists:usuario,usuarioid',
            'nombre' => 'required|string|max:100',
            'ubicacion' => 'nullable|string|max:200',
            'superficie' => 'required|numeric|min:0.01',
            'insumosemallaid' => 'nullable|exists:insumo,insumoid',
            'cantidad_semilla_planificada' => 'nullable|numeric|min:0',
            'latitud' => 'nullable|numeric|between:-90,90',
            'longitud' => 'nullable|numeric|between:-180,180',
            'imagen' => 'nullable|image|max:2048',
        ]);

        $data['cultivoid'] = LoteCultivoResolver::resolver($data['insumosemallaid'] ?? null);

        $data['ubicacion'] = UbicacionGpsParser::normalizarUbicacionLote(
            $data['ubicacion'] ?? null,
            isset($data['latitud']) ? (float) $data['latitud'] : null,
            isset($data['longitud']) ? (float) $data['longitud'] : null
        );

        if ($request->hasFile('imagen')) {
            try {
                $file = $request->file('imagen');
                $filename = 'lote_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $supabase = new \App\Services\SupabaseStorage();
                $response = $supabase->upload($filename, file_get_contents($file), $file->getMimeType());
                if ($response->successful()) {
                    $data['imagenurl'] = $supabase->getPublicUrl($filename);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Supabase upload error: ' . $e->getMessage());
            }
        }

        unset($data['imagen']);
        $data = LoteDefaults::enrich($data, true);
        $lote = Lote::create($data);
        LoteDefaults::registrarHistorialInicial($lote);

        return response()->json(
            $lote->load(['usuario', 'cultivo', 'estadoTipo', 'insumoSemilla'])
                ->makeVisible(['usuario', 'cultivo', 'estadoTipo', 'insumoSemilla']),
            201
        );
    }

    public function update(Request $request, $lote)
    {
        $lote = $lote instanceof Lote ? $lote : Lote::query()->findOrFail($lote);
        abort_unless(LoteAcceso::puedeGestionar($request->user(), $lote), 403, 'No tienes acceso a este lote.');

        $data = $request->validate([
            'usuarioid' => 'sometimes|exists:usuario,usuarioid',
            'nombre' => 'sometimes|string|max:100',
            'ubicacion' => 'nullable|string|max:200',
            'superficie' => 'sometimes|numeric|min:0.01',
            'insumosemallaid' => 'nullable|exists:insumo,insumoid',
            'cantidad_semilla_planificada' => 'nullable|numeric|min:0',
            'latitud' => 'nullable|numeric|between:-90,90',
            'longitud' => 'nullable|numeric|between:-180,180',
        ]);

        if (isset($data['insumosemallaid'])) {
            $data['cultivoid'] = LoteCultivoResolver::resolver($data['insumosemallaid'] ?? null);
        }

        $data = LoteDefaults::enrich($data, false);
        $lote->update($data);

        return response()->json(
            $lote->load(['usuario', 'cultivo', 'estadoTipo', 'insumoSemilla'])
                ->makeVisible(['usuario', 'cultivo', 'estadoTipo', 'insumoSemilla'])
        );
    }

    public function destroy(Request $request, $lote)
    {
        $lote = $lote instanceof Lote ? $lote : Lote::query()->findOrFail($lote);
        abort_unless(LoteAcceso::puedeGestionar($request->user(), $lote), 403, 'No tienes acceso a este lote.');

        $lote->delete();

        return response()->json(['message' => 'Eliminado correctamente']);
    }
}
