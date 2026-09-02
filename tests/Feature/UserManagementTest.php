<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression untuk KI-05 (Assign Klien -> isClientUser() tidak ada) dan
 * KI-06 (login_enabled tidak fillable, user baru tidak bisa login) - lihat
 * docs/USER_MANUAL_SOURCE_OF_TRUTH.md Bagian 22.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);
    }

    private function managerWithUserManagement(): User
    {
        $manager = User::factory()->create(['status' => 'active']);
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'user_management', 'action' => 'manage']);
        $role->permissions()->attach($permission->id);
        $manager->roles()->attach($role->id);

        return $manager;
    }

    // ===== KI-05: Assign Klien =====

    public function test_manager_can_assign_clients_to_a_staff_member(): void
    {
        $manager = $this->managerWithUserManagement();
        $staff = User::factory()->create(['status' => 'active']);
        $clientA = $this->client();
        $clientB = $this->client();

        $response = $this->actingAs($manager)->put(route('user-management.clients.update', $staff), [
            'client_ids' => [$clientA->id, $clientB->id],
        ]);

        $response->assertRedirect();
        $this->assertEqualsCanonicalizing(
            [$clientA->id, $clientB->id],
            $staff->assignedClients()->pluck('clients.id')->all()
        );
    }

    public function test_assign_clients_works_even_for_ceo_manager_themselves(): void
    {
        $manager = $this->managerWithUserManagement();
        $client = $this->client();

        // Sebelum KI-05 diperbaiki, panggilan $user->isClientUser() (method
        // yang tidak ada di model User manapun) bikin route ini fatal error
        // untuk SEMUA user, bukan cuma kasus tertentu.
        $response = $this->actingAs($manager)->put(route('user-management.clients.update', $manager), [
            'client_ids' => [$client->id],
        ]);

        $response->assertRedirect();
        $this->assertTrue($manager->assignedClients()->whereKey($client->id)->exists());
    }

    // ===== KI-06: login_enabled & akses login =====

    public function test_inviting_a_user_actually_grants_login_access(): void
    {
        $manager = $this->managerWithUserManagement();
        $role = Role::create(['name' => 'Copywriter Test '.uniqid()]);

        $response = $this->actingAs($manager)->post(route('user-management.store'), [
            'name' => 'Staf Baru',
            'email' => 'staf.baru+'.uniqid().'@example.test',
            'role_ids' => [$role->id],
        ]);

        $response->assertRedirect();
        $newUser = User::where('name', 'Staf Baru')->firstOrFail();
        $this->assertTrue((bool) $newUser->login_enabled, 'login_enabled harus true - user baru diundang harus bisa login.');
    }

    public function test_manager_can_toggle_login_access_for_existing_staff(): void
    {
        $manager = $this->managerWithUserManagement();
        $staff = User::factory()->create(['status' => 'active', 'login_enabled' => false]);

        $response = $this->actingAs($manager)->patch(route('user-management.toggle-login-access', $staff));

        $response->assertRedirect();
        $this->assertTrue((bool) $staff->fresh()->login_enabled);

        // Toggle lagi harus mencabutnya kembali.
        $this->actingAs($manager)->patch(route('user-management.toggle-login-access', $staff));
        $this->assertFalse((bool) $staff->fresh()->login_enabled);
    }

    /**
     * Phase 4.3 FINAL - CEO identity sekarang satu sumber kebenaran:
     * config('organization.ceo_email') (CEO_EMAIL di .env), BUKAN lagi
     * literal di RoleSeeder/DemoSeeder yang terbukti drift 2x tanpa
     * pemberitahuan (audit Phase 4.2). RoleSeeder HANYA meng-assign role
     * ke user yang SUDAH ADA (bukan lagi firstOrCreate), dan skip AMAN
     * (bukan diam-diam User::first()) kalau config/user-nya belum ada.
     * 4 test di bawah ini regression buat kontrak itu.
     */
    public function test_configured_ceo_email_resolves_intended_user(): void
    {
        $ceo = User::factory()->create(['email' => 'ceo@523studio.test', 'status' => 'active', 'login_enabled' => false]);
        config(['organization.ceo_email' => 'ceo@523studio.test']);

        (new \Database\Seeders\RoleSeeder)->run();

        $ceoRole = \App\Models\Role::where('name', 'CEO')->firstOrFail();
        $this->assertTrue($ceo->fresh()->roles->contains($ceoRole), 'User dengan email CEO_EMAIL harus dapat role CEO.');
        $this->assertTrue((bool) $ceo->fresh()->login_enabled, 'Login diaktifkan kalau belum, supaya CEO yang di-assign benar-benar bisa login.');
    }

    public function test_different_first_created_user_is_not_accidentally_selected_as_ceo(): void
    {
        // User INI dibuat LEBIH DULU (row id lebih kecil) - kalau seeder
        // masih ada jejak User::first() fallback di jalur manapun, dia yang
        // akan ketuker jadi CEO, bukan yang benar-benar cocok CEO_EMAIL.
        $firstUser = User::factory()->create(['email' => 'first-created@523studio.test', 'status' => 'active']);
        $intendedCeo = User::factory()->create(['email' => 'intended-ceo@523studio.test', 'status' => 'active']);
        config(['organization.ceo_email' => 'intended-ceo@523studio.test']);

        (new \Database\Seeders\RoleSeeder)->run();

        $ceoRole = \App\Models\Role::where('name', 'CEO')->firstOrFail();
        $this->assertTrue($intendedCeo->fresh()->roles->contains($ceoRole));
        $this->assertFalse($firstUser->fresh()->roles->contains($ceoRole), 'User pertama yang dibuat TIDAK BOLEH ketuker jadi CEO hanya karena dia row paling lama.');
    }

    public function test_missing_ceo_config_does_not_silently_select_first_user(): void
    {
        $firstUser = User::factory()->create(['email' => 'someone-else@523studio.test', 'status' => 'active']);
        config(['organization.ceo_email' => null]);

        (new \Database\Seeders\RoleSeeder)->run();

        $ceoRole = \App\Models\Role::where('name', 'CEO')->firstOrFail();
        $this->assertSame(0, $ceoRole->users()->count(), 'Tanpa CEO_EMAIL, TIDAK ADA user manapun (termasuk yang pertama dibuat) yang boleh diam-diam dapat role CEO.');
        $this->assertFalse($firstUser->fresh()->roles->contains($ceoRole));
    }

    public function test_configured_ceo_email_missing_from_users_skips_safely(): void
    {
        $firstUser = User::factory()->create(['email' => 'unrelated@523studio.test', 'status' => 'active']);
        config(['organization.ceo_email' => 'not-registered-yet@523studio.test']);

        // Seeder HARUS tidak melempar exception (skip aman), dan TIDAK
        // BOLEH membuat user baru dengan email itu (bukan lagi firstOrCreate).
        (new \Database\Seeders\RoleSeeder)->run();

        $ceoRole = \App\Models\Role::where('name', 'CEO')->firstOrFail();
        $this->assertDatabaseMissing('users', ['email' => 'not-registered-yet@523studio.test']);
        $this->assertSame(0, $ceoRole->users()->count());
        $this->assertFalse($firstUser->fresh()->roles->contains($ceoRole), 'User lain yang kebetulan ada TIDAK BOLEH jadi fallback CEO.');
    }
}
