<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\AlmacenMovimiento;
use App\Models\Insumo;
use App\Models\ProduccionAlmacenamiento;
use App\Models\TipoMovimientoAlmacen;
use App\Support\InsumoCatalogo;
use App\Services\AlmacenCapacidadService;
use App\Services\DestinosMotivoAlmacenService;
use App\Services\ReferenciasAlmacenDisponiblesService;
use App\Support\AlmacenAmbito;
use App\Support\AlmacenPlantaCosechaCatalogo;
use App\Support\CampoJefeScope;
use App\Support\MayoristaAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AlmacenMovimientoController extends Controller
{
    public function __construct(
        private readonly AlmacenCapacidadService $capacidadService,
    ) {}

    public function index(Request $request)
    {
        $ctx = AlmacenAmbito::contexto($request);
        $ambito = $ctx['ambito'];
        $prefijo = $ctx['rutaPrefijo'];

        $filtroNaturaleza = $request->string('naturaleza')->toString();
        if (! in_array($filtroNaturaleza, ['ingreso', 'salida'], true)) {
            $filtroNaturaleza = '';
        }

        $lineas = $this->construirLineasMovimientos($ambito, $prefijo, $filtroNaturaleza, $request->user());

        $totalIngresos = $lineas->where('naturaleza', 'ingreso')->count();
        $totalSalidas = $lineas->where('naturaleza', 'salida')->count();
        $totalMovimientos = $lineas->count();

        $almacenesFiltro = $lineas->pluck('almacen_nombre')->filter()->unique()->sort()->values();
        $tiposFiltro = $lineas->pluck('tipo_nombre')->filter()->unique()->sort()->values();

        $perPage = 20;
        $page = max(1, (int) $request->integer('page', 1));
        $items = $lineas->slice(($page - 1) * $perPage, $perPage)->values();

        $movimientos = new LengthAwarePaginator(
            $items,
            $lineas->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('almacen_movimientos.index', array_merge(compact(
            'movimientos',
            'filtroNaturaleza',
            'totalIngresos',
            'totalSalidas',
            'totalMovimientos',
            'almacenesFiltro',
            'tiposFiltro'
        ), $ctx));
    }

    public function showCosecha(Request $request, ProduccionAlmacenamiento $produccionAlmacenamiento)
    {
        $ctx = AlmacenAmbito::contexto($request);
        $produccionAlmacenamiento->load([
            'produccion.lote.cultivo',
            'almacen.unidadMedida',
            'unidadMedida',
        ]);

        if (Schema::hasColumn('almacen', 'ambito')
            && $produccionAlmacenamiento->almacen?->ambito !== $ctx['ambito']) {
            abort(404);
        }

        $resumenCapacidad = $produccionAlmacenamiento->almacen
            ? $this->capacidadService->resumen($produccionAlmacenamiento->almacen)
            : null;

        return view('almacen_movimientos.cosecha-show', array_merge([
            'registro' => $produccionAlmacenamiento,
            'resumenCapacidad' => $resumenCapacidad,
            'filtroNaturaleza' => $request->string('naturaleza')->toString(),
        ], $ctx));
    }

    /**
     * @return Collection<int, object>
     */
    private function construirLineasMovimientos(string $ambito, string $prefijo, string $filtroNaturaleza, $user = null): Collection
    {
        $lineas = collect();

        $baseInsumo = AlmacenMovimiento::query()
            ->with(['almacen', 'insumo.unidadMedida', 'tipo', 'usuario'])
            ->whereHas('almacen', fn ($a) => AlmacenAmbito::scopeParaUsuario($a, $ambito, $user));

        if ($ambito === AlmacenAmbito::AGRICOLA) {
            CampoJefeScope::aplicarEnMovimientoPorUsuario($baseInsumo, $user);
        }

        if ($filtroNaturaleza !== '') {
            $baseInsumo->whereHas('tipo', fn ($t) => $t->where('naturaleza', $filtroNaturaleza));
        }

        foreach ($baseInsumo->orderByDesc('fecha')->orderByDesc('almacen_movimientoid')->get() as $mov) {
            $responsable = trim(($mov->usuario?->nombre ?? '').' '.($mov->usuario?->apellido ?? ''));
            $lineas->push((object) [
                'tipo_linea' => 'insumo',
                'fecha' => $mov->fecha,
                'orden_id' => $mov->almacen_movimientoid,
                'naturaleza' => $mov->tipo?->naturaleza ?? '',
                'tipo_nombre' => $mov->tipo?->nombre ?? '—',
                'almacen_id' => (int) ($mov->almacenid ?? 0),
                'almacen_nombre' => $mov->almacen?->nombre ?? '',
                'producto' => $mov->insumo?->nombre ?? '—',
                'cantidad' => (float) $mov->cantidad,
                'unidad' => $mov->insumo?->unidadMedida?->abreviatura ?? '',
                'responsable' => $responsable,
                'referencia' => $mov->referencia ?? '',
                'observaciones' => $mov->observaciones ?? '',
                'url_ver' => route($prefijo.'.movimientos.show', [
                    'almacenMovimiento' => $mov->almacen_movimientoid,
                    'naturaleza' => $filtroNaturaleza,
                ]),
                'search_text' => strtolower(trim(
                    ($mov->insumo?->nombre ?? '').' '.$responsable.' '.($mov->referencia ?? '')
                )),
            ]);
        }

        if ($ambito === AlmacenAmbito::AGRICOLA && $filtroNaturaleza !== 'salida') {
            $cosechas = ProduccionAlmacenamiento::query()
                ->with(['almacen.responsable', 'produccion.lote.usuario', 'produccion.lote.cultivo', 'unidadMedida'])
                ->whereHas('almacen', fn ($a) => AlmacenAmbito::scope($a, AlmacenAmbito::AGRICOLA));
            CampoJefeScope::aplicarEnProduccionAlmacenamiento($cosechas, $user);
            $cosechas = $cosechas
                ->orderByDesc('fechaentrada')
                ->orderByDesc('produccionalmacenamientoid')
                ->get();

            foreach ($cosechas as $c) {
                $lote = $c->produccion?->lote;
                $cultivo = $lote?->cultivo?->nombre ?? 'Cultivo';
                $loteNombre = $lote?->nombre ?? ('Producción #'.$c->produccionid);
                $producto = $cultivo.' · '.$loteNombre;
                $fecha = $c->fechaentrada ? Carbon::parse($c->fechaentrada) : Carbon::today();
                $metricas = AlmacenPlantaCosechaCatalogo::metricasProduccionAlmacenamiento($c, $this->capacidadService);
                $responsable = $this->responsableLineaCosecha($c);

                $lineas->push((object) [
                    'tipo_linea' => 'cosecha',
                    'fecha' => $fecha,
                    'orden_id' => $c->produccionalmacenamientoid,
                    'naturaleza' => 'ingreso',
                    'tipo_nombre' => 'Ingreso por cosecha',
                    'almacen_id' => (int) ($c->almacenid ?? 0),
                    'almacen_nombre' => $c->almacen?->nombre ?? '',
                    'producto' => $producto,
                    'cantidad' => $metricas['cantidad'],
                    'unidad' => $metricas['unidad'],
                    'responsable' => $responsable,
                    'referencia' => 'Cosecha #'.$c->produccionid,
                    'observaciones' => $c->observaciones ?? '',
                    'url_ver' => route($prefijo.'.movimientos.cosecha.show', [
                        'produccionAlmacenamiento' => $c->produccionalmacenamientoid,
                        'naturaleza' => $filtroNaturaleza,
                    ]),
                    'search_text' => strtolower(trim($producto.' '.$c->almacen?->nombre.' cosecha')),
                ]);
            }
        }

        return $lineas
            ->sortByDesc(fn ($l) => Carbon::parse($l->fecha)->format('Y-m-d').str_pad((string) $l->orden_id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    private function responsableLineaCosecha(ProduccionAlmacenamiento $c): string
    {
        $lote = $c->produccion?->lote;
        $candidatos = [
            $lote?->usuario,
            $c->almacen?->responsable,
        ];

        foreach ($candidatos as $usuario) {
            if ($usuario === null) {
                continue;
            }
            $nombre = trim(($usuario->nombre ?? '').' '.($usuario->apellido ?? ''));
            if ($nombre !== '') {
                return $nombre;
            }
        }

        return '—';
    }

    public function create(Request $request, string $naturaleza)
    {
        abort_unless(in_array($naturaleza, ['ingreso', 'salida'], true), 404);
        $permisoCrear = $naturaleza === 'ingreso' ? 'almacen.ingresos.create' : 'almacen.salidas.create';
        abort_unless($request->user()?->can($permisoCrear), 403);

        $ctx = AlmacenAmbito::contexto($request);

        if ($ctx['ambito'] === AlmacenAmbito::AGRICOLA && $naturaleza === 'ingreso') {
            return $this->createIngresoAgricola($request, $ctx);
        }

        $user = $request->user();
        $almacenes = AlmacenAmbito::scopeParaUsuario(Almacen::query(), $ctx['ambito'], $user)->orderBy('nombre');
        $almacenesList = $almacenes->get();
        $insumos = InsumoCatalogo::aplicarFiltroOperativo(
            Insumo::query()->with('unidadMedida')
        )
            ->whereIn('almacenid', $almacenesList->pluck('almacenid')->all() ?: [-1])
            ->orderBy('nombre');

        $insumosList = $insumos->get();
        $guias = config('almacen_movimientos', []);
        $tiposAyuda = $guias['tipos'][$naturaleza] ?? [];
        $tipos = TipoMovimientoAlmacen::activosPorNaturaleza($naturaleza);
        $tiposAyudaPorId = $tipos->mapWithKeys(function (TipoMovimientoAlmacen $tipo) use ($tiposAyuda) {
            $texto = $tiposAyuda[$tipo->nombre] ?? collect($tiposAyuda)->first(
                fn ($_, string $nombre) => TipoMovimientoAlmacen::normalizeNombre($nombre) === TipoMovimientoAlmacen::normalizeNombre($tipo->nombre)
            );

            return [$tipo->tipo_movimiento_almacenid => $texto ?? 'Motivo del movimiento según su operación interna.'];
        });

        $almacenIdInicial = (int) old('almacenid', $almacenesList->first()?->almacenid ?? 0) ?: null;
        $insumoIdInicial = (int) old('insumoid') ?: null;
        $tipoIdInicial = (int) old('tipo_movimiento_almacenid') ?: null;

        $referenciasService = app(ReferenciasAlmacenDisponiblesService::class);
        $destinosService = app(DestinosMotivoAlmacenService::class);

        $sugerenciasIniciales = [
            'almacenid' => $almacenIdInicial,
            'grupos' => [],
            'destinos' => [],
            'destino_sugerido' => null,
        ];

        if ($almacenIdInicial) {
            $sugerenciasIniciales['grupos'] = $referenciasService->listar($naturaleza, $almacenIdInicial, $insumoIdInicial);
            $sugerenciasIniciales['destinos'] = $destinosService->listar(
                $naturaleza,
                $almacenIdInicial,
                $insumoIdInicial,
                $tipoIdInicial,
                old('referencia')
            );
            $sugerenciasIniciales['destino_sugerido'] = $sugerenciasIniciales['destinos'][0]['items'][0]['valor'] ?? null;
        }

        return view('almacen_movimientos.create', array_merge([
            'naturaleza' => $naturaleza,
            'almacenes' => $almacenesList,
            'insumos' => $insumosList,
            'insumosPorAlmacen' => $insumosList->groupBy('almacenid')->map(fn ($items) => $items->values()),
            'tipos' => $tipos,
            'guias' => $guias,
            'tiposAyudaPorId' => $tiposAyudaPorId,
            'sugerenciasIniciales' => $sugerenciasIniciales,
        ], $ctx));
    }

    public function referenciasDisponibles(
        Request $request,
        ReferenciasAlmacenDisponiblesService $referenciasService,
        DestinosMotivoAlmacenService $destinosService,
    ) {
        $user = $request->user();
        abort_unless(
            $user?->can('almacen.movimientos.view')
            || $user?->can('almacen.ingresos.view')
            || $user?->can('almacen.ingresos.create')
            || $user?->can('almacen.salidas.view')
            || $user?->can('almacen.salidas.create'),
            403
        );

        $naturaleza = $request->string('naturaleza')->toString();
        abort_unless(in_array($naturaleza, ['ingreso', 'salida'], true), 422);

        $almacenId = $request->integer('almacenid') ?: null;
        $insumoId = $request->integer('insumoid') ?: null;
        $tipoId = $request->integer('tipo_movimiento_almacenid') ?: null;
        $referencia = $request->string('referencia')->toString();

        if ($almacenId !== null) {
            $ctx = AlmacenAmbito::contexto($request);
            abort_unless($this->almacenVisible($ctx['ambito'], $almacenId, $user), 403);
        }

        $destinos = $destinosService->listar($naturaleza, $almacenId, $insumoId, $tipoId, $referencia ?: null);
        $destinoSugerido = $destinos[0]['items'][0]['valor'] ?? null;

        if ($referencia) {
            $desdeRef = $referenciasService->resolverDestinoPorReferencia($naturaleza, $almacenId, $referencia);
            if ($desdeRef) {
                $destinoSugerido = $desdeRef;
            }
        }

        return response()->json([
            'grupos' => $referenciasService->listar($naturaleza, $almacenId, $insumoId),
            'destinos' => $destinos,
            'destino_sugerido' => $destinoSugerido,
        ]);
    }

    public function show(Request $request, AlmacenMovimiento $almacenMovimiento)
    {
        $almacenMovimiento->load(['almacen', 'insumo.unidadMedida', 'tipo', 'usuario']);

        $ctx = AlmacenAmbito::contexto($request);
        abort_unless($this->almacenVisible($ctx['ambito'], (int) $almacenMovimiento->almacenid, $request->user()), 404);

        return view('almacen_movimientos.show', array_merge([
            'movimiento' => $almacenMovimiento,
            'filtroNaturaleza' => $request->string('naturaleza')->toString(),
        ], $ctx));
    }

    public function store(Request $request, string $naturaleza)
    {
        abort_unless(in_array($naturaleza, ['ingreso', 'salida'], true), 404);
        $permisoCrear = $naturaleza === 'ingreso' ? 'almacen.ingresos.create' : 'almacen.salidas.create';
        abort_unless($request->user()?->can($permisoCrear), 403);

        $ctx = AlmacenAmbito::contexto($request);

        if ($naturaleza === 'ingreso'
            && $ctx['ambito'] === AlmacenAmbito::AGRICOLA
            && $request->boolean('ingreso_manual_agricola')) {
            return $this->storeIngresoAgricola($request, $ctx);
        }

        $data = $request->validate([
            'almacenid' => 'required|exists:almacen,almacenid',
            'insumoid' => 'required|exists:insumo,insumoid',
            'tipo_movimiento_almacenid' => 'required|exists:tipo_movimiento_almacen,tipo_movimiento_almacenid',
            'fecha' => 'required|date',
            'cantidad' => 'required|numeric|min:0.001',
            'referencia' => 'nullable|string|max:100',
            'destino_motivo' => 'nullable|string|max:150',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        $tipo = TipoMovimientoAlmacen::query()
            ->whereKey($data['tipo_movimiento_almacenid'])
            ->where('naturaleza', $naturaleza)
            ->where('activo', true)
            ->firstOrFail();

        $insumo = Insumo::query()->findOrFail($data['insumoid']);
        if ((int) $insumo->almacenid !== (int) $data['almacenid']) {
            return back()->withInput()->withErrors([
                'insumoid' => 'El insumo no pertenece al almacén seleccionado. Elija un insumo de ese mismo almacén.',
            ]);
        }

        $almacen = Almacen::query()->with('unidadMedida')->findOrFail($data['almacenid']);
        abort_unless($this->almacenVisible($ctx['ambito'], (int) $almacen->almacenid, $user), 403, 'El almacén no pertenece a este módulo o no es suyo.');
        if ($ctx['ambito'] === AlmacenAmbito::MAYORISTA) {
            MayoristaAccess::asegurarPuedeGestionar($user, $almacen);
        }
        $insumo->loadMissing('unidadMedida');

        if ($tipo->naturaleza === 'ingreso') {
            try {
                $this->capacidadService->validarIngresoKg(
                    $almacen,
                    $this->capacidadService->convertirAKg((float) $data['cantidad'], $insumo->unidadMedida)
                );
            } catch (\Illuminate\Validation\ValidationException $e) {
                return back()->withInput()->withErrors($e->errors());
            }
        }

        if ($tipo->naturaleza === 'salida' && ! $insumo->tieneStockSuficiente((float) $data['cantidad'])) {
            return back()->withInput()->withErrors([
                'cantidad' => 'Stock insuficiente. Disponible: '.number_format((float) $insumo->stock, 3),
            ]);
        }

        try {
            DB::transaction(function () use ($data, $insumo, $tipo, $user) {
                AlmacenMovimiento::create($data + [
                    'usuarioid' => $user->usuarioid,
                    'tipo_movimiento_almacenid' => $tipo->tipo_movimiento_almacenid,
                ]);

                if ($tipo->naturaleza === 'ingreso') {
                    $insumo->incrementarStock((float) $data['cantidad']);
                } else {
                    $insumo->decrementarStock((float) $data['cantidad']);
                }
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors([
                'cantidad' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route($ctx['rutaPrefijo'].'.movimientos.index', ['naturaleza' => $naturaleza])
            ->with('success', 'Movimiento de almacén registrado correctamente.');
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createIngresoAgricola(Request $request, array $ctx)
    {
        InsumoCatalogo::asegurarCatalogosBase();

        $almacenes = AlmacenAmbito::scope(Almacen::query(), $ctx['ambito'])
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        $insumosList = Insumo::query()
            ->with(['tipo', 'unidadMedida'])
            ->whereIn('tipoinsumoid', InsumoCatalogo::tiposValidosIds())
            ->orderBy('nombre')
            ->get();

        $insumosJson = $insumosList->map(fn (Insumo $i) => [
            'id' => $i->insumoid,
            'almacenid' => $i->almacenid,
            'nombre' => $i->nombre,
            'tipo_slug' => InsumoCatalogo::slugFromNombreTipo($i->tipo?->nombre),
            'tipo_nombre' => $i->tipo?->nombre ?? '',
            'unidad' => $i->unidadMedida?->abreviatura ?? $i->unidadMedida?->nombre ?? '',
            'stock' => (float) $i->stock,
        ])->values();

        $tipoMovimientoAjusteId = $this->tipoMovimientoIngresoManualId();

        return view('almacen_movimientos.create-ingreso-agricola', array_merge([
            'almacenes' => $almacenes,
            'insumosJson' => $insumosJson,
            'tipoMovimientoAjusteId' => $tipoMovimientoAjusteId,
        ], $ctx));
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function storeIngresoAgricola(Request $request, array $ctx)
    {
        $data = $request->validate([
            'almacenid' => 'required|exists:almacen,almacenid',
            'categoria_entrada' => 'required|in:insumo,cosecha',
            'insumoid' => 'required|exists:insumo,insumoid',
            'tipo_movimiento_almacenid' => 'required|exists:tipo_movimiento_almacen,tipo_movimiento_almacenid',
            'fecha' => 'required|date',
            'cantidad' => 'required|numeric|min:0.001',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $almacen = AlmacenAmbito::scope(Almacen::query(), $ctx['ambito'])
            ->where('almacenid', $data['almacenid'])
            ->where('activo', true)
            ->firstOrFail();

        $insumo = Insumo::query()->with(['tipo', 'unidadMedida'])->findOrFail($data['insumoid']);

        $slug = InsumoCatalogo::slugFromNombreTipo($insumo->tipo?->nombre);

        if ($data['categoria_entrada'] === 'insumo' && $slug === 'material_siembra') {
            return back()->withInput()->withErrors([
                'insumoid' => 'Para ingreso de insumo elija fertilizantes, pesticidas o material de riego. Use Cosecha para material de siembra.',
            ]);
        }

        if ($data['categoria_entrada'] === 'cosecha') {
            if ($slug !== 'material_siembra') {
                return back()->withInput()->withErrors([
                    'insumoid' => 'Para cosecha debe elegir un insumo de tipo Material de Siembra.',
                ]);
            }
            $cantidadStock = $this->capacidadService->convertirDesdeKg((float) $data['cantidad'], $insumo->unidadMedida);
            $prefijoObs = '[Ingreso manual — Cosecha] ';
        } else {
            $cantidadStock = (float) $data['cantidad'];
            $prefijoObs = '[Ingreso manual — Insumo] ';
        }

        $tipo = TipoMovimientoAlmacen::query()
            ->whereKey($data['tipo_movimiento_almacenid'])
            ->where('naturaleza', 'ingreso')
            ->where('activo', true)
            ->firstOrFail();

        $user = $request->user();

        $cantidadKgIngreso = $data['categoria_entrada'] === 'cosecha'
            ? (float) $data['cantidad']
            : $this->capacidadService->convertirAKg($cantidadStock, $insumo->unidadMedida);

        try {
            $this->capacidadService->validarIngresoKg($almacen, $cantidadKgIngreso);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        try {
            DB::transaction(function () use ($data, $almacen, $insumo, $tipo, $user, $cantidadStock, $prefijoObs) {
                if ((int) $insumo->almacenid !== (int) $almacen->almacenid) {
                    $insumo->almacenid = $almacen->almacenid;
                    $insumo->save();
                }

                AlmacenMovimiento::create([
                    'almacenid' => $almacen->almacenid,
                    'insumoid' => $insumo->insumoid,
                    'tipo_movimiento_almacenid' => $tipo->tipo_movimiento_almacenid,
                    'usuarioid' => $user->usuarioid,
                    'fecha' => $data['fecha'],
                    'cantidad' => $cantidadStock,
                    'referencia' => 'Inventario inicial / manual',
                    'destino_motivo' => $almacen->nombre,
                    'observaciones' => $prefijoObs.($data['observaciones'] ?? ''),
                ]);

                $insumo->incrementarStock($cantidadStock);
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors([
                'cantidad' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route($ctx['rutaPrefijo'].'.movimientos.index', ['naturaleza' => 'ingreso'])
            ->with('success', 'Ingreso manual registrado. El stock del almacén fue actualizado.');
    }

    private function tipoMovimientoIngresoManualId(): int
    {
        $tipo = TipoMovimientoAlmacen::query()
            ->where('naturaleza', 'ingreso')
            ->where('activo', true)
            ->get()
            ->first(fn (TipoMovimientoAlmacen $t) => in_array(
                TipoMovimientoAlmacen::normalizeNombre($t->nombre),
                ['ajuste positivo', 'compra'],
                true
            ));

        return (int) ($tipo?->tipo_movimiento_almacenid
            ?? TipoMovimientoAlmacen::activosPorNaturaleza('ingreso')->first()?->tipo_movimiento_almacenid
            ?? 1);
    }

    public function reportes(Request $request)
    {
        $ctx = AlmacenAmbito::contexto($request);
        $ambito = $ctx['ambito'];
        $user = $request->user();
        $almacenId = $request->integer('almacenid') ?: null;
        [$fechaDesde, $fechaHasta, $periodoActivo] = $this->resolverPeriodoReportes($request);

        $base = AlmacenMovimiento::query()
            ->with(['almacen', 'insumo.unidadMedida', 'tipo'])
            ->whereHas('almacen', fn ($a) => AlmacenAmbito::scopeParaUsuario($a, $ambito, $user))
            ->when($almacenId, fn ($q) => $q->where('almacenid', $almacenId))
            ->whereDate('fecha', '>=', $fechaDesde)
            ->whereDate('fecha', '<=', $fechaHasta);

        if ($ambito === AlmacenAmbito::AGRICOLA) {
            CampoJefeScope::aplicarEnMovimientoPorUsuario($base, $user);
        }

        $movimientos = (clone $base)->orderByDesc('fecha')->limit(200)->get();

        $totalIngresos = (clone $base)
            ->whereHas('tipo', fn ($t) => $t->where('naturaleza', 'ingreso'))
            ->count();

        $totalSalidas = (clone $base)
            ->whereHas('tipo', fn ($t) => $t->where('naturaleza', 'salida'))
            ->count();

        if ($ambito === AlmacenAmbito::AGRICOLA) {
            $cosechasQ = ProduccionAlmacenamiento::query()
                ->whereHas('almacen', fn ($a) => AlmacenAmbito::scope($a, $ambito))
                ->when($almacenId, fn ($q) => $q->where('almacenid', $almacenId))
                ->whereDate('fechaentrada', '>=', $fechaDesde)
                ->whereDate('fechaentrada', '<=', $fechaHasta);
            CampoJefeScope::aplicarEnProduccionAlmacenamiento($cosechasQ, $user);
            $totalIngresos += $cosechasQ->count();
        }

        $resumenProducto = (clone $base)
            ->join('tipo_movimiento_almacen as tma', 'almacen_movimiento.tipo_movimiento_almacenid', '=', 'tma.tipo_movimiento_almacenid')
            ->join('insumo as i', 'almacen_movimiento.insumoid', '=', 'i.insumoid')
            ->select('i.nombre as producto')
            ->selectRaw("COUNT(CASE WHEN tma.naturaleza = 'ingreso' THEN 1 END) as ingresos")
            ->selectRaw("COUNT(CASE WHEN tma.naturaleza = 'salida' THEN 1 END) as salidas")
            ->selectRaw("SUM(CASE WHEN tma.naturaleza = 'ingreso' THEN almacen_movimiento.cantidad ELSE 0 END) as cantidad_ingresos")
            ->selectRaw("SUM(CASE WHEN tma.naturaleza = 'salida' THEN almacen_movimiento.cantidad ELSE 0 END) as cantidad_salidas")
            ->groupBy('i.nombre')
            ->orderBy('i.nombre')
            ->get();

        $stockPorAlmacen = Insumo::query()
            ->select('almacen.nombre as almacen')
            ->selectRaw('SUM(insumo.stock) as stock')
            ->join('almacen', 'insumo.almacenid', '=', 'almacen.almacenid')
            ->when($almacenId, fn ($q) => $q->where('insumo.almacenid', $almacenId))
            ->whereHas('almacen', fn ($a) => AlmacenAmbito::scopeParaUsuario($a, $ambito, $user))
            ->groupBy('almacen.nombre')
            ->orderBy('almacen.nombre')
            ->get();

        $almacenes = AlmacenAmbito::scopeParaUsuario(Almacen::query(), $ambito, $user)
            ->orderBy('nombre')
            ->get();

        $almacenNombreFiltro = $almacenId
            ? (string) ($almacenes->firstWhere('almacenid', $almacenId)?->nombre ?? '')
            : '';

        return view('almacen_movimientos.reportes', array_merge(compact(
            'movimientos',
            'resumenProducto',
            'stockPorAlmacen',
            'almacenes',
            'almacenId',
            'almacenNombreFiltro',
            'fechaDesde',
            'fechaHasta',
            'periodoActivo',
            'totalIngresos',
            'totalSalidas'
        ), $ctx));
    }

    /** El almacén pertenece al ámbito del módulo y, en mayorista, es del usuario (MAY-03). */
    private function almacenVisible(string $ambito, int $almacenId, $user): bool
    {
        return $almacenId > 0 && AlmacenAmbito::scopeParaUsuario(Almacen::query(), $ambito, $user)
            ->whereKey($almacenId)
            ->exists();
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolverPeriodoReportes(Request $request): array
    {
        $periodo = $request->string('periodo')->toString();
        $desde = $request->string('fecha_desde')->toString();
        $hasta = $request->string('fecha_hasta')->toString();
        $hoy = Carbon::today();

        $usarFechasManual = $periodo === 'personalizado' || ($periodo === '' && ($desde !== '' || $hasta !== ''));

        if ($usarFechasManual) {
            $fechaDesde = $desde !== '' ? Carbon::parse($desde) : $hoy->copy()->subDays(29);
            $fechaHasta = $hasta !== '' ? Carbon::parse($hasta) : $hoy->copy();
            if ($fechaDesde->gt($fechaHasta)) {
                [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
            }

            return [$fechaDesde->toDateString(), $fechaHasta->toDateString(), 'personalizado'];
        }

        switch ($periodo) {
            case 'hoy':
                $fechaDesde = $hoy->copy();
                $fechaHasta = $hoy->copy();
                $periodoActivo = 'hoy';
                break;
            case '7d':
                $fechaDesde = $hoy->copy()->subDays(6);
                $fechaHasta = $hoy->copy();
                $periodoActivo = '7d';
                break;
            case 'mes_actual':
                $fechaDesde = $hoy->copy()->startOfMonth();
                $fechaHasta = $hoy->copy()->endOfMonth();
                $periodoActivo = 'mes_actual';
                break;
            case 'mes_pasado':
                $fechaDesde = $hoy->copy()->subMonthNoOverflow()->startOfMonth();
                $fechaHasta = $hoy->copy()->subMonthNoOverflow()->endOfMonth();
                $periodoActivo = 'mes_pasado';
                break;
            case '90d':
                $fechaDesde = $hoy->copy()->subDays(89);
                $fechaHasta = $hoy->copy();
                $periodoActivo = '90d';
                break;
            case '30d':
            default:
                $fechaDesde = $hoy->copy()->subDays(29);
                $fechaHasta = $hoy->copy();
                $periodoActivo = '30d';
                break;
        }

        return [$fechaDesde->toDateString(), $fechaHasta->toDateString(), $periodoActivo];
    }
}
