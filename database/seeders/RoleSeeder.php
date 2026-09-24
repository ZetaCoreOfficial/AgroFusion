<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public const ROLES_CANONICOS = ['admin', 'agricultor', 'planta', 'jefe_planta', 'transportista'];

    public const ROLES_LEGACY = ['operador', 'almacen', 'Operador', 'Almacen', 'Admin', 'Agricultor'];

    public function run(): void
    {
        foreach (self::ROLES_CANONICOS as $nombre) {
            Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }

        // Slug canónico «admin»: migra usuarios que aún tengan el rol legacy «Admin».
        Usuario::query()
            ->where(function ($q) {
                $q->where('role', 'Admin')
                    ->orWhereHas('roles', fn ($r) => $r->where('name', 'Admin'));
            })
            ->each(function (Usuario $usuario) {
                if ($usuario->hasRole('Admin')) {
                    $usuario->removeRole('Admin');
                }
                $usuario->assignRole('admin');
                $usuario->role = 'admin';
                $usuario->fechamodificacion = now();
                $usuario->save();
            });

        $agricultorRole = Role::findByName('agricultor', 'web');

        Usuario::query()
            ->where(function ($q) {
                $q->whereIn('role', ['operador', 'almacen'])
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['operador', 'almacen']));
            })
            ->each(function (Usuario $usuario) use ($agricultorRole) {
                $usuario->syncRoles([$agricultorRole->name]);
                $usuario->role = 'agricultor';
                $usuario->fechamodificacion = now();
                $usuario->save();
            });

        $adminRole = Role::findByName('admin', 'web');
        $adminUser = Usuario::where('email', 'admin@agrofusion.com')->first();
        if ($adminUser) {
            $adminUser->syncRoles([$adminRole->name]);
            $adminUser->role = 'admin';
            $adminUser->fechamodificacion = now();
            $adminUser->save();
        }

        $agricultorUser = Usuario::where('email', 'agricultor@agrofusion.com')->first();
        if ($agricultorUser) {
            $agricultorUser->syncRoles([$agricultorRole->name]);
            $agricultorUser->role = 'agricultor';
            $agricultorUser->fechamodificacion = now();
            $agricultorUser->save();
        }

        foreach (['operador@agrofusion.com', 'almacen@agrofusion.com'] as $email) {
            $legacy = Usuario::where('email', $email)->first();
            if ($legacy) {
                $legacy->syncRoles([$agricultorRole->name]);
                $legacy->role = 'agricultor';
                $legacy->fechamodificacion = now();
                $legacy->save();
            }
        }
    }
}
