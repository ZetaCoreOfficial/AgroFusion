<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

final class ActividadPermisos
{
    public static function puedeAcceder(?Usuario $user, Actividad $actividad): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            $actividad->loadMissing('lote');

            return $actividad->lote
                && in_array(
                    (int) $actividad->lote->usuarioid,
                    UsuarioRol::idsUsuariosBajoJefeAgricultor($user),
                    true
                );
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            $uid = (int) $user->usuarioid;

            return (int) $actividad->usuarioid === $uid
                || (int) ($actividad->usuarioid_ejecutor ?? 0) === $uid;
        }

        return false;
    }

    /**
     * Mismo alcance que el listado web de actividades.
     *
     * @param  Builder<\App\Models\Actividad>  $query
     */
    public static function aplicarScopeVisibles(Builder $query, ?Usuario $user): void
    {
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            $uid = (int) $user->usuarioid;
            $query->where(function (Builder $q) use ($uid) {
                $q->where('usuarioid', $uid)
                    ->orWhere('usuarioid_ejecutor', $uid);
            });
        } elseif (UsuarioRol::esJefeAgricultor($user) && ! UsuarioRol::esAdminGlobal($user)) {
            $query->whereHas(
                'lote',
                fn (Builder $q) => $q->whereIn('usuarioid', UsuarioRol::idsUsuariosBajoJefeAgricultor($user))
            );
        } elseif (! UsuarioRol::esAdminGlobal($user)) {
            $query->whereRaw('1 = 0');
        }
    }

    public static function puedeMarcarCompletada(?Usuario $user, Actividad $actividad): bool
    {
        // El admin supervisa actividades pero no las completa en nombre de otros.
        if (! UsuarioRol::puedeOperar($user) || $actividad->fechafin !== null) {
            return false;
        }

        $secuencia = app(ActividadSecuenciaService::class);
        if (! $secuencia->esSiguienteEnCola($actividad, false)) {
            return false;
        }

        // AGR-05: el jefe supervisa; no ejecuta/completa tareas del operario.
        if (UsuarioRol::esJefeAgricultor($user)) {
            return false;
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            $uid = (int) $user->usuarioid;

            return (int) $actividad->usuarioid === $uid
                || (int) ($actividad->usuarioid_ejecutor ?? 0) === $uid;
        }

        return false;
    }
}
