<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Support\Collection;

/** Responsable operativo de un almacén según su ámbito (no el admin global). */
final class AlmacenResponsableCatalogo
{
    /** @return list<string> */
    public static function rolesSpatie(string $ambito): array
    {
        return match ($ambito) {
            AlmacenAmbito::AGRICOLA => ['jefe_agricultor', 'agricultor'],
            AlmacenAmbito::PLANTA => ['jefe_planta', 'planta'],
            AlmacenAmbito::MAYORISTA => ['jefe_mayorista', 'mayorista'],
            AlmacenAmbito::PUNTO_VENTA => ['minorista'],
            default => [],
        };
    }

    public static function etiquetaResponsable(string $ambito): string
    {
        return match ($ambito) {
            AlmacenAmbito::AGRICOLA => 'Jefe agrícola / responsable',
            AlmacenAmbito::PLANTA => 'Jefe de planta / responsable',
            AlmacenAmbito::MAYORISTA => 'Mayorista responsable',
            AlmacenAmbito::PUNTO_VENTA => 'Minorista responsable',
            default => 'Responsable del almacén',
        };
    }

    public static function usuarioValidoParaAmbito(?Usuario $user, string $ambito): bool
    {
        if ($user === null || UsuarioRol::esAdminGlobal($user)) {
            return false;
        }

        $legacy = (string) ($user->role ?? '');
        $legacyValido = match ($ambito) {
            AlmacenAmbito::AGRICOLA => in_array($legacy, ['jefe_agricultor', 'agricultor'], true),
            AlmacenAmbito::PLANTA => in_array($legacy, ['jefe_planta', 'planta'], true),
            AlmacenAmbito::MAYORISTA => in_array($legacy, ['jefe_mayorista', 'mayorista'], true),
            AlmacenAmbito::PUNTO_VENTA => $legacy === 'minorista',
            default => false,
        };

        if ($legacyValido) {
            return true;
        }

        foreach (self::rolesSpatie($ambito) as $rol) {
            if ($user->hasRole($rol)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Usuario> */
    public static function usuariosParaSelector(string $ambito): Collection
    {
        $roles = self::rolesSpatie($ambito);
        if ($roles === []) {
            return collect();
        }

        return Usuario::query()
            ->where('activo', true)
            ->where(function ($q) use ($roles) {
                $q->whereIn('role', $roles)
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', $roles));
            })
            ->whereNotIn('role', UsuarioRol::nombresRolAdmin())
            ->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', UsuarioRol::nombresRolAdmin()))
            ->orderBy('nombre')
            ->orderBy('apellido')
            ->get();
    }
}
