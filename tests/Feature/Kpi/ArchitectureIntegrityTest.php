<?php

namespace Tests\Feature\Kpi;

use App\Enums\UserRole;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 6 - jaminan struktural keputusan produk 2026-09-02: "jangan menambah
 * role baru", "hapus konsep Account Lead/Reviewer/Publisher/operational role
 * terpisah", "jangan membuat proses assignment khusus KPI". Test ini
 * memverifikasi arsitektur paralel yang sudah dihapus TIDAK PERNAH kembali
 * secara tidak sengaja (mis. lewat migration/seeder baru yang lupa aturan
 * ini), bukan menguji satu fitur tertentu.
 */
class ArchitectureIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_role_tables_do_not_exist(): void
    {
        $this->assertFalse(Schema::hasTable('operational_roles'), 'Tabel operational_roles sudah dihapus per koreksi produk - tidak boleh ada lagi.');
        $this->assertFalse(Schema::hasTable('client_role_assignments'), 'Tabel client_role_assignments sudah dihapus - KPI-per-client harus lewat relasi/aktivitas EXISTING.');
        $this->assertFalse(Schema::hasTable('content_item_operational_assignments'), 'Tabel content_item_operational_assignments sudah dihapus - PIC harus dari content_item_assignments EXISTING.');
    }

    public function test_operational_role_classes_do_not_exist(): void
    {
        $this->assertFalse(class_exists(\App\Models\OperationalRole::class), 'Model OperationalRole sudah dihapus.');
        $this->assertFalse(class_exists(\App\Models\ClientRoleAssignment::class), 'Model ClientRoleAssignment sudah dihapus.');
        $this->assertFalse(class_exists(\App\Models\ContentItemOperationalAssignment::class), 'Model ContentItemOperationalAssignment sudah dihapus.');
        $this->assertFalse(class_exists(\App\Http\Controllers\ClientRoleAssignmentController::class), 'Controller ClientRoleAssignmentController sudah dihapus.');
        $this->assertFalse(class_exists(\App\Http\Controllers\ContentItemOperationalAssignmentController::class), 'Controller ContentItemOperationalAssignmentController sudah dihapus.');
    }

    /** Seluruh role yang ada setelah seeding HARUS persis role EXISTING (UserRole enum) - tidak ada "Account Lead"/"Reviewer"/"Publisher" yang diam-diam ditambahkan kembali. */
    public function test_seeded_roles_never_include_invented_kpi_roles(): void
    {
        (new PermissionSeeder)->run();

        $roleNames = Role::pluck('name')->all();
        $existingRoleNames = array_map(fn (UserRole $r) => $r->value, UserRole::cases());

        foreach (['Account Lead', 'Reviewer', 'Publisher'] as $inventedRole) {
            $this->assertNotContains($inventedRole, $roleNames, "Role '{$inventedRole}' adalah konsep operational role yang sudah dihapus - tidak boleh muncul lagi lewat seeder mana pun.");
        }

        foreach ($roleNames as $name) {
            $this->assertContains($name, $existingRoleNames, "Role '{$name}' bukan bagian dari UserRole enum EXISTING - keputusan produk melarang penambahan role baru untuk KPI.");
        }
    }
}
