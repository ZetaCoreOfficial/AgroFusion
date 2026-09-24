<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Lote;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

final class LoteAcceso
{
    public static function participoEnLote(Usuario $user, Lote $lote): bool
    {
        if ((int) $lote->usuarioid === (int) $user->usuarioid) {
            return true;
        }

        return Actividad::query()
            ->where('loteid', $lote->loteid)
            ->where(function (Builder $q) use ($user) {
                $uid = (int) $user->usuarioid;
                $q->where('usuarioid', $uid)
                    ->orWhere('usuarioid_ejecutor', $uid);
            })
            ->exists();
    }

    public static function tieneActividadPendiente(Usuario $user, Lote $lote): bool
    {
        return Actividad::query()
            ->where('loteid', $lote->loteid)
            ->where('usuarioid', (int) $user->usuarioid)
            ->whereNull('fechafin')
            ->exists();
    }

    public static function puedeVer(?Usuario $user, Lote $lote): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (UsuarioRol::esJefeAgricultor($user) && ! UsuarioRol::esAdminGlobal($user)) {
            return in_array(
                (int) $lote->usuarioid,
                UsuarioRol::idsUsuariosBajoJefeAgricultor($user),
                true
            );
        }

        if (! UsuarioRol::debeAcotarPorAsignacion($user)) {
            return true;
        }

        return self::participoEnLote($user, $lote);
    }

    /** Encargado del lote o jefe agrícola de su equipo (el admin solo supervisa). */
    public static function puedeGestionar(?Usuario $user, Lote $lote): bool
    {
        // El admin consulta lotes pero no los gestiona.
        if (! UsuarioRol::puedeOperar($user)) {
            return false;
        }

        if (UsuarioRol::gestionaCampo($user)) {
            if (UsuarioRol::esJefeAgricultor($user)) {
                return in_array(
                    (int) $lote->usuarioid,
                    UsuarioRol::idsUsuariosBajoJefeAgricultor($user),
                    true
                );
            }
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            return (int) $lote->usuarioid === (int) $user->usuarioid;
        }

        return $user->can('lotes.update');
    }

    public static function soloLectura(?Usuario $user, Lote $lote): bool
    {
        return self::puedeVer($user, $lote) && ! self::puedeGestionar($user, $lote);
    }

    /** @param  Builder<\App\Models\Lote>  $query */
    public static function aplicarScopeVisibles(Builder $query, ?Usuario $user): void
    {
        if (! $user) {
            return;
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            $uid = (int) $user->usuarioid;
            $query->where(function (Builder $q) use ($uid) {
                $q->where('usuarioid', $uid)
                    ->orWhereHas('actividades', function (Builder $a) use ($uid) {
                        $a->where('usuarioid', $uid)
                            ->orWhere('usuarioid_ejecutor', $uid);
                    });
            });
        } elseif (UsuarioRol::esJefeAgricultor($user) && ! UsuarioRol::esAdminGlobal($user)) {
            $query->whereIn('usuarioid', UsuarioRol::idsUsuariosBajoJefeAgricultor($user));
        }
    }

    /**
     * Lotes donde el operario puede crear o completar actividades (encargado o con tarea pendiente).
     *
     * @param  Builder<\App\Models\Lote>  $query
     */
    public static function aplicarScopeOperativoActividad(Builder $query, ?Usuario $user): void
    {
        if (! $user) {
            return;
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            $uid = (int) $user->usuarioid;
            $query->where(function (Builder $q) use ($uid) {
                $q->where('usuarioid', $uid)
                    ->orWhereHas('actividades', fn (Builder $a) => $a
                        ->where('usuarioid', $uid)
                        ->whereNull('fechafin'));
            });
        } elseif (UsuarioRol::esJefeAgricultor($user) && ! UsuarioRol::esAdminGlobal($user)) {
            $query->whereIn('usuarioid', UsuarioRol::idsUsuariosBajoJefeAgricultor($user));
        }
    }

    public static function denegarConModal(Request $request, string $mensaje = 'No tienes acceso a este lote.'): never
    {
        if ($request->expectsJson()) {
            abort(403, $mensaje);
        }

        $destino = $request->headers->get('referer');
        if (! is_string($destino) || $destino === '') {
            $destino = route('lotes.index');
        }

        throw new HttpResponseException(
            redirect()->to($destino)->with([
                'error' => $mensaje,
                'error_modal' => true,
                'error_modal_titulo' => 'Acceso restringido',
            ])
        );
    }
}
