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
            'dashboard', 'client', 'team_performance', 'user_management',
            'analytics', 'report', 'master_data', 'settings',
            'content_plan', 'workflow', 'publishing',
        ];
        $actions = ['view', 'create', 'update', 'approve', 'manage'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['module' => $module, 'action' => $action]);
            }
        }

        // Mapping role -> permission, mengikuti batas akses RBAC 523 Studio:
        // - dashboard, client onboarding, team performance, user management: CEO & Manager
        // - analytics, report, master data, settings: CEO, Manager, SMO
        // - content plan: semua role bisa lihat, tapi cuma CEO/Manager/Copywriter yang bisa buat plan/item baru
        // - production workflow, revision log, publishing tracker: semua role bisa lihat (data discope per-client di controller)
        // - publishing (submit data publikasi/mark uploaded): khusus SMO
        $mapping = [
            'CEO' => ['*'], // semua permission
            'Manager' => [
                ['dashboard', 'view'],
                ['client', 'manage'],
                ['team_performance', 'view'],
                ['user_management', 'manage'],
                ['analytics', 'view'],
                ['report', 'view'],
                ['master_data', 'manage'],
                ['settings', 'manage'],
                ['content_plan', 'view'], ['content_plan', 'create'],
                ['workflow', 'view'],
            ],
            'Content Creator' => [
                ['content_plan', 'view'],
                ['workflow', 'view'], ['workflow', 'update'],
            ],
            'Graphic Designer' => [
                ['content_plan', 'view'],
                ['workflow', 'view'], ['workflow', 'update'],
            ],
            'SMO' => [
                ['analytics', 'view'],
                ['report', 'view'],
                ['master_data', 'manage'],
                ['settings', 'manage'],
                ['content_plan', 'view'],
                ['workflow', 'view'], ['workflow', 'update'],
                ['publishing', 'manage'],
            ],
            'Copywriter' => [
                ['content_plan', 'view'], ['content_plan', 'create'],
                ['workflow', 'view'],
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
