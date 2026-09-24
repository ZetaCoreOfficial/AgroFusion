<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DestinoProduccion;
use App\Models\MaquinaPlanta;
use App\Models\MaquinaVariablePlanta;
use App\Models\ProcesoPlanta;
use App\Models\VariableEstandar;
use App\Support\CatalogoTecnicoPlantaAcceso;
use App\Support\MaquinaPlantaCodigo;
use App\Support\ParametroRangoPlanta;
use App\Support\PlantillaTransformacionDisponibilidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MaquinaPlantaController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            $method = $request->route()?->getActionMethod();

            if ($method === 'variablesSugeridas') {
                abort_unless(CatalogoTecnicoPlantaAcceso::puedeLeerAuxiliar($user), 403);
            } elseif (in_array($method, ['store', 'update', 'destroy', 'toggleActivo', 'create', 'edit'], true)) {
                abort_unless(CatalogoTecnicoPlantaAcceso::puedeGestionar($user), 403);
            } else {
                abort_unless(CatalogoTecnicoPlantaAcceso::puedeAdministrar($user), 403);
            }

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $query = $this->filteredQuery($request);

        $stats = [
            'total' => MaquinaPlanta::count(),
            'activas' => MaquinaPlanta::where('activo', true)->count(),
            'con_codigo' => MaquinaPlanta::whereNotNull('codigo')->where('codigo', '!=', '')->count(),
        ];

        $maquinas = $query->orderBy('maquinaplantaid', 'desc')->paginate(15)->withQueryString();

        return view('maquinas_planta.index', compact('maquinas', 'stats'));
    }

    public function create(): View
    {
        return view('maquinas_planta.create', [
            'variablesCatalogo' => VariableEstandar::where('activo', true)->orderBy('nombre')->get(),
        ]);
    }

    public function show(Request $request, MaquinaPlanta $maquinas_plantum): View
    {
        $maquina = $maquinas_plantum;
        $maquina->loadCount('producciones');

        $query = $maquina->producciones()
            ->with(['lote.cultivo', 'unidadMedida', 'destino', 'procesoPlanta']);

        if ($request->filled('buscar')) {
            $buscar = '%'.trim((string) $request->buscar).'%';
            $query->where(function ($q) use ($buscar) {
                $q->whereHas('lote', fn ($lq) => $lq->where('nombre', 'like', $buscar))
                    ->orWhereHas('procesoPlanta', fn ($pq) => $pq->where('nombre', 'like', $buscar));
            });
        }

        if ($request->filled('proceso')) {
            $query->where('procesoplantaid', (int) $request->proceso);
        }

        if ($request->filled('destino')) {
            $query->where('destinoproduccionid', (int) $request->destino);
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fechacosecha', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fechacosecha', '<=', $request->fecha_hasta);
        }

        $producciones = $query->orderByDesc('produccionid')->paginate(15)->withQueryString();

        $idsProceso = $maquina->producciones()->whereNotNull('procesoplantaid')->distinct()->pluck('procesoplantaid');
        $idsDestino = $maquina->producciones()->whereNotNull('destinoproduccionid')->distinct()->pluck('destinoproduccionid');

        $procesosFiltro = ProcesoPlanta::whereIn('procesoplantaid', $idsProceso)->orderBy('nombre')->get(['procesoplantaid', 'nombre']);
        $destinosFiltro = DestinoProduccion::whereIn('destinoproduccionid', $idsDestino)->orderBy('nombre')->get(['destinoproduccionid', 'nombre']);

        $maquina->load(['variablesSugeridas.variableEstandar']);

        return view('maquinas_planta.show', compact('maquina', 'producciones', 'procesosFiltro', 'destinosFiltro'));
    }

    public function edit(MaquinaPlanta $maquinas_plantum): View
    {
        $maquinas_plantum->load(['variablesSugeridas.variableEstandar']);

        return view('maquinas_planta.edit', [
            'maquina' => $maquinas_plantum,
            'variablesCatalogo' => VariableEstandar::where('activo', true)->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'codigo' => [
                'nullable', 'string', 'max:60',
                Rule::unique('maquina_planta', 'codigo'),
            ],
            'descripcion' => 'nullable|string',
            'activo' => 'nullable|boolean',
            'imagen' => 'nullable|file|mimes:jpeg,jpg,png,webp,gif|max:4096',
        ]);
        $data['activo'] = $request->boolean('activo', true);
        $data['codigo'] = MaquinaPlantaCodigo::resolverParaGuardar(
            $data['nombre'],
            $data['codigo'] ?? null
        );
        if ($request->hasFile('imagen')) {
            $data['imagenurl'] = $this->procesarImagen($request) ?? $data['imagenurl'] ?? null;
        }

        $this->validarVariablesSugeridasEntrada($request);

        $maquina = MaquinaPlanta::create($data);
        $this->syncVariablesSugeridas($maquina, $request->input('variables_sugeridas', []));

        return redirect()
            ->route('maquinas-planta.show', $maquina)
            ->with('success', 'M?quina registrada correctamente.');
    }

    public function update(Request $request, MaquinaPlanta $maquinas_plantum): RedirectResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'codigo' => [
                'nullable', 'string', 'max:60',
                Rule::unique('maquina_planta', 'codigo')->ignore($maquinas_plantum->maquinaplantaid, 'maquinaplantaid'),
            ],
            'descripcion' => 'nullable|string',
            'activo' => 'nullable|boolean',
            'imagen' => 'nullable|file|mimes:jpeg,jpg,png,webp,gif|max:4096',
            'quitar_imagen' => 'nullable|boolean',
        ]);
        $data['activo'] = $request->boolean('activo');
        $data['codigo'] = MaquinaPlantaCodigo::resolverParaGuardar(
            $data['nombre'],
            $data['codigo'] ?? null,
            (int) $maquinas_plantum->maquinaplantaid
        );

        if ($request->boolean('quitar_imagen')) {
            $this->eliminarImagen($maquinas_plantum->imagenurl);
            $data['imagenurl'] = null;
        } elseif ($request->hasFile('imagen')) {
            $nueva = $this->procesarImagen($request, $maquinas_plantum->imagenurl);
            if ($nueva) {
                $data['imagenurl'] = $nueva;
            }
        }

        unset($data['imagen'], $data['quitar_imagen']);
        $this->validarVariablesSugeridasEntrada($request);
        $maquinas_plantum->update($data);
        $this->syncVariablesSugeridas($maquinas_plantum, $request->input('variables_sugeridas', []));

        return redirect()
            ->route('maquinas-planta.show', $maquinas_plantum)
            ->with('success', 'M?quina actualizada.');
    }

    public function destroy(MaquinaPlanta $maquinas_plantum): RedirectResponse
    {
        $this->eliminarImagen($maquinas_plantum->imagenurl);
        $maquinas_plantum->delete();

        return redirect()->route('maquinas-planta.index')->with('success', 'M?quina eliminada.');
    }

    public function toggleActivo(MaquinaPlanta $maquinas_plantum): RedirectResponse
    {
        $bloqueadasAntes = PlantillaTransformacionDisponibilidad::idsBloqueadas();

        $maquinas_plantum->update(['activo' => ! $maquinas_plantum->activo]);

        $mensaje = $maquinas_plantum->activo
            ? 'La m?quina qued? activa y disponible en registro.'
            : 'La m?quina qued? en mantenimiento.';

        if (! $maquinas_plantum->activo) {
            $nuevasBloqueadas = count(array_diff(
                PlantillaTransformacionDisponibilidad::idsBloqueadas(),
                $bloqueadasAntes
            ));
            if ($nuevasBloqueadas > 0) {
                $mensaje .= ' '.$nuevasBloqueadas.' proceso(s) de transformaci?n quedaron temporalmente no disponibles.';
            }
        } else {
            $mensaje .= ' Los procesos de transformaci?n vinculados se reevaluar?n autom?ticamente.';
        }

        return back()->with('success', $mensaje);
    }

    public function variablesSugeridas(MaquinaPlanta $maquinas_plantum): JsonResponse
    {
        $items = $maquinas_plantum->variablesSugeridas()
            ->with('variableEstandar')
            ->get()
            ->map(fn (MaquinaVariablePlanta $v) => [
                'variableestandarid' => (int) $v->variableestandarid,
                'nombre' => $v->variableEstandar?->nombre,
                'unidad' => $v->variableEstandar?->unidad,
                'valor_minimo' => $v->valor_minimo,
                'valor_maximo' => $v->valor_maximo,
                'valor_objetivo' => $v->valor_objetivo,
                'obligatorio' => (bool) $v->obligatorio,
            ])
            ->values();

        return response()->json([
            'maquinaplantaid' => (int) $maquinas_plantum->maquinaplantaid,
            'nombre' => $maquinas_plantum->nombre,
            'imagen_src' => $maquinas_plantum->imagenSrc(),
            'variables' => $items,
        ]);
    }

    /** @param  list<array<string, mixed>>|null  $filas */
    private function syncVariablesSugeridas(MaquinaPlanta $maquina, ?array $filas): void
    {
        if (! Schema::hasTable('maquina_variable_planta')) {
            return;
        }

        $maquina->variablesSugeridas()->delete();
        $filas = $filas ?? [];
        $vistos = [];

        foreach ($filas as $fila) {
            $varId = (int) ($fila['variableestandarid'] ?? 0);
            if ($varId <= 0 || isset($vistos[$varId])) {
                continue;
            }
            $vistos[$varId] = true;

            $min = (float) ($fila['valor_minimo'] ?? 0);
            $max = (float) ($fila['valor_maximo'] ?? 0);
            if ($max < $min) {
                continue;
            }

            MaquinaVariablePlanta::create([
                'maquinaplantaid' => $maquina->maquinaplantaid,
                'variableestandarid' => $varId,
                'valor_minimo' => $min,
                'valor_maximo' => $max,
                'valor_objetivo' => isset($fila['valor_objetivo']) && $fila['valor_objetivo'] !== ''
                    ? (float) $fila['valor_objetivo'] : null,
                'obligatorio' => filter_var($fila['obligatorio'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    private function validarVariablesSugeridasEntrada(Request $request): void
    {
        $request->validate([
            'variables_sugeridas' => ['nullable', 'array'],
            'variables_sugeridas.*.variableestandarid' => ['required', 'integer', 'exists:variable_estandar,variableestandarid'],
            'variables_sugeridas.*.valor_minimo' => ['required', 'numeric'],
            'variables_sugeridas.*.valor_maximo' => ['required', 'numeric'],
            'variables_sugeridas.*.valor_objetivo' => ['nullable', 'numeric'],
            'variables_sugeridas.*.obligatorio' => ['nullable'],
        ]);

        $filas = $request->input('variables_sugeridas', []) ?? [];
        $nombres = VariableEstandar::query()
            ->whereIn('variableestandarid', collect($filas)->pluck('variableestandarid')->filter()->all())
            ->pluck('nombre', 'variableestandarid');

        foreach ($filas as $fila) {
            $varId = (int) ($fila['variableestandarid'] ?? 0);
            if ($varId <= 0) {
                continue;
            }

            $error = ParametroRangoPlanta::validarRango(
                null,
                $varId,
                (float) ($fila['valor_minimo'] ?? 0),
                (float) ($fila['valor_maximo'] ?? 0),
                $nombres[$varId] ?? null,
            );

            if ($error !== null) {
                throw ValidationException::withMessages(['variables_sugeridas' => $error]);
            }
        }
    }

    private function filteredQuery(Request $request)
    {
        $query = MaquinaPlanta::query();

        if ($request->filled('estado')) {
            if ($request->estado === 'activa') {
                $query->where('activo', true);
            } elseif (in_array($request->estado, ['inactiva', 'mantenimiento'], true)) {
                $query->where('activo', false);
            }
        }

        if ($request->filled('buscar')) {
            $buscar = '%'.trim((string) $request->buscar).'%';
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre', 'like', $buscar)
                    ->orWhere('codigo', 'like', $buscar)
                    ->orWhere('descripcion', 'like', $buscar);
            });
        }

        return $query;
    }

    private function procesarImagen(Request $request, ?string $imagenAnterior = null): ?string
    {
        if (! $request->hasFile('imagen')) {
            return $imagenAnterior;
        }

        $file = $request->file('imagen');
        $nombre = 'maquina_'.uniqid('', true).'.'.$file->getClientOriginalExtension();

        $this->eliminarImagen($imagenAnterior);

        $ruta = $file->storeAs('maquinas_planta', $nombre, 'public');
        if ($ruta === false) {
            Log::error('No se pudo guardar la imagen de m?quina en disco p?blico.');

            return $imagenAnterior;
        }

        return $ruta;
    }

    private function eliminarImagen(?string $imagenurl): void
    {
        if (! $imagenurl) {
            return;
        }

        $rel = $imagenurl;
        if (str_contains($rel, '/storage/')) {
            $rel = ltrim(str_replace('/storage/', '', parse_url($rel, PHP_URL_PATH) ?? ''), '/');
        } elseif (str_starts_with($rel, 'storage/')) {
            $rel = substr($rel, 8);
        }

        if ($rel !== '' && ! str_contains($rel, '://')) {
            Storage::disk('public')->delete($rel);
        }
    }
}
