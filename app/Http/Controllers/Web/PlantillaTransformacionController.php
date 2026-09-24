<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PlantillaTransformacion;
use App\Models\PlantillaTransformacionPasoVariable;
use App\Models\VariableEstandar;
use App\Support\CatalogoTecnicoPlantaAcceso;
use App\Support\ParametroRangoPlanta;
use App\Support\ProcesoPlantaCatalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PlantillaTransformacionController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            $method = $request->route()?->getActionMethod();

            if ($method === 'parametrosJson') {
                abort_unless(CatalogoTecnicoPlantaAcceso::puedeLeerAuxiliar($user), 403);
            } elseif (in_array($method, ['store', 'update', 'destroy', 'create', 'edit'], true)) {
                abort_unless(CatalogoTecnicoPlantaAcceso::puedeGestionar($user), 403);
            } else {
                abort_unless(CatalogoTecnicoPlantaAcceso::puedeAdministrar($user), 403);
            }

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $query = PlantillaTransformacion::query()
            ->withCount('pasos')
            ->with(['pasos.maquina']);

        if ($request->filled('buscar')) {
            $buscar = '%'.trim((string) $request->buscar).'%';
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre', 'like', $buscar)
                    ->orWhere('producto_ejemplo', 'like', $buscar)
                    ->orWhere('descripcion', 'like', $buscar);
            });
        }

        if ($request->filled('estado')) {
            match ($request->estado) {
                'activo' => $query->operativas(),
                'mantenimiento' => $query->bloqueadasPorMantenimiento(),
                default => null,
            };
        }

        $stats = [
            'total' => PlantillaTransformacion::count(),
            'activas' => PlantillaTransformacion::query()->operativas()->count(),
            'inactivas' => PlantillaTransformacion::count() - PlantillaTransformacion::query()->operativas()->count(),
        ];

        $plantillas = $query->orderBy('nombre')->paginate(15)->withQueryString();

        return view('plantillas_transformacion.index', compact('plantillas', 'stats'));
    }

    public function create(): View
    {
        return view('plantillas_transformacion.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validar($request);
        $plantilla = PlantillaTransformacion::create($data);
        $this->syncPasos($plantilla, $request->input('pasos', []));

        return redirect()
            ->route('plantillas-transformacion.show', $plantilla)
            ->with('success', 'Plantilla de transformación creada.');
    }

    public function show(PlantillaTransformacion $plantillas_transformacion): View
    {
        $plantilla = $plantillas_transformacion;
        $plantilla->load(['pasos.proceso', 'pasos.maquina', 'pasos.variables.variableEstandar']);

        return view('plantillas_transformacion.show', compact('plantilla'));
    }

    public function edit(PlantillaTransformacion $plantillas_transformacion): View
    {
        $plantilla = $plantillas_transformacion;
        $plantilla->load(['pasos.proceso', 'pasos.maquina', 'pasos.variables.variableEstandar']);

        return view('plantillas_transformacion.edit', array_merge($this->formData(), compact('plantilla')));
    }

    public function update(Request $request, PlantillaTransformacion $plantillas_transformacion): RedirectResponse
    {
        $data = $this->validar($request);
        $plantillas_transformacion->update($data);
        $this->syncPasos($plantillas_transformacion, $request->input('pasos', []));

        return redirect()
            ->route('plantillas-transformacion.show', $plantillas_transformacion)
            ->with('success', 'Plantilla actualizada.');
    }

    public function destroy(PlantillaTransformacion $plantillas_transformacion): RedirectResponse
    {
        if ($plantillas_transformacion->lotes()->exists()) {
            return back()->with('error', 'No se puede eliminar: hay lotes que usan esta plantilla.');
        }

        $plantillas_transformacion->delete();

        return redirect()
            ->route('plantillas-transformacion.index')
            ->with('success', 'Plantilla eliminada.');
    }

    public function parametrosJson(PlantillaTransformacion $plantillas_transformacion): JsonResponse
    {
        $pasos = app(\App\Support\LoteProduccionParametrosService::class)
            ->parametrosJsonPlantilla($plantillas_transformacion);

        return response()->json([
            'id' => $plantillas_transformacion->plantillatransformacionid,
            'nombre' => $plantillas_transformacion->nombre,
            'pasos' => $pasos,
        ]);
    }

    /** @return array{procesos: \Illuminate\Support\Collection, mapaMaquinasProceso: array<int, list<array{id: int, nombre: string, codigo: ?string, activo: bool, imagen_src: ?string}>>, procesoCierreId: ?int, variablesCatalogo: \Illuminate\Support\Collection, urlVariablesMaquina: string} */
    private function formData(): array
    {
        return [
            'procesos' => ProcesoPlantaCatalogo::paraTransformacion(),
            'mapaMaquinasProceso' => \App\Support\MaquinaProcesoCompatibilidad::mapaMaquinasFormulario(),
            'procesoCierreId' => ProcesoPlantaCatalogo::idProcesoCierreTransformacion(),
            'variablesCatalogo' => VariableEstandar::where('activo', true)->orderBy('nombre')->get(),
            'urlVariablesMaquina' => url('/maquinas-planta/__ID__/variables-sugeridas'),
        ];
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
            'activo' => ['nullable', 'boolean'],
            'pasos' => ['required', 'array', 'min:1'],
            'pasos.*.procesoplantaid' => ['required', 'integer', 'exists:proceso_planta,procesoplantaid'],
            'pasos.*.maquinaplantaid' => ['nullable', 'integer', 'exists:maquina_planta,maquinaplantaid'],
            'pasos.*.notas' => ['nullable', 'string', 'max:255'],
            'pasos.*.variables' => ['nullable', 'array'],
            'pasos.*.variables.*.variableestandarid' => ['required', 'integer', 'exists:variable_estandar,variableestandarid'],
            'pasos.*.variables.*.valor_minimo' => ['required', 'numeric'],
            'pasos.*.variables.*.valor_maximo' => ['required', 'numeric'],
            'pasos.*.variables.*.obligatorio' => ['nullable'],
        ]);

        $errorCierre = ProcesoPlantaCatalogo::errorSiUltimoPasoNoEsEmpaquetado($request->input('pasos', []));
        if ($errorCierre !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pasos' => [$errorCierre],
            ]);
        }

        $this->validarRangosPasos($request->input('pasos', []));

        return [
            'nombre' => $request->input('nombre'),
            'descripcion' => $request->input('descripcion'),
            'producto_ejemplo' => null,
            'palabras_clave' => null,
            'activo' => true,
        ];
    }

    /** @param  list<array{procesoplantaid?: mixed, maquinaplantaid?: mixed, notas?: mixed, variables?: list<array<string, mixed>>}>  $pasos */
    private function syncPasos(PlantillaTransformacion $plantilla, array $pasos): void
    {
        $plantilla->pasos()->each(function ($paso) {
            $paso->variables()->delete();
        });
        $plantilla->pasos()->delete();
        $orden = 1;

        foreach ($pasos as $paso) {
            if (empty($paso['procesoplantaid'])) {
                continue;
            }

            $creado = $plantilla->pasos()->create([
                'orden' => $orden++,
                'procesoplantaid' => (int) $paso['procesoplantaid'],
                'maquinaplantaid' => ! empty($paso['maquinaplantaid']) ? (int) $paso['maquinaplantaid'] : null,
                'notas' => $paso['notas'] ?? null,
            ]);

            $this->syncVariablesPaso($creado, $paso['variables'] ?? []);
        }
    }

    /** @param  list<array<string, mixed>>  $variables */
    private function syncVariablesPaso(\App\Models\PlantillaTransformacionPaso $paso, array $variables): void
    {
        if (! Schema::hasTable('plantilla_transformacion_paso_variable')) {
            return;
        }

        $vistos = [];
        foreach ($variables as $var) {
            $varId = (int) ($var['variableestandarid'] ?? 0);
            if ($varId <= 0 || isset($vistos[$varId])) {
                continue;
            }
            $min = (float) ($var['valor_minimo'] ?? 0);
            $max = (float) ($var['valor_maximo'] ?? 0);
            if ($max < $min) {
                continue;
            }
            $vistos[$varId] = true;

            PlantillaTransformacionPasoVariable::create([
                'plantillapasoid' => $paso->plantillapasoid,
                'variableestandarid' => $varId,
                'valor_minimo' => $min,
                'valor_maximo' => $max,
                'valor_objetivo' => null,
                'obligatorio' => true,
            ]);
        }
    }

    /** @param  list<array<string, mixed>>  $pasos */
    private function validarRangosPasos(array $pasos): void
    {
        if (! Schema::hasTable('plantilla_transformacion_paso_variable')) {
            return;
        }

        $nombres = VariableEstandar::query()->pluck('nombre', 'variableestandarid')->all();
        $errores = [];

        foreach ($pasos as $i => $paso) {
            $maqId = ! empty($paso['maquinaplantaid']) ? (int) $paso['maquinaplantaid'] : null;
            $variables = is_array($paso['variables'] ?? null) ? $paso['variables'] : [];
            $error = ParametroRangoPlanta::validarLista($maqId, $variables, $nombres);
            if ($error !== null) {
                $errores['pasos.'.$i.'.variables'] = 'Paso '.($i + 1).': '.$error;
            }
        }

        if ($errores !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errores);
        }
    }
}
