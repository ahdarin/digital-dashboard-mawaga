<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.4 (Langkah 1) - DEPLOYMENT PROVISIONING TOOL, BUKAN runtime
 * authorization mechanism. Wewenang jalan tetap 100% permission-based lewat
 * User::hasPermissionTo('analytics', 'manage') di middleware/Blade (Phase
 * 4.2) - command ini SATU-SATUNYA tugasnya menyalurkan permission
 * 'analytics'+'manage' (row yang SUDAH ADA, dibuat otomatis oleh
 * PermissionSeeder's module x action loop, BUKAN dibuat di sini) ke role
 * Manager & SMO di database operasional yang SUDAH ADA, TANPA menjalankan
 * PermissionSeeder penuh - Phase 4.3 audit menemukan PermissionSeeder pakai
 * sync() (full-replace per role) yang BISA diam-diam revoke permission lain
 * kalau state live sudah drift dari hardcoded mapping (lihat Admin role
 * yang bahkan tidak ada di local DB test, Phase 4.3 finding).
 *
 * KONTRAK KERAS:
 * - ADDITIVE SAJA - syncWithoutDetaching(), TIDAK PERNAH detach/sync/
 *   delete apapun. Permission role LAIN, dan permission LAIN pada
 *   Manager/SMO sendiri, TIDAK PERNAH disentuh.
 * - PREVALIDATE SEBELUM MUTATE - permission 'analytics'/'manage' DAN
 *   role Manager DAN role SMO harus SEMUA ditemukan lebih dulu (via
 *   query eksplisit + null-check TEGAS, BUKAN first()?-> yang diam-diam
 *   skip) - kalau SATU SAJA hilang, TIDAK ADA mutation dilakukan sama
 *   sekali (fail-fast, zero side effect).
 * - ATOMIC - 2 grant (Manager + SMO) dibungkus 1 DB::transaction() -
 *   tidak mungkin Manager granted tapi SMO gagal di tengah (rollback
 *   total kalau ada error setelah prevalidasi lolos).
 * - IDEMPOTENT - run kedua kali tidak menduplikasi pivot row apapun
 *   (syncWithoutDetaching aman dipanggil berkali-kali).
 * - TIDAK PERNAH memanggil PermissionSeeder/db:seed dari dalam sini.
 * - TIDAK PERNAH print credential/token/secret apapun (cuma nama role +
 *   status grant, tidak ada data user/integration yang disentuh).
 *
 * --dry-run: cuma laporkan already_has/would_add per role, TIDAK ADA
 * write ke DB sama sekali (bahkan tidak membuka transaction).
 *
 * TIDAK dijalankan otomatis di deploy pipeline manapun saat ini (Phase 4.4
 * SENGAJA cuma bikin tool-nya, keputusan eksekusi ada di Final QA) - lihat
 * laporan Phase 4.4.
 */
class GrantAnalyticsManagePermission extends Command
{
    protected $signature = 'permissions:grant-analytics-manage {--dry-run : Cuma laporkan status, tidak menulis apapun ke DB}';

    protected $description = 'Additive, idempotent: beri permission analytics/manage ke role Manager & SMO tanpa menjalankan PermissionSeeder penuh';

    private const TARGET_ROLES = ['Manager', 'SMO'];

    public function handle(): int
    {
        $permission = Permission::where('module', 'analytics')->where('action', 'manage')->first();

        if (! $permission) {
            $this->error('GAGAL: permission analytics/manage tidak ditemukan di database. Tidak ada mutation dilakukan.');

            foreach (self::TARGET_ROLES as $roleName) {
                $this->line("{$roleName}: failed - permission analytics/manage tidak ditemukan");
            }

            return self::FAILURE;
        }

        // Resolve KEDUA role eksplisit SEBELUM mutation apapun - null-check
        // TEGAS (bukan first()?->), biar role yang hilang TIDAK diam-diam
        // dilewati begitu saja.
        $roles = [];
        $missingRoles = [];

        foreach (self::TARGET_ROLES as $roleName) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                $missingRoles[] = $roleName;
                continue;
            }

            $roles[$roleName] = $role;
        }

        if (! empty($missingRoles)) {
            $this->error('GAGAL: role berikut tidak ditemukan: '.implode(', ', $missingRoles).'. Tidak ada mutation dilakukan (zero partial assignment).');

            foreach (self::TARGET_ROLES as $roleName) {
                $status = in_array($roleName, $missingRoles, true)
                    ? 'failed - role tidak ditemukan'
                    : 'failed - dibatalkan karena role lain hilang (fail-fast, zero partial assignment)';
                $this->line("{$roleName}: {$status}");
            }

            return self::FAILURE;
        }

        // Sampai sini: permission + KEDUA role dipastikan ada.
        $currentlyHas = [];
        foreach ($roles as $roleName => $role) {
            $currentlyHas[$roleName] = $role->permissions()
                ->where('permissions.id', $permission->id)
                ->exists();
        }

        if ($this->option('dry-run')) {
            foreach (self::TARGET_ROLES as $roleName) {
                $status = $currentlyHas[$roleName] ? 'already_has' : 'would_add';
                $this->line("{$roleName}: {$status}");
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($roles, $permission) {
            foreach ($roles as $role) {
                // syncWithoutDetaching - ADDITIVE MURNI, tidak pernah
                // menyentuh/menghapus permission lain yang sudah dipegang
                // role ini.
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            }
        });

        foreach (self::TARGET_ROLES as $roleName) {
            $status = $currentlyHas[$roleName] ? 'already-present' : 'added';
            $this->line("{$roleName}: {$status}");
        }

        return self::SUCCESS;
    }
}
