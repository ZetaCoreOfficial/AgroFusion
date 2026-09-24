<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CondicionTransporte;
use App\Models\RutaDistribucion;
use App\Models\TipoIncidenteTransporte;
use App\Services\CierreEnvioDistribucionPdvService;
use App\Services\DistribucionRutaService;
use App\Services\RecepcionPdvMinoristaService;
use App\Support\EnvioCierreAgricolaCatalogo;
use App\Support\EnvioCierreRutaUiCatalogo;
use App\Support\MayoristaAccess;
use App\Support\PuntoVentaAccess;
use App\Support\RutaDistribucionNavegacion;
use App\Support\UsuarioRol;
use App\Support\ViajeAcceso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnvioCierreDistribucionPdvController extends Controller
{
    public function __construct(
        private readonly CierreEnvioDistribucionPdvService $cierre,
        private readonly DistribucionRutaService $distribucion,
        private readonly RecepcionPdvMinoristaService $recepcion,
    ) {}

    public function panel(Request $request, RutaDistribucion $ruta): View
    {
        abort_if($ruta->esTrasladoPlantaMayorista(), 404);
        $this->autorizarVer($ruta);

        $ruta->load([
            'transportista',
            'vehiculo',
            'almacenOrigen',
            'paradas',
            'pedidos.puntoVenta',
            'checklistCondicionVehiculo.detalles.condicion',
            'checklistIncidente.detalles.tipoIncidente',
            'firmaTransportista',
            'firmaRecepcion',
            'llegadaConfirmadaPor',
        ]);

        $resumen = $this->cierre->resumenPasos($ruta);
        $pasoActual = $resumen['paso_actual'];
        $pasosCompletados = EnvioCierreAgricolaCatalogo::pasosCompletados($resumen);
        $pasoSolicitado = (string) $request->query('paso', '');

        if ($pasoSolicitado !== ''
            && in_array($pasoSolicitado, EnvioCierreAgricolaCatalogo::pasosOrdenados(), true)) {
            if ($pasoSolicitado === $pasoActual) {
                $pasoVista = $pasoActual;
                $modoConsulta = false;
            } elseif (in_array($pasoSolicitado, $pasosCompletados, true)) {
                $pasoVista = $pasoSolicitado;
                $modoConsulta = true;
            } else {
                $pasoVista = $pasoActual;
                $modoConsulta = false;
            }
        } else {
            $pasoVista = $pasoActual;
            $modoConsulta = false;
        }

        $rutaPrefijo = $request->routeIs('logistica.rutas-distribucion.*')
            ? 'logistica.rutas-distribucion'
            : 'punto-venta.rutas';
        $user = $request->user();
        $vistaResumenCompleto = (bool) ($resumen['recibido_pdv'] ?? false);
        $esVistaMinorista = $this->recepcion->esVistaMinorista($user);
        $estadoRecepcion = $this->recepcion->estadoRecepcion($ruta);
        $vistaMinoristaEspera = false;
        $vistaMayoristaEspera = false;

        if ($esVistaMinorista && ! $vistaResumenCompleto && $pasoSolicitado === '') {
            if ($resumen['puede_firmar_recepcion'] ?? false) {
                $pasoVista = EnvioCierreAgricolaCatalogo::PASO_FIRMA_RECEPCION;
                $modoConsulta = false;
            } elseif (! ($resumen['firma_recepcion'] ?? false)) {
                $vistaMinoristaEspera = true;
                $pasoVista = '__receptor_espera__';
                $modoConsulta = true;
            }
        } elseif (
            UsuarioRol::esTransportista($user)
            && ($resumen['pendiente_confirmacion_minorista'] ?? false)
            && ! $vistaResumenCompleto
            && $pasoSolicitado === ''
        ) {
            $pasoVista = '__espera_confirmacion_minorista__';
            $modoConsulta = false;
        }

        return view('logistica.cierre-planta-mayorista.panel', [
            'ruta' => $ruta,
            'resumen' => $resumen,
            'pasoActual' => $pasoActual,
            'pasoVista' => $vistaResumenCompleto ? EnvioCierreAgricolaCatalogo::PASO_COMPLETADO : $pasoVista,
            'modoConsulta' => $modoConsulta,
            'vistaResumenCompleto' => $vistaResumenCompleto,
            'documentoEntrega' => $this->cierre->documentoEntrega($ruta),
            'pasosCompletados' => $pasosCompletados,
            'condicionesCatalogo' => CondicionTransporte::query()->orderBy('condiciontransporteid')->get(),
            'incidentesCatalogo' => TipoIncidenteTransporte::query()->orderBy('tipoincidentetransporteid')->get(),
            'volverUrl' => RutaDistribucionNavegacion::volverUrl($ruta, $user, $rutaPrefijo),
            'rutaPrefijo' => $rutaPrefijo,
            'paradasMapa' => $this->distribucion->paradasMapa($ruta),
            'esVistaMayorista' => false,
            'esVistaMinorista' => $esVistaMinorista,
            'estadoRecepcion' => $estadoRecepcion,
            'vistaMayoristaEspera' => $vistaMayoristaEspera,
            'vistaMinoristaEspera' => $vistaMinoristaEspera,
            'uiCierre' => EnvioCierreRutaUiCatalogo::mayoristaPdv($ruta),
        ]);
    }

    public function registrarCondiciones(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_if($ruta->esTrasladoPlantaMayorista(), 404);
        $this->autorizarVer($ruta);

        $validated = $request->validate([
            'perfectas_condiciones' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'condiciones' => ['nullable', 'array'],
            'condiciones.*.id' => ['required_with:condiciones', 'integer'],
            'condiciones.*.valor' => ['required_with:condiciones'],
        ]);

        $perfectas = (bool) ($validated['perfectas_condiciones'] ?? false);
        if (! $perfectas && ! empty($validated['condiciones'])) {
            $todasSi = collect($validated['condiciones'])
                ->every(fn (array $item) => filter_var($item['valor'] ?? false, FILTER_VALIDATE_BOOLEAN));
            if ($todasSi) {
                $perfectas = true;
            }
        }

        try {
            $this->cierre->registrarCondicionesVehiculo(
                $ruta,
                $request->user(),
                $perfectas,
                $validated['condiciones'] ?? null,
                $validated['observaciones'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        return $this->respuestaExito('Condiciones del vehículo registradas correctamente.');
    }

    public function confirmarLlegada(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_if($ruta->esTrasladoPlantaMayorista(), 404);

        try {
            $this->cierre->confirmarLlegada($ruta, $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        return $this->respuestaExito('Llegada al punto de venta confirmada. Registre incidentes para continuar.');
    }

    public function registrarIncidentes(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_if($ruta->esTrasladoPlantaMayorista(), 404);

        $validated = $request->validate([
            'sin_incidentes' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'incidentes' => ['nullable', 'array'],
            'incidentes.*.id' => ['required_with:incidentes', 'integer'],
            'incidentes.*.ocurrio' => ['nullable'],
        ]);

        $sinIncidentes = (bool) ($validated['sin_incidentes'] ?? false);
        if (! $sinIncidentes) {
            $incidentes = $validated['incidentes'] ?? [];
            $algunoMarcado = collect($incidentes)
                ->contains(fn (array $item) => filter_var($item['ocurrio'] ?? false, FILTER_VALIDATE_BOOLEAN));
            if (! $algunoMarcado) {
                $sinIncidentes = true;
            }
        }

        try {
            $this->cierre->registrarIncidentes(
                $ruta,
                $request->user(),
                $sinIncidentes,
                $validated['incidentes'] ?? null,
                $validated['observaciones'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        return $this->respuestaExito('Registro de incidentes guardado. Proceda con las firmas.');
    }

    public function firmaTransportista(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_if($ruta->esTrasladoPlantaMayorista(), 404);

        $validated = $request->validate(['imagen_firma' => ['required', 'string']]);

        try {
            $this->cierre->guardarFirmaTransportista($ruta, $request->user(), $validated['imagen_firma']);
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        return $this->respuestaExito('Firma del transportista registrada.');
    }

    public function firmaRecepcion(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_if($ruta->esTrasladoPlantaMayorista(), 404);

        $validated = $request->validate(['imagen_firma' => ['required', 'string']]);

        try {
            $this->cierre->guardarFirmaRecepcion($ruta, $request->user(), $validated['imagen_firma']);
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        $ruta->refresh();
        $resumen = $this->cierre->resumenPasos($ruta);
        $rutaPrefijo = $request->routeIs('logistica.rutas-distribucion.*')
            ? 'logistica.rutas-distribucion'
            : 'punto-venta.rutas';

        if (($resumen['puede_finalizar'] ?? false) && $this->recepcion->esVistaMinorista($request->user())) {
            try {
                $this->cierre->finalizarEntrega($ruta, $request->user());
            } catch (\InvalidArgumentException $e) {
                return $this->respuestaError($e->getMessage());
            }

            $pedido = $ruta->pedidos()->first();
            $redirect = $pedido
                ? route('punto-venta.pedidos.show', ['pedido' => $pedido, 'paso' => 5])
                : route($rutaPrefijo.'.show', $ruta);

            if ($request->expectsJson()) {
                return response()->json(['mensaje' => 'Recepción firmada y entrega finalizada.', 'redirect' => $redirect]);
            }

            return redirect()->to($redirect);
        }

        return $this->respuestaExito('Firma de recepción en punto de venta registrada.');
    }

    public function finalizar(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_if($ruta->esTrasladoPlantaMayorista(), 404);

        try {
            $documento = $this->cierre->finalizarEntrega($ruta, $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        $rutaPrefijo = $request->routeIs('logistica.rutas-distribucion.*')
            ? 'logistica.rutas-distribucion'
            : 'punto-venta.rutas';

        if ($request->expectsJson()) {
            return response()->json([
                'mensaje' => 'Entrega finalizada. Comprobante generado.',
                'documento_url' => route('logistica.documentos.show', $documento),
            ]);
        }

        if ($this->recepcion->esVistaMinorista($request->user())) {
            $pedido = $ruta->pedidos()->first();

            return redirect()
                ->route('punto-venta.pedidos.show', ['pedido' => $pedido ?? $ruta->pedidos()->first(), 'paso' => 5]);
        }

        return redirect()
            ->route('logistica.documentos.show', $documento);
    }

    private function autorizarVer(RutaDistribucion $ruta): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }

        // Ownership (TRA-02): conductor asignado, mayorista de origen, minorista receptor o supervisión del admin.
        if (ViajeAcceso::puedeVerRutaDistribucion($user, $ruta)) {
            return;
        }

        abort(403);
    }

    private function respuestaExito(string $mensaje): RedirectResponse|JsonResponse
    {
        if (request()->expectsJson()) {
            return response()->json(['mensaje' => $mensaje]);
        }

        return back();
    }

    private function respuestaError(string $mensaje): RedirectResponse|JsonResponse
    {
        if (request()->expectsJson()) {
            return response()->json(['mensaje' => $mensaje], 422);
        }

        return back()->with('error', $mensaje);
    }
}
