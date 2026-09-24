<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CatalogoTamanoConteo;
use App\Models\Insumo;
use App\Models\TipoEmpaque;
use App\Support\InsumoCatalogo;
use App\Support\InsumoImagenCatalogo;
use App\Support\TipoEmpaqueAmbito;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InsumoController extends Controller
{
    public function index()
    {
        InsumoCatalogo::asegurarInsumosCampo();

        $umbral = InsumoCatalogo::UMBRAL_ALERTA_STOCK;
        $user = auth()->user();
        $q = InsumoCatalogo::aplicarFiltroOperativo(
            Insumo::with(['tipo', 'unidadMedida', 'almacen'])
        )->orderBy('insumoid', 'desc');

        // AGR-04: el jefe/operario ve existencias de su(s) almacén(es) agrícola(s), no legacy null.
        if (
            $user
            && ! \App\Support\UsuarioRol::esAdminGlobal($user)
            && Schema::hasColumn('insumo', 'almacenid')
        ) {
            $almacenIds = \App\Support\CampoAccess::scopeAlmacenesAgricolas(
                \App\Models\Almacen::query()->where('activo', true),
                $user
            )->pluck('almacenid')->map(fn ($id) => (int) $id)->all();

            if ($almacenIds === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('almacenid', $almacenIds);
            }
        } elseif (Schema::hasColumn('insumo', 'almacenid')) {
            $q->whereNotNull('almacenid');
        }

        $stats = [
            'total' => (clone $q)->count(),
            'stock_bajo' => (clone $q)->where('stock', '<=', $umbral)->count(),
            'categorias' => (clone $q)->distinct()->count('tipoinsumoid'),
            'en_alerta' => (clone $q)->where('stock', '<=', $umbral)->count(),
        ];

        $insumos = $q->paginate(15);

        $tiposFiltro = InsumoCatalogo::tiposOrdenados();

        return view('insumos.index', compact('insumos', 'stats', 'umbral', 'tiposFiltro'));
    }

    public function create()
    {
        InsumoCatalogo::asegurarCatalogosBase();

        return view('insumos.create', [
            'tipos' => InsumoCatalogo::tiposOrdenados(),
            'unidadesPorTipo' => InsumoCatalogo::unidadesPorTipoParaJs(),
            'tiposEmpaque' => $this->tiposEmpaqueAgricola(),
            'calibre' => null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validarInsumo($request);
        $calibreData = $data['_calibre'] ?? null;
        unset($data['_calibre']);

        $data['stockminimo'] = InsumoCatalogo::UMBRAL_ALERTA_STOCK;
        $data = $this->aplicarImagenInsumo($request, $data);

        if (Schema::hasColumn('insumo', 'almacenid')) {
            $data['almacenid'] = $this->resolverAlmacenAgricolaParaNuevoInsumo($request);
        }

        $insumo = Insumo::create($data);
        $this->guardarCalibreSiembra($insumo, $calibreData);

        return redirect()->route('insumos.index')->with('success', 'Insumo registrado correctamente.');
    }

    public function show(Insumo $insumo)
    {
        $this->asegurarInsumoDelAlmacenUsuario($insumo);
        InsumoCatalogo::asegurarInsumoOperativo($insumo);
        $insumo->load(['tipo', 'unidadMedida']);

        return view('insumos.show', [
            'insumo' => $insumo,
            'umbral' => InsumoCatalogo::UMBRAL_ALERTA_STOCK,
        ]);
    }

    public function edit(Insumo $insumo)
    {
        $this->asegurarInsumoDelAlmacenUsuario($insumo);
        InsumoCatalogo::asegurarInsumoOperativo($insumo);
        InsumoCatalogo::asegurarCatalogosBase();
        $insumo->load(['tipo', 'unidadMedida']);

        return view('insumos.edit', [
            'insumo' => $insumo,
            'tipos' => InsumoCatalogo::tiposOrdenados(),
            'unidadesPorTipo' => InsumoCatalogo::unidadesPorTipoParaJs(),
            'tiposEmpaque' => $this->tiposEmpaqueAgricola(),
            'calibre' => $this->calibrePrincipal($insumo),
        ]);
    }

    public function update(Request $request, Insumo $insumo)
    {
        $this->asegurarInsumoDelAlmacenUsuario($insumo);
        InsumoCatalogo::asegurarInsumoOperativo($insumo);

        $data = $this->validarInsumo($request);
        $calibreData = $data['_calibre'] ?? null;
        unset($data['_calibre']);

        $data['stockminimo'] = InsumoCatalogo::UMBRAL_ALERTA_STOCK;
        $data = $this->aplicarImagenInsumo($request, $data, $insumo);

        // AGR-04: la existencia no puede reasignarse a otro almacén por request manipulado.
        unset($data['almacenid']);

        $insumo->update($data);
        $this->guardarCalibreSiembra($insumo->fresh(), $calibreData);

        return redirect()->route('insumos.index')->with('success', 'Insumo actualizado.');
    }

    public function destroy(Insumo $insumo)
    {
        $this->asegurarInsumoDelAlmacenUsuario($insumo);
        InsumoCatalogo::asegurarInsumoOperativo($insumo);

        $this->eliminarImagenSubida($insumo->imagenurl);
        $insumo->delete();

        return redirect()->route('insumos.index')->with('success', 'Insumo eliminado.');
    }

    private function validarInsumo(Request $request): array
    {
        InsumoCatalogo::asegurarCatalogosBase();
        $tiposIds = InsumoCatalogo::tiposOrdenados()->pluck('tipoinsumoid')->all();

        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'tipoinsumoid' => ['required', Rule::in($tiposIds)],
            'unidadmedidaid' => 'required|exists:unidadmedida,unidadmedidaid',
            'stock' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'descripcion' => 'nullable|string',
            'dosis_por_ha' => 'nullable|numeric|min:0',
            'dosis_unidad' => 'nullable|string|max:20',
            'semillas_por_kg' => 'nullable|numeric|min:0',
            'imagen' => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:4096',
            'quitar_imagen' => 'nullable|boolean',
            'calibre_nombre' => 'nullable|string|max:150',
            'calibre_conteo_por_empaque' => 'nullable|integer|min:1',
            'calibre_peso_promedio_kg' => 'nullable|numeric|min:0.0001',
            'calibre_tipoempaqueid' => 'nullable|exists:tipo_empaque,tipoempaqueid',
        ]);

        $tipo = InsumoCatalogo::tiposOrdenados()->firstWhere('tipoinsumoid', (int) $data['tipoinsumoid']);
        $slug = InsumoCatalogo::slugFromNombreTipo($tipo?->nombre);
        $permitidas = collect(InsumoCatalogo::unidadesPorTipoParaJs()[$slug] ?? [])->pluck('id')->all();

        if ($permitidas !== [] && ! in_array((int) $data['unidadmedidaid'], $permitidas, true)) {
            throw ValidationException::withMessages([
                'unidadmedidaid' => 'La unidad no corresponde al tipo de insumo seleccionado.',
            ]);
        }

        if ($slug !== 'material_siembra') {
            $data['semillas_por_kg'] = null;
            $data['_calibre'] = null;
        } else {
            $calibreRules = $request->validate([
                'calibre_nombre' => 'required|string|max:150',
                'calibre_conteo_por_empaque' => 'required|integer|min:1',
                'calibre_peso_promedio_kg' => 'required|numeric|min:0.0001',
                'calibre_tipoempaqueid' => 'nullable|exists:tipo_empaque,tipoempaqueid',
            ], [
                'calibre_nombre.required' => 'Indique el nombre del calibre de cosecha (obligatorio para material de siembra).',
                'calibre_conteo_por_empaque.required' => 'Indique cuántas unidades caben por empaque.',
                'calibre_peso_promedio_kg.required' => 'Indique el peso promedio por unidad (kg).',
            ]);

            $data['_calibre'] = [
                'nombre' => trim((string) $calibreRules['calibre_nombre']),
                'conteo_por_empaque' => (int) $calibreRules['calibre_conteo_por_empaque'],
                'peso_promedio_kg' => (float) $calibreRules['calibre_peso_promedio_kg'],
                'tipoempaqueid' => ! empty($calibreRules['calibre_tipoempaqueid'])
                    ? (int) $calibreRules['calibre_tipoempaqueid']
                    : null,
            ];
        }

        $um = \App\Models\UnidadMedida::find((int) $data['unidadmedidaid']);
        $data['dosis_unidad'] = $um
            ? InsumoCatalogo::normalizarDosisUnidad($um->abreviatura, $slug)
            : null;

        unset(
            $data['imagen'],
            $data['quitar_imagen'],
            $data['calibre_nombre'],
            $data['calibre_conteo_por_empaque'],
            $data['calibre_peso_promedio_kg'],
            $data['calibre_tipoempaqueid'],
        );

        $data['stock'] = max(0.0, (float) $data['stock']);

        return $data;
    }

    /** @param  array{nombre: string, conteo_por_empaque: int, peso_promedio_kg: float, tipoempaqueid: int|null}|null  $calibre */
    private function guardarCalibreSiembra(Insumo $insumo, ?array $calibre): void
    {
        if ($calibre === null || ! Schema::hasTable('catalogo_tamano_conteo')) {
            return;
        }

        $existente = $this->calibrePrincipal($insumo);

        $payload = [
            'insumoid' => (int) $insumo->insumoid,
            'nombre' => $calibre['nombre'],
            'conteo_por_empaque' => $calibre['conteo_por_empaque'],
            'peso_promedio_kg' => $calibre['peso_promedio_kg'],
            'tipoempaqueid' => $calibre['tipoempaqueid'],
            'activo' => true,
        ];

        if ($existente) {
            $existente->update($payload);
        } else {
            CatalogoTamanoConteo::query()->create($payload);
        }
    }

    private function calibrePrincipal(Insumo $insumo): ?CatalogoTamanoConteo
    {
        if (! Schema::hasTable('catalogo_tamano_conteo')) {
            return null;
        }

        return CatalogoTamanoConteo::query()
            ->where('insumoid', $insumo->insumoid)
            ->where('activo', true)
            ->orderBy('catalogotamanoconteoid')
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function tiposEmpaqueAgricola()
    {
        if (! Schema::hasTable('tipo_empaque')) {
            return collect();
        }

        return TipoEmpaque::query()
            ->where('activo', true)
            ->tap(fn ($q) => TipoEmpaqueAmbito::scopeAgricola($q))
            ->orderBy('nombre')
            ->pluck('nombre', 'tipoempaqueid');
    }

    /** @param  array<string, mixed>  $data */
    private function aplicarImagenInsumo(Request $request, array $data, ?Insumo $insumo = null): array
    {
        $tipo = InsumoCatalogo::tiposOrdenados()->firstWhere('tipoinsumoid', (int) $data['tipoinsumoid']);
        $slug = InsumoCatalogo::slugFromNombreTipo($tipo?->nombre);

        if ($request->boolean('quitar_imagen')) {
            $this->eliminarImagenSubida($insumo?->imagenurl);
            $data['imagenurl'] = InsumoImagenCatalogo::urlPorNombreYTipo($data['nombre'], $slug);

            return $data;
        }

        if ($request->hasFile('imagen')) {
            $this->eliminarImagenSubida($insumo?->imagenurl);
            $data['imagenurl'] = $request->file('imagen')->store('insumos', 'public');

            return $data;
        }

        if ($insumo === null) {
            $data['imagenurl'] = InsumoImagenCatalogo::urlPorNombreYTipo($data['nombre'], $slug);
        }

        return $data;
    }

    private function eliminarImagenSubida(?string $imagenurl): void
    {
        $ruta = InsumoImagenCatalogo::rutaAlmacenamiento($imagenurl);
        if ($ruta !== null && Storage::disk('public')->exists($ruta)) {
            Storage::disk('public')->delete($ruta);
        }
    }

    private function asegurarInsumoDelAlmacenUsuario(Insumo $insumo): void
    {
        $user = auth()->user();
        if (! $user || \App\Support\UsuarioRol::esAdminGlobal($user)) {
            return;
        }

        if (! Schema::hasColumn('insumo', 'almacenid')) {
            return;
        }

        // Legacy sin almacén: visible solo lectura admin; resto bloqueado.
        if ($insumo->almacenid === null) {
            abort(403, 'Este registro legacy no está ligado a un almacén agrícola operativo.');
        }

        $almacen = \App\Models\Almacen::query()->find((int) $insumo->almacenid);
        if (! $almacen || ! \App\Support\CampoAccess::puedeVerAlmacen($user, $almacen)) {
            abort(403, 'No tiene acceso a este insumo de almacén.');
        }
    }

    private function resolverAlmacenAgricolaParaNuevoInsumo(Request $request): int
    {
        $user = $request->user();
        if (! $user) {
            throw ValidationException::withMessages([
                'stock' => 'Debe iniciar sesión para registrar existencias agrícolas.',
            ]);
        }

        // Admin puede indicar almacén agrícola explícito; jefes/operarios NUNCA
        // usan un almacenid del request (evita inyectar existencia en almacén ajeno).
        if (\App\Support\UsuarioRol::esAdminGlobal($user)) {
            $almacenId = (int) $request->input('almacenid', 0);
            if ($almacenId > 0) {
                $alm = \App\Models\Almacen::query()->findOrFail($almacenId);
                if (($alm->ambito ?? '') !== \App\Support\AlmacenAmbito::AGRICOLA) {
                    throw ValidationException::withMessages([
                        'almacenid' => 'Debe indicar un almacén agrícola.',
                    ]);
                }

                return (int) $alm->almacenid;
            }
        }

        $jefe = \App\Support\UsuarioRol::esJefeAgricultor($user)
            ? $user
            : (
                $user->supervisor_usuarioid
                    ? \App\Models\Usuario::query()->find((int) $user->supervisor_usuarioid)
                    : null
            );

        if (! $jefe || ! \App\Support\UsuarioRol::esJefeAgricultor($jefe)) {
            throw ValidationException::withMessages([
                'stock' => 'Solo el jefe agrícola (o su equipo) puede registrar existencias en su almacén.',
            ]);
        }

        try {
            $almacen = \App\Support\CampoAccess::almacenAgricolaOperativoDeJefe($jefe);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'stock' => $e->getMessage(),
            ]);
        }

        if ($almacen === null) {
            throw ValidationException::withMessages([
                'stock' => 'Cree primero el almacén agrícola operativo del jefe antes de registrar insumos.',
            ]);
        }

        return (int) $almacen->almacenid;
    }
}
