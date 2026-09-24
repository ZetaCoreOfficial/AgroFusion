<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidenteEnvio;
use App\Support\ViajeAcceso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidenteEnvioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $estado = $request->string('estado')->toString();
        $q = ViajeAcceso::scopeIncidentes(IncidenteEnvio::query(), $request->user())
            ->with(['reportadoPor', 'resueltoPor', 'pedido'])
            ->when($estado !== '', fn($query) => $query->where('estado', $estado))
            ->orderByDesc('created_at');

        $incidentes = $q->paginate(20);

        return response()->json($incidentes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'externo_envio_id' => ['nullable', 'string', 'max:64'],
            'pedidoid' => ['nullable', 'integer', 'exists:pedido,pedidoid'],
            'tipo' => ['required', 'string', 'max:100'],
            'descripcion' => ['required', 'string', 'max:3000'],
        ]);

        abort_unless(ViajeAcceso::conductorPuedeReportarEn(
            $request->user(),
            $validated['externo_envio_id'] ?? null,
            isset($validated['pedidoid']) ? (int) $validated['pedidoid'] : null
        ), 403, 'Solo puede reportar incidentes de sus viajes asignados.');

        $validated['reportadopor_usuarioid'] = auth()->id();
        $validated['estado'] = 'abierto';

        $incidente = IncidenteEnvio::create($validated);

        return response()->json($incidente, 201);
    }

    public function resolve(Request $request, IncidenteEnvio $incidente): JsonResponse
    {
        abort_unless(ViajeAcceso::puedeVerIncidente($request->user(), $incidente), 403);

        $validated = $request->validate([
            'nota_resolucion' => ['nullable', 'string', 'max:2000'],
        ]);

        $incidente->update([
            'estado' => 'resuelto',
            'nota_resolucion' => $validated['nota_resolucion'] ?? null,
            'resueltopor_usuarioid' => auth()->id(),
            'fecha_resolucion' => now(),
        ]);

        return response()->json($incidente->fresh());
    }
}

