<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Usuario;

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
            return (int) $actividad->usuarioid === (int) $user->usuarioid;
        }

        return true;
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

        if (UsuarioRol::gestionaCampo($user)) {
            $actividad->loadMissing('lote');

            return $actividad->lote
                && in_array(
                    (int) $actividad->lote->usuarioid,
                    UsuarioRol::idsUsuariosBajoJefeAgricultor($user),
                    true
                );
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            return (int) $actividad->usuarioid === (int) $user->usuarioid;
        }

        return $user->can('lotes.update');
    }
}
