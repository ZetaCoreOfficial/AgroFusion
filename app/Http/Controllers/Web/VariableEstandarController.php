<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MaquinaVariablePlanta;
use App\Models\PlantillaTransformacionPasoVariable;
use App\Models\VariableEstandar;
use App\Models\VariableProcesoMaquinaPlanta;
use App\Support\CatalogoTecnicoPlantaAcceso;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VariableEstandarController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            $metodo = $request->route()?->getActionMethod();

            if (in_array($metodo, ['store', 'update', 'destroy', 'create', 'edit'], true)) {
                abort_unless(CatalogoTecnicoPlantaAcceso::puedeGestionar($user), 403);
            } else {
                abort_unless(CatalogoTecnicoPlantaAcceso::puedeAdministrar($user), 403);
            }

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $query = VariableEstandar::query();

        if ($request->filled('buscar')) {
            $buscar = '%'.trim((string) $request->buscar).'%';
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre', 'like', $buscar)
                    ->orWhere('codigo', 'like', $buscar)
                    ->orWhere('unidad', 'like', $buscar)
                    ->orWhere('descripcion', 'like', $buscar);
            });
        }

        if ($request->filled('estado')) {
            match ($request->estado) {
                'activa' => $query->where('activo', true),
                'inactiva' => $query->where('activo', false),
                default => null,
            };
        }

        $stats = [
            'total' => VariableEstandar::count(),
            'activas' => VariableEstandar::where('activo', true)->count(),
            'inactivas' => VariableEstandar::where('activo', false)->count(),
        ];

        $variables = $query->orderBy('variableestandarid')->paginate(15)->withQueryString();

        return view('variables_estandar.index', compact('variables', 'stats'));
    }

    public function create(): View
    {
        return view('variables_estandar.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validar($request);
        $variable = VariableEstandar::create($data);

        return redirect()
            ->route('variables-estandar.show', $variable)
            ->with('success', 'Variable estándar registrada.');
    }

    public function show(VariableEstandar $variables_estandar): View
    {
        $variable = $variables_estandar;

        return view('variables_estandar.show', compact('variable'));
    }

    public function edit(VariableEstandar $variables_estandar): View
    {
        return view('variables_estandar.edit', ['variable' => $variables_estandar]);
    }

    public function update(Request $request, VariableEstandar $variables_estandar): RedirectResponse
    {
        $data = $this->validar($request, $variables_estandar);
        $variables_estandar->update($data);

        return redirect()
            ->route('variables-estandar.show', $variables_estandar)
            ->with('success', 'Variable actualizada.');
    }

    public function destroy(VariableEstandar $variables_estandar): RedirectResponse
    {
        $id = (int) $variables_estandar->variableestandarid;

        if (VariableProcesoMaquinaPlanta::where('variableestandarid', $id)->exists()) {
            return back()->with('error', 'No se puede eliminar: la variable está vinculada a procesos de máquina.');
        }

        if (MaquinaVariablePlanta::where('variableestandarid', $id)->exists()) {
            return back()->with('error', 'No se puede eliminar: la variable está asignada a máquinas.');
        }

        if (Schema::hasTable('plantilla_transformacion_paso_variable')
            && PlantillaTransformacionPasoVariable::where('variableestandarid', $id)->exists()) {
            return back()->with('error', 'No se puede eliminar: la variable está en plantillas de transformación.');
        }

        $variables_estandar->delete();

        return redirect()
            ->route('variables-estandar.index')
            ->with('success', 'Variable eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?VariableEstandar $existente = null): array
    {
        $id = $existente?->variableestandarid;

        $data = $request->validate([
            'codigo' => [
                'required', 'string', 'max:50',
                Rule::unique('variable_estandar', 'codigo')->ignore($id, 'variableestandarid'),
            ],
            'nombre' => ['required', 'string', 'max:100'],
            'unidad' => ['nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $data['activo'] = $request->boolean('activo', true);
        $data['codigo'] = strtoupper(trim($data['codigo']));

        return $data;
    }
}
