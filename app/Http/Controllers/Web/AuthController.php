<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\UsuarioUsernameService;
use App\Support\CuentaEstado;
use App\Support\RegistroValidacion;
use App\Support\TiposLicenciaBolivia;
use App\Support\AgricultorLoginNotificacion;
use App\Support\JefeAgricultorLoginNotificacion;
use App\Support\JefePlantaLoginNotificacion;
use App\Support\MayoristaLoginNotificacion;
use App\Support\MinoristaLoginNotificacion;
use App\Support\OperarioPlantaLoginNotificacion;
use App\Support\TransportistaLoginNotificacion;
use App\Support\UsuarioRol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function showLoginForm(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return response()
            ->view('auth.login')
            ->withCookie($this->cookieOlvidarRecordarme())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function login(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = trim($credentials['email']);
        $password = $credentials['password'];

        $usuario = Usuario::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower($email)])
            ->first();

        if (! $usuario || ! Hash::check($password, $usuario->getAuthPassword())) {
            return back()
                ->withErrors(['email' => 'Credenciales inválidas.'])
                ->withInput($request->only('email'))
                ->withCookie($this->cookieOlvidarRecordarme());
        }

        Auth::login($usuario, $request->boolean('remember', true));

        $user = Auth::user();
        $estado = $user->estado_cuenta ?? CuentaEstado::APROBADO;

        if (! CuentaEstado::puedeIniciarSesion($estado, (bool) $user->activo)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $mensaje = match ($estado) {
                CuentaEstado::PENDIENTE => 'Tu cuenta está pendiente de aprobación por un administrador.',
                default => 'Tu cuenta no está activa.',
            };

            return back()->withErrors(['email' => $mensaje])->withInput($request->only('email'));
        }

        $ultimoLoginPrevio = $user->ultimologin ? $user->ultimologin->copy() : null;

        $request->session()->regenerate();
        $user->ultimologin = now();
        $user->save();

        if (UsuarioRol::esTransportista($user)) {
            $nuevas = TransportistaLoginNotificacion::nuevasAsignacionesDesdeLogin($user, $ultimoLoginPrevio);
            if ($nuevas !== []) {
                $request->session()->put('transportista_nuevas_asignaciones', $nuevas);
            }
        }

        if (UsuarioRol::esOperarioPlanta($user)) {
            $nuevasTareas = OperarioPlantaLoginNotificacion::nuevasTareasDesdeLogin($user, $ultimoLoginPrevio);
            if ($nuevasTareas !== []) {
                $request->session()->put('operario_planta_nuevas_tareas', $nuevasTareas);
            }
        }

        if (UsuarioRol::esAgricultorOperativo($user) && ! UsuarioRol::esJefeAgricultor($user)) {
            $actividades = AgricultorLoginNotificacion::actividadesPendientes($user);
            if ($actividades !== []) {
                $request->session()->put('agricultor_actividades_pendientes', $actividades);
            }
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            $enviosPendientes = JefeAgricultorLoginNotificacion::enviosPendientesConfirmacion($user);
            if ($enviosPendientes !== []) {
                $request->session()->put('jefe_agricultor_envios_pendientes', $enviosPendientes);
            }
        }

        if (UsuarioRol::esJefePlanta($user) || UsuarioRol::esAdminGlobal($user)) {
            $trasladosPendientes = JefePlantaLoginNotificacion::trasladosPendientesDesdeLogin($user, $ultimoLoginPrevio);
            if ($trasladosPendientes !== []) {
                $request->session()->put('jefe_planta_traslados_pendientes', $trasladosPendientes);
            }
        }

        if (UsuarioRol::puedeGestionarDistribucionMayorista($user) && ! UsuarioRol::esMinorista($user)) {
            $nuevasSolicitudes = MayoristaLoginNotificacion::nuevasSolicitudesDesdeLogin($user, $ultimoLoginPrevio);
            if ($nuevasSolicitudes !== []) {
                $request->session()->put('mayorista_nuevas_solicitudes', $nuevasSolicitudes);
            }
        }

        if (UsuarioRol::esMinorista($user)) {
            $enviosPdv = MinoristaLoginNotificacion::enviosPendientesConfirmacion($user);
            if ($enviosPdv !== []) {
                $request->session()->put('minorista_envios_pendientes', $enviosPdv);
            }
        }

        // Vuelve a la pantalla que pidió sesión (p. ej. el QR de firma de recepción).
        return redirect()->intended(route('dashboard'));
    }

    public function showRegisterForm()
    {
        return view('auth.register', [
            'rolesRegistro' => CuentaEstado::rolesRegistroPublico(),
            'prefijosTelefono' => config('telefono_prefijos', []),
            'tiposLicencia' => TiposLicenciaBolivia::todos(),
        ]);
    }

    public function register(Request $request)
    {
        $esTransportista = $request->input('rol_solicitado') === 'transportista';

        $request->merge([
            'nombre' => trim(preg_replace('/\s+/u', ' ', (string) $request->input('nombre', ''))),
            'apellido' => trim(preg_replace('/\s+/u', ' ', (string) $request->input('apellido', ''))),
            'telefono' => trim(preg_replace('/\s+/u', ' ', (string) $request->input('telefono', ''))),
            'ci_nit' => trim(preg_replace('/\s+/u', ' ', (string) $request->input('ci_nit', ''))),
        ]);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100', 'regex:'.RegistroValidacion::NOMBRE_APELLIDO],
            'apellido' => ['required', 'string', 'max:100', 'regex:'.RegistroValidacion::NOMBRE_APELLIDO],
            'email' => 'required|email|max:100|unique:usuario,email',
            'telefono' => ['required', 'string', 'max:20', 'regex:'.RegistroValidacion::TELEFONO],
            'ci_nit' => ['required', 'string', 'max:30', 'regex:'.RegistroValidacion::CI_NIT, 'unique:usuario,ci_nit'],
            'rol_solicitado' => ['required', Rule::in(CuentaEstado::rolesRegistroPublico())],
            'licencias_todas' => ['nullable', 'boolean'],
            'licencias' => ['nullable', 'array'],
            'licencias.*' => ['string', Rule::in(TiposLicenciaBolivia::codigosVehiculo())],
            'carta_motivacion' => 'required|string|min:30|max:2000',
            'password' => 'required|string|min:6|confirmed',
        ], array_merge(RegistroValidacion::mensajes(), [
            'licencias.*.in' => 'Selecciona licencias de vehículo válidas (P, A, B, C o T).',
        ]));

        $licenciasJson = null;
        $tipoLicenciaPrincipal = null;
        if ($esTransportista) {
            $licencias = TiposLicenciaBolivia::resolverDesdeSolicitud(
                $request->boolean('licencias_todas'),
                $request->input('licencias', [])
            );
            if ($licencias === []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'licencias' => 'Indica al menos una licencia de vehículo o marca «Tiene todas las licencias».',
                ]);
            }
            $licenciasJson = $licencias;
            $tipoLicenciaPrincipal = TiposLicenciaBolivia::licenciaPrincipal($licencias);
        }

        $nombreusuario = app(UsuarioUsernameService::class)->generarTemporalSolicitud();

        Usuario::create([
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'email' => $data['email'],
            'nombreusuario' => $nombreusuario,
            'telefono' => $data['telefono'],
            'ci_nit' => $data['ci_nit'],
            'tipo_licencia' => $tipoLicenciaPrincipal,
            'licencias_json' => $licenciasJson,
            'carta_motivacion' => $data['carta_motivacion'],
            'rol_solicitado' => $data['rol_solicitado'],
            'passwordhash' => Hash::make($data['password']),
            'imagenurl' => 'https://bsmobatqfjmrfiipkimu.supabase.co/storage/v1/object/public/agronexus-bucket/usuarios/userDefault.png',
            'activo' => true,
            'estado_cuenta' => CuentaEstado::PENDIENTE,
            'fecharegistro' => now(),
        ]);

        return redirect()->route('register.enviado');
    }

    public function registroEnviado()
    {
        return view('auth.registro-enviado');
    }

    public function logout(Request $request)
    {
        $this->cerrarSesionActiva($request);

        return redirect()
            ->route('login')
            ->withCookie($this->cookieOlvidarRecordarme())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    private function cerrarSesionActiva(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function cookieOlvidarRecordarme(): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::forget(Auth::getRecallerName());
    }
}
