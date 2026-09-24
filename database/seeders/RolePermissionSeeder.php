<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = config('permission_matrix.modules', []);
        $rolePermissions = config('permission_matrix.role_permissions', []);
        $permissions = collect($modules)
            ->flatMap(fn(array $actions) => array_values($actions))
            ->unique()
            ->values()
            ->all();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (array_keys($rolePermissions) as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions[$roleName] ?? []);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

