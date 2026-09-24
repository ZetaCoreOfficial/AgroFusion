<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SolicitudProduccionPlanta;
use App\Services\SolicitudProduccionPlantaService;
use App\Support\SolicitudProduccionPlantaCatalogo;
use App\Support\UsuarioRol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SolicitudProduccionPlantaController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(
            UsuarioRol::esPlantaOperativo($request->user())
                || UsuarioRol::esJefePlanta($request->user())
                || UsuarioRol::esAdminGlobal($request->user()),
            403
        );

        $estado = trim((string) $request->query('estado', ''));

        $query = SolicitudProduccionPlanta::query()
            ->with(['pedidoDistribucion.puntoVenta', 'creadoPor', 'aceptadoPor'])
            ->orderByDesc('solicitudproduccionplantaid');

        if ($estado !== '') {
            $query->where('estado', $estado);
        }

        $solicitudes = $query->paginate(15)->withQueryString();

        return view('planta.solicitudes-produccion.index', [
            'solicitudes' => $solicitudes,
            'estadoFiltro' => $estado,
            'etiquetasEstado' => [
                SolicitudProduccionPlantaCatalogo::ESTADO_PENDIENTE => SolicitudProduccionPlantaCatalogo::etiquetaEstado(SolicitudProduccionPlantaCatalogo::ESTADO_PENDIENTE),
                SolicitudProduccionPlantaCatalogo::ESTADO_ACEPTADA => SolicitudProduccionPlantaCatalogo::etiquetaEstado(SolicitudProduccionPlantaCatalogo::ESTADO_ACEPTADA),
                SolicitudProduccionPlantaCatalogo::ESTADO_EN_PRODUCCION => SolicitudProduccionPlantaCatalogo::etiquetaEstado(SolicitudProduccionPlantaCatalogo::ESTADO_EN_PRODUCCION),
                SolicitudProduccionPlantaCatalogo::ESTADO_COMPLETADA => SolicitudProduccionPlantaCatalogo::etiquetaEstado(SolicitudProduccionPlantaCatalogo::ESTADO_COMPLETADA),
                SolicitudProduccionPlantaCatalogo::ESTADO_RECHAZADA => SolicitudProduccionPlantaCatalogo::etiquetaEstado(SolicitudProduccionPlantaCatalogo::ESTADO_RECHAZADA),
            ],
        ]);
    }

    public function show(SolicitudProduccionPlanta $solicitud): View
    {
        abort_unless(
            UsuarioRol::esPlantaOperativo(auth()->user())
                || UsuarioRol::esJefePlanta(auth()->user())
                || UsuarioRol::esAdminGlobal(auth()->user())
                || UsuarioRol::puedeGestionarDistribucionMayorista(auth()->user()),
            403
        );

        $solicitud->load([
            'pedidoDistribucion.puntoVenta.minorista',
            'pedidoDistribucion.detalles.presentacion',
            'creadoPor',
            'aceptadoPor',
            'presentacion',
            'insumoPlantaReferencia',
        ]);

        $puedeGestionarPlanta = UsuarioRol::esPlantaOperativo(auth()->user())
            || UsuarioRol::esJefePlanta(auth()->user());

        return view('planta.solicitudes-produccion.show', [
            'solicitud' => $solicitud,
            'puedeGestionarPlanta' => $puedeGestionarPlanta,
        ]);
    }

    public function aceptar(SolicitudProduccionPlanta $solicitud): RedirectResponse
    {
        abort_unless(
            UsuarioRol::esPlantaOperativo(auth()->user())
                || UsuarioRol::esJefePlanta(auth()->user()),
            403
        );

        try {
            app(SolicitudProduccionPlantaService::class)->aceptar($solicitud, auth()->user());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Solicitud aceptada. Puede marcar en producción o completar cuando esté lista.');
    }

    public function marcarEnProduccion(SolicitudProduccionPlanta $solicitud): RedirectResponse
    {
        abort_unless(
            UsuarioRol::esPlantaOperativo(auth()->user())
                || UsuarioRol::esJefePlanta(auth()->user()),
            403
        );

        try {
            app(SolicitudProduccionPlantaService::class)->marcarEnProduccion($solicitud);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Solicitud marcada en producción.');
    }

    public function completar(SolicitudProduccionPlanta $solicitud): RedirectResponse
    {
        abort_unless(
            UsuarioRol::esPlantaOperativo(auth()->user())
                || UsuarioRol::esJefePlanta(auth()->user()),
            403
        );

        try {
            app(SolicitudProduccionPlantaService::class)->completar($solicitud);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Producción completada. El mayorista puede continuar con la entrega al punto de venta.');
    }

    public function rechazar(Request $request, SolicitudProduccionPlanta $solicitud): RedirectResponse
    {
        abort_unless(
            UsuarioRol::esPlantaOperativo(auth()->user())
                || UsuarioRol::esJefePlanta(auth()->user()),
            403
        );

        $data = $request->validate(['motivo_rechazo' => 'nullable|string|max:500']);

        try {
            app(SolicitudProduccionPlantaService::class)->rechazar(
                $solicitud,
                $data['motivo_rechazo'] ?? null,
                auth()->user()
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('planta.solicitudes-produccion.index')
            ->with('success', 'Solicitud rechazada.');
    }
}
