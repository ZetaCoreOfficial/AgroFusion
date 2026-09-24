<?php

use App\Support\PermissionMatrixSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * MIN-06: el ajuste de stock del punto de venta pasa a ser un permiso explícito
 * (punto_venta.ajuste_stock). Resincroniza el rol minorista desde la matriz; no borra datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        if (Role::query()->where('name', 'minorista')->where('guard_name', 'web')->exists()) {
            PermissionMatrixSync::syncRole('minorista');
        }
    }

    public function down(): void
    {
        // Sin reversión: quitar el permiso dejaría al minorista sin forma auditada de ajustar.
    }
};
