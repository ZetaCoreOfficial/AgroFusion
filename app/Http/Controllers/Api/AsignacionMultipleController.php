<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnvioAsignacionMultiple;
use App\Support\ViajeAcceso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsignacionMultipleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Ownership (TRA-02): el conductor solo lista sus envíos; comercial no ve el trayecto agrícola.
        $q = ViajeAcceso::scopeEnviosAgricolas(EnvioAsignacionMultiple::query(), $request->user())
            ->with(['transportista', 'asignadoPor', 'ruta'])
            ->orderByDesc('created_at');
        $asignaciones = $q->paginate(30);

        return response()->json($asignaciones);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'externo_envio_id' => ['required', 'string', 'max:64'],
            'pedidoid' => ['nullable', 'integer', 'exists:pedido,pedidoid'],
            'transportista_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'rutamultientregaid' => ['nullable', 'integer', 'exists:ruta_multi_entrega,rutamultientregaid'],
            'vehiculo_ref' => ['nullable', 'string', 'max:80'],
            'almacenid' => ['nullable', 'integer', 'exists:almacen,almacenid'],
        ]);

        $asignacion = EnvioAsignacionMultiple::updateOrCreate(
            [
                'externo_envio_id' => $validated['externo_envio_id'],
                'transportista_usuarioid' => $validated['transportista_usuarioid'],
            ],
            [
                'pedidoid' => $validated['pedidoid'] ?? null,
                'asignadopor_usuarioid' => auth()->id(),
                'rutamultientregaid' => $validated['rutamultientregaid'] ?? null,
                'vehiculo_ref' => $validated['vehiculo_ref'] ?? null,
                'almacenid' => $validated['almacenid'] ?? null,
                'estado' => 'asignado',
                'fecha_asignacion' => now(),
            ]
        );

        return response()->json($asignacion->load(['transportista', 'asignadoPor', 'ruta']), 201);
    }

    public function storeBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'envio_ids' => ['required', 'array', 'min:1'],
            'envio_ids.*' => ['required', 'string', 'max:64'],
            'transportista_usuarioid' => ['required', 'integer', 'exists:usuario,usuarioid'],
            'rutamultientregaid' => ['nullable', 'integer', 'exists:ruta_multi_entrega,rutamultientregaid'],
            'vehiculo_ref' => ['nullable', 'string', 'max:80'],
            'almacenid' => ['nullable', 'integer', 'exists:almacen,almacenid'],
        ]);

        foreach ($validated['envio_ids'] as $envioId) {
            EnvioAsignacionMultiple::updateOrCreate(
                [
                    'externo_envio_id' => $envioId,
                    'transportista_usuarioid' => $validated['transportista_usuarioid'],
                ],
                [
                    'asignadopor_usuarioid' => auth()->id(),
                    'rutamultientregaid' => $validated['rutamultientregaid'] ?? null,
                    'vehiculo_ref' => $validated['vehiculo_ref'] ?? null,
                    'almacenid' => $validated['almacenid'] ?? null,
                    'estado' => 'asignado',
                    'fecha_asignacion' => now(),
                ]
            );
        }

        return response()->json(['message' => 'Asignación múltiple registrada.']);
    }
}

