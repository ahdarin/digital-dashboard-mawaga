<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'client', 'content_plan', 'workflow', 'analytics',
            'master_data', 'report', 'user_management',
        ];
        $actions = ['view', 'create', 'update', 'approve', 'manage'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['module' => $module, 'action' => $action]);
            }
        }

        // Mapping role -> permission (sesuaikan lagi nanti kalau ada modul yang kurang tepat)
        $mapping = [
            'CEO' => ['*'], // semua permission
            'Admin' => [
                ['client', 'manage'], ['master_data', 'manage'],
                ['report', 'manage'], ['user_management', 'manage'],
                ['content_plan', 'view'], ['workflow', 'view'], ['analytics', 'view'],
            ],
            'Content Creator' => [
                ['client', 'view'], ['content_plan', 'manage'],
                ['workflow', 'view'], ['workflow', 'update'],
            ],
            'Graphic Designer' => [
                ['workflow', 'view'], ['workflow', 'update'],
            ],
            'MSO' => [
                ['workflow', 'view'], ['workflow', 'update'],
                ['analytics', 'view'],
            ],
            'Client Owner' => [
                ['workflow', 'view'], ['workflow', 'approve'], ['analytics', 'view'],
            ],
            'Client Member' => [
                ['workflow', 'view'], ['analytics', 'view'],
            ],
        ];

        foreach ($mapping as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            if ($perms === ['*']) {
                $role->permissions()->sync(Permission::pluck('id'));
                continue;
            }

            $ids = [];
            foreach ($perms as [$module, $action]) {
                $perm = Permission::where('module', $module)->where('action', $action)->first();
                if ($perm) $ids[] = $perm->id;
            }
            $role->permissions()->sync($ids);
        }
    }
}