<?php

use App\Support\PermissionMatrixSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TRA-09: usuarios con la columna legacy role=transportista sin el rol Spatie lo reciben
 *         (el ownership y los permisos se resuelven con Spatie).
 * TRA-11: resincroniza el rol transportista desde la matriz (sin envios.update,
 *         pedidos_distribucion.update, monitoreo.view ni incidentes.delete).
 * Solo agrega asignaciones de rol y ajusta permisos del rol; no borra usuarios ni datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles') || ! Schema::hasTable('usuario')) {
            return;
        }

        $rol = Role::findOrCreate('transportista', 'web');
        PermissionMatrixSync::syncRole('transportista');

        $sinRol = DB::table('usuario')
            ->whereRaw('LOWER(role) = ?', ['transportista'])
            ->whereNotExists(function ($q) use ($rol) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'usuario.usuarioid')
                    ->where('model_has_roles.role_id', $rol->id)
                    ->where('model_has_roles.model_type', \App\Models\Usuario::class);
            })
            ->pluck('usuarioid');

        foreach ($sinRol as $usuarioId) {
            DB::table('model_has_roles')->insert([
                'role_id' => $rol->id,
                'model_type' => \App\Models\Usuario::class,
                'model_id' => $usuarioId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin reversión: quitar el rol Spatie dejaría a los conductores sin acceso a sus viajes.
    }
};
