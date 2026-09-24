<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentoEntrega;
use App\Support\DocumentoEntregaCatalogo;
use App\Support\DocumentoEntregaTransportista;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoEntregaController extends Controller
{
    public function index(): JsonResponse
    {
        $q = DocumentoEntrega::query()
            ->with(['usuario', 'pedido'])
            ->tap(fn ($query) => DocumentoEntregaCatalogo::aplicarFiltroOperativo($query))
            ->orderByDesc('created_at');

        // Misma regla que la web (TRA-13): conductor → sus viajes; mayorista → sus almacenes; etc.
        \App\Support\DocumentoEntregaAcceso::aplicarFiltroRol($q, auth()->user());

        $documentos = $q->paginate(20);

        return response()->json($documentos);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['required', 'string', 'max:50'],
            'externo_envio_id' => ['nullable', 'string', 'max:64'],
            'pedidoid' => ['nullable', 'integer', 'exists:pedido,pedidoid'],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'almacenid' => ['nullable', 'integer', 'exists:almacen,almacenid'],
        ]);

        $user = $request->user();
        if ($user->hasRole('transportista')) {
            $ext = $validated['externo_envio_id'] ?? null;
            $ped = $validated['pedidoid'] ?? null;
            if (($ext === null || $ext === '') && $ped === null) {
                return response()->json(['message' => 'Indique externo_envio_id o pedidoid de su asignación.'], 422);
            }
            if (! DocumentoEntregaTransportista::puedeSubirParaSusAsignaciones($user->usuarioid, $ext, $ped)) {
                return response()->json(['message' => 'Solo puede cargar documentos para envíos asignados a usted.'], 403);
            }
        }

        $path = $request->file('archivo')->store('documentos_entrega', 'public');

        $almacenDoc = $validated['almacenid'] ?? null;

        $documento = DocumentoEntrega::create([
            'titulo' => $validated['titulo'],
            'tipo_documento' => $validated['tipo_documento'],
            'externo_envio_id' => $validated['externo_envio_id'] ?? null,
            'pedidoid' => $validated['pedidoid'] ?? null,
            'almacenid' => $almacenDoc,
            'archivo_path' => $path,
            'usuarioid' => auth()->id(),
            'metadata' => [
                'original_name' => $request->file('archivo')->getClientOriginalName(),
                'mime' => $request->file('archivo')->getClientMimeType(),
                'size' => $request->file('archivo')->getSize(),
            ],
        ]);

        return response()->json($documento, 201);
    }

    public function download(DocumentoEntrega $documento): StreamedResponse
    {
        abort_unless(\App\Support\DocumentoEntregaAcceso::puedeVerDocumento($documento, auth()->user()), 403);
        abort_unless(Storage::disk('public')->exists($documento->archivo_path), 404, 'Documento no encontrado.');

        return Storage::disk('public')->download(
            $documento->archivo_path,
            ($documento->metadata['original_name'] ?? $documento->titulo.'.pdf')
        );
    }
}

