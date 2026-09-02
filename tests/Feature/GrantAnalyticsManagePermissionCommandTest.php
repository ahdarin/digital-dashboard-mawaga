<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi Phase 4.4 (Langkah 2) - artisan permissions:grant-analytics-manage.
 * Tests A-J dari spesifikasi. Command ini SENGAJA TIDAK PERNAH dijalankan
 * terhadap DB operasional sungguhan di luar test suite (Langkah 8) - tests
 * di sini jalan di DB testing terisolasi (RefreshDatabase), bukan
 * "menjalankan provisioning" dalam arti deployment.
 */
class GrantAnalyticsManagePermissionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function analyticsManagePermission(): Permission
    {
        return Permission::firstOrCreate(['module' => 'analytics', 'action' => 'manage']);
    }

    private function unrelatedPermission(): Permission
    {
        return Permission::firstOrCreate(['module' => 'client', 'action' => 'view']);
    }

    // ===== A: Manager+SMO belum punya -> command tambah tepat 2 pivot grant =====

    public function test_grants_analytics_manage_to_manager_and_smo(): void
    {
        $this->analyticsManagePermission();
        $manager = Role::create(['name' => 'Manager']);
        $smo = Role::create(['name' => 'SMO']);

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(0);

        $this->assertTrue($manager->fresh()->hasPermission('analytics', 'manage'));
        $this->assertTrue($smo->fresh()->hasPermission('analytics', 'manage'));
    }

    // ===== B: run kedua -> idempotent, tidak duplicate =====

    public function test_running_twice_is_idempotent_no_duplicate_pivot_rows(): void
    {
        $permission = $this->analyticsManagePermission();
        $manager = Role::create(['name' => 'Manager']);
        $smo = Role::create(['name' => 'SMO']);

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(0);
        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(0);

        $this->assertSame(1, $manager->permissions()->where('permissions.id', $permission->id)->count());
        $this->assertSame(1, $smo->permissions()->where('permissions.id', $permission->id)->count());
    }

    // ===== C/D: permission lain yang sudah ada di Manager/SMO TETAP ADA =====

    public function test_unrelated_existing_permission_on_manager_is_preserved(): void
    {
        $this->analyticsManagePermission();
        $unrelated = $this->unrelatedPermission();
        $manager = Role::create(['name' => 'Manager']);
        Role::create(['name' => 'SMO']);
        $manager->permissions()->attach($unrelated->id);

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(0);

        $this->assertTrue($manager->fresh()->hasPermission('client', 'view'), 'Permission lain milik Manager tidak boleh hilang.');
    }

    public function test_unrelated_existing_permission_on_smo_is_preserved(): void
    {
        $this->analyticsManagePermission();
        $unrelated = $this->unrelatedPermission();
        Role::create(['name' => 'Manager']);
        $smo = Role::create(['name' => 'SMO']);
        $smo->permissions()->attach($unrelated->id);

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(0);

        $this->assertTrue($smo->fresh()->hasPermission('client', 'view'), 'Permission lain milik SMO tidak boleh hilang.');
    }

    // ===== E: role lain TIDAK berubah =====

    public function test_other_roles_are_not_modified(): void
    {
        $permission = $this->analyticsManagePermission();
        $unrelated = $this->unrelatedPermission();
        Role::create(['name' => 'Manager']);
        Role::create(['name' => 'SMO']);
        $ceo = Role::create(['name' => 'CEO']);
        $ceo->permissions()->attach($unrelated->id);
        $copywriter = Role::create(['name' => 'Copywriter']);

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(0);

        $this->assertFalse($ceo->fresh()->hasPermission('analytics', 'manage'), 'CEO TIDAK disebut command ini - tidak boleh ikut diubah.');
        $this->assertTrue($ceo->fresh()->hasPermission('client', 'view'), 'Permission existing CEO tidak boleh hilang.');
        $this->assertSame(0, $copywriter->fresh()->permissions()->count(), 'Copywriter TIDAK disebut command ini - tidak boleh dapat permission apapun.');
        $this->assertFalse($copywriter->fresh()->hasPermission('analytics', 'manage'));
    }

    // ===== F: permission analytics/manage hilang -> gagal, zero mutation =====

    public function test_fails_with_zero_mutation_when_permission_row_missing(): void
    {
        // SENGAJA TIDAK membuat Permission analytics/manage.
        $manager = Role::create(['name' => 'Manager']);
        $smo = Role::create(['name' => 'SMO']);

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(1);

        $this->assertSame(0, $manager->fresh()->permissions()->count());
        $this->assertSame(0, $smo->fresh()->permissions()->count());
    }

    // ===== G: role Manager hilang -> gagal, zero mutation =====

    public function test_fails_with_zero_mutation_when_manager_role_missing(): void
    {
        $permission = $this->analyticsManagePermission();
        // SENGAJA TIDAK membuat role Manager.
        $smo = Role::create(['name' => 'SMO']);

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(1);

        $this->assertSame(0, $smo->fresh()->permissions()->count(), 'SMO TIDAK boleh granted walau dia sendiri ditemukan - Manager hilang harus membatalkan SELURUH operasi (zero partial assignment).');
        $this->assertSame(0, $permission->roles()->count());
    }

    // ===== H: role SMO hilang -> gagal, zero mutation =====

    public function test_fails_with_zero_mutation_when_smo_role_missing(): void
    {
        $permission = $this->analyticsManagePermission();
        $manager = Role::create(['name' => 'Manager']);
        // SENGAJA TIDAK membuat role SMO.

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(1);

        $this->assertSame(0, $manager->fresh()->permissions()->count(), 'Manager TIDAK boleh granted walau dia sendiri ditemukan - SMO hilang harus membatalkan SELURUH operasi (zero partial assignment).');
        $this->assertSame(0, $permission->roles()->count());
    }

    // ===== I: --dry-run -> zero mutation =====

    public function test_dry_run_performs_zero_mutation(): void
    {
        $this->analyticsManagePermission();
        $manager = Role::create(['name' => 'Manager']);
        $smo = Role::create(['name' => 'SMO']);

        $this->artisan('permissions:grant-analytics-manage', ['--dry-run' => true])
            ->expectsOutputToContain('would_add')
            ->assertExitCode(0);

        $this->assertFalse($manager->fresh()->hasPermission('analytics', 'manage'));
        $this->assertFalse($smo->fresh()->hasPermission('analytics', 'manage'));
    }

    public function test_dry_run_reports_already_has_when_permission_already_granted(): void
    {
        $permission = $this->analyticsManagePermission();
        $manager = Role::create(['name' => 'Manager']);
        $smo = Role::create(['name' => 'SMO']);
        $manager->permissions()->attach($permission->id);

        $this->artisan('permissions:grant-analytics-manage', ['--dry-run' => true])
            ->expectsOutputToContain('already_has')
            ->expectsOutputToContain('would_add')
            ->assertExitCode(0);

        // Zero mutation - SMO TETAP belum granted setelah dry-run.
        $this->assertFalse($smo->fresh()->hasPermission('analytics', 'manage'));
    }

    // ===== J: command TIDAK memanggil PermissionSeeder =====

    public function test_command_does_not_run_full_permission_seeder(): void
    {
        $this->analyticsManagePermission();
        Role::create(['name' => 'Manager']);
        Role::create(['name' => 'SMO']);
        // TIDAK ADA role lain (Content Creator, Graphic Designer, Copywriter,
        // Admin, CEO) dibuat sama sekali - kalau command ini diam-diam
        // memanggil PermissionSeeder::run(), role-role itu akan otomatis
        // ke-create dengan permission masing-masing. Buktikan itu TIDAK
        // terjadi.

        $this->artisan('permissions:grant-analytics-manage')->assertExitCode(0);

        $this->assertSame(2, Role::count(), 'Cuma Manager & SMO yang dibuat test ini - kalau command diam-diam menjalankan PermissionSeeder, role lain (CEO/Admin/dst) akan ikut ter-create.');
    }
}
