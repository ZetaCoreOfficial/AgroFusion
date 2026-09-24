<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class PermissionMatrixSync
{
    public static function syncRole(string $roleName): void
    {
        $rolePermissions = config('permission_matrix.role_permissions', []);
        $assigned = $rolePermissions[$roleName] ?? null;

        if ($assigned === null) {
            return;
        }

        $allPermissions = collect(config('permission_matrix.modules', []))
            ->flatMap(fn (array $actions) => array_values($actions))
            ->unique()
            ->values()
            ->all();

        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

        // Sin comodín: cada rol (incluido admin) recibe exactamente la lista de la matriz.
        $role->syncPermissions($assigned);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function syncAllRoles(): void
    {
        foreach (array_keys(config('permission_matrix.role_permissions', [])) as $roleName) {
            self::syncRole($roleName);
        }
    }
}
