<?php

use App\Models\Usuario;
use App\Support\PermissionMatrixSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADM-01 / ADM-06: el rol admin deja de tener todos los permisos (comodín «*»)
 * y pasa a la lista de supervisión de config/permission_matrix.php.
 * Además migra usuarios con el rol legacy «Admin» al slug canónico «admin».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        if (Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists()) {
            PermissionMatrixSync::syncRole('admin');
        }

        $legacy = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->first();
        if ($legacy !== null) {
            Usuario::query()
                ->whereHas('roles', fn ($r) => $r->where('name', 'Admin'))
                ->each(function (Usuario $usuario) {
                    $usuario->removeRole('Admin');
                    $usuario->assignRole('admin');
                    $usuario->role = 'admin';
                    $usuario->save();
                });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin reversión automática: volver al comodín reabriría el bypass del admin.
    }
};
