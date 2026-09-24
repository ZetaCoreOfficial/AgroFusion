<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EnvioAsignacionMultiple;
use App\Models\RutaDistribucion;
use App\Services\RecepcionQrFirmaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecepcionQrPublicaController extends Controller
{
    public function __construct(
        private readonly RecepcionQrFirmaService $recepcionQr,
    ) {}

    public function show(Request $request, string $token): View
    {
        $qr = $this->recepcionQr->resolverPorToken($token);
        $operacion = $this->recepcionQr->resolverOperacion($qr);
        $operacion->loadMissing('firmaTransportista', 'firmaRecepcion');
        $usuario = $request->user();

        // Tras iniciar sesión, el receptor vuelve a esta misma pantalla para firmar.
        if ($usuario === null) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        $titulo = 'Firma de recepción';
        $codigo = $operacion instanceof RutaDistribucion
            ? ($operacion->codigo ?? 'Ruta #'.$operacion->rutadistribucionid)
            : ($operacion->externo_envio_id ?? 'Envío #'.$operacion->envioasignacionmultipleid);

        return view('recepcion.publica', [
            'token' => $token,
            'titulo' => $titulo,
            'codigo' => $codigo,
            'yaFirmado' => $this->recepcionQr->recepcionFirmada($operacion),
            'sinFirmaTransportista' => $operacion->firmaTransportista === null,
            'requiereSesion' => $usuario === null,
            // MAY-14: el mayorista indica lo que realmente recibió por línea.
            'lineasTraslado' => $operacion instanceof RutaDistribucion && $operacion->esTrasladoPlantaMayorista()
                ? $operacion->detallesTraslado()->get()
                : collect(),
            'puedeFirmar' => $this->recepcionQr->esReceptorAutorizado($operacion, $usuario),
            'usuario' => $usuario,
        ]);
    }

    public function firmar(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'imagen_firma' => ['required', 'string'],
            'recepcion' => ['nullable', 'array'],
            'recepcion.*.recibido' => ['nullable', 'numeric', 'min:0'],
            'recepcion.*.motivo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->recepcionQr->guardarFirmaRecepcionConCuenta(
                $token,
                $request->user(),
                $validated['imagen_firma'],
                $validated['recepcion'] ?? [],
            );
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['mensaje' => $e->getMessage()], 422);
            }

            return back()->withInput()->withErrors(['firma' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['mensaje' => 'Firma de recepción registrada correctamente.']);
        }

        return redirect()->route('recepcion.publica', $token)
            ->with('exito', 'Firma de recepción registrada correctamente.');
    }
}
