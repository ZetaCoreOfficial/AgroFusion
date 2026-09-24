<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Lote;
use App\Models\PerfilTransportista;
use App\Models\Usuario;
use App\Services\UsuarioEliminacionService;
use App\Services\UsuarioUsernameService;
use App\Support\CuentaEstado;
use App\Support\PermissionMatrixSync;
use App\Support\RegistroValidacion;
use App\Support\TelefonoBolivia;
use App\Support\UsuarioAvatar;
use App\Support\UsuarioRol;
use App\Support\UsuarioSolicitud;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class GestionUsuariosController extends Controller
{
    public function index(Request $request): View
    {
        $this->autorizarModulo();
        $modoJefe = $this->modoJefe();
        $query = $this->usuariosFilteredQuery($request);

        $stats = $this->statsUsuarios($modoJefe);

        $usuarios = $query->orderByDesc('usuarioid')->paginate(15)->withQueryString();
        $roles = $this->rolesDisponibles();
        $lotes = (! $modoJefe || UsuarioRol::esJefeAgricultor(auth()->user()))
            ? Lote::query()->orderBy('nombre')->get(['loteid', 'nombre'])
            : collect();
        $loteSeleccionado = $request->filled('lote') && $lotes->isNotEmpty()
            ? $lotes->firstWhere('loteid', (int) $request->lote)
            : null;

        $tituloGestion = $modoJefe
            ? (UsuarioRol::esJefeAgricultor(auth()->user()) ? 'Empleados agrícolas' : 'Empleados de planta')
            : 'Gestión de usuarios';

        return view('usuarios.index', compact(
            'usuarios',
            'roles',
            'stats',
            'lotes',
            'loteSeleccionado',
            'modoJefe',
            'tituloGestion'
        ));
    }

    public function create(): View
    {
        $this->autorizarModulo();
        $modoJefe = $this->modoJefe();

        return view('usuarios.create', [
            'roles' => $this->rolesDisponibles(),
            'modoJefe' => $modoJefe,
            'rolEmpleadoFijo' => $modoJefe ? UsuarioRol::rolEmpleadoAsignable(auth()->user()) : null,
        ]);
    }

    public function show(Usuario $usuario): View
    {
        $this->autorizarAccesoUsuario($usuario);

        $usuario->load(['roles', 'perfilTransportista']);

        $stats = [
            'lotes' => $usuario->lotes()->count(),
            'actividades' => $usuario->actividades()->count(),
        ];

        $lotesRecientes = $usuario->lotes()
            ->with(['cultivo', 'estadoTipo'])
            ->orderByDesc('loteid')
            ->limit(10)
            ->get();

        $modoJefe = $this->modoJefe();

        return view('usuarios.show', compact('usuario', 'stats', 'lotesRecientes', 'modoJefe'));
    }

    public function edit(Usuario $usuario): View|RedirectResponse
    {
        $this->autorizarAccesoUsuario($usuario);

        if (UsuarioSolicitud::adminSoloPuedeRevisar($usuario) && UsuarioRol::esAdminGlobal(auth()->user())) {
            return redirect()
                ->route('gestion.show', $usuario)
                ->with('error', 'Las solicitudes pendientes solo pueden revisarse (aprobar o rechazar), no editarse.');
        }

        $usuario->load('roles');

        return view('usuarios.edit', [
            'usuario' => $usuario,
            'roles' => $this->rolesDisponibles(),
            'modoJefe' => $this->modoJefe(),
            'rolEmpleadoFijo' => UsuarioRol::rolEmpleadoAsignable(auth()->user()),
        ]);
    }

    public function storeUsuario(Request $request): RedirectResponse
    {
        $this->autorizarModulo();

        if ($this->modoJefe()) {
            return $this->storeEmpleadoJefe($request);
        }

        $this->normalizarTelefonoEnRequest($request);

        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:usuario,email',
            'nombreusuario' => 'required|string|max:100|unique:usuario,nombreusuario',
            'telefono' => $this->reglaTelefonoOpcional(),
            'passwordhash' => 'required|string|max:250',
            'imagenurl' => 'nullable|string|max:250',
            'informacionadicional' => 'nullable|string',
            'rolid' => 'nullable|exists:roles,id',
        ], $this->mensajesValidacionUsuario(), $this->atributosValidacionUsuario());

        $data['passwordhash'] = Hash::make($data['passwordhash']);
        $data['activo'] = true;
        $data['estado_cuenta'] = CuentaEstado::APROBADO;

        $usuario = Usuario::create($data);

        if ($request->filled('rolid')) {
            $rol = Role::findById($request->rolid);
            if ($rol) {
                $usuario->assignRole($rol);
                $usuario->update(['role' => $rol->name]);
            }
        }

        return redirect()->route('gestion.show', $usuario)->with('success', 'Usuario creado correctamente.');
    }

    public function updateUsuario(Request $request, Usuario $usuario): RedirectResponse
    {
        $this->autorizarAccesoUsuario($usuario);

        if (UsuarioSolicitud::adminSoloPuedeRevisar($usuario) && UsuarioRol::esAdminGlobal(auth()->user())) {
            abort(403, 'Las solicitudes pendientes no pueden editarse.');
        }

        if ($this->modoJefe()) {
            return $this->updateEmpleadoJefe($request, $usuario);
        }

        $this->normalizarTelefonoEnRequest($request);

        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:usuario,email,'.$usuario->usuarioid.',usuarioid',
            'nombreusuario' => 'required|string|max:100|unique:usuario,nombreusuario,'.$usuario->usuarioid.',usuarioid',
            'telefono' => $this->reglaTelefonoOpcional(),
            'imagenurl' => 'nullable|string|max:250',
            'informacionadicional' => 'nullable|string',
            'rolid' => 'nullable|exists:roles,id',
        ], $this->mensajesValidacionUsuario(), $this->atributosValidacionUsuario());

        unset($data['activo']);

        $usuario->update($data);

        if ($request->filled('rolid')) {
            $rol = Role::findById($request->rolid);
            if ($rol) {
                $usuario->syncRoles([$rol]);
                $usuario->update(['role' => $rol->name]);
            }
        } else {
            $usuario->syncRoles([]);
        }

        return redirect()->route('gestion.show', $usuario)->with('success', 'Usuario actualizado correctamente.');
    }

    public function destroyUsuario(Usuario $usuario): RedirectResponse
    {
        $this->autorizarAccesoUsuario($usuario);

        if (UsuarioSolicitud::adminSoloPuedeRevisar($usuario) && UsuarioRol::esAdminGlobal(auth()->user())) {
            abort(403, 'Las solicitudes pendientes no pueden eliminarse. Usa Rechazar en el detalle.');
        }

        $eliminacion = app(UsuarioEliminacionService::class);

        if (! $eliminacion->puedeEliminar($usuario)) {
            return redirect()
                ->route('gestion.index')
                ->with('error', 'Este usuario es esencial del sistema y no puede eliminarse.');
        }

        try {
            $eliminacion->eliminar($usuario);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->back()
                ->with('error', 'No se pudo eliminar el usuario porque tiene datos vinculados.');
        }

        return redirect()->route('gestion.index')->with('success', 'Usuario eliminado correctamente.');
    }

    public function storeRol(Request $request): RedirectResponse
    {
        abort_unless(UsuarioRol::administraUsuariosGlobal(auth()->user()), 403);

        $data = $request->validate([
            'nombre' => 'required|string|max:50|unique:roles,name',
        ]);

        Role::create(['name' => $data['nombre']]);

        return redirect()->route('gestion.index')->with('success', 'Rol creado correctamente.');
    }

    public function updateRol(Request $request, Role $role): RedirectResponse
    {
        abort_unless(UsuarioRol::administraUsuariosGlobal(auth()->user()), 403);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($role->id)],
        ]);

        $role->update(['name' => $data['nombre']]);

        return redirect()->route('gestion.index')->with('success', 'Rol actualizado correctamente.');
    }

    public function destroyRol(Role $role): RedirectResponse
    {
        abort_unless(UsuarioRol::administraUsuariosGlobal(auth()->user()), 403);

        $role->delete();

        return redirect()->route('gestion.index')->with('success', 'Rol eliminado correctamente.');
    }

    public function aprobarSolicitud(Request $request, Usuario $usuario): RedirectResponse
    {
        $this->authorizeAprobar($usuario);

        $rolNombre = $usuario->rol_solicitado;
        if (! $rolNombre || ! in_array($rolNombre, CuentaEstado::rolesRegistroPublico(), true)) {
            return back()->withErrors(['rol' => 'La solicitud no tiene un rol válido.']);
        }

        // TRA-05: el admin decide la flota del transportista; ya no queda «agrícola» por defecto.
        $ambitoFlota = null;
        if ($rolNombre === 'transportista') {
            $ambitoFlota = $request->validate([
                'ambito_flota' => ['required', 'in:'.implode(',', \App\Support\TransportistaFlotaCatalogo::valores())],
            ], [
                'ambito_flota.required' => 'Elija la flota (agrícola, planta o mayorista) del transportista antes de aprobar.',
            ])['ambito_flota'];
        }

        Role::firstOrCreate(['name' => $rolNombre, 'guard_name' => 'web']);
        PermissionMatrixSync::syncRole($rolNombre);

        $usernameService = app(UsuarioUsernameService::class);
        $nombreusuario = $usernameService->generarDesdeNombreApellido(
            (string) $usuario->nombre,
            (string) $usuario->apellido
        );

        $usuario->update([
            'estado_cuenta' => CuentaEstado::APROBADO,
            'activo' => true,
            'role' => $rolNombre,
            'nombreusuario' => $nombreusuario,
            'nombreusuario_editado' => false,
            'bienvenida_vista' => false,
            'motivo_rechazo' => null,
            'revisado_por' => auth()->id(),
            'fecha_revision' => now(),
            'fechamodificacion' => now(),
        ]);
        $usuario->syncRoles([$rolNombre]);

        if ($rolNombre === 'transportista') {
            $this->crearPerfilTransportistaDesdeSolicitud($usuario, $ambitoFlota);
        }

        return redirect()->route('gestion.show', $usuario)->with(
            'success',
            'Solicitud aprobada. Se asignó el usuario «'.$nombreusuario.'».'
        );
    }

    public function rechazarSolicitud(Request $request, Usuario $usuario): RedirectResponse
    {
        $this->authorizeAprobar($usuario);

        $nombre = trim($usuario->nombre.' '.$usuario->apellido);
        app(UsuarioEliminacionService::class)->eliminar($usuario);

        return redirect()->route('gestion.index')->with(
            'success',
            'Solicitud de '.$nombre.' rechazada y eliminada del sistema.'
        );
    }

    private function storeEmpleadoJefe(Request $request): RedirectResponse
    {
        $jefe = auth()->user();
        $rolEmpleado = UsuarioRol::rolEmpleadoAsignable($jefe);
        abort_unless($rolEmpleado !== null, 403);

        $this->normalizarTelefonoEnRequest($request);

        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:usuario,email',
            'telefono' => $this->reglaTelefonoOpcional(),
            'passwordhash' => 'required|string|min:5|max:250',
        ], $this->mensajesValidacionUsuario(), $this->atributosValidacionUsuario());

        Role::firstOrCreate(['name' => $rolEmpleado, 'guard_name' => 'web']);

        $nombreusuario = app(UsuarioUsernameService::class)->generarDesdeNombreApellido(
            $data['nombre'],
            $data['apellido']
        );

        $empleado = Usuario::create([
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'email' => $data['email'],
            'telefono' => $data['telefono'] ?? null,
            'nombreusuario' => $nombreusuario,
            'passwordhash' => Hash::make($data['passwordhash']),
            'imagenurl' => UsuarioAvatar::placeholder(),
            'role' => $rolEmpleado,
            'supervisor_usuarioid' => $jefe->usuarioid,
            'activo' => true,
            'estado_cuenta' => CuentaEstado::APROBADO,
            'nombreusuario_editado' => false,
            'fecharegistro' => now(),
        ]);

        $empleado->assignRole($rolEmpleado);

        return redirect()->route('gestion.show', $empleado)
            ->with('success', 'Empleado registrado. Ya puede iniciar sesión.');
    }

    private function updateEmpleadoJefe(Request $request, Usuario $usuario): RedirectResponse
    {
        $this->normalizarTelefonoEnRequest($request);

        $rules = [
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:usuario,email,'.$usuario->usuarioid.',usuarioid',
            'telefono' => $this->reglaTelefonoOpcional(),
            'passwordhash' => 'nullable|string|min:5|max:250',
        ];

        if (! $usuario->nombreusuario_editado) {
            $rules['nombreusuario'] = 'required|string|max:100|unique:usuario,nombreusuario,'.$usuario->usuarioid.',usuarioid';
        }

        $data = $request->validate($rules, $this->mensajesValidacionUsuario(), $this->atributosValidacionUsuario());

        $payload = [
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'email' => $data['email'],
            'telefono' => $data['telefono'] ?? null,
            'fechamodificacion' => now(),
        ];

        if (! $usuario->nombreusuario_editado && isset($data['nombreusuario'])) {
            $payload['nombreusuario'] = $data['nombreusuario'];
            $payload['nombreusuario_editado'] = true;
        }

        if (! empty($data['passwordhash'])) {
            $payload['passwordhash'] = Hash::make($data['passwordhash']);
        }

        $usuario->update($payload);

        return redirect()->route('gestion.show', $usuario)->with('success', 'Empleado actualizado.');
    }

    private function usuariosFilteredQuery(Request $request)
    {
        $query = Usuario::query()->with(['roles', 'perfilTransportista'])->withCount('lotes');

        if ($this->modoJefe()) {
            $jefe = auth()->user();
            $query->where('supervisor_usuarioid', $jefe->usuarioid)
                ->whereIn('role', UsuarioRol::rolesEmpleadosGestionables($jefe));
        }

        if ($request->filled('buscar')) {
            $buscar = '%'.trim((string) $request->buscar).'%';
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre', 'like', $buscar)
                    ->orWhere('apellido', 'like', $buscar)
                    ->orWhere('email', 'like', $buscar)
                    ->orWhere('nombreusuario', 'like', $buscar)
                    ->orWhere('telefono', 'like', $buscar);
            });
        }

        if ($request->filled('rol') && ! $this->modoJefe()) {
            $query->whereHas('roles', fn ($q) => $q->where('id', (int) $request->rol));
        }

        if ($request->filled('estado') && ! $this->modoJefe()) {
            if ($request->estado === 'activo') {
                $query->where(function ($q) {
                    $q->whereNull('estado_cuenta')->orWhere('estado_cuenta', CuentaEstado::APROBADO);
                });
            } elseif ($request->estado === 'pendiente') {
                $query->where('estado_cuenta', CuentaEstado::PENDIENTE);
            }
        }

        if ($request->filled('lote')) {
            $loteId = (int) $request->lote;
            $query->whereHas('lotes', fn ($q) => $q->where('loteid', $loteId));
        }

        return $query;
    }

    /** @return array<string, int> */
    private function statsUsuarios(bool $modoJefe): array
    {
        if ($modoJefe) {
            $base = Usuario::query()
                ->where('supervisor_usuarioid', auth()->id())
                ->whereIn('role', UsuarioRol::rolesEmpleadosGestionables(auth()->user()));

            return [
                'total' => (clone $base)->count(),
                'activos' => (clone $base)->where(function ($q) {
                    $q->whereNull('estado_cuenta')->orWhere('estado_cuenta', CuentaEstado::APROBADO);
                })->count(),
                'pendientes' => 0,
                'roles' => 1,
            ];
        }

        return [
            'total' => Usuario::query()->count(),
            'activos' => Usuario::query()->where(function ($q) {
                $q->whereNull('estado_cuenta')->orWhere('estado_cuenta', CuentaEstado::APROBADO);
            })->count(),
            'pendientes' => Usuario::query()->where('estado_cuenta', CuentaEstado::PENDIENTE)->count(),
            'roles' => count($this->rolesCanonicos()),
        ];
    }

    private function modoJefe(): bool
    {
        return UsuarioRol::puedeGestionarEmpleados(auth()->user())
            && ! UsuarioRol::administraUsuariosGlobal(auth()->user());
    }

    /**
     * Solo el admin (global) o un jefe agrícola/planta (su equipo) usan este módulo.
     * Cualquier otro rol con permisos `usuarios.*` en la matriz no obtiene administración global.
     */
    private function autorizarModulo(): void
    {
        abort_unless(UsuarioRol::puedeGestionarUsuarios(auth()->user()), 403);
    }

    private function autorizarAccesoUsuario(Usuario $usuario): void
    {
        if (UsuarioRol::administraUsuariosGlobal(auth()->user())) {
            return;
        }

        if ($this->modoJefe()) {
            abort_unless(
                (int) $usuario->supervisor_usuarioid === (int) auth()->id()
                && in_array($usuario->role, UsuarioRol::rolesEmpleadosGestionables(auth()->user()), true),
                403
            );

            return;
        }

        abort(403);
    }

    private function crearPerfilTransportistaDesdeSolicitud(Usuario $usuario, string $ambitoFlota): void
    {
        if (! Schema::hasTable('perfil_transportista')) {
            return;
        }

        $estadoId = null;
        if (Schema::hasTable('estado_transportista')) {
            $estadoId = DB::table('estado_transportista')
                ->where('nombre', 'disponible')
                ->value('estadotransportistaid');
        }

        PerfilTransportista::updateOrCreate(
            ['usuarioid' => $usuario->usuarioid],
            [
                'ambito_flota' => $ambitoFlota,
                'tipo_licencia' => $usuario->tipo_licencia,
                'licencias_json' => $usuario->licencias_json,
                'licencia' => $usuario->ci_nit,
                'estadotransportistaid' => $estadoId,
                'disponible' => true,
            ]
        );
    }

    private function authorizeAprobar(Usuario $usuario): void
    {
        if (($usuario->estado_cuenta ?? CuentaEstado::APROBADO) !== CuentaEstado::PENDIENTE) {
            abort(403, 'Esta solicitud ya fue procesada.');
        }

        if (! UsuarioRol::puedeAprobarSolicitud(auth()->user(), $usuario->rol_solicitado)) {
            abort(403, 'No tienes permiso para aprobar esta solicitud.');
        }
    }

    /** @return \Illuminate\Support\Collection<int, Role> */
    private function rolesDisponibles()
    {
        if ($this->modoJefe()) {
            $rol = UsuarioRol::rolEmpleadoAsignable(auth()->user());

            return Role::query()->where('name', $rol)->get();
        }

        return $this->rolesCanonicos();
    }

    /** @return \Illuminate\Support\Collection<int, Role> */
    private function rolesCanonicos()
    {
        $nombres = array_values(array_diff(
            array_keys(config('permission_matrix.role_permissions', [])),
            UsuarioRol::rolesLegacyOcultosEnSelector()
        ));

        $orden = [
            'admin' => 0,
            'planta' => 10,
            'agricultor' => 20,
            'jefe_planta' => 30,
            'jefe_agricultor' => 40,
            'transportista' => 50,
            'mayorista' => 60,
            'minorista' => 70,
        ];

        return Role::query()
            ->whereIn('name', $nombres)
            ->get()
            ->sortBy(fn (Role $rol) => [$orden[$rol->name] ?? 999, UsuarioRol::etiquetaRol($rol->name)])
            ->values();
    }

    private function normalizarTelefonoEnRequest(Request $request): void
    {
        if (! $request->has('telefono')) {
            return;
        }

        $request->merge([
            'telefono' => TelefonoBolivia::normalizar($request->input('telefono')),
        ]);
    }

    /** @return array<int, string> */
    private function reglaTelefonoOpcional(): array
    {
        return ['nullable', 'string', 'max:20', 'regex:'.RegistroValidacion::TELEFONO];
    }

    /** @return array<string, string> */
    private function mensajesValidacionUsuario(): array
    {
        return array_merge(RegistroValidacion::mensajes(), [
            'passwordhash.required' => 'Indica una contraseña para el nuevo usuario.',
            'passwordhash.min' => 'La contraseña debe tener al menos 5 caracteres.',
        ]);
    }

    /** @return array<string, string> */
    private function atributosValidacionUsuario(): array
    {
        return [
            'passwordhash' => 'contraseña',
            'telefono' => 'teléfono',
            'nombreusuario' => 'nombre de usuario',
        ];
    }
}
