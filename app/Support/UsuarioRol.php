<?php

namespace App\Support;

use App\Models\RutaDistribucion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

final class UsuarioRol
{
    /** Rol Spatie canónico del administrador de la plataforma. */
    public const ROL_ADMIN = 'admin';

    /** Slugs legacy del administrador aún presentes en datos antiguos (columna `role`). */
    private const ROLES_ADMIN_LEGACY = ['Admin'];

    /**
     * Nombres de rol que identifican al admin, para consultas SQL sobre `role` o `roles.name`.
     * Única fuente de la compatibilidad legacy: no repetir ['admin', 'Admin'] en otros archivos.
     *
     * @return list<string>
     */
    public static function nombresRolAdmin(): array
    {
        return [self::ROL_ADMIN, ...self::ROLES_ADMIN_LEGACY];
    }

    /**
     * Administrador de la plataforma (incluye el slug legacy «Admin»).
     *
     * El admin es un rol de SUPERVISIÓN: puede consultar todo y administrar usuarios,
     * solicitudes, catálogos y reportes, pero no ejecuta flujos de negocio
     * (actividades, tareas de planta, transporte, firmas, pedidos). Use este helper
     * solo para visibilidad/alcance de consultas; para acciones use {@see puedeEjecutarOperacion()}.
     */
    public static function esAdminGlobal(?Usuario $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(self::nombresRolAdmin())
            || strtolower((string) ($user->role ?? '')) === self::ROL_ADMIN;
    }

    /**
     * Supervisión global de solo lectura (lotes, planta, rutas, pedidos, reportes, trazabilidad).
     * Nunca debe usarse para autorizar una escritura.
     */
    public static function puedeSupervisarTodo(?Usuario $user): bool
    {
        return self::esAdminGlobal($user);
    }

    /** Usuario autenticado que puede ejecutar flujos operativos (todos menos el admin supervisor). */
    public static function puedeOperar(?Usuario $user): bool
    {
        return $user !== null && ! self::esAdminGlobal($user);
    }

    /**
     * Ejecutar una operación de negocio: exige ser actor operativo Y tener el permiso explícito.
     * La supervisión global ({@see puedeSupervisarTodo()}) no concede operación. El ownership
     * (lote, etapa, ruta, pedido) sigue siendo responsabilidad de cada módulo.
     */
    public static function puedeEjecutarOperacion(?Usuario $user, string $permiso): bool
    {
        return self::puedeOperar($user) && $user->can($permiso);
    }

    /**
     * Administración global de usuarios, roles y solicitudes. Solo el admin: tener permisos
     * `usuarios.*` en la matriz NO convierte a un rol operativo en administrador global.
     */
    public static function administraUsuariosGlobal(?Usuario $user): bool
    {
        return self::esAdminGlobal($user);
    }

    /** Acceso al módulo Gestión de usuarios: admin (global) o jefe agrícola/planta (solo su equipo). */
    public static function puedeGestionarUsuarios(?Usuario $user): bool
    {
        return self::administraUsuariosGlobal($user)
            || (self::puedeOperar($user) && self::puedeGestionarEmpleados($user));
    }

    public static function esAgricultorOperativo(?Usuario $user): bool
    {
        return (bool) ($user && $user->hasRole('agricultor'));
    }

    public static function esJefeAgricultor(?Usuario $user): bool
    {
        return (bool) ($user && $user->hasRole('jefe_agricultor'));
    }

    public static function esJefePlanta(?Usuario $user): bool
    {
        return (bool) ($user && $user->hasRole('jefe_planta'));
    }

    public static function esPlantaOperativo(?Usuario $user): bool
    {
        return (bool) ($user && $user->hasAnyRole(['planta', 'jefe_planta']));
    }

    /** Operario de planta (Spatie rol planta, sin jefe_planta ni admin). */
    public static function esOperarioPlanta(?Usuario $user): bool
    {
        return (bool) ($user && $user->hasRole('planta') && ! self::esJefePlanta($user) && ! self::esAdminGlobal($user));
    }

    /** Operario de planta: solo ve lotes de producción con etapas asignadas a él. */
    public static function debeAcotarLotesProduccionPorAsignacion(?Usuario $user): bool
    {
        return self::esOperarioPlanta($user);
    }

    /** Usuarios asignables como operarios en transformación (solo Spatie «planta», nunca jefe). */
    public static function queryOperariosPlanta(): Builder
    {
        return Usuario::query()
            ->where('activo', true)
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'planta'))
            ->whereDoesntHave('roles', fn (Builder $q) => $q->whereIn('name', ['jefe_planta', ...self::nombresRolAdmin()]))
            ->where(function (Builder $q) {
                $q->whereNull('email')
                    ->orWhere('email', '!=', 'planta@agrofusion.com');
            });
    }

    public static function puedeConfirmarRecepcionPlanta(?Usuario $user): bool
    {
        return self::puedeOperar($user) && $user->hasAnyRole(['planta', 'jefe_planta']);
    }

    public static function esTransportista(?Usuario $user): bool
    {
        return (bool) ($user && $user->hasRole('transportista'));
    }

    public static function esMinorista(?Usuario $user): bool
    {
        return (bool) ($user && $user->hasRole('minorista'));
    }

    public static function esJefeMayorista(?Usuario $user): bool
    {
        return self::esMayorista($user);
    }

    /** Rol operativo mayorista (incluye legacy jefe_mayorista). */
    public static function esMayorista(?Usuario $user): bool
    {
        return (bool) ($user && $user->hasAnyRole(['mayorista', 'jefe_mayorista']));
    }

    /** Gestiona (acepta, rechaza, despacha) pedidos de distribución como mayorista. */
    public static function puedeGestionarDistribucionMayorista(?Usuario $user): bool
    {
        return self::puedeOperar($user) && self::esMayorista($user);
    }

    /** Solo el transportista asignado inicia la ruta (doble control: nadie firma/inicia por él). */
    public static function puedeMarcarEnRutaDistribucion(?Usuario $user, RutaDistribucion $ruta): bool
    {
        return self::puedeOperar($user)
            && self::esTransportista($user)
            && (int) $ruta->transportista_usuarioid === (int) $user->usuarioid;
    }

    /**
     * Planificación manual de rutas de distribución (módulo deshabilitado en el menú).
     * Antes era exclusiva del admin; al ser supervisor ya no la ejecuta y ningún rol operativo la tiene asignada.
     */
    public static function puedePlanificarDistribucion(?Usuario $user): bool
    {
        return false;
    }

    public static function puedeGestionarDistribucionPlanta(?Usuario $user): bool
    {
        return self::puedeGestionarDistribucionMayorista($user);
    }

    /** Jefe agrícola que gestiona el campo (asigna/completa actividades de su equipo). */
    public static function gestionaCampo(?Usuario $user): bool
    {
        return self::puedeOperar($user) && self::esJefeAgricultor($user);
    }

    /** Jefe de planta que gestiona transformación (asigna etapas, cierra fases). */
    public static function gestionaPlanta(?Usuario $user): bool
    {
        return self::puedeOperar($user) && self::esJefePlanta($user);
    }

    /** Agricultor de campo: solo ve lotes/actividades asignados a él (no jefes). */
    public static function debeAcotarPorAsignacion(?Usuario $user): bool
    {
        return self::esAgricultorOperativo($user)
            && ! self::esAdminGlobal($user)
            && ! self::esJefeAgricultor($user);
    }

    public static function puedeGestionarEmpleados(?Usuario $user): bool
    {
        return self::esJefeAgricultor($user) || self::esJefePlanta($user);
    }

    /** @return list<string> */
    public static function rolesEmpleadosGestionables(?Usuario $jefe): array
    {
        if (self::esJefeAgricultor($jefe)) {
            return ['agricultor'];
        }
        if (self::esJefePlanta($jefe)) {
            return ['planta'];
        }

        return [];
    }

    /** IDs de usuarios cuyos lotes/actividades ve un jefe agrícola (él + su equipo). */
    public static function idsUsuariosBajoJefeAgricultor(?Usuario $jefe): array
    {
        if (! $jefe || ! self::esJefeAgricultor($jefe)) {
            return [];
        }

        $ids = self::idsEmpleadosOperativosDeJefeAgricultor($jefe);
        $ids[] = (int) $jefe->usuarioid;

        return array_values(array_unique($ids));
    }

    /** Empleados agrícolas operativos bajo un jefe (sin incluir al jefe). */
    public static function idsEmpleadosOperativosDeJefeAgricultor(?Usuario $jefe): array
    {
        if (! $jefe || ! self::esJefeAgricultor($jefe)) {
            return [];
        }

        return Usuario::query()
            ->where('supervisor_usuarioid', $jefe->usuarioid)
            ->whereIn('role', self::rolesEmpleadosGestionables($jefe))
            ->where('activo', true)
            ->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', 'jefe_agricultor'))
            ->pluck('usuarioid')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** Usuario que puede ejecutar una actividad de campo (no jefe ni admin). */
    public static function esResponsableActividadPermitido(?Usuario $user): bool
    {
        if (! $user || ! $user->activo) {
            return false;
        }

        if (self::esAdminGlobal($user) || self::esJefeAgricultor($user)) {
            return false;
        }

        $rolColumna = strtolower((string) ($user->role ?? ''));

        return $rolColumna === 'agricultor' || $user->hasRole('agricultor');
    }

    /** Rol operativo que un jefe puede asignar al crear empleados. */
    public static function rolEmpleadoAsignable(?Usuario $jefe): ?string
    {
        if (self::esJefeAgricultor($jefe)) {
            return 'agricultor';
        }
        if (self::esJefePlanta($jefe)) {
            return 'planta';
        }

        return null;
    }

    /** Etiqueta legible para mostrar en pantalla (sin guiones bajos ni jerga técnica). */
    public static function etiquetaRol(?string $nombre): string
    {
        return match (strtolower((string) $nombre)) {
            'admin' => 'Administrador',
            'agricultor' => 'Agricultor (Operario)',
            'jefe_agricultor' => 'Jefe Agricultor',
            'jefe_planta' => 'Jefe Planta',
            'jefe_mayorista' => 'Mayorista',
            'mayorista' => 'Mayorista',
            'minorista' => 'Minorista',
            'planta' => 'Planta (Operario)',
            'transportista' => 'Transportista',
            default => ucfirst(str_replace('_', ' ', (string) $nombre)),
        };
    }

    /** Etiqueta(s) de rol para listados; transportistas incluyen categoría de flota. */
    public static function etiquetasUsuario(Usuario $usuario): string
    {
        $nombres = $usuario->relationLoaded('roles')
            ? $usuario->roles->pluck('name')->all()
            : $usuario->getRoleNames()->all();

        if ($nombres === []) {
            return filled($usuario->role)
                ? self::etiquetaRol((string) $usuario->role)
                : 'Sin rol';
        }

        $etiquetas = [];
        foreach ($nombres as $nombre) {
            if (strtolower((string) $nombre) === 'transportista') {
                $ambito = $usuario->perfilTransportista?->ambito_flota
                    ?? TransportistaFlotaCatalogo::AGRICOLA;
                $etiquetas[] = 'Transportista/'.TransportistaFlotaCatalogo::categoriaRolTransportista($ambito);
            } else {
                $etiquetas[] = self::etiquetaRol((string) $nombre);
            }
        }

        return implode(' · ', array_values(array_unique($etiquetas)));
    }

    /** @return list<string> Slugs legacy que no deben listarse en selectores de rol. */
    public static function rolesLegacyOcultosEnSelector(): array
    {
        return ['jefe_mayorista'];
    }

    public static function puedeAprobarSolicitud(?Usuario $user, ?string $rolSolicitado): bool
    {
        return self::administraUsuariosGlobal($user);
    }
}
