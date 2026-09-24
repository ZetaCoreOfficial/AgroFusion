<?php

use App\Support\PermissionMatrixSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * MAY-01: el rol legacy jefe_mayorista deja de tener usuarios.* en la base (la matriz ya no los incluye).
 * Solo resincroniza los permisos del rol desde config/permission_matrix.php; no borra datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        if (Role::query()->where('name', 'jefe_mayorista')->where('guard_name', 'web')->exists()) {
            PermissionMatrixSync::syncRole('jefe_mayorista');
        }
    }

    public function down(): void
    {
        // Sin reversión: devolver usuarios.* reabriría la administración global de usuarios.
    }
};
