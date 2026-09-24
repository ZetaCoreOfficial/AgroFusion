<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CertificacionLote;
use App\Models\EstadoLoteTipo;
use App\Models\HistorialEstadoLote;
use App\Models\Lote;
use App\Services\Blockchain\CertificacionBlockchainService;
use App\Support\CertificacionIndexService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CertificacionController extends Controller
{
    public function __construct(
        private CertificacionIndexService $indexService,
        private CertificacionBlockchainService $blockchainService,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $datos = $this->indexService->datosCampo($request->user());

        return view('certificaciones.index', [
            'lotesPendientes' => $datos['pendientes'],
            'certificados' => $datos['evaluaciones'],
            'stats' => $datos['stats'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'loteid'        => ['required', 'integer', 'exists:lote,loteid'],
            'resultado'     => ['nullable', 'string', Rule::in(CertificacionLote::RAZONES)],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'recomendaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $resultado = $validated['resultado'] ?? CertificacionLote::RAZON_CERTIFICADO;

        if ($resultado === CertificacionLote::RAZON_NO_CONFORME && blank($validated['observaciones'] ?? null)) {
            return back()
                ->withErrors(['observaciones' => 'Indique el motivo del no conforme (daños, plagas, calidad, etc.).'])
                ->withInput();
        }

        $this->evaluarLote(
            (int) $validated['loteid'],
            $resultado,
            $validated['observaciones'] ?? null,
            $validated['recomendaciones'] ?? null
        );

        $lote = Lote::findOrFail((int) $validated['loteid']);

        $returnUrl = $this->validReturnUrl($request->input('return'));
        if ($returnUrl) {
            return redirect($returnUrl);
        }

        if ($request->boolean('from_trazabilidad')) {
            return redirect()->route('lotes.trazabilidad', $lote);
        }

        return redirect()->route('certificaciones.index');
    }

    private function validReturnUrl(mixed $return): ?string
    {
        if (! is_string($return) || trim($return) === '') {
            return null;
        }

        $return = trim($return);
        if (str_starts_with($return, '/')) {
            return $return;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if (str_starts_with($return, $appUrl)) {
            return $return;
        }

        if (preg_match('#^https?://[^/]+(/lotes/\d+/trazabilidad(?:\?.*)?)$#', $return, $m)) {
            return $m[1];
        }

        return null;
    }

    public function storeBatch(Request $request): RedirectResponse
    {
        $request->validate([
            'lotes'         => ['required', 'array', 'min:1'],
            'lotes.*'       => ['integer', 'exists:lote,loteid'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $count = 0;
        foreach ($request->lotes as $loteid) {
            $this->evaluarLote((int) $loteid, CertificacionLote::RAZON_CERTIFICADO, $request->observaciones);
            $count++;
        }

        return redirect()->route('certificaciones.index');
    }

    public function show(CertificacionLote $certificacion): View
    {
        $certificacion->load([
            'lote.cultivo',
            'lote.estadoTipo',
            'lote.actorAbastecimiento',
            'lote.unidadSuperficie',
            'usuario',
        ]);

        return view('certificaciones.show', ['cert' => $certificacion]);
    }

    public function sincronizarBlockchain(CertificacionLote $certificacion): RedirectResponse
    {
        if (! $certificacion->esCertificado()) {
            return back()->with('warning', 'Solo las certificaciones conformes pueden sincronizarse con blockchain.');
        }

        if (! in_array($certificacion->blockchain_estado, [
            CertificacionLote::BLOCKCHAIN_PENDIENTE,
            CertificacionLote::BLOCKCHAIN_ERROR,
        ], true)) {
            return back()->with('info', 'Esta certificación no tiene una solicitud blockchain pendiente para sincronizar.');
        }

        $this->blockchainService->enviar((int) $certificacion->certificacionid);
        $certificacion->refresh();

        return match ($certificacion->blockchain_estado) {
            CertificacionLote::BLOCKCHAIN_CERTIFICADA => back()->with('success', 'Certificación blockchain actualizada. Tx ID recibido.'),
            CertificacionLote::BLOCKCHAIN_RECHAZADA => back()->with('warning', 'La solicitud blockchain fue rechazada.'),
            CertificacionLote::BLOCKCHAIN_ERROR => back()->with('error', 'No se pudo sincronizar con blockchain: '.$certificacion->blockchain_error),
            default => back()->with('info', 'La solicitud blockchain continúa pendiente de aprobación.'),
        };
    }

    private function evaluarLote(int $loteid, string $resultado, ?string $observaciones, ?string $recomendaciones = null): void
    {
        $user = auth()->user();
        if (! $user || ! \App\Support\UsuarioRol::gestionaCampo($user)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'loteid' => 'Solo el jefe agrícola o administrador puede certificar lotes.',
            ]);
        }

        $lote = Lote::findOrFail($loteid);
        $trazabilidad = app(\App\Support\LoteTrazabilidadService::class);

        if (! $trazabilidad->puedeCertificarCampo($lote, $user)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'loteid' => 'No puede certificar este lote: complete todas las actividades pendientes y asegúrese de haber registrado riego, control de plagas y fertilización.',
            ]);
        }

        $prefijo = $resultado === CertificacionLote::RAZON_NO_CONFORME ? 'NCONF' : 'CERT';
        $codigo = $prefijo.'-'.now()->format('Y').'-'.str_pad((string) $lote->loteid, 4, '0', STR_PAD_LEFT);

        $estadoNombre = $resultado === CertificacionLote::RAZON_CERTIFICADO
            ? 'Certificado'
            : 'No conforme';
        $estadoDescripcion = $resultado === CertificacionLote::RAZON_CERTIFICADO
            ? 'Lote validado para despacho y trazabilidad'
            : 'Lote no apto para ingreso a almacén';

        $estado = EstadoLoteTipo::firstOrCreate(
            ['nombre' => $estadoNombre],
            ['descripcion' => $estadoDescripcion]
        );

        $certificacion = CertificacionLote::updateOrCreate(
            ['loteid' => $lote->loteid],
            [
                'usuarioid'           => auth()->id(),
                'codigo_certificado'  => $codigo,
                'resultado'           => $resultado,
                'observaciones'       => $observaciones,
                'recomendaciones'     => $recomendaciones,
                'fecha_certificacion' => now(),
            ]
        );

        $lote->update(['estadolotetipoid' => $estado->estadolotetipoid]);

        $histObs = $resultado.': '.$certificacion->codigo_certificado
            .($observaciones ? ' — '.$observaciones : '');
        if ($recomendaciones && $resultado === CertificacionLote::RAZON_NO_CONFORME) {
            $histObs .= ' | Recomendaciones: '.$recomendaciones;
        }

        HistorialEstadoLote::create([
            'loteid'           => $lote->loteid,
            'estadolotetipoid' => $estado->estadolotetipoid,
            'fecha_cambio'     => now(),
            'observaciones'    => $histObs,
            'usuarioid'        => auth()->id(),
        ]);

        app(\App\Services\Blockchain\CertificacionBlockchainService::class)
            ->encolarSiCorresponde($certificacion->fresh());
    }
}
