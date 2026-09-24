<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actividad;
use App\Models\Lote;
use App\Support\ActividadPermisos;
use App\Support\LoteAcceso;
use App\Support\UsuarioRol;
use Illuminate\Http\Request;

class ActividadController extends Controller
{
    public function index(Request $request)
    {
        $query = Actividad::query()->with(['lote', 'usuario', 'tipoActividad', 'prioridad']);
        ActividadPermisos::aplicarScopeVisibles($query, $request->user());

        return response()->json($query->get());
    }

    public function show(Request $request, $actividad)
    {
        $actividad = $actividad instanceof Actividad
            ? $actividad
            : Actividad::query()->findOrFail($actividad);

        abort_unless(
            ActividadPermisos::puedeAcceder($request->user(), $actividad),
            403,
            'No tienes acceso a esta actividad.'
        );

        return response()->json(
            $actividad->load(['lote', 'usuario', 'tipoActividad', 'prioridad'])
        );
    }

    public function store(Request $request)
    {
        abort_unless(
            UsuarioRol::gestionaCampo($request->user()) || UsuarioRol::esAdminGlobal($request->user()),
            403,
            'Solo el jefe agrícola puede crear o asignar actividades.'
        );

        $data = $request->validate([
            'loteid' => 'required|exists:lote,loteid',
            'usuarioid' => 'nullable|exists:usuario,usuarioid',
            'descripcion' => 'required|string|max:200',
            'fechainicio' => 'nullable|date',
            'fecha_planificada' => 'nullable|date',
            'fechafin' => 'nullable|date',
            'tipoactividadid' => 'required|exists:tipoactividad,tipoactividadid',
            'prioridadid' => 'required|exists:prioridad,prioridadid',
            'observaciones' => 'nullable|string|max:250',
        ]);

        $user = $request->user();
        $lote = Lote::query()->findOrFail((int) $data['loteid']);

        $permitido = Lote::query()
            ->where('loteid', $lote->loteid)
            ->tap(fn ($q) => LoteAcceso::aplicarScopeOperativoActividad($q, $user))
            ->exists();

        abort_unless($permitido, 403, 'No tienes acceso a este lote.');

        $data['usuarioid'] = (int) ($data['usuarioid'] ?? $user->usuarioid);
        if (empty($data['fechainicio'])) {
            $data['fechainicio'] = now();
        }
        $data['fechafin'] = $data['fechafin'] ?? null;

        $actividad = Actividad::create($data);

        return response()->json($actividad, 201);
    }

    public function update(Request $request, $actividad)
    {
        $actividad = $actividad instanceof Actividad
            ? $actividad
            : Actividad::query()->findOrFail($actividad);

        abort_unless(
            ActividadPermisos::puedeAcceder($request->user(), $actividad),
            403,
            'No tienes acceso a esta actividad.'
        );

        $data = $request->validate([
            'loteid' => 'sometimes|exists:lote,loteid',
            'usuarioid' => 'sometimes|exists:usuario,usuarioid',
            'descripcion' => 'sometimes|string|max:200',
            'fechainicio' => 'nullable|date',
            'fecha_planificada' => 'nullable|date',
            'fechafin' => 'nullable|date',
            'tipoactividadid' => 'sometimes|exists:tipoactividad,tipoactividadid',
            'prioridadid' => 'sometimes|exists:prioridad,prioridadid',
            'observaciones' => 'nullable|string|max:250',
        ]);

        $user = $request->user();

        if (isset($data['loteid'])) {
            $lote = Lote::query()->findOrFail((int) $data['loteid']);
            $permitido = Lote::query()
                ->where('loteid', $lote->loteid)
                ->tap(fn ($q) => LoteAcceso::aplicarScopeOperativoActividad($q, $user))
                ->exists();
            abort_unless($permitido, 403, 'No tienes acceso a este lote.');
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            unset($data['usuarioid']);
        }

        if (array_key_exists('fechafin', $data) && $data['fechafin'] !== null) {
            abort_unless(
                ActividadPermisos::puedeMarcarCompletada($user, $actividad),
                403,
                'No puede completar esta actividad.'
            );
            app(\App\Support\ActividadSecuenciaService::class)->asegurarEnTurnoParaCompletar($actividad);
        }

        $actividad->update($data);

        return response()->json($actividad);
    }

    public function destroy(Request $request, $actividad)
    {
        $actividad = $actividad instanceof Actividad
            ? $actividad
            : Actividad::query()->findOrFail($actividad);

        $actividad->loadMissing('lote');

        abort_unless(
            ActividadPermisos::puedeAcceder($request->user(), $actividad)
            && $actividad->lote
            && LoteAcceso::puedeGestionar($request->user(), $actividad->lote),
            403,
            'No tienes permiso para eliminar esta actividad.'
        );

        $actividad->delete();

        return response()->json(['message' => 'Eliminado correctamente']);
    }
}
