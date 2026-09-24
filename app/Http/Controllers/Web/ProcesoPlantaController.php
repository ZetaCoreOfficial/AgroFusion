<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ProcesoPlanta;
use App\Support\CatalogoTecnicoPlantaAcceso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcesoPlantaController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            $method = $request->route()?->getActionMethod();

            if (in_array($method, ['store', 'update', 'destroy', 'create', 'edit'], true)) {
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
            'total' => ProcesoPlanta::count(),
            'activos' => ProcesoPlanta::where('activo', true)->count(),
            'inactivos' => ProcesoPlanta::where('activo', false)->count(),
        ];

        $procesos = $query->orderBy('procesoplantaid', 'desc')->paginate(15)->withQueryString();

        return view('procesos_planta.index', compact('procesos', 'stats'));
    }

    public function create(): View
    {
        return view('procesos_planta.create');
    }

    public function show(ProcesoPlanta $procesos_plantum): View
    {
        $proceso = $procesos_plantum;
        $proceso->loadCount('producciones');

        $produccionesRecientes = $proceso->producciones()
            ->with(['lote.cultivo', 'unidadMedida', 'destino'])
            ->orderByDesc('produccionid')
            ->limit(20)
            ->get();

        return view('procesos_planta.show', compact('proceso', 'produccionesRecientes'));
    }

    public function edit(ProcesoPlanta $procesos_plantum): View
    {
        return view('procesos_planta.edit', ['proceso' => $procesos_plantum]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'descripcion' => 'nullable|string',
            'activo' => 'nullable|boolean',
        ]);
        $data['activo'] = $request->boolean('activo', true);
        $proceso = ProcesoPlanta::create($data);

        return redirect()
            ->route('procesos-planta.index')
            ->with('success', 'Proceso registrado correctamente.');
    }

    public function update(Request $request, ProcesoPlanta $procesos_plantum): RedirectResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'descripcion' => 'nullable|string',
            'activo' => 'nullable|boolean',
        ]);
        $data['activo'] = $request->boolean('activo', false);
        $procesos_plantum->update($data);

        return redirect()
            ->route('procesos-planta.index')
            ->with('success', 'Proceso actualizado.');
    }

    public function destroy(ProcesoPlanta $procesos_plantum): RedirectResponse
    {
        $procesos_plantum->delete();

        return redirect()->route('procesos-planta.index')->with('success', 'Proceso eliminado.');
    }

    private function filteredQuery(Request $request)
    {
        $query = ProcesoPlanta::query();

        if ($request->filled('estado')) {
            if ($request->estado === 'activo') {
                $query->where('activo', true);
            } elseif ($request->estado === 'inactivo') {
                $query->where('activo', false);
            }
        }

        if ($request->filled('buscar')) {
            $buscar = '%'.trim((string) $request->buscar).'%';
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre', 'like', $buscar)
                    ->orWhere('descripcion', 'like', $buscar);
            });
        }

        return $query;
    }
}
