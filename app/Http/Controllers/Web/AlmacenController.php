<?php



namespace App\Http\Controllers\Web;



use App\Http\Controllers\Controller;

use App\Models\Almacen;

use App\Models\AlmacenajeLoteProduccion;

use App\Models\Insumo;

use App\Models\AlmacenMovimiento;
use App\Models\InsumoPresentacion;
use App\Models\InventarioPresentacionLote;

use App\Models\ProduccionAlmacenamiento;

use App\Models\TipoAlmacen;

use App\Models\UnidadMedida;

use App\Models\Usuario;

use App\Services\AlmacenCapacidadService;

use App\Services\InventarioPresentacionService;

use App\Services\ProductoPlantaInventarioService;

use App\Services\UbicacionesAlmacenService;

use App\Support\AlmacenAmbito;

use App\Support\AlmacenNombreCatalogo;

use App\Support\AlmacenPlantaCosechaCatalogo;

use App\Support\AlmacenResponsableCatalogo;

use App\Support\InsumoCatalogo;

use App\Support\MayoristaAccess;

use App\Support\UbicacionGpsParser;

use App\Support\UsuarioRol;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Schema;

use Illuminate\Validation\ValidationException;



class AlmacenController extends Controller

{

    public function __construct(
        private readonly AlmacenCapacidadService $capacidadService,
        private readonly InventarioPresentacionService $inventarioPresentacion,
        private readonly ProductoPlantaInventarioService $inventarioPlanta,
    ) {}



    public function index(Request $request)

    {

        $ctx = AlmacenAmbito::contexto($request);

        $ambito = $ctx['ambito'];

        $q = $this->queryAlmacenesVisibles($request, $ambito);



        $almacenesPagina = (clone $q)

            ->with(['unidadMedida'])

            ->orderBy('almacenid', 'desc')

            ->paginate(15);



        $ocupacionPorId = [];

        $capacidadTotalKg = 0;

        $ocupadoTotalKg = 0;



        foreach ($almacenesPagina as $almacen) {

            $resumen = $this->capacidadService->resumen($almacen);

            $ocupacionPorId[$almacen->almacenid] = $resumen;

            $capacidadTotalKg += $resumen['capacidad_kg'];

            $ocupadoTotalKg += $resumen['ocupado_kg'];

        }



        $stats = [

            'total' => (clone $q)->count(),

            'capacidad_total' => $capacidadTotalKg,

            'ocupado_total' => $ocupadoTotalKg,

            'ocupacion_promedio' => $capacidadTotalKg > 0

                ? round(($ocupadoTotalKg / $capacidadTotalKg) * 100, 1)

                : 0,

        ];



        return view('almacenes.index', array_merge(compact('almacenesPagina', 'stats', 'ocupacionPorId'), $ctx, [

            'almacenes' => $almacenesPagina,

            'almacenesMapa' => $this->almacenesParaMapaIndex($request, $ambito, $ctx['rutaPrefijo'] ?? 'almacen-agricola'),

        ]));

    }



    public function create(Request $request)

    {

        $ctx = AlmacenAmbito::contexto($request);



        return view('almacenes.create', array_merge(

            $this->datosFormulario(null, $ctx['ambito'], $request),

            $ctx

        ));

    }



    public function selectorUbicacion(Request $request)

    {

        $ctx = AlmacenAmbito::contexto($request);

        $excluirAlmacenId = $request->integer('excluir_almacen_id') ?: null;

        $ubicacionesGrupos = app(UbicacionesAlmacenService::class)

            ->listarParaFormulario($excluirAlmacenId);



        return view('almacenes.selector-ubicacion', array_merge([

            'ubicacionesGrupos' => $ubicacionesGrupos,

        ], $ctx));

    }

    public function sugerirNombre(Request $request)
    {
        $ctx = AlmacenAmbito::contexto($request);
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'zona' => 'nullable|string|max:120',
        ]);

        return response()->json([
            'nombre' => AlmacenNombreCatalogo::sugerirNombreNuevo(
                $ctx['ambito'],
                (float) $data['lat'],
                (float) $data['lng'],
                isset($data['zona']) ? trim((string) $data['zona']) : null
            ),
        ]);
    }



    public function store(Request $request)

    {

        $ctx = AlmacenAmbito::contexto($request);

        $data = $this->validarAlmacen($request);

        $data['ambito'] = $ctx['ambito'];

        $data['activo'] = true;

        $data['unidadmedidaid'] = $this->unidadKilogramoId();

        $data['tipoalmacenid'] = $this->tipoAlmacenPorDefecto($ctx['ambito']);

        if (Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            if (
                UsuarioRol::esAdminGlobal($request->user())
                && AlmacenResponsableCatalogo::usuariosParaSelector($ctx['ambito'])->isEmpty()
            ) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'responsable_usuarioid' => 'No hay usuarios con rol válido para este almacén. Cree el responsable en Gestión de usuarios.',
                    ]);
            }

            $data['responsable_usuarioid'] = $this->resolverResponsableAlmacen($request, $ctx['ambito'], $data);
        }



        Almacen::create($data);



        return redirect()

            ->route($ctx['rutaPrefijo'].'.index')

            ->with('success', 'Almacén creado.');

    }



    public function show(Request $request, Almacen $almacen)

    {

        $ctx = AlmacenAmbito::contexto($request);

        $this->asegurarAmbitoAlmacen($almacen, $ctx['ambito']);
        $this->asegurarAccesoAlmacen($request, $almacen, $ctx['ambito'], gestion: false);

        // Un GET de visualización no descuenta stock ni crea movimientos (MAY-07): la salida
        // mayorista se registra al iniciar la ruta, dentro de su transacción.
        $almacen->load(['unidadMedida', 'almacenamientos']);

        $resumenCapacidad = $this->capacidadService->resumen($almacen);

        $contenidos = $this->contenidoAlmacen($almacen);

        if (in_array($almacen->ambito ?? '', [AlmacenAmbito::PLANTA, AlmacenAmbito::AGRICOLA], true)) {
            $contenidos = AlmacenPlantaCosechaCatalogo::consolidarItemsPlanta(
                $contenidos,
                $almacen,
                $ctx['rutaPrefijo']
            );
        }

        $tiposContenidoFiltro = $contenidos->pluck('tipo_label')->unique()->sort()->values();



        return view('almacenes.show', array_merge(compact(
            'almacen',
            'resumenCapacidad',
            'contenidos',
            'tiposContenidoFiltro'
        ), $ctx));

    }



    public function edit(Request $request, Almacen $almacen)

    {

        $ctx = AlmacenAmbito::contexto($request);

        $this->asegurarAmbitoAlmacen($almacen, $ctx['ambito']);
        $this->asegurarAccesoAlmacen($request, $almacen, $ctx['ambito'], gestion: true);



        return view('almacenes.edit', array_merge(

            $this->datosFormulario($almacen, $ctx['ambito'], $request),

            $ctx,

            ['almacen' => $almacen]

        ));

    }



    public function update(Request $request, Almacen $almacen)

    {

        $ctx = AlmacenAmbito::contexto($request);

        $this->asegurarAmbitoAlmacen($almacen, $ctx['ambito']);
        $this->asegurarAccesoAlmacen($request, $almacen, $ctx['ambito'], gestion: true);



        $data = $this->validarAlmacen($request, $almacen);

        $data['ambito'] = $ctx['ambito'];

        $data['activo'] = true;

        $data['unidadmedidaid'] = $this->unidadKilogramoId();

        if (! $almacen->tipoalmacenid) {
            $data['tipoalmacenid'] = $this->tipoAlmacenPorDefecto($ctx['ambito']);
        } else {
            unset($data['tipoalmacenid']);
        }

        if (Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            $data['responsable_usuarioid'] = $this->resolverResponsableAlmacen($request, $ctx['ambito'], $data);
        }



        $almacen->update($data);



        return redirect()

            ->route($ctx['rutaPrefijo'].'.index')

            ->with('success', 'Almacén actualizado.');

    }



    public function destroy(Request $request, Almacen $almacen)

    {

        $ctx = AlmacenAmbito::contexto($request);

        $this->asegurarAmbitoAlmacen($almacen, $ctx['ambito']);
        $this->asegurarAccesoAlmacen($request, $almacen, $ctx['ambito'], gestion: true);

        $eval = \App\Support\AlmacenEliminacionCatalogo::evaluar($almacen);
        if (! $eval['ok']) {
            return back()->with([
                'error' => $eval['mensaje'],
                'error_modal' => true,
                'error_modal_titulo' => $eval['titulo'],
            ]);
        }

        try {
            \App\Support\AlmacenEliminacionCatalogo::eliminar($almacen);
        } catch (\Illuminate\Database\QueryException $e) {
            if (\App\Support\EliminacionSegura::esViolacionFk($e)) {
                return back()->with([
                    'error' => \App\Support\EliminacionSegura::mensajeGenerico(),
                    'error_modal' => true,
                    'error_modal_titulo' => 'No se puede eliminar',
                ]);
            }

            throw $e;
        } catch (\RuntimeException $e) {
            return back()->with([
                'error' => $e->getMessage(),
                'error_modal' => true,
                'error_modal_titulo' => 'No se puede eliminar',
            ]);
        }

        return redirect()

            ->route($ctx['rutaPrefijo'].'.index')

            ->with('success', 'Almacén eliminado.');

    }



    private function asegurarAmbitoAlmacen(Almacen $almacen, string $ambito): void

    {

        if (Schema::hasColumn('almacen', 'ambito') && $almacen->ambito !== $ambito) {

            abort(404);

        }

    }

    /**
     * Ownership del almacén mayorista (MAY-02): ver exige que sea propio (o supervisión del admin);
     * editar/eliminar exige operarlo. Así un mayorista no abre, edita ni se vuelve responsable
     * de un almacén ajeno cambiando el id en la URL.
     */
    private function asegurarAccesoAlmacen(Request $request, Almacen $almacen, string $ambito, bool $gestion): void
    {
        if ($ambito !== AlmacenAmbito::MAYORISTA) {
            return;
        }

        if ($gestion) {
            MayoristaAccess::asegurarPuedeGestionar($request->user(), $almacen);

            return;
        }

        MayoristaAccess::asegurarPuedeVer($request->user(), $almacen);
    }



    /**

     * @return array<string, mixed>

     */

    private function datosFormulario(?Almacen $almacen = null, ?string $ambito = null, ?Request $request = null): array

    {

        $request ??= request();

        $ambito ??= $almacen !== null && Schema::hasColumn('almacen', 'ambito')

            ? (string) ($almacen->ambito ?? AlmacenAmbito::AGRICOLA)

            : AlmacenAmbito::fromRequest($request);

        $esAdmin = UsuarioRol::esAdminGlobal($request->user());



        return [

            'almacen' => $almacen,

            'guias' => config('almacenes', []),

            'esAdmin' => $esAdmin,

            'responsables' => $esAdmin ? AlmacenResponsableCatalogo::usuariosParaSelector($ambito) : collect(),

            'etiquetaResponsable' => AlmacenResponsableCatalogo::etiquetaResponsable($ambito),

        ];

    }



    /**

     * @return array<string, mixed>

     */

    private function validarAlmacen(Request $request, ?Almacen $almacen = null): array

    {

        $reglas = [

            'nombre' => 'required|string|max:100|unique:almacen,nombre'.($almacen ? ','.$almacen->almacenid.',almacenid' : ''),

            'descripcion' => 'nullable|string|max:250',

            'ubicacion' => 'nullable|string|max:200',

            'capacidad' => 'required|numeric|min:0.01',

        ];



        if (Schema::hasColumn('almacen', 'direccionlogisticaid')) {

            $reglas['direccionlogisticaid'] = 'nullable|exists:direccion_logistica,direccionlogisticaid';

        }

        if (
            Schema::hasColumn('almacen', 'responsable_usuarioid')
            && UsuarioRol::esAdminGlobal($request->user())
        ) {
            $reglas['responsable_usuarioid'] = 'required|integer|exists:usuario,usuarioid';
        }



        $data = $request->validate($reglas);



        if (! Schema::hasColumn('almacen', 'direccionlogisticaid')) {

            unset($data['direccionlogisticaid']);

        } elseif (empty($data['direccionlogisticaid'])) {

            $data['direccionlogisticaid'] = null;

        }



        return $data;

    }



    /** @param  array<string, mixed>  $validated */
    private function resolverResponsableAlmacen(Request $request, string $ambito, array $validated): int
    {
        $user = $request->user();
        abort_if($user === null, 403);

        if (UsuarioRol::esAdminGlobal($user)) {
            $responsable = Usuario::query()->findOrFail((int) ($validated['responsable_usuarioid'] ?? 0));
            if (! AlmacenResponsableCatalogo::usuarioValidoParaAmbito($responsable, $ambito)) {
                throw ValidationException::withMessages([
                    'responsable_usuarioid' => 'Debe asignar un responsable válido para este tipo de almacén. El administrador no puede ser dueño del almacén.',
                ]);
            }

            return (int) $responsable->usuarioid;
        }

        return (int) $user->usuarioid;
    }



    private function unidadKilogramoId(): ?int

    {

        $id = UnidadMedida::query()

            ->where(function ($q) {

                $q->whereRaw('LOWER(abreviatura) = ?', ['kg'])

                    ->orWhereRaw('LOWER(nombre) LIKE ?', ['%kilogramo%']);

            })

            ->value('unidadmedidaid');



        return $id ? (int) $id : null;

    }



    private function tipoAlmacenPorDefecto(?string $ambito = null): ?int
    {
        $ambito ??= AlmacenAmbito::AGRICOLA;

        $preferidos = match ($ambito) {
            AlmacenAmbito::PLANTA => ['Planta', 'Central', 'Secundario'],
            AlmacenAmbito::MAYORISTA => ['Mayorista', 'Central', 'Secundario'],
            AlmacenAmbito::PUNTO_VENTA => ['Punto de venta', 'Minorista', 'Central', 'Secundario'],
            default => ['Agrícola', 'Agricola', 'Central', 'Secundario'],
        };

        foreach ($preferidos as $nombre) {
            $id = TipoAlmacen::query()
                ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower($nombre)])
                ->value('tipoalmacenid');
            if ($id) {
                return (int) $id;
            }
        }

        // Evitar asignar "Planta"/"Mayorista" a un almacén agrícola por accidente.
        $excluir = match ($ambito) {
            AlmacenAmbito::AGRICOLA => ['planta', 'mayorista', 'punto de venta', 'minorista'],
            AlmacenAmbito::PLANTA => ['agrícola', 'agricola', 'mayorista', 'punto de venta', 'minorista'],
            AlmacenAmbito::MAYORISTA => ['agrícola', 'agricola', 'planta', 'punto de venta', 'minorista'],
            default => [],
        };

        $fallback = TipoAlmacen::query()
            ->when($excluir !== [], function ($q) use ($excluir) {
                $q->whereRaw('LOWER(TRIM(nombre)) NOT IN ('.implode(',', array_fill(0, count($excluir), '?')).')', $excluir);
            })
            ->orderBy('tipoalmacenid')
            ->value('tipoalmacenid');

        return $fallback ? (int) $fallback : null;
    }



    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function contenidoAlmacen(Almacen $almacen): \Illuminate\Support\Collection
    {
        $items = collect();
        $esPlanta = ($almacen->ambito ?? '') === AlmacenAmbito::PLANTA;
        $esMayorista = ($almacen->ambito ?? '') === AlmacenAmbito::MAYORISTA;
        $esAgricola = ($almacen->ambito ?? '') === AlmacenAmbito::AGRICOLA;

        $insumosQuery = Insumo::query()->with(['tipo', 'unidadMedida'])
            ->where('almacenid', $almacen->almacenid);

        if ($esPlanta) {
            $insumosQuery = InsumoCatalogo::aplicarFiltroExcluirProductoTerminado($insumosQuery);
            $tipoSiembraId = InsumoCatalogo::tiposOrdenados()
                ->filter(fn ($t) => InsumoCatalogo::slugFromNombreTipo($t->nombre) === 'material_siembra')
                ->pluck('tipoinsumoid')
                ->first();
            if ($tipoSiembraId) {
                $insumosQuery->where(function ($q) use ($tipoSiembraId) {
                    $q->where('tipoinsumoid', '!=', (int) $tipoSiembraId)
                        ->orWhere('descripcion', 'like', 'Recepción pedido%');
                });
            }
        } elseif ($esMayorista) {
            $insumosQuery = InsumoCatalogo::aplicarFiltroProductoTerminado($insumosQuery)
                ->with(['presentaciones.tipoEmpaque']);
        } elseif ($esAgricola) {
            $insumosQuery->where('descripcion', 'like', 'Recepción pedido%');
        } else {
            $insumosQuery = InsumoCatalogo::aplicarFiltroOperativo($insumosQuery);
        }

        $insumos = $insumosQuery->orderBy('nombre')->get();

        foreach ($insumos as $insumo) {
            if ($esMayorista) {
                $fila = $this->filaMayoristaConsolidada($almacen, $insumo);
                if ($fila !== null) {
                    $items->push($fila);
                }

                continue;
            }

            if ((float) $insumo->stock <= 0) {
                continue;
            }

            $tipoNombre = $insumo->tipo?->nombre ?? 'Insumo';
            $slug = InsumoCatalogo::slugFromNombreTipo($tipoNombre) ?? 'insumo';
            $esRecepcionPedido = ! $esMayorista && AlmacenPlantaCosechaCatalogo::esRecepcionPedidoInsumo($insumo);
            $kg = $this->capacidadService->convertirAKg((float) $insumo->stock, $insumo->unidadMedida);
            $claveCultivo = AlmacenPlantaCosechaCatalogo::claveCultivo($insumo->nombre);
            if ($esRecepcionPedido) {
                $metricas = AlmacenPlantaCosechaCatalogo::metricasInsumoRecepcion($insumo, $this->capacidadService);
                $cantidad = $metricas['cantidad'];
                $unidad = $metricas['unidad'];
                $kg = $metricas['kg'];
                $empaque = $metricas['empaque'];
            } else {
                $cantidad = (float) $insumo->stock;
                $unidad = $insumo->unidadMedida?->abreviatura ?? $insumo->unidadMedida?->nombre ?? '';
                $empaque = null;
            }

            $items->push((object) [
                'categoria' => $esMayorista ? 'producto_terminado' : ($esRecepcionPedido ? 'cosecha' : 'insumo'),
                'tipo_label' => $esMayorista ? 'Producto terminado' : ($esRecepcionPedido ? 'Cosecha' : $tipoNombre),
                'tipo_filtro' => $esMayorista ? 'producto_terminado' : ($esRecepcionPedido ? 'cosecha' : $slug),
                'nombre' => $esRecepcionPedido
                    ? AlmacenPlantaCosechaCatalogo::etiquetaCultivo($insumo->nombre)
                    : $insumo->nombre,
                'detalle' => $insumo->descripcion ? \Illuminate\Support\Str::limit($insumo->descripcion, 60) : '—',
                'cantidad' => $cantidad,
                'unidad' => $unidad,
                'kg' => $kg,
                'empaque' => $empaque ?? null,
                'fecha_orden' => AlmacenPlantaCosechaCatalogo::fechaDesdeDescripcionRecepcion($insumo->descripcion)?->timestamp ?? 0,
                'search' => strtolower(trim($insumo->nombre.' '.$tipoNombre)),
                'insumoid' => $insumo->insumoid,
                'origen_tipo' => $esRecepcionPedido ? 'recepcion_pedido' : 'insumo',
                'clave_cultivo' => $claveCultivo,
            ]);
        }

        $cosechas = ProduccionAlmacenamiento::query()
            ->with(['produccion.lote.cultivo', 'unidadMedida'])
            ->where('almacenid', $almacen->almacenid)
            ->whereNull('fechasalida')
            ->orderByDesc('fechaentrada')
            ->get();

        foreach ($cosechas as $c) {
            $lote = $c->produccion?->lote;
            $cultivo = $lote?->cultivo?->nombre ?? 'Cultivo';
            $nombre = $cultivo.' · '.($lote?->nombre ?? 'Producción #'.$c->produccionid);
            if ($esAgricola || $esPlanta) {
                $metricas = AlmacenPlantaCosechaCatalogo::metricasProduccionAlmacenamiento($c, $this->capacidadService);
                $cantidad = $metricas['cantidad'];
                $unidad = $metricas['unidad'];
                $kg = $metricas['kg'];
                $empaque = $metricas['empaque'];
            } else {
                $cantidad = (float) $c->cantidad;
                $unidad = $c->unidadMedida?->abreviatura ?? 'kg';
                $kg = $this->capacidadService->convertirAKg((float) $c->cantidad, $c->unidadMedida);
                $empaque = null;
            }

            $fechaEntrada = $c->fechaentrada ? \Carbon\Carbon::parse($c->fechaentrada) : null;
            $claveCultivo = AlmacenPlantaCosechaCatalogo::claveCultivo($cultivo !== '' ? $cultivo : $nombre, $cultivo !== '' ? $cultivo : null);

            $items->push((object) [
                'categoria' => 'cosecha',
                'tipo_label' => 'Cosecha',
                'tipo_filtro' => 'cosecha',
                'nombre' => AlmacenPlantaCosechaCatalogo::etiquetaCultivo($cultivo !== '' ? $cultivo : $nombre, $claveCultivo),
                'detalle' => $fechaEntrada ? $fechaEntrada->format('d/m/Y') : '—',
                'cantidad' => $cantidad,
                'unidad' => $unidad,
                'kg' => $kg,
                'empaque' => $empaque,
                'fecha_orden' => $fechaEntrada?->timestamp ?? 0,
                'search' => strtolower(trim($nombre.' cosecha '.$cultivo)),
                'produccionid' => $c->produccionid,
                'produccionalmacenamientoid' => $c->produccionalmacenamientoid,
                'origen_tipo' => 'produccion',
                'clave_cultivo' => $claveCultivo,
                'lote_nombre' => $lote?->nombre,
            ]);
        }

        if ($esPlanta) {
            $this->inventarioPlanta->sincronizarAlmacenajeDesdeInventario((int) $almacen->almacenid);
            $this->inventarioPlanta->sincronizarDesdeAlmacenajes((int) $almacen->almacenid);
        }

        $productosPlanta = AlmacenajeLoteProduccion::query()
            ->with(['loteProduccionPedido.unidadMedida', 'loteProduccionPedido.materiasPrimas.insumo.unidadMedida'])
            ->whereNull('fecha_retiro')
            ->where('almacenid', $almacen->almacenid)
            ->orderByDesc('fecha_almacenaje')
            ->get();

        foreach ($productosPlanta as $ingreso) {
            $lote = $ingreso->loteProduccionPedido;
            if (! $lote) {
                continue;
            }

            $lote->loadMissing('materiasPrimas.insumo.unidadMedida', 'unidadMedida');
            $producto = \App\Support\LoteProduccionNombre::productoDesdeLote($lote);
            $nombre = \App\Support\ProductoPlantaCatalogo::etiquetaLoteAlmacen($lote);
            $resumen = \App\Support\ProductoPlantaCatalogo::resumenProduccion($lote, $this->capacidadService);
            $fechaAlm = $ingreso->fecha_almacenaje ? \Carbon\Carbon::parse($ingreso->fecha_almacenaje) : null;

            $invRows = InventarioPresentacionLote::query()
                ->where('almacenid', $almacen->almacenid)
                ->where('loteproduccionpedidoid', $lote->loteproduccionpedidoid)
                ->with('presentacion')
                ->get();

            if ($invRows->isNotEmpty()) {
                foreach ($invRows as $inv) {
                    $presentacion = $inv->presentacion;
                    $cantidad = (float) $inv->cantidad_unidades;
                    $kg = (float) $inv->cantidad_kg;
                    $sinStock = $cantidad <= 0 && $kg <= 0;
                    $unidad = $presentacion
                        ? \App\Support\EmpaquePlantaCatalogo::etiquetaUnidad(null, $presentacion->tipo_envase)
                        : \App\Support\ProductoPlantaCatalogo::unidadEtiqueta($producto, $lote->unidadMedida, $lote);
                    $empaqueLabel = $presentacion?->nombre;
                    $detalleEmpaque = $sinStock
                        ? 'Sin stock en almacén'
                        : ($cantidad > 0 && $empaqueLabel
                            ? number_format($cantidad, 0, ',', '.').' '.$unidad.' · '.$empaqueLabel
                                .($kg > 0 ? ' · '.number_format($kg, 2, ',', '.').' kg producto' : '')
                            : '');

                    $insumoId = Insumo::query()
                        ->where('almacenid', $almacen->almacenid)
                        ->whereRaw('LOWER(TRIM(nombre)) = ?', [\Illuminate\Support\Str::lower(trim($producto))])
                        ->value('insumoid');

                    $items->push((object) [
                        'categoria' => 'producto_planta',
                        'tipo_label' => 'Producto procesado',
                        'tipo_filtro' => 'producto procesado',
                        'nombre' => $nombre,
                        'detalle' => $detalleEmpaque !== '' ? \Illuminate\Support\Str::limit($detalleEmpaque, 120) : ($lote->codigo_lote ?? '—'),
                        'cantidad' => $cantidad,
                        'unidad' => $unidad,
                        'kg' => $kg,
                        'empaque' => $empaqueLabel,
                        'sin_stock' => $sinStock,
                        'fecha_orden' => $fechaAlm?->timestamp ?? 0,
                        'search' => strtolower(trim($nombre.' producto planta '.$producto.' '.$lote->codigo_lote.' '.($empaqueLabel ?? ''))),
                        'lote_produccion_pedido_id' => $lote->loteproduccionpedidoid,
                        'insumoid' => $insumoId ? (int) $insumoId : null,
                    ]);
                }

                continue;
            }

            $kg = (float) ($resumen['kg'] ?? 0);
            if ($kg <= 0) {
                $kg = $this->capacidadService->convertirAKg((float) $ingreso->cantidad, $lote->unidadMedida);
            }
            $cantidadAlmacenaje = (float) $ingreso->cantidad;
            if ($cantidadAlmacenaje > 0) {
                $cantidad = $cantidadAlmacenaje;
            } elseif (\App\Support\ProductoPlantaCatalogo::esProduccionPorUnidades($lote)) {
                $cantidad = (float) \App\Support\ProductoPlantaCatalogo::unidadesProducidas($lote, $this->capacidadService);
            } else {
                $cantidad = (float) ($resumen['cantidad'] ?? 0);
            }
            $sinStock = $cantidad <= 0 && $kg <= 0;
            $unidad = \App\Support\ProductoPlantaCatalogo::unidadEtiqueta($producto, $lote->unidadMedida, $lote);
            $empaqueLabel = \App\Support\ProductoPlantaCatalogo::loteTieneEmpaquePlanificado($lote)
                ? \App\Support\EmpaquePlantaCatalogo::etiquetaEmpaquePlanificado(
                    $lote->empaque_catalogo_slug,
                    $lote->empaque_nombre_personalizado,
                    $lote->empaque_peso_neto_kg
                )
                : null;
            $detalleEmpaque = \App\Support\ProductoPlantaCatalogo::detalleEmpaqueAlmacen($lote, $resumen);
            $fechaAlm = $ingreso->fecha_almacenaje ? \Carbon\Carbon::parse($ingreso->fecha_almacenaje) : null;
            $detalle = $detalleEmpaque !== ''
                ? $detalleEmpaque
                : trim(($ingreso->condicion ? $ingreso->condicion.' · ' : '').($fechaAlm ? $fechaAlm->format('d/m/Y H:i') : ''));

            $insumoId = Insumo::query()
                ->where('almacenid', $almacen->almacenid)
                ->whereRaw('LOWER(TRIM(nombre)) = ?', [\Illuminate\Support\Str::lower(trim($producto))])
                ->value('insumoid');

            $items->push((object) [
                'categoria' => 'producto_planta',
                'tipo_label' => 'Producto procesado',
                'tipo_filtro' => 'producto procesado',
                'nombre' => $nombre,
                'detalle' => $detalle !== '' ? \Illuminate\Support\Str::limit($detalle, 120) : ($lote->codigo_lote ?? '—'),
                'cantidad' => $cantidad,
                'unidad' => $unidad,
                'kg' => $kg,
                'empaque' => $empaqueLabel,
                'sin_stock' => $sinStock,
                'fecha_orden' => $fechaAlm?->timestamp ?? 0,
                'search' => strtolower(trim($nombre.' producto planta '.$producto.' '.$lote->codigo_lote.' '.($empaqueLabel ?? ''))),
                'lote_produccion_pedido_id' => $lote->loteproduccionpedidoid,
                'insumoid' => $insumoId ? (int) $insumoId : null,
            ]);
        }

        return $items->sortByDesc('fecha_orden')->values();
    }

    /**
     * Una fila por producto; el desglose por empaque queda en inventario.show.
     */
    private function filaMayoristaConsolidada(Almacen $almacen, Insumo $insumo): ?object
    {
        $presentaciones = $insumo->relationLoaded('presentaciones')
            ? $insumo->presentaciones->where('activo', true)->sortBy('orden')->values()
            : InsumoPresentacion::query()
                ->with('tipoEmpaque')
                ->where('insumoid', $insumo->insumoid)
                ->where('activo', true)
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get();

        $totalUnidades = 0.0;
        $totalKg = 0.0;
        $empaquesConStock = 0;
        $empaquesRegistrados = 0;
        $nombresEmpaque = [];
        $etiquetasUnidad = [];

        foreach ($presentaciones as $presentacion) {
            $unidades = $this->inventarioPresentacion->stockTotalUnidades(
                (int) $almacen->almacenid,
                (int) $presentacion->insumo_presentacionid
            );
            $kg = $this->inventarioPresentacion->stockTotalKg(
                (int) $almacen->almacenid,
                (int) $presentacion->insumo_presentacionid
            );
            $tieneRegistro = InventarioPresentacionLote::query()
                ->where('almacenid', $almacen->almacenid)
                ->where('insumo_presentacionid', $presentacion->insumo_presentacionid)
                ->exists();

            if ($unidades <= 0 && $kg <= 0 && ! $tieneRegistro) {
                continue;
            }

            $empaquesRegistrados++;
            if ($kg <= 0 && $unidades > 0) {
                $kg = $unidades * $presentacion->pesoNetoKg();
            }

            $totalUnidades += $unidades;
            $totalKg += $kg;
            if ($unidades > 0 || $kg > 0) {
                $empaquesConStock++;
            }
            $nombresEmpaque[] = trim($presentacion->nombre);
            $etiquetasUnidad[] = $presentacion->etiquetaUnidad();
        }

        $tieneHistorial = $this->insumoTieneHistorialMayorista($almacen, $insumo);

        if ($empaquesRegistrados === 0 && ! $tieneHistorial) {
            return null;
        }

        $sinStock = $totalUnidades <= 0 && $totalKg <= 0;

        if ($totalKg <= 0 && $totalUnidades <= 0 && $sinStock) {
            $totalKg = 0.0;
            $totalUnidades = 0.0;
        } elseif ($totalKg <= 0 && $totalUnidades > 0) {
            $totalKg = $this->capacidadService->convertirAKg((float) $insumo->stock, $insumo->unidadMedida);
        } elseif ($totalKg <= 0) {
            $totalKg = $this->capacidadService->convertirAKg((float) $insumo->stock, $insumo->unidadMedida);
        }

        $empaque = match (true) {
            $empaquesRegistrados === 1 => $nombresEmpaque[0] ?? '—',
            $empaquesRegistrados > 1 => $empaquesRegistrados.' presentaciones',
            default => '—',
        };

        $unidadEtiqueta = count(array_unique($etiquetasUnidad)) === 1
            ? ($etiquetasUnidad[0] ?? 'empaques')
            : 'empaques';

        $refTraslado = AlmacenMovimiento::query()
            ->where('almacenid', $almacen->almacenid)
            ->where('insumoid', $insumo->insumoid)
            ->where('referencia', 'like', 'TPM-%')
            ->orderByDesc('fecha')
            ->value('referencia');

        $descripcionVisible = trim((string) $insumo->descripcion);
        if ($refTraslado && ! str_contains($descripcionVisible, (string) $refTraslado)) {
            $marca = 'Producto recibido desde planta — '.$refTraslado;
            $descripcionVisible = $descripcionVisible === ''
                || str_contains(mb_strtolower($descripcionVisible), 'producto terminado de planta')
                ? $marca
                : $descripcionVisible.' | '.$marca;
        }

        $lotesRefs = InventarioPresentacionLote::query()
            ->where('almacenid', $almacen->almacenid)
            ->where('insumoid', $insumo->insumoid)
            ->pluck('referencia_lote')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $fechaMovimiento = AlmacenMovimiento::query()
            ->where('almacenid', $almacen->almacenid)
            ->where('insumoid', $insumo->insumoid)
            ->max('fecha');

        $fechaOrden = $fechaMovimiento
            ? strtotime((string) $fechaMovimiento)
            : ($insumo->updated_at?->timestamp ?? 0);

        return (object) [
            'categoria' => 'producto_terminado',
            'tipo_label' => 'Producto procesado',
            'tipo_filtro' => 'producto procesado',
            'nombre' => $insumo->nombre,
            'detalle' => $sinStock
                ? 'Sin stock en almacén'
                : ($descripcionVisible !== '' ? \Illuminate\Support\Str::limit($descripcionVisible, 60) : '—'),
            'cantidad' => $totalUnidades > 0 ? $totalUnidades : 0.0,
            'unidad' => $totalUnidades > 0 || $empaquesRegistrados > 0
                ? ($unidadEtiqueta ?? 'empaques')
                : ($insumo->unidadMedida?->abreviatura ?? 'kg'),
            'kg' => $totalKg,
            'empaque' => $empaque,
            'sin_stock' => $sinStock,
            'fecha_orden' => $fechaOrden,
            'search' => strtolower(trim($insumo->nombre.' '.$descripcionVisible.' '.implode(' ', $nombresEmpaque).' '.implode(' ', $lotesRefs).' producto procesado')),
            'insumoid' => $insumo->insumoid,
            'origen_tipo' => 'insumo',
        ];
    }

    private function insumoTieneHistorialMayorista(Almacen $almacen, Insumo $insumo): bool
    {
        if (InventarioPresentacionLote::query()
            ->where('almacenid', $almacen->almacenid)
            ->where('insumoid', $insumo->insumoid)
            ->exists()) {
            return true;
        }

        return AlmacenMovimiento::query()
            ->where('almacenid', $almacen->almacenid)
            ->where('insumoid', $insumo->insumoid)
            ->exists();
    }

    private function queryAlmacenesVisibles(Request $request, string $ambito)
    {
        $query = AlmacenAmbito::scope(Almacen::query(), $ambito);
        $user = $request->user();

        if (! $user || UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        if ($ambito === AlmacenAmbito::MAYORISTA) {
            return MayoristaAccess::scopeAlmacenesMayorista($query, $user);
        }

        if (
            $ambito === AlmacenAmbito::AGRICOLA
            && UsuarioRol::esJefeAgricultor($user)
            && Schema::hasColumn('almacen', 'responsable_usuarioid')
        ) {
            return $query->where('responsable_usuarioid', (int) $user->usuarioid);
        }

        return $query;
    }

    /** @return list<array<string, mixed>> */
    private function almacenesParaMapaIndex(Request $request, string $ambito, string $rutaPrefijo): array
    {
        $almacenes = $this->queryAlmacenesVisibles($request, $ambito)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        $items = [];

        foreach ($almacenes as $almacen) {
            $resuelto = UbicacionGpsParser::resolverAlmacen(
                (int) $almacen->almacenid,
                $almacen->nombre,
                $almacen->ubicacion
            );

            $nombre = (string) $almacen->nombre;
            $items[] = [
                'id' => (int) $almacen->almacenid,
                'nombre' => $nombre,
                'lat' => $resuelto['lat'],
                'lng' => $resuelto['lng'],
                'direccion' => $resuelto['direccion'],
                'search' => mb_strtolower($nombre.' '.($resuelto['direccion'] ?? '')),
                'url' => route($rutaPrefijo.'.show', $almacen),
            ];
        }

        return $items;
    }

}

