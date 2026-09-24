<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Insumo;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Services\AlmacenCapacidadService;
use App\Services\PuntoVentaAlmacenService;
use App\Services\PuntoVentaInventarioPresentacionService;
use App\Services\RecepcionPuntoVentaService;
use App\Support\CuentaEstado;
use App\Support\PuntoVentaAccess;
use App\Support\PuntoVentaEliminacionCatalogo;
use App\Support\UsuarioRol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PuntoVentaController extends Controller
{
    public function index(
        Request $request,
        AlmacenCapacidadService $capacidadService,
        RecepcionPuntoVentaService $recepcionPdv
    ): View {
        $user = $request->user();
        $query = PuntoVentaAccess::scopePuntosDelUsuario(
            PuntoVenta::query()->with(['minorista', 'almacen.unidadMedida']),
            $user
        );

        if ($request->filled('q')) {
            $term = $request->string('q')->trim()->toString();
            $query->where(function ($w) use ($term) {
                $w->where('nombre', 'like', "%{$term}%")
                    ->orWhere('direccion', 'like', "%{$term}%")
                    ->orWhereHas('minorista', function ($m) use ($term) {
                        $m->where('nombre', 'like', "%{$term}%")
                            ->orWhere('apellido', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        if ($request->filled('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }

        $puntos = $query->orderByDesc('puntoventaid')->get();
        $recepcionPdv->repararInventarioPedidosRecibidos($puntos);
        $puntos->each(fn (PuntoVenta $p) => $p->loadMissing('almacen.unidadMedida'));
        $esAdmin = UsuarioRol::esAdminGlobal($user);

        $ocupacionPorPunto = [];
        $eliminacionPorPunto = [];
        $stockTotalKg = 0.0;
        foreach ($puntos as $punto) {
            if ($punto->almacen) {
                $resumen = $capacidadService->resumen($punto->almacen);
            } else {
                $resumen = [
                    'ocupado_kg' => 0.0,
                    'capacidad_kg' => 0.0,
                    'disponible_kg' => 0.0,
                    'porcentaje' => 0.0,
                ];
            }
            $ocupacionPorPunto[$punto->puntoventaid] = $resumen;
            $eliminacionPorPunto[$punto->puntoventaid] = PuntoVentaEliminacionCatalogo::evaluar($punto);
            $stockTotalKg += (float) $resumen['ocupado_kg'];
        }

        return view('punto_venta.puntos.index', compact(
            'puntos',
            'esAdmin',
            'ocupacionPorPunto',
            'eliminacionPorPunto',
            'stockTotalKg'
        ));
    }

    public function create(Request $request): View
    {
        $minoristas = $this->minoristasParaSelector($request->user());
        $puntosMapa = $this->puntosParaMapa($request->user());

        return view('punto_venta.puntos.create', compact('minoristas', 'puntosMapa'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $esAdmin = UsuarioRol::esAdminGlobal($user);

        if ($esAdmin && $this->minoristasParaSelector($user)->isEmpty()) {
            return back()
                ->withInput()
                ->withErrors(['usuarioid' => 'Debe existir al menos un minorista aprobado para registrar un punto de venta.']);
        }

        if ($esAdmin && $request->input('usuarioid') === '') {
            $request->merge(['usuarioid' => null]);
        }

        $rules = [
            'nombre' => 'required|string|max:150',
            'direccion' => 'nullable|string|max:500',
            'latitud' => 'required|numeric|between:-90,90',
            'longitud' => 'required|numeric|between:-180,180',
            'observaciones' => 'nullable|string|max:1000',
            'capacidad' => 'required|numeric|min:0.01',
        ];

        if ($esAdmin) {
            $rules['usuarioid'] = 'required|integer|exists:usuario,usuarioid';
        }

        $data = $request->validate($rules, [
            'capacidad.min' => 'La capacidad debe ser mayor a 0 kg.',
            'capacidad.required' => 'Indique la capacidad del depósito en kilogramos.',
        ]);

        if ($esAdmin) {
            $responsable = Usuario::query()->findOrFail((int) $data['usuarioid']);
            if (! UsuarioRol::esMinorista($responsable)) {
                return back()
                    ->withInput()
                    ->withErrors(['usuarioid' => 'Debe asignar un minorista válido. El administrador no puede ser dueño del punto de venta.']);
            }
            $usuarioidResponsable = (int) $responsable->usuarioid;
        } else {
            $usuarioidResponsable = (int) $user->usuarioid;
        }

        $puntoVenta = PuntoVenta::create([
            'usuarioid' => $usuarioidResponsable,
            'nombre' => $data['nombre'],
            'direccion' => $data['direccion'] ?? null,
            'latitud' => $data['latitud'] ?? null,
            'longitud' => $data['longitud'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
            'activo' => true,
            'fechacreacion' => now(),
        ]);

        app(PuntoVentaAlmacenService::class)->crearAlmacenParaPuntoVenta(
            $puntoVenta,
            (float) $data['capacidad']
        );

        return redirect()
            ->route('punto-venta.puntos.show', $puntoVenta)
            ->with('success', 'Punto de venta registrado correctamente.');
    }

    public function show(PuntoVenta $punto, PuntoVentaInventarioPresentacionService $presentaciones): View
    {
        abort_unless(PuntoVentaAccess::puedeVerPunto(auth()->user(), $punto), 403);

        $punto->load(['minorista', 'almacen']);
        $lineasInventario = $presentaciones->lineasParaPuntos(collect([$punto]));
        $pedidos = $punto->pedidosDistribucion()
            ->with('detalles')
            ->orderByDesc('pedidodistribucionid')
            ->limit(10)
            ->get();

        $evalEliminacion = PuntoVentaEliminacionCatalogo::evaluar($punto);

        return view('punto_venta.puntos.show', compact('punto', 'lineasInventario', 'pedidos', 'evalEliminacion'));
    }

    public function edit(PuntoVenta $punto): View
    {
        abort_unless(PuntoVentaAccess::puedeEditarPunto(auth()->user(), $punto), 403);

        $punto->load('almacen');
        $minoristas = $this->minoristasParaSelector(auth()->user());
        $puntosMapa = $this->puntosParaMapa(auth()->user(), $punto->puntoventaid);
        $evalEliminacion = PuntoVentaEliminacionCatalogo::evaluar($punto);

        return view('punto_venta.puntos.edit', compact('punto', 'minoristas', 'puntosMapa', 'evalEliminacion'));
    }

    public function update(Request $request, PuntoVenta $punto, AlmacenCapacidadService $capacidadService): RedirectResponse
    {
        abort_unless(PuntoVentaAccess::puedeEditarPunto(auth()->user(), $punto), 403);

        $esAdmin = UsuarioRol::esAdminGlobal($request->user());

        if ($esAdmin && $request->input('usuarioid') === '') {
            $request->merge(['usuarioid' => null]);
        }

        $rules = [
            'nombre' => 'required|string|max:150',
            'direccion' => 'nullable|string|max:500',
            'latitud' => 'required|numeric|between:-90,90',
            'longitud' => 'required|numeric|between:-180,180',
            'observaciones' => 'nullable|string|max:1000',
            'capacidad' => 'required|numeric|min:0.01',
            'activo' => 'sometimes|boolean',
        ];

        if ($esAdmin) {
            $rules['usuarioid'] = 'required|integer|exists:usuario,usuarioid';
        }

        $data = $request->validate($rules, [
            'capacidad.min' => 'La capacidad debe ser mayor a 0 kg.',
            'capacidad.required' => 'Indique la capacidad del depósito en kilogramos.',
        ]);

        $punto->load('almacen');

        if ($esAdmin) {
            $responsable = Usuario::query()->findOrFail((int) $data['usuarioid']);
            if (! UsuarioRol::esMinorista($responsable)) {
                return back()
                    ->withInput()
                    ->withErrors(['usuarioid' => 'Debe asignar un minorista válido.']);
            }
            $usuarioidResponsable = (int) $responsable->usuarioid;
        } else {
            $usuarioidResponsable = $punto->usuarioid;
        }

        $punto->update([
            'nombre' => $data['nombre'],
            'direccion' => $data['direccion'] ?? null,
            'latitud' => $data['latitud'] ?? null,
            'longitud' => $data['longitud'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
            'activo' => $request->boolean('activo', true),
            'usuarioid' => $usuarioidResponsable,
        ]);

        if ($punto->almacen) {
            $ocupadoKg = $capacidadService->ocupadoKg($punto->almacen);
            if ((float) $data['capacidad'] < $ocupadoKg - 0.001) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'capacidad' => 'La capacidad no puede ser menor al stock actual ('.number_format($ocupadoKg, 2, ',', '.').' kg).',
                    ]);
            }

            app(PuntoVentaAlmacenService::class)->sincronizarNombreAlmacen($punto, $punto->almacen);
            $almacenUpdate = [
                'ubicacion' => $punto->direccion,
                'capacidad' => (float) $data['capacidad'],
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('almacen', 'responsable_usuarioid')) {
                $almacenUpdate['responsable_usuarioid'] = $usuarioidResponsable;
            }
            $punto->almacen->update($almacenUpdate);
        }

        return redirect()
            ->route('punto-venta.puntos.show', $punto)
            ->with('success', 'Punto de venta actualizado.');
    }

    public function destroy(PuntoVenta $punto): RedirectResponse
    {
        abort_unless(PuntoVentaAccess::puedeEditarPunto(auth()->user(), $punto), 403);

        $eval = PuntoVentaEliminacionCatalogo::evaluar($punto);
        if (! $eval['ok']) {
            return back()->with([
                'error' => $eval['mensaje'],
                'error_modal' => true,
                'error_modal_titulo' => $eval['titulo'],
            ]);
        }

        PuntoVentaEliminacionCatalogo::cancelarPedidosPendientes($punto);
        PuntoVentaEliminacionCatalogo::eliminarHistorialAsociado($punto);

        if ($punto->almacenid) {
            Insumo::query()->where('almacenid', $punto->almacenid)->delete();
            $punto->almacen?->delete();
        }

        try {
            $punto->delete();
        } catch (\Illuminate\Database\QueryException) {
            return back()->with([
                'error' => 'No se pudo eliminar «'.$punto->nombre.'» porque aún hay registros vinculados en el sistema.',
                'error_modal' => true,
                'error_modal_titulo' => 'No se puede eliminar',
            ]);
        }

        return redirect()
            ->route('punto-venta.puntos.index')
            ->with('success', 'Punto de venta eliminado.');
    }

    /** @return \Illuminate\Support\Collection<int, Usuario> */
    private function minoristasParaSelector(?Usuario $user)
    {
        if (UsuarioRol::esAdminGlobal($user)) {
            return Usuario::query()
                ->where('role', 'minorista')
                ->where(function ($q) {
                    $q->whereNull('estado_cuenta')
                        ->orWhere('estado_cuenta', CuentaEstado::APROBADO);
                })
                ->where('activo', true)
                ->orderBy('nombre')
                ->orderBy('apellido')
                ->get();
        }

        return collect([$user])->filter();
    }

    /**
     * @return list<array{id: int, nombre: string, direccion: ?string, lat: float, lng: float, usuarioid: ?int}>
     */
    private function puntosParaMapa(?Usuario $user, ?int $excluirPuntoId = null): array
    {
        $query = PuntoVentaAccess::scopePuntosDelUsuario(PuntoVenta::query(), $user)
            ->whereNotNull('latitud')
            ->whereNotNull('longitud');

        if ($excluirPuntoId !== null) {
            $query->where('puntoventaid', '!=', $excluirPuntoId);
        }

        return $query
            ->orderBy('nombre')
            ->get(['puntoventaid', 'nombre', 'direccion', 'latitud', 'longitud', 'usuarioid'])
            ->map(fn (PuntoVenta $p) => [
                'id' => (int) $p->puntoventaid,
                'nombre' => (string) $p->nombre,
                'direccion' => $p->direccion,
                'lat' => (float) $p->latitud,
                'lng' => (float) $p->longitud,
                'usuarioid' => $p->usuarioid !== null ? (int) $p->usuarioid : null,
            ])
            ->values()
            ->all();
    }
}
