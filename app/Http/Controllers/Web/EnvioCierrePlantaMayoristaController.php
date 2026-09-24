<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CondicionTransporte;
use App\Models\RutaDistribucion;
use App\Models\TipoIncidenteTransporte;
use App\Services\CierreEnvioPlantaMayoristaService;
use App\Services\DistribucionRutaService;
use App\Services\RecepcionPlantaMayoristaService;
use App\Support\EnvioCierreAgricolaCatalogo;
use App\Support\MayoristaAccess;
use App\Support\UsuarioRol;
use App\Support\ViajeAcceso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnvioCierrePlantaMayoristaController extends Controller
{
    public function __construct(
        private readonly CierreEnvioPlantaMayoristaService $cierre,
        private readonly DistribucionRutaService $distribucion,
        private readonly RecepcionPlantaMayoristaService $recepcion,
    ) {}

    public function panel(Request $request, RutaDistribucion $ruta): View
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);
        $this->autorizarVer($ruta);

        $ruta->load([
            'transportista',
            'vehiculo',
            'almacenPlantaOrigen',
            'almacenMayoristaDestino',
            'detallesTraslado',
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

        $paradasMapa = $this->distribucion->paradasMapa($ruta);
        $rutaPrefijo = $request->routeIs('almacen-mayorista.traslados-planta.*')
            ? 'almacen-mayorista.traslados-planta'
            : 'logistica.traslados-planta';
        $vistaResumenCompleto = (bool) ($resumen['recibido_planta'] ?? false);
        $user = $request->user();
        $esVistaMayorista = $this->recepcion->esVistaMayorista($user);
        $estadoRecepcion = $this->recepcion->estadoRecepcion($ruta);
        $vistaMayoristaEspera = false;

        if ($esVistaMayorista && ! $vistaResumenCompleto && $pasoSolicitado === '') {
            if ($resumen['puede_firmar_recepcion'] ?? false) {
                $pasoVista = EnvioCierreAgricolaCatalogo::PASO_FIRMA_RECEPCION;
                $modoConsulta = false;
            } elseif (! ($resumen['firma_recepcion'] ?? false)) {
                $vistaMayoristaEspera = true;
                $pasoVista = '__mayorista_espera__';
                $modoConsulta = true;
            }
        }

        return view('logistica.cierre-planta-mayorista.panel', [
            'ruta' => $ruta,
            'resumen' => $resumen,
            'pasoActual' => $pasoActual,
            'pasoVista' => $vistaResumenCompleto ? EnvioCierreAgricolaCatalogo::PASO_COMPLETADO : $pasoVista,
            'modoConsulta' => $modoConsulta,
            'vistaResumenCompleto' => $vistaResumenCompleto,
            'documentoEntrega' => app(CierreEnvioPlantaMayoristaService::class)->documentoEntrega($ruta),
            'pasosCompletados' => $pasosCompletados,
            'condicionesCatalogo' => CondicionTransporte::query()->orderBy('condiciontransporteid')->get(),
            'incidentesCatalogo' => TipoIncidenteTransporte::query()->orderBy('tipoincidentetransporteid')->get(),
            'volverUrl' => \App\Support\RutaDistribucionNavegacion::volverUrl($ruta, $user, $rutaPrefijo),
            'rutaPrefijo' => $rutaPrefijo,
            'paradasMapa' => $paradasMapa,
            'esVistaMayorista' => $esVistaMayorista,
            'estadoRecepcion' => $estadoRecepcion,
            'vistaMayoristaEspera' => $vistaMayoristaEspera,
            'uiCierre' => \App\Support\EnvioCierreRutaUiCatalogo::plantaMayorista(),
        ]);
    }

    public function registrarCondiciones(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);
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
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);

        try {
            $this->cierre->confirmarLlegada($ruta, $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        return $this->respuestaExito('Llegada al almacén mayorista confirmada. Registre incidentes para continuar.');
    }

    public function registrarIncidentes(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);

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
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);

        $validated = $request->validate([
            'imagen_firma' => ['required', 'string'],
        ]);

        try {
            $this->cierre->guardarFirmaTransportista($ruta, $request->user(), $validated['imagen_firma']);
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        return $this->respuestaExito('Firma del transportista registrada.');
    }

    public function firmaRecepcion(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);

        $validated = $request->validate([
            'imagen_firma' => ['required', 'string'],
            'recepcion' => ['nullable', 'array'],
            'recepcion.*.recibido' => ['nullable', 'numeric', 'min:0'],
            'recepcion.*.motivo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->cierre->guardarFirmaRecepcion($ruta, $request->user(), $validated['imagen_firma'], $validated['recepcion'] ?? []);
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        $ruta->refresh();
        $resumen = $this->cierre->resumenPasos($ruta);

        if (($resumen['puede_finalizar'] ?? false) && $this->recepcion->esVistaMayorista($request->user())) {
            try {
                $this->cierre->finalizarEntrega($ruta, $request->user());
            } catch (\InvalidArgumentException $e) {
                return $this->respuestaError($e->getMessage());
            }

            $rutaPrefijo = $request->routeIs('almacen-mayorista.traslados-planta.*')
                ? 'almacen-mayorista.traslados-planta'
                : 'logistica.traslados-planta';

            if ($request->expectsJson()) {
                return response()->json([
                    'mensaje' => 'Recepción firmada y traslado finalizado.',
                    'redirect' => route($rutaPrefijo.'.show', $ruta),
                ]);
            }

            return redirect()
                ->route($rutaPrefijo.'.show', $ruta);
        }

        return $this->respuestaExito('Firma de recepción mayorista registrada.');
    }

    public function finalizar(Request $request, RutaDistribucion $ruta): RedirectResponse|JsonResponse
    {
        abort_unless($ruta->esTrasladoPlantaMayorista(), 404);

        try {
            $documento = $this->cierre->finalizarEntrega($ruta, $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaError($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'mensaje' => 'Traslado finalizado. Documento de transporte generado.',
                'documento_url' => route('logistica.documentos.show', $documento),
            ]);
        }

        $rutaPrefijo = $request->routeIs('almacen-mayorista.traslados-planta.*')
            ? 'almacen-mayorista.traslados-planta'
            : 'logistica.traslados-planta';

        if ($this->recepcion->esVistaMayorista($request->user())) {
            return redirect()
                ->route($rutaPrefijo.'.show', $ruta);
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

        // Ownership (TRA-02): conductor asignado, mayorista destino, planta o supervisión del admin.
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
