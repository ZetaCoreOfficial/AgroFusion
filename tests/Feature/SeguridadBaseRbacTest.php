<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminSoloSupervision;
use App\Models\Actividad;
use App\Models\AsignacionEtapaPlanta;
use App\Models\Cultivo;
use App\Models\EstadoLoteTipo;
use App\Models\Lote;
use App\Models\LoteProduccionPedido;
use App\Models\MaquinaPlanta;
use App\Models\Pedido;
use App\Models\Prioridad;
use App\Models\ProcesoPlanta;
use App\Models\RutaDistribucion;
use App\Models\TipoActividad;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Support\ActividadPermisos;
use App\Support\UsuarioRol;
use Database\Seeders\CatalogosOperacionAgricolaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Frente 1 — seguridad base y RBAC global (SEC-01, SEC-02, ADM-01…ADM-07, C1/C2, D1).
 *
 * Los barridos recorren TODAS las rutas registradas: una ruta nueva que quede pública
 * o que el admin pueda mutar sin estar en la lista de administración hace fallar el test.
 */
class SeguridadBaseRbacTest extends TestCase
{
    use RefreshDatabase;

    /** Rutas API públicas por diseño (registro queda pendiente de aprobación; login; ping). */
    private const API_PUBLICAS = ['api/login', 'api/register', 'api/test-api'];

    /** Escrituras web públicas por diseño (sesión). La firma QR exige la cuenta del receptor (Frente 3, CROSS-A). */
    private const WEB_ESCRITURA_PUBLICAS = ['login.post', 'register.post', 'logout'];

    /** Lecturas web públicas por diseño (portada, login/registro, QR de recepción y trazabilidad pública). */
    private const WEB_LECTURA_PUBLICAS = [
        '/', 'up', 'login', 'register', 'registro-enviado', 'logout',
        'recepcion/{token}', 'trazabilidad/{codigo}', 'storage/{path}', 'sanctum/csrf-cookie',
    ];

    /** Operaciones de negocio que el barrido debe cubrir explícitamente (ADM-02/03/04). */
    private const OPERACIONES_CRITICAS = [
        'actividades.store',
        'actividades.marcar-realizada',
        'lotes.siembra.asignar',
        'lotes.siembra.completar.store',
        'tareas-planta.completar',
        'procesamiento.completar-etapa-asignada',
        'procesamiento.completar',
        'logistica.asignaciones.empezar-ruta',
        'logistica.rutas-distribucion.empezar-ruta',
        'logistica.traslados-planta.empezar-ruta',
        'punto-venta.pedidos.empezar-ruta',
        'logistica.asignaciones.cierre.firma-transportista',
        'logistica.asignaciones.cierre.firma-recepcion',
        'logistica.rutas-distribucion.cierre.firma-transportista',
        'logistica.rutas-distribucion.cierre.firma-recepcion',
        'punto-venta.rutas.cierre.firma-transportista',
        'punto-venta.rutas.cierre.firma-recepcion',
        'almacen-mayorista.traslados-planta.cierre.firma-recepcion',
        'punto-venta.pedidos.store',
        'punto-venta.pedidos.aceptar',
        'punto-venta.pedidos.confirmar-recepcion',
        'pedidos.asignar-transportista',
        'almacen-agricola.movimientos.store',
        'almacen-planta.movimientos.store',
        'almacen-mayorista.movimientos.store',
    ];

    private int $secuencia = 0;

    private function crearUsuario(string $rol, array $overrides = []): Usuario
    {
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate($rol, 'web');
        $n = ++$this->secuencia;

        $usuario = Usuario::create(array_merge([
            'nombre' => 'Test',
            'apellido' => ucfirst($rol).$n,
            'email' => "{$rol}{$n}.rbac@test.local",
            'nombreusuario' => "{$rol}{$n}_rbac",
            'passwordhash' => Hash::make('secret123'),
            'role' => $rol,
            'fecharegistro' => now(),
            'fechamodificacion' => now(),
            'activo' => true,
        ], $overrides));

        $usuario->syncRoles([$rol]);

        return $usuario;
    }

    /** @return list<RouteDefinition> */
    private function rutas(): array
    {
        return array_values(Route::getRoutes()->getRoutes());
    }

    /** Rutas de routes/api.php (grupo «api», token Sanctum). Algunas rutas web usan el prefijo api/ con sesión. */
    private function esApi(RouteDefinition $ruta): bool
    {
        return in_array('api', $ruta->gatherMiddleware(), true);
    }

    private function esWeb(RouteDefinition $ruta): bool
    {
        return in_array('web', $ruta->gatherMiddleware(), true);
    }

    private function esEscritura(RouteDefinition $ruta): bool
    {
        return array_diff($ruta->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
    }

    private function uriConParametros(RouteDefinition $ruta): string
    {
        return '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $ruta->uri()), '/');
    }

    // ------------------------------------------------------------------ SEC-01

    public function test_toda_ruta_api_no_publica_exige_token_y_responde_401_sin_sesion(): void
    {
        $revisadas = 0;

        foreach ($this->rutas() as $ruta) {
            if (! $this->esApi($ruta) || in_array($ruta->uri(), self::API_PUBLICAS, true)) {
                continue;
            }

            $this->assertContains('auth:sanctum', $ruta->gatherMiddleware(), "La ruta API {$ruta->uri()} no exige auth:sanctum.");

            foreach (array_diff($ruta->methods(), ['HEAD', 'OPTIONS']) as $metodo) {
                $this->json($metodo, $this->uriConParametros($ruta), ['password' => 'x'])
                    ->assertUnauthorized();
                $revisadas++;
            }
        }

        // Cobertura mínima del backlog: usuarios, roles, almacenes, actividades, producciones, movimientos…
        $this->assertGreaterThan(60, $revisadas);
    }

    public function test_toda_ruta_web_no_publica_exige_sesion(): void
    {
        foreach ($this->rutas() as $ruta) {
            if ($this->esApi($ruta)) {
                continue;
            }
            $publica = $this->esEscritura($ruta)
                ? in_array($ruta->getName(), self::WEB_ESCRITURA_PUBLICAS, true)
                : in_array($ruta->uri(), self::WEB_LECTURA_PUBLICAS, true);
            if ($publica) {
                continue;
            }

            $this->assertContains('auth', $ruta->gatherMiddleware(), "La ruta web {$ruta->uri()} no exige sesión.");
        }
    }

    // ------------------------------------------------------------------ SEC-02

    public function test_no_existe_alta_publica_de_administrador(): void
    {
        foreach (['/api/register-admin', '/register-admin'] as $uri) {
            $this->postJson($uri, [
                'nombre' => 'X', 'apellido' => 'Y', 'email' => 'intruso@test.local',
                'nombreusuario' => 'intruso', 'password' => 'secret123', 'rol_solicitado' => 'admin',
            ])->assertStatus(404);
        }

        // El registro web tampoco acepta «admin» como rol solicitado.
        $this->post(route('register.post'), [
            'nombre' => 'X', 'apellido' => 'Y', 'email' => 'intruso2@test.local',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'rol_solicitado' => 'admin',
        ])->assertSessionHasErrors('rol_solicitado');

        $this->assertDatabaseMissing('usuario', ['email' => 'intruso@test.local']);
        $this->assertDatabaseMissing('usuario', ['email' => 'intruso2@test.local']);
    }

    // ------------------------------------------------------------------ ADM-01 / ADM-05

    public function test_matriz_sin_comodin_y_admin_solo_con_permisos_de_supervision_o_administracion(): void
    {
        $matriz = config('permission_matrix');
        $definidos = [];
        foreach ($matriz['modules'] as $acciones) {
            foreach ($acciones as $permiso) {
                $definidos[$permiso] = true;
            }
        }

        foreach ($matriz['role_permissions'] as $rol => $permisos) {
            $this->assertNotContains('*', $permisos, "El rol {$rol} tiene comodín.");
            foreach ($permisos as $permiso) {
                $this->assertArrayHasKey($permiso, $definidos, "El rol {$rol} usa el permiso no definido {$permiso}.");
            }
        }

        // Escritura permitida al admin: solo módulos de administración de plataforma.
        $modulosAdministracion = ['usuarios', 'solicitudes', 'catalogos', 'vehiculos', 'transportistas', 'direcciones'];
        foreach ($matriz['role_permissions']['admin'] as $permiso) {
            $modulo = explode('.', $permiso)[0];
            $esLectura = str_ends_with($permiso, '.view');
            $this->assertTrue(
                $esLectura || in_array($modulo, $modulosAdministracion, true),
                "El admin no debería tener el permiso operativo {$permiso}."
            );
        }

        $this->assertStringNotContainsString('Gate::before', (string) file_get_contents(app_path('Providers/AppServiceProvider.php')));
    }

    public function test_helpers_separan_supervision_de_operacion(): void
    {
        $admin = $this->crearUsuario('admin');
        $jefePlanta = $this->crearUsuario('jefe_planta');

        $this->assertTrue(UsuarioRol::puedeSupervisarTodo($admin));
        $this->assertFalse(UsuarioRol::puedeOperar($admin));
        $this->assertFalse(UsuarioRol::puedeEjecutarOperacion($admin, 'lotes.view'), 'Supervisar no es operar, aunque tenga el permiso.');

        $this->assertFalse(UsuarioRol::puedeSupervisarTodo($jefePlanta));
        $this->assertTrue(UsuarioRol::puedeEjecutarOperacion($jefePlanta, 'lote_produccion.update'));
        $this->assertFalse(UsuarioRol::puedeEjecutarOperacion($jefePlanta, 'lotes.create'), 'Operar exige el permiso explícito.');
    }

    // ------------------------------------------------------------------ ADM-02 / ADM-03 / ADM-04

    public function test_admin_no_ejecuta_ninguna_escritura_web_fuera_de_administracion(): void
    {
        $admin = $this->crearUsuario('admin');
        $middleware = new AdminSoloSupervision;
        $bloqueadas = [];

        foreach ($this->rutas() as $ruta) {
            if ($this->esApi($ruta) || ! $this->esEscritura($ruta)) {
                continue;
            }

            $this->assertTrue($this->esWeb($ruta), "La ruta {$ruta->uri()} no pasa por el grupo web (AdminSoloSupervision).");

            $metodo = array_values(array_diff($ruta->methods(), ['GET', 'HEAD', 'OPTIONS']))[0];
            $request = Request::create($this->uriConParametros($ruta), $metodo);
            $request->setRouteResolver(fn () => $ruta);
            $request->setUserResolver(fn () => $admin);

            if ($request->routeIs(...AdminSoloSupervision::RUTAS_ADMINISTRACION)
                || in_array($ruta->getName(), self::WEB_ESCRITURA_PUBLICAS, true)) {
                continue;
            }

            try {
                $middleware->handle($request, fn () => response('ejecutado'));
                $this->fail("El admin pudo ejecutar {$metodo} {$ruta->uri()} ({$ruta->getName()}).");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }

            $bloqueadas[] = $ruta->getName();
        }

        foreach (self::OPERACIONES_CRITICAS as $nombre) {
            $this->assertContains($nombre, $bloqueadas, "La operación {$nombre} no está cubierta por el bloqueo del admin.");
        }
    }

    public function test_admin_no_completa_actividad_asignada_a_un_agricultor(): void
    {
        $this->seed(CatalogosOperacionAgricolaSeeder::class);
        $admin = $this->crearUsuario('admin');
        $agricultor = $this->crearUsuario('agricultor');
        $lote = Lote::create([
            'usuarioid' => $agricultor->usuarioid,
            'nombre' => 'Lote RBAC',
            'ubicacion' => 'Parcela test',
            'superficie' => 1,
            'unidadsuperficieid' => UnidadMedida::query()->firstOrCreate(['abreviatura' => 'ha'], ['nombre' => 'Hectárea', 'categoria' => 'superficie'])->unidadmedidaid,
            'cultivoid' => Cultivo::query()->firstOrCreate(['nombre' => 'Tomate'], ['detalle' => 'Test'])->cultivoid,
            'estadolotetipoid' => EstadoLoteTipo::query()->firstOrFail()->estadolotetipoid,
            'fechacreacion' => now(),
            'fechamodificacion' => now(),
        ]);
        $actividad = Actividad::create([
            'loteid' => $lote->loteid,
            'usuarioid' => $agricultor->usuarioid,
            'descripcion' => 'Riego asignado',
            'fechainicio' => now(),
            'fechafin' => null,
            'tipoactividadid' => TipoActividad::create(['nombre' => 'Riego RBAC'])->tipoactividadid,
            'prioridadid' => Prioridad::query()->firstOrCreate(['nombre' => 'Media'])->prioridadid,
        ]);

        // Capa de dominio (independiente del middleware): el admin supervisa, no completa.
        $this->assertTrue(ActividadPermisos::puedeAcceder($admin, $actividad));
        $this->assertFalse(ActividadPermisos::puedeMarcarCompletada($admin, $actividad));

        $this->actingAs($admin)
            ->post(route('actividades.marcar-realizada', $actividad))
            ->assertForbidden();

        $this->assertNull($actividad->fresh()->fechafin);
        $this->actingAs($admin)->get(route('lotes.index'))->assertOk();
    }

    public function test_admin_no_completa_etapa_de_planta_asignada_a_un_operario(): void
    {
        $admin = $this->crearUsuario('admin');
        $jefe = $this->crearUsuario('jefe_planta');
        $operario = $this->crearUsuario('planta');

        $pedido = Pedido::create([
            'numero_solicitud' => 'LP-RBAC-PED',
            'nombre_planta' => 'Planta test',
            'latitud' => -12.0,
            'longitud' => -77.0,
            'estado' => 'en produccion',
        ]);
        $lote = LoteProduccionPedido::create([
            'pedidoid' => $pedido->pedidoid,
            'codigo_lote' => 'LP-RBAC',
            'nombre' => 'Lote RBAC',
            'producto' => 'Producto test',
            'fecha_creacion' => now()->toDateString(),
        ]);
        $asignacion = AsignacionEtapaPlanta::create([
            'loteproduccionpedidoid' => $lote->loteproduccionpedidoid,
            'procesoplantaid' => ProcesoPlanta::create(['nombre' => 'Lavado', 'activo' => true])->procesoplantaid,
            'maquinaplantaid' => MaquinaPlanta::create(['nombre' => 'Lavadora', 'codigo' => 'MQ-RBAC', 'activo' => true])->maquinaplantaid,
            'operador_usuarioid' => $operario->usuarioid,
            'asignado_por_usuarioid' => $jefe->usuarioid,
            'orden' => 1,
            'estado' => AsignacionEtapaPlanta::ESTADO_PENDIENTE,
            'creado_en' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('tareas-planta.completar', $asignacion))
            ->assertForbidden();
        $this->actingAs($admin)
            ->post(route('procesamiento.completar-etapa-asignada', [$lote, $asignacion]))
            ->assertForbidden();

        $this->assertSame(AsignacionEtapaPlanta::ESTADO_PENDIENTE, $asignacion->fresh()->estado);
        $this->actingAs($admin)->get(route('procesamiento.show', $lote))->assertOk();
    }

    public function test_admin_no_inicia_ruta_aunque_figure_como_transportista(): void
    {
        $admin = $this->crearUsuario('admin');
        $transportista = $this->crearUsuario('transportista');

        $rutaDelAdmin = new RutaDistribucion(['transportista_usuarioid' => $admin->usuarioid]);
        $rutaDelTransportista = new RutaDistribucion(['transportista_usuarioid' => $transportista->usuarioid]);

        $this->assertFalse(UsuarioRol::puedeMarcarEnRutaDistribucion($admin, $rutaDelAdmin));
        $this->assertFalse(UsuarioRol::puedeMarcarEnRutaDistribucion($admin, $rutaDelTransportista));
        $this->assertTrue(UsuarioRol::puedeMarcarEnRutaDistribucion($transportista, $rutaDelTransportista));
    }

    public function test_admin_no_crea_pedidos_por_api_ni_como_minorista(): void
    {
        $admin = $this->crearUsuario('admin');

        Sanctum::actingAs($admin);
        $this->getJson('/api/pedidos')->assertOk();
        $this->postJson('/api/pedidos', [])->assertForbidden();

        $this->actingAs($admin)->post(route('punto-venta.pedidos.store'), [])->assertForbidden();
        $this->assertFalse(UsuarioRol::puedeGestionarDistribucionMayorista($admin));
    }

    // ------------------------------------------------------------------ ADM-06

    public function test_rol_admin_canonico_y_compatibilidad_legacy_centralizada(): void
    {
        $this->assertSame('admin', UsuarioRol::ROL_ADMIN);
        $this->assertSame(['admin', 'Admin'], UsuarioRol::nombresRolAdmin());

        $soloColumna = $this->crearUsuario('agricultor', ['role' => 'admin']);
        $soloColumna->syncRoles([]);
        $this->assertTrue(UsuarioRol::esAdminGlobal($soloColumna->fresh()));

        // Ninguna comparación manual con el slug legacy fuera del helper central.
        foreach (['app', 'resources/views'] as $dir) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                if ($archivo->getExtension() !== 'php' || $archivo->getFilename() === 'UsuarioRol.php') {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression(
                    "/['\"]Admin['\"]\s*[\],)]/",
                    (string) file_get_contents($archivo->getPathname()),
                    'Comparación manual con el rol legacy «Admin» en '.$archivo->getPathname()
                );
            }
        }
    }

    // ------------------------------------------------------------------ D1 Gestión de usuarios

    public function test_admin_gestiona_usuarios_globalmente(): void
    {
        $admin = $this->crearUsuario('admin');
        $agricultor = $this->crearUsuario('agricultor');
        $rolPlanta = Role::findOrCreate('planta', 'web');

        $this->actingAs($admin)->get(route('gestion.index'))->assertOk();
        $this->actingAs($admin)->get(route('gestion.show', $agricultor))->assertOk();

        $this->actingAs($admin)->post(route('gestion.usuario.store'), [
            'nombre' => 'Nuevo',
            'apellido' => 'Operario',
            'email' => 'nuevo.operario@test.local',
            'nombreusuario' => 'nuevo_operario',
            'passwordhash' => 'secret123',
            'rolid' => $rolPlanta->id,
        ])->assertRedirect();

        $this->assertTrue(Usuario::query()->where('email', 'nuevo.operario@test.local')->firstOrFail()->hasRole('planta'));
    }

    public function test_rol_con_permisos_usuarios_no_se_convierte_en_admin_global(): void
    {
        // jefe_mayorista (legacy) tenía usuarios.* en la matriz: antes caía en el modo global
        // y podía ver/editar/eliminar a cualquiera y crear cuentas con rol admin. La matriz ya no
        // se los da (MAY-01), pero aun concedidos a mano no deben convertirlo en administrador.
        $admin = $this->crearUsuario('admin');
        $jefeMayorista = $this->crearUsuario('jefe_mayorista');
        $jefeMayorista->givePermissionTo(['usuarios.view', 'usuarios.create', 'usuarios.update', 'usuarios.delete']);
        $this->assertTrue($jefeMayorista->can('usuarios.create'));
        $rolAdmin = Role::findByName('admin', 'web');

        $this->actingAs($jefeMayorista);
        $this->get(route('gestion.index'))->assertForbidden();
        $this->get(route('gestion.create'))->assertForbidden();
        $this->get(route('gestion.show', $admin))->assertForbidden();
        $this->get(route('gestion.edit', $admin))->assertForbidden();
        $this->post(route('gestion.usuario.store'), [
            'nombre' => 'Falso',
            'apellido' => 'Admin',
            'email' => 'falso.admin@test.local',
            'nombreusuario' => 'falso_admin',
            'passwordhash' => 'secret123',
            'rolid' => $rolAdmin->id,
        ])->assertForbidden();
        $this->put(route('gestion.usuario.update', $admin), [
            'nombre' => 'Hack', 'apellido' => 'Hack', 'email' => $admin->email, 'nombreusuario' => $admin->nombreusuario,
        ])->assertForbidden();
        $this->delete(route('gestion.usuario.destroy', $admin))->assertForbidden();

        $this->assertDatabaseMissing('usuario', ['email' => 'falso.admin@test.local']);
        $this->assertNotNull(Usuario::find($admin->usuarioid));
        $this->assertSame('Test', $admin->fresh()->nombre);
        $this->assertFalse(UsuarioRol::puedeGestionarUsuarios($jefeMayorista));
    }

    public function test_jefe_solo_gestiona_su_equipo_y_no_crea_administradores(): void
    {
        $admin = $this->crearUsuario('admin');
        $jefe = $this->crearUsuario('jefe_agricultor');
        $empleado = $this->crearUsuario('agricultor', ['supervisor_usuarioid' => $jefe->usuarioid]);
        $ajeno = $this->crearUsuario('agricultor');
        $rolAdmin = Role::findByName('admin', 'web');

        $this->actingAs($jefe);
        $this->get(route('gestion.index'))->assertOk();
        $this->get(route('gestion.show', $empleado))->assertOk();
        $this->get(route('gestion.show', $ajeno))->assertForbidden();
        $this->get(route('gestion.show', $admin))->assertForbidden();
        $this->delete(route('gestion.usuario.destroy', $admin))->assertForbidden();

        // El rol del empleado lo fija el jefe; un rolid inyectado se ignora.
        $this->post(route('gestion.usuario.store'), [
            'nombre' => 'Empleado',
            'apellido' => 'Campo',
            'email' => 'empleado.campo@test.local',
            'passwordhash' => 'secret123',
            'rolid' => $rolAdmin->id,
        ])->assertRedirect();

        $nuevo = Usuario::query()->where('email', 'empleado.campo@test.local')->firstOrFail();
        $this->assertTrue($nuevo->hasRole('agricultor'));
        $this->assertFalse(UsuarioRol::esAdminGlobal($nuevo));
        $this->assertSame((int) $jefe->usuarioid, (int) $nuevo->supervisor_usuarioid);

        // Aprobar solicitudes y administrar roles sigue siendo exclusivo del admin.
        $this->post(route('gestion.rol.store'), ['nombre' => 'rol_intruso'])->assertForbidden();
    }

    public function test_rol_operativo_normal_no_gestiona_usuarios(): void
    {
        foreach (['agricultor', 'planta', 'transportista', 'minorista', 'mayorista'] as $rol) {
            $usuario = $this->crearUsuario($rol);
            $this->assertFalse(UsuarioRol::puedeGestionarUsuarios($usuario), $rol);
            $this->actingAs($usuario)->get(route('gestion.index'))->assertForbidden();
        }
    }

    // ------------------------------------------------------------------ C1 / C2 Permisos

    public function test_toda_ruta_referencia_un_permiso_existente_en_la_matriz(): void
    {
        foreach ($this->rutas() as $ruta) {
            foreach ($ruta->gatherMiddleware() as $mw) {
                if (! is_string($mw) || ! str_starts_with($mw, 'action.permission:')) {
                    continue;
                }
                [$modulo, $accion] = explode(',', substr($mw, strlen('action.permission:')));
                $this->assertNotNull(
                    config("permission_matrix.modules.{$modulo}.{$accion}"),
                    "La ruta {$ruta->uri()} usa {$modulo},{$accion}, que no existe en la matriz."
                );
            }
        }
    }

    public function test_codigo_no_consulta_permisos_inexistentes(): void
    {
        $definidos = [];
        foreach (config('permission_matrix.modules') as $acciones) {
            foreach ($acciones as $permiso) {
                $definidos[$permiso] = true;
            }
        }

        // Pendientes de decisión del frente de logística: hoy siempre evalúan false
        // (no existe «asignaciones.read»); cambiarlos a «.view» ampliaría el acceso.
        $pendientes = ['asignaciones.read'];

        $patron = '/(?:->can|->cannot|->canany|hasPermissionTo|hasAnyPermission|@can|@canany|@cannot)\s*\(\s*(\[[^\]]*\]|\'[^\']+\'|"[^"]+")/';
        $desconocidos = [];
        foreach (['app', 'resources/views', 'routes'] as $dir) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                if ($archivo->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all($patron, (string) file_get_contents($archivo->getPathname()), $llamadas);
                foreach ($llamadas[1] as $argumento) {
                    preg_match_all('/[\'"]([a-z_]+(?:\.[a-z_]+)+)[\'"]/', $argumento, $permisos);
                    foreach ($permisos[1] as $permiso) {
                        if (! isset($definidos[$permiso]) && ! in_array($permiso, $pendientes, true)) {
                            $desconocidos[] = $permiso.' en '.$archivo->getFilename();
                        }
                    }
                }
            }
        }

        $this->assertSame([], $desconocidos, 'Permisos inexistentes (typo view/read): '.implode(', ', $desconocidos));
    }

    public function test_movimiento_de_almacen_por_api_exige_permiso_de_escritura(): void
    {
        // Antes: POST api/almacen-movimientos/{naturaleza} solo exigía almacen_movimientos,read
        // en la ruta (el permiso real quedaba solo dentro del controlador).
        foreach (['ingreso' => 'almacen_ingresos,create', 'salida' => 'almacen_salidas,create'] as $naturaleza => $permiso) {
            $ruta = Route::getRoutes()->match(Request::create("/api/almacen-movimientos/{$naturaleza}", 'POST'));
            $this->assertContains('action.permission:'.$permiso, $ruta->gatherMiddleware());
        }

        $this->seed(RolePermissionSeeder::class);
        $soloLectura = Role::findOrCreate('auditor_almacen_test', 'web');
        $soloLectura->syncPermissions([Permission::findByName('almacen.movimientos.view', 'web')]);
        $auditor = $this->crearUsuario('auditor_almacen_test');

        Sanctum::actingAs($auditor);
        $this->getJson('/api/almacen-movimientos')->assertOk();
        $this->postJson('/api/almacen-movimientos/ingreso', [])->assertForbidden();
        $this->postJson('/api/almacen-movimientos/salida', [])->assertForbidden();

        Sanctum::actingAs($this->crearUsuario('admin'));
        $this->postJson('/api/almacen-movimientos/ingreso', [])->assertForbidden();

        // Con permiso de escritura llega a la validación (422), no al 403.
        Sanctum::actingAs($this->crearUsuario('jefe_planta'));
        $this->postJson('/api/almacen-movimientos/ingreso', [])->assertUnprocessable();
        $this->postJson('/api/almacen-movimientos/traslado', [])->assertNotFound();
    }
}
