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
        // - client onboarding/edit/hapus (client,manage): CEO & Manager. Lihat
        //   detail 1 client (client,view) sekarang dibuka ke semua role internal
        //   (tapi tetap discope ke client yang di-assign ke dia lewat
        //   EnsureClientScope, sama seperti workflow/content item) - biar hasil
        //   search "klien" nggak jadi dead-end 403 buat role selain CEO/Manager.
        //   Tombol ubah data (edit/hapus/ubah paket/atur PIC) di halamannya
        //   sendiri tetap cuma nyala buat yang punya client,manage.
        // - dashboard, team performance, user management: CEO & Manager
        // - analytics, report, master data, settings: CEO, Manager, SMO
        // - content plan: semua role bisa lihat, tapi cuma CEO/Manager/Copywriter yang bisa buat plan/item baru
        // - production workflow, revision log, publishing tracker: semua role bisa lihat (data discope per-client di controller)
        // - publishing (submit data publikasi/mark uploaded): khusus SMO
        $mapping = [
            'CEO' => ['*'], // semua permission
            'Manager' => [
                ['dashboard', 'view'],
                ['client', 'view'], ['client', 'manage'],
                ['team_performance', 'view'],
                ['user_management', 'manage'],
                ['analytics', 'view'],
                ['report', 'view'],
                ['master_data', 'manage'],
                ['settings', 'manage'],
                ['content_plan', 'view'], ['content_plan', 'create'], ['content_plan', 'approve'],
                ['workflow', 'view'], ['workflow', 'update'], ['workflow', 'approve'],
            ],
            'Content Creator' => [
                ['client', 'view'],
                ['content_plan', 'view'],
                ['workflow', 'view'], ['workflow', 'update'],
            ],
            'Desain Grafis' => [
                ['client', 'view'],
                ['content_plan', 'view'],
                ['workflow', 'view'], ['workflow', 'update'],
            ],
            'SMO' => [
                ['dashboard', 'view'],
                ['client', 'view'],
                ['analytics', 'view'],
                ['report', 'view'],
                ['master_data', 'manage'],
                ['settings', 'manage'],
                ['content_plan', 'view'], ['content_plan', 'approve'],
                ['workflow', 'view'], ['workflow', 'update'], ['workflow', 'approve'],
                ['publishing', 'manage'],
            ],
            'Copywriter' => [
                ['client', 'view'],
                ['content_plan', 'view'], ['content_plan', 'create'],
                ['workflow', 'view'],
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
