<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Lote;
use App\Models\Usuario;
use App\Models\Cultivo;
use App\Models\Insumo;
use App\Models\EstadoLoteTipo;
use App\Models\Produccion;
use App\Models\Almacen;
use App\Models\HistorialEstadoLote;
use App\Models\ProduccionAlmacenamiento;
use App\Support\AlmacenAmbito;
use App\Support\CertificacionCampoService;
use App\Services\AlmacenCapacidadService;
use App\Support\CultivoSiembraCatalogo;
use App\Support\EstadoLoteCatalogo;
use App\Support\InsumoCatalogo;
use App\Support\LoteAgricolaNombre;
use App\Support\LoteCultivoResolver;
use App\Support\LoteAcceso;
use App\Support\LoteKpiStats;
use App\Support\LoteDefaults;
use App\Services\PlanificacionCosechaService;
use App\Services\LoteEliminacionService;
use App\Services\LoteUbicacionTerrestreService;
use Illuminate\Http\JsonResponse;
use App\Support\SuperficieFormato;
use App\Support\UbicacionGpsParser;
use App\Support\LoteEstadoPorActividad;
use App\Support\LoteTrazabilidadService;
use App\Services\LoteSiembraService;
use App\Services\NotificacionUsuarioService;
use App\Support\UsuarioRol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
// use App\Services\SupabaseStorage; // COMENTADO TEMPORALMENTE

class LoteController extends Controller
{
    /** Roles que pueden ser responsables de un lote (nunca admin). */
    private const ROLES_RESPONSABLE_LOTE = ['agricultor', 'jefe_agricultor'];

    public function __construct(
        private LoteTrazabilidadService $trazabilidadService,
        private LoteEstadoPorActividad $loteEstadoPorActividad,
        private NotificacionUsuarioService $notificaciones,
        private AlmacenCapacidadService $capacidadService,
        private CertificacionCampoService $certificacionCampo,
    ) {}

    public function index(Request $request)
    {
        $query = Lote::query()
            ->with(['usuario', 'cultivo', 'insumoSemilla', 'estadoTipo']);

        $this->aplicarScopeLotesVisibles($query, $request->user());

        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->q).'%';
            $query->where(function ($q) use ($term) {
                $q->where('nombre', 'like', $term)
                    ->orWhere('ubicacion', 'like', $term)
                    ->orWhere('codigo_trazabilidad', 'like', $term);
            });
        }

        if ($request->filled('insumosemillaid')) {
            $query->where('insumosemillaid', (int) $request->insumosemillaid);
        } elseif ($request->filled('cultivoid')) {
            $query->where('cultivoid', (int) $request->cultivoid);
        }

        if ($request->filled('estadolotetipoid')) {
            $query->where('estadolotetipoid', (int) $request->estadolotetipoid);
        }

        if ($request->filled('usuarioid')) {
            $query->where('usuarioid', (int) $request->usuarioid);
        }

        $statsBase = Lote::query();
        $this->aplicarScopeLotesVisibles($statsBase, $request->user());
        $stats = LoteKpiStats::desdeQuery($statsBase);
        $stats['planificados'] = (clone $statsBase)->whereHas('estadoTipo', fn ($q) => $q->whereRaw('LOWER(TRIM(nombre)) = ?', ['planificado']))->count();
        $stats['sembrados'] = (clone $statsBase)->whereHas('estadoTipo', fn ($q) => $q->whereRaw('LOWER(TRIM(nombre)) = ?', ['sembrado']))->count();

        $lotes = $query->orderByDesc('loteid')->paginate(15)->withQueryString();

        $filtros = $request->only(['q', 'insumosemillaid', 'cultivoid', 'estadolotetipoid', 'usuarioid']);
        $estados = EstadoLoteCatalogo::paraSelect();
        $usuarios = Usuario::query()
            ->where('activo', true)
            ->whereIn('role', ['agricultor', 'admin'])
            ->orderBy('nombre')
            ->get();

        return view('lotes.index', compact('lotes', 'stats', 'filtros', 'estados', 'usuarios'));
    }

    /**
     * Mapa interactivo de lotes visibles para el usuario
     */
    public function mapa(Request $request)
    {
        $baseQuery = Lote::query();
        $this->aplicarScopeLotesVisibles($baseQuery, $request->user());

        $stats = LoteKpiStats::desdeQuery($baseQuery, incluirEnMapa: true);

        $lotesConCoordenadas = (clone $baseQuery)
            ->with(['usuario', 'cultivo', 'insumoSemilla', 'estadoTipo'])
            ->whereNotNull('latitud')
            ->whereNotNull('longitud')
            ->get()
            ->map(function ($lote) {
                return [
                    'id' => $lote->loteid,
                    'nombre' => $lote->nombre,
                    'latitud' => (float) $lote->latitud,
                    'longitud' => (float) $lote->longitud,
                    'superficie' => (float) $lote->superficie,
                    'superficie_etiqueta' => SuperficieFormato::etiqueta($lote->superficie),
                    'ubicacion' => $lote->ubicacion_visible,
                    'ubicacion_visible' => $lote->ubicacion_visible,
                    'propietario' => ($lote->usuario->nombre ?? '') . ' ' . ($lote->usuario->apellido ?? ''),
                    'cultivo' => $lote->cultivo_etiqueta,
                    'estado' => $lote->estadoTipo->nombre ?? 'Planificación',
                    'estado_slug' => EstadoLoteCatalogo::slugFromNombre($lote->estadoTipo->nombre ?? null) ?? 'planificado',
                    'estado_color' => EstadoLoteCatalogo::colorMapaPorSlug(
                        EstadoLoteCatalogo::slugFromNombre($lote->estadoTipo->nombre ?? null) ?? 'planificado'
                    ),
                    'usuarioid' => $lote->usuarioid,
                    'cultivoid' => $lote->cultivoid,
                    'insumosemillaid' => $lote->insumosemillaid,
                    'estadoid' => $lote->estadolotetipoid,
                    'codigo_trazabilidad' => $lote->codigo_trazabilidad,
                ];
            });

        $topLotes = (clone $baseQuery)
            ->with(['usuario', 'cultivo'])
            ->select('lote.*')
            ->selectSub(
                Produccion::selectRaw('COALESCE(SUM(cantidad), 0)')
                    ->whereColumn('produccion.loteid', 'lote.loteid'),
                'total_produccion'
            )
            ->orderByDesc('total_produccion')
            ->limit(5)
            ->get();

        $estados = EstadoLoteCatalogo::paraSelect();

        return view('lotes.mapa', compact(
            'stats',
            'lotesConCoordenadas',
            'topLotes',
            'estados'
        ) + [
            'leyendaMapa' => EstadoLoteCatalogo::leyendaMapa(),
            'coloresEstadoMapa' => EstadoLoteCatalogo::coloresMapa(),
            'slugEstadoPorNombre' => EstadoLoteCatalogo::mapaSlugPorNombreJs(),
        ]);
    }

    public function create()
    {
        $user = auth()->user();
        $esJefeSolo = $user && UsuarioRol::esJefeAgricultor($user) && ! UsuarioRol::esAdminGlobal($user);
        $mostrarSelectorPropietario = $this->puedeDesignarResponsableLote($user) && ! $esJefeSolo;
        $responsableSelectorParams = $this->paramsSelectorResponsable($user);
        $esJefeAgricultorDesignando = $esJefeSolo;

        $propietarioPorDefecto = $this->responsableLotePorDefecto($user);
        $usuarioidInicial = (int) old('usuarioid', $propietarioPorDefecto ?? 0);
        $responsableLabel = null;
        if ($mostrarSelectorPropietario && $usuarioidInicial && $this->puedeAsignarUsuarioALote($user, $usuarioidInicial)) {
            $resp = Usuario::find($usuarioidInicial);
            $responsableLabel = $resp ? trim($resp->nombre.' '.($resp->apellido ?? '')) : null;
        } elseif ($mostrarSelectorPropietario) {
            $usuarioidInicial = 0;
        }

        $insumoSemillaId = old('insumosemillaid', request()->query('insumosemillaid'));
        $insumoSemillaLabel = null;
        $dosisInicial = null;
        $semillaStockInicial = null;
        if ($insumoSemillaId) {
            $insumoSemilla = Insumo::query()->with(['unidadMedida', 'tipo'])->find($insumoSemillaId);
            $insumoSemillaLabel = $insumoSemilla?->nombre;
            $semillaStockInicial = $this->metaStockSemilla($insumoSemilla);
            if ($insumoSemilla && (float) old('superficie', 0) > 0) {
                $dosisInicial = CultivoSiembraCatalogo::sugerenciaParaInsumo(
                    $insumoSemilla,
                    (float) old('superficie')
                );
            } elseif ($insumoSemilla) {
                $dosisInicial = CultivoSiembraCatalogo::sugerenciaParaInsumo($insumoSemilla, 1.0);
                $dosisInicial['superficie_ha'] = 0;
            }
        }

        return view('lotes.create', compact(
            'mostrarSelectorPropietario',
            'propietarioPorDefecto',
            'usuarioidInicial',
            'responsableLabel',
            'insumoSemillaId',
            'insumoSemillaLabel',
            'dosisInicial',
            'semillaStockInicial',
            'responsableSelectorParams',
            'esJefeAgricultorDesignando'
        ));
    }

    public function siguienteNombre(Request $request): JsonResponse
    {
        $producto = LoteAgricolaNombre::normalizarProducto((string) $request->query('producto', ''));
        if ($producto === '') {
            $insumoId = (int) $request->query('insumoid', 0);
            if ($insumoId > 0) {
                $producto = LoteAgricolaNombre::productoDesdeInsumo($insumoId) ?? '';
            }
        }

        if ($producto === '') {
            return response()->json(['nombre' => '', 'numero' => 1]);
        }

        return response()->json([
            'nombre' => LoteAgricolaNombre::siguienteNombre($producto),
            'numero' => LoteAgricolaNombre::siguienteNumero($producto),
        ]);
    }

    public function planificarCosecha(Request $request, PlanificacionCosechaService $planificacion): JsonResponse
    {
        $insumoId = (int) $request->query('insumoid', 0);
        if ($insumoId <= 0) {
            return response()->json(['ok' => false, 'mensaje' => 'Seleccione una semilla / cultivo.']);
        }

        if ($request->boolean('solo_contexto')) {
            return response()->json($planificacion->contexto($insumoId));
        }

        return response()->json($planificacion->calcular([
            'modo' => (string) $request->query('modo', PlanificacionCosechaService::MODO_HECTAREAS),
            'insumoid' => $insumoId,
            'calibre_id' => $request->query('calibre_id') ? (int) $request->query('calibre_id') : null,
            'hectareas' => $request->query('hectareas'),
            'objetivo_unidades' => $request->query('objetivo_unidades'),
            'objetivo_empaques' => $request->query('objetivo_empaques'),
        ]));
    }

    public function validarUbicacion(Request $request, LoteUbicacionTerrestreService $ubicacion): JsonResponse
    {
        $data = $request->validate([
            'latitud' => 'required|numeric|between:-90,90',
            'longitud' => 'required|numeric|between:-180,180',
            'superficie' => 'nullable|numeric|min:0',
        ]);

        $lat = (float) $data['latitud'];
        $lng = (float) $data['longitud'];
        $ha = (float) ($data['superficie'] ?? 0);

        if ($ha > 0) {
            return response()->json($ubicacion->validarSuperficie($lat, $lng, $ha, ligera: true));
        }

        return response()->json($ubicacion->validarMarcador($lat, $lng));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuarioid' => ['nullable', 'exists:usuario,usuarioid', $this->reglaResponsableLote($request->user())],
            'nombre' => 'required|string|max:100',
            'ubicacion' => 'nullable|string|max:200',
            'superficie' => 'required|numeric|min:0.01',
            'insumosemillaid' => 'required|exists:insumo,insumoid',
            'cantidad_semilla_planificada' => 'nullable|numeric|min:0',
            'catalogotamanoconteoid' => 'nullable|exists:catalogo_tamano_conteo,catalogotamanoconteoid',
            'plan_modo' => 'nullable|string|in:hectareas,unidades,empaques',
            'plan_objetivo_empaques' => 'nullable|numeric|min:0',
            'plan_objetivo_unidades' => 'nullable|numeric|min:0',
            'latitud' => 'nullable|numeric|between:-90,90',
            'longitud' => 'nullable|numeric|between:-180,180',
            'imagen' => 'nullable|image|max:2048',
        ], [
            'insumosemillaid.required' => 'Seleccione la semilla o cultivo a cosechar.',
        ]);

        $data['usuarioid'] = $this->resolverUsuarioidLote($request, $data['usuarioid'] ?? null);
        $data['insumosemillaid'] = $this->resolverInsumoSemilla($data['insumosemillaid'] ?? null);
        $data['cultivoid'] = LoteCultivoResolver::resolver($data['insumosemillaid']);
        if (empty(trim($data['nombre'])) && $data['insumosemillaid']) {
            $producto = LoteAgricolaNombre::productoDesdeInsumo($data['insumosemillaid']);
            if ($producto) {
                $data['nombre'] = LoteAgricolaNombre::siguienteNombre($producto);
            }
        }
        $data['cantidad_semilla_planificada'] = $this->resolverCantidadSemillaPlanificada(
            $data['insumosemillaid'],
            (float) $data['superficie'],
            $data['cantidad_semilla_planificada'] ?? null
        );

        $this->validarPlanificacionAlGuardar($request, $data);

        $this->validarUbicacionTerrestreAlGuardar($data);

        $data['ubicacion'] = UbicacionGpsParser::normalizarUbicacionLote(
            $data['ubicacion'] ?? null,
            isset($data['latitud']) ? (float) $data['latitud'] : null,
            isset($data['longitud']) ? (float) $data['longitud'] : null
        );

        // SUPABASE UPLOAD
        if ($request->hasFile('imagen')) {
            try {
                $file = $request->file('imagen');
                $filename = 'lote_' . uniqid() . '.' . $file->getClientOriginalExtension();

                // Instanciar servicio (no inyección de dependencias para no romper constructor existente si no es necesario)
                $supabase = new \App\Services\SupabaseStorage();
                $response = $supabase->upload($filename, file_get_contents($file), $file->getMimeType());

                if ($response->successful()) {
                    $data['imagenurl'] = $supabase->getPublicUrl($filename);
                }
            } catch (\Exception $e) {
                // Silently fail or log error, don't stop creation
                \Illuminate\Support\Facades\Log::error('Supabase upload error: ' . $e->getMessage());
            }
        }

        unset($data['imagen']);

        $data = LoteDefaults::enrich($data, true);
        $lote = Lote::create($data);
        LoteDefaults::registrarHistorialInicial($lote);
        $this->asignarSiembraInicialSiCorresponde($lote, (int) $request->user()->usuarioid);
        $this->notificaciones->loteAsignado($lote);

        return redirect()
            ->route('lotes.index')
            ->with('lote_creado_modal', [
                'nombre' => $lote->nombre,
                'trazabilidad_url' => route('lotes.trazabilidad', $lote, absolute: false),
            ]);
    }


    public function show(Lote $lote, Request $request)
    {
        $this->autorizarLoteVisible($request, $lote);

        return view('lotes.show', array_merge(
            $this->trazabilidadService->buildLoteDetalleBase($lote),
            ['solo_lectura_lote' => LoteAcceso::soloLectura($request->user(), $lote)],
        ));
    }

    public function cambiarEstadoForm(Lote $lote, Request $request)
    {
        $slug = (string) $request->query('estado', '');
        if (! isset(EstadoLoteCatalogo::ESTADOS[$slug])) {
            abort(404);
        }

        if ($slug === 'sembrado') {
            return redirect(EstadoLoteCatalogo::urlCambioEstado($lote, 'sembrado'));
        }

        return view('lotes.cambiar-estado', [
            'lote' => $lote,
            'slug' => $slug,
        ]);
    }

    public function cambiarEstadoStore(Request $request, Lote $lote)
    {
        $data = $request->validate([
            'estado' => 'required|string',
            'motivo' => 'required|string|max:500',
        ]);

        $slug = $data['estado'];
        if (! isset(EstadoLoteCatalogo::ESTADOS[$slug]) || $slug === 'sembrado') {
            abort(422);
        }

        $estadoAplicado = $this->loteEstadoPorActividad->aplicarCambioManual(
            $lote,
            $slug,
            $data['motivo'],
            $request->user()
        );

        if (! $estadoAplicado) {
            return back()->with('warning', 'El lote ya se encuentra en ese estado.');
        }

        return redirect()
            ->route('lotes.trazabilidad', $lote)
            ->with('success', "Estado actualizado a «{$estadoAplicado}».")
            ->withFragment('historial-eventos');
    }

    public function trazabilidad(Lote $lote, Request $request)
    {
        $this->autorizarLoteVisible($request, $lote);

        $user = $request->user();
        $lote->loadMissing('usuario');
        $data = $this->trazabilidadService->dashboardLote($lote, $request);

        if ($data['puede_enviar_almacen'] ?? false) {
            $data = array_merge($data, $this->trazabilidadService->datosFormularioEnvioAlmacen($lote, $user));
        }

        if (LoteAcceso::soloLectura($user, $lote)) {
            $data['puede_asignar_siembra'] = false;
            $data['puede_completar_siembra_directa'] = false;
            $data['url_completar_siembra'] = null;
            $data['url_asignar_actividad'] = null;
        }

        $puedeDesignar = $this->puedeDesignarResponsableLote($user);
        $responsableInicial = old('usuarioid');
        $responsableLabel = '';

        if ($responsableInicial) {
            $u = Usuario::find($responsableInicial);
            $responsableLabel = $u ? trim($u->nombre.' '.($u->apellido ?? '')) : '';
        } elseif ($lote->usuarioid && $this->puedeAsignarUsuarioALote($user, (int) $lote->usuarioid)) {
            $responsableInicial = $lote->usuarioid;
            $responsableLabel = trim($lote->usuario->nombre.' '.($lote->usuario->apellido ?? ''));
        } elseif (! $puedeDesignar && $user) {
            $responsableInicial = $user->usuarioid;
            $responsableLabel = trim($user->nombre.' '.($user->apellido ?? ''));
        }

        return view('lotes.trazabilidad', array_merge($data, [
            'puede_designar_responsable_siembra' => $puedeDesignar,
            'responsable_siembra_params' => $this->paramsSelectorResponsable($user),
            'responsable_siembra_inicial' => $responsableInicial,
            'responsable_siembra_label' => $responsableLabel,
            'solo_lectura_lote' => LoteAcceso::soloLectura($user, $lote),
            'puede_ver_almacen_historial' => AlmacenAmbito::puedeVerAlmacenDesdeHistorial($user),
        ]));
    }

    public function enviarAlmacen(Request $request, Lote $lote)
    {
        $user = $request->user();
        if (! $user || ! UsuarioRol::gestionaCampo($user)) {
            abort(403, 'Solo el jefe agrícola o administrador puede enviar la cosecha al almacén.');
        }

        $this->autorizarLoteGestion($request, $lote);

        if (! $this->trazabilidadService->puedeEnviarAlmacenCampo($lote)) {
            $bloqueo = $this->certificacionCampo->mensajeBloqueoAlmacen($lote);

            return redirect()
                ->route('lotes.trazabilidad', $lote)
                ->with('error', $bloqueo ?? 'No puede enviar este lote al almacén en este momento.');
        }

        $data = $request->validate([
            'produccionid' => 'required|exists:produccion,produccionid',
            'almacenid' => 'required|exists:almacen,almacenid',
        ], [
            'almacenid.required' => 'Seleccione un almacén agrícola de destino.',
        ]);

        $produccion = Produccion::query()
            ->with(['almacenamientos', 'unidadMedida', 'lote.cultivo'])
            ->where('loteid', $lote->loteid)
            ->findOrFail((int) $data['produccionid']);

        if ($produccion->almacenamientos->isNotEmpty()) {
            return redirect()
                ->route('lotes.trazabilidad', $lote)
                ->with('warning', 'Esta cosecha ya fue enviada al almacén.');
        }

        DB::beginTransaction();

        try {
            $almacen = AlmacenAmbito::scope(Almacen::query(), AlmacenAmbito::AGRICOLA)
                ->where('almacenid', $data['almacenid'])
                ->firstOrFail();

            if (! AlmacenAmbito::puedeUsarAlmacenEnvioCampo($almacen, $lote, $user)) {
                throw ValidationException::withMessages([
                    'almacenid' => 'No puede enviar la cosecha a ese almacén agrícola.',
                ]);
            }

            $cantidadBaseKg = (float) ($produccion->cantidad_base ?? $this->capacidadService->convertirAKg(
                (float) $produccion->cantidad,
                $produccion->unidadMedida
            ));

            $resumenAlmacen = $this->capacidadService->resumen($almacen);
            $disponibleKg = $resumenAlmacen['disponible_kg'];

            if ($cantidadBaseKg > $disponibleKg) {
                throw ValidationException::withMessages([
                    'almacenid' => 'La cantidad a almacenar excede la capacidad disponible. Disponible: '.
                        round($disponibleKg, 2).' kg',
                ]);
            }

            ProduccionAlmacenamiento::create([
                'produccionid' => $produccion->produccionid,
                'almacenid' => $almacen->almacenid,
                'cantidad' => $produccion->cantidad,
                'unidadmedidaid' => $produccion->unidadmedidaid,
                'catalogotamanoconteoid' => $produccion->catalogotamanoconteoid,
                'cantidad_unidades' => $produccion->cantidad_unidades,
                'cantidad_empaques' => $produccion->cantidad_empaques,
                'fechaentrada' => now(),
                'observaciones' => "Cosecha del lote {$lote->nombre}",
            ]);

            $produccion->update(['almacendestinoid' => null]);

            $this->loteEstadoPorActividad->aplicarEstado(
                $lote->fresh(),
                'Finalizado',
                'Cosecha ingresada al almacén agrícola.',
                auth()->id()
            );

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('lotes.trazabilidad', $lote)
                ->with('error', $e->getMessage())
                ->withInput();
        }

        $unidad = $produccion->unidadMedida?->abreviatura ?? 'kg';
        $mensaje = "Cosecha almacenada en {$almacen->nombre}: {$produccion->cantidad} {$unidad}.";

        return redirect()
            ->route('lotes.trazabilidad', $lote)
            ->with('success', $mensaje)
            ->withFragment('historial-eventos');
    }

    public function ubicacion(Lote $lote)
    {
        return view('lotes.ubicacion', $this->trazabilidadService->buildLoteDetalleBase($lote));
    }

    public function edit(Lote $lote, Request $request)
    {
        $user = $request->user();
        $this->autorizarLoteGestion($request, $lote);

        $lote->load(['usuario', 'cultivo', 'insumoSemilla', 'estadoTipo']);

        if (EstadoLoteCatalogo::loteEsCerrado($lote->estadoTipo?->nombre)) {
            return redirect()
                ->route('lotes.trazabilidad', $lote)
                ->with('info', 'Este lote ya fue cosechado y no puede editarse.');
        }

        $puedeDesignarResponsable = $this->puedeDesignarResponsableLote($user);
        $responsableSelectorParams = $this->paramsSelectorResponsable($user);
        $esJefeAgricultorDesignando = $user && UsuarioRol::esJefeAgricultor($user) && ! UsuarioRol::esAdminGlobal($user);

        $responsableLabel = ($lote->usuario && $this->puedeSerResponsableDeLote((int) $lote->usuarioid))
            ? trim($lote->usuario->nombre.' '.($lote->usuario->apellido ?? ''))
            : null;
        $insumoSemillaLabel = $lote->insumoSemilla?->nombre;
        $semillaStockInicial = $this->metaStockSemilla($lote->insumoSemilla);
        $dosisInicial = null;
        if ($lote->insumoSemilla && (float) $lote->superficie > 0) {
            $dosisInicial = CultivoSiembraCatalogo::sugerenciaParaInsumo(
                $lote->insumoSemilla,
                (float) $lote->superficie
            );
        }
        $mostrarFechaSiembra = (bool) $lote->fechasiembra;

        return view('lotes.edit', compact(
            'lote',
            'responsableLabel',
            'insumoSemillaLabel',
            'dosisInicial',
            'semillaStockInicial',
            'mostrarFechaSiembra',
            'puedeDesignarResponsable',
            'responsableSelectorParams',
            'esJefeAgricultorDesignando'
        ));
    }

    public function update(Request $request, Lote $lote)
    {
        $this->autorizarLoteGestion($request, $lote);
        $lote->loadMissing('estadoTipo');

        if (EstadoLoteCatalogo::loteEsCerrado($lote->estadoTipo?->nombre)) {
            return redirect()
                ->route('lotes.trazabilidad', $lote)
                ->with('info', 'Este lote ya fue cosechado y no puede modificarse.');
        }

        $data = $request->validate([
            'usuarioid' => ['required', 'exists:usuario,usuarioid', $this->reglaResponsableLote($request->user())],
            'nombre' => 'required|string|max:100',
            'ubicacion' => 'nullable|string|max:200',
            'superficie' => 'required|numeric|min:0',
            'insumosemillaid' => 'nullable|exists:insumo,insumoid',
            'cantidad_semilla_planificada' => 'nullable|numeric|min:0',
            'latitud' => 'nullable|numeric|between:-90,90',
            'longitud' => 'nullable|numeric|between:-180,180',
        ]);

        $anteriorUsuarioid = (int) $lote->usuarioid;
        $data['insumosemillaid'] = $this->resolverInsumoSemilla($data['insumosemillaid'] ?? null);
        $data['cultivoid'] = LoteCultivoResolver::resolver($data['insumosemillaid']);
        $data['cantidad_semilla_planificada'] = $this->resolverCantidadSemillaPlanificada(
            $data['insumosemillaid'],
            (float) $data['superficie'],
            $data['cantidad_semilla_planificada'] ?? null
        );

        $this->validarPlanificacionAlGuardar($request, $data);

        $this->validarUbicacionTerrestreAlGuardar($data);

        $data['ubicacion'] = UbicacionGpsParser::normalizarUbicacionLote(
            $data['ubicacion'] ?? null,
            isset($data['latitud']) ? (float) $data['latitud'] : null,
            isset($data['longitud']) ? (float) $data['longitud'] : null,
            $lote->loteid
        );
        $lote->update(LoteDefaults::enrich($data, false));
        $lote->refresh();
        $this->asignarSiembraInicialSiCorresponde($lote, (int) $request->user()->usuarioid);
        $this->notificaciones->loteAsignado($lote, $anteriorUsuarioid);

        return redirect()->route('lotes.index')->with('success', 'Lote actualizado.');
    }

    public function destroy(Lote $lote, LoteEliminacionService $eliminacion)
    {
        $lote->loadMissing('estadoTipo');

        if (! EstadoLoteCatalogo::loteSePuedeEliminar($lote->estadoTipo?->nombre)) {
            return redirect()->route('lotes.index')
                ->with('error_modal', true)
                ->with('error_modal_titulo', 'No se puede eliminar')
                ->with('error', 'Solo se pueden eliminar lotes en estado Planificado.');
        }

        try {
            $eliminacion->eliminar($lote);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('lotes.index')
                ->with('error_modal', true)
                ->with('error_modal_titulo', 'No se puede eliminar')
                ->with('error', $e->getMessage());
        }

        return redirect()->route('lotes.index')->with('success', 'Lote eliminado.');
    }

    private function reglaResponsableLote(?Usuario $actor = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($actor): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! $this->puedeAsignarUsuarioALote($actor ?? auth()->user(), (int) $value)) {
                $fail(UsuarioRol::esJefeAgricultor($actor ?? auth()->user())
                    ? 'Debe elegir un agricultor de su equipo.'
                    : 'El administrador no puede ser responsable de un lote. Elija un agricultor.');
            }
        };
    }

    private function puedeSerResponsableDeLote(int $usuarioid): bool
    {
        $usuario = Usuario::find($usuarioid);
        if (! $usuario || ! $usuario->activo) {
            return false;
        }

        if ($this->usuarioEsAdmin($usuario)) {
            return false;
        }

        if ($usuario->hasRole('agricultor') || $usuario->hasRole('jefe_agricultor')) {
            return true;
        }

        return in_array(strtolower((string) ($usuario->role ?? '')), self::ROLES_RESPONSABLE_LOTE, true);
    }

    private function usuarioEsAdmin(Usuario $usuario): bool
    {
        return UsuarioRol::esAdminGlobal($usuario);
    }

    private function aplicarScopeLotesVisibles($query, ?Usuario $user): void
    {
        LoteAcceso::aplicarScopeVisibles($query, $user);
    }

    private function autorizarLoteVisible(Request $request, Lote $lote): void
    {
        if (! LoteAcceso::puedeVer($request->user(), $lote)) {
            LoteAcceso::denegarConModal($request);
        }
    }

    private function autorizarLoteGestion(Request $request, Lote $lote): void
    {
        if (! LoteAcceso::puedeGestionar($request->user(), $lote)) {
            LoteAcceso::denegarConModal(
                $request,
                'No tienes permiso para modificar este lote. Solo puedes consultarlo si participaste en él.'
            );
        }
    }

    private function puedeDesignarResponsableLote(?Usuario $user): bool
    {
        return UsuarioRol::gestionaCampo($user);
    }

    /** @return array<string, mixed> */
    private function paramsSelectorResponsable(?Usuario $user): array
    {
        $params = ['roles' => 'agricultor'];

        if ($user && UsuarioRol::esJefeAgricultor($user) && ! UsuarioRol::esAdminGlobal($user)) {
            $params['supervisor_usuarioid'] = $user->usuarioid;
        }

        return $params;
    }

    private function puedeAsignarUsuarioALote(?Usuario $actor, int $usuarioid): bool
    {
        if (! $this->puedeSerResponsableDeLote($usuarioid)) {
            return false;
        }

        // El admin supervisa: no crea lotes ni asigna responsables.
        if (! UsuarioRol::puedeOperar($actor)) {
            return false;
        }

        if (UsuarioRol::esJefeAgricultor($actor)) {
            if ((int) $usuarioid === (int) $actor->usuarioid) {
                return true;
            }

            $empleado = Usuario::find($usuarioid);

            return $empleado
                && (int) $empleado->supervisor_usuarioid === (int) $actor->usuarioid;
        }

        return (int) $usuarioid === (int) $actor->usuarioid;
    }

    private function responsableLotePorDefecto(?Usuario $user): ?int
    {
        if (! $user || $this->usuarioEsAdmin($user)) {
            return null;
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            return (int) $user->usuarioid;
        }

        $role = strtolower((string) ($user->role ?? ''));
        if (in_array($role, self::ROLES_RESPONSABLE_LOTE, true)) {
            return (int) $user->usuarioid;
        }

        return null;
    }

    private function resolverUsuarioidLote(Request $request, mixed $usuarioid): int
    {
        $auth = $request->user();

        if ($usuarioid && $this->puedeAsignarUsuarioALote($auth, (int) $usuarioid)) {
            return (int) $usuarioid;
        }

        if ($auth && ! $this->usuarioEsAdmin($auth)) {
            $defecto = $this->responsableLotePorDefecto($auth);
            if ($defecto) {
                return $defecto;
            }
        }

        throw ValidationException::withMessages([
            'usuarioid' => 'Debe asignar un agricultor como responsable del lote. El administrador solo supervisa.',
        ]);
    }

    private function resolverInsumoSemilla(mixed $insumoid): ?int
    {
        if (blank($insumoid)) {
            return null;
        }

        $insumo = Insumo::query()->with('tipo')->find((int) $insumoid);
        if ($insumo === null || ! InsumoCatalogo::esInsumoOperativo($insumo)) {
            throw ValidationException::withMessages([
                'insumosemillaid' => 'Seleccione una semilla válida del inventario de insumos.',
            ]);
        }

        if (InsumoCatalogo::slugFromNombreTipo($insumo->tipo?->nombre) !== 'material_siembra') {
            throw ValidationException::withMessages([
                'insumosemillaid' => 'Solo puede asignar insumos de tipo material de siembra.',
            ]);
        }

        return (int) $insumo->insumoid;
    }

    private function resolverCantidadSemillaPlanificada(?int $insumoSemillaId, float $superficieHa, mixed $cantidad): ?float
    {
        if (! $insumoSemillaId) {
            return null;
        }

        if ($cantidad !== null && $cantidad !== '') {
            return round((float) $cantidad, 3);
        }

        $insumo = Insumo::query()->find($insumoSemillaId);
        if ($insumo === null) {
            return null;
        }

        $sugerencia = CultivoSiembraCatalogo::sugerenciaParaInsumo($insumo, $superficieHa);

        return $sugerencia['tiene_dosis'] ? (float) $sugerencia['sugerido'] : null;
    }

    private function validarPlanificacionAlGuardar(Request $request, array &$data): void
    {
        $insumoId = (int) ($data['insumosemillaid'] ?? 0);
        if ($insumoId <= 0) {
            return;
        }

        $calibreId = ! empty($data['catalogotamanoconteoid']) ? (int) $data['catalogotamanoconteoid'] : null;
        $modo = (string) $request->input('plan_modo', PlanificacionCosechaService::MODO_HECTAREAS);

        if ($calibreId && in_array($modo, [
            PlanificacionCosechaService::MODO_HECTAREAS,
            PlanificacionCosechaService::MODO_UNIDADES,
            PlanificacionCosechaService::MODO_EMPAQUES,
        ], true)) {
            $calcInput = [
                'insumoid' => $insumoId,
                'calibre_id' => $calibreId,
                'modo' => $modo,
            ];

            if ($modo === PlanificacionCosechaService::MODO_EMPAQUES) {
                $objetivo = $request->input('plan_objetivo_empaques');
                if ($objetivo === null || $objetivo === '') {
                    throw ValidationException::withMessages([
                        'superficie' => 'Indique cuántas cajas desea obtener en la planificación.',
                    ]);
                }
                $calcInput['objetivo_empaques'] = $objetivo;
            } elseif ($modo === PlanificacionCosechaService::MODO_UNIDADES) {
                $objetivo = $request->input('plan_objetivo_unidades');
                if ($objetivo === null || $objetivo === '') {
                    throw ValidationException::withMessages([
                        'superficie' => 'Indique cuántas unidades desea cosechar en la planificación.',
                    ]);
                }
                $calcInput['objetivo_unidades'] = $objetivo;
            } else {
                $calcInput['hectareas'] = $data['superficie'];
            }

            $resultado = app(PlanificacionCosechaService::class)->calcular($calcInput);
            if (! ($resultado['ok'] ?? false)) {
                throw ValidationException::withMessages([
                    'superficie' => $resultado['mensaje'] ?? 'No se puede guardar con esta planificación.',
                    'cantidad_semilla_planificada' => $resultado['mensaje'] ?? 'No se puede guardar con esta planificación.',
                ]);
            }

            $data['superficie'] = $resultado['hectareas'];
            $data['cantidad_semilla_planificada'] = $resultado['semilla_cantidad'];

            return;
        }

        $this->validarStockPlanificacionSemilla(
            $insumoId,
            (float) $data['superficie'],
            $data['cantidad_semilla_planificada'] ?? null
        );
    }

    /** @param  array<string, mixed>  $data */
    private function validarUbicacionTerrestreAlGuardar(array $data): void
    {
        if (! isset($data['latitud'], $data['longitud'])) {
            return;
        }

        $lat = (float) $data['latitud'];
        $lng = (float) $data['longitud'];
        $ha = (float) ($data['superficie'] ?? 0);

        if ($ha <= 0) {
            return;
        }

        $resultado = app(LoteUbicacionTerrestreService::class)->validarMarcador($lat, $lng);
        if ($resultado['ok'] ?? false) {
            return;
        }

        throw ValidationException::withMessages([
            'latitud' => $resultado['mensaje'] ?? 'La ubicación del lote no es válida.',
            'superficie' => $resultado['mensaje'] ?? 'La ubicación del lote no es válida.',
        ]);
    }

    private function validarStockPlanificacionSemilla(?int $insumoSemillaId, float $superficieHa, ?float $cantidadPlanificada): void
    {
        if (! $insumoSemillaId) {
            return;
        }

        $insumo = Insumo::query()->with('unidadMedida')->find($insumoSemillaId);
        if ($insumo === null) {
            return;
        }

        $cantidad = $cantidadPlanificada;
        if ($cantidad === null || $cantidad <= 0) {
            $sugerencia = CultivoSiembraCatalogo::sugerenciaParaInsumo($insumo, $superficieHa);
            $cantidad = $sugerencia['tiene_dosis'] ? (float) $sugerencia['sugerido'] : null;
        }

        if ($cantidad === null) {
            return;
        }

        $mensaje = CultivoSiembraCatalogo::mensajeStockInsuficiente($insumo, $cantidad);
        if ($mensaje !== null) {
            throw ValidationException::withMessages([
                'cantidad_semilla_planificada' => $mensaje,
                'superficie' => $mensaje,
            ]);
        }
    }

    /**
     * @return array{stock: float, unidad: string, sin_stock: bool}|null
     */
    private function metaStockSemilla(?Insumo $insumo): ?array
    {
        if ($insumo === null) {
            return null;
        }

        $insumo->loadMissing('unidadMedida', 'tipo');
        $stock = (float) ($insumo->stock ?? 0);

        return [
            'stock' => $stock,
            'unidad' => $insumo->unidadMedida?->abreviatura ?? $insumo->unidadMedida?->nombre ?? 'ud',
            'sin_stock' => $stock <= 0,
        ];
    }

    private function asignarSiembraInicialSiCorresponde(Lote $lote, ?int $asignadoPorId): void
    {
        if (! $lote->usuarioid) {
            return;
        }

        if ($this->trazabilidadService->actividadSiembraPendiente($lote) !== null) {
            return;
        }

        if ($this->trazabilidadService->siembraCompletada($lote)) {
            return;
        }

        try {
            app(LoteSiembraService::class)->asignar(
                $lote,
                (int) $lote->usuarioid,
                $asignadoPorId && (int) $asignadoPorId !== (int) $lote->usuarioid ? $asignadoPorId : null
            );
        } catch (ValidationException) {
            // El lote aún no está en fase de siembra o ya tiene siembra registrada.
        }
    }
}