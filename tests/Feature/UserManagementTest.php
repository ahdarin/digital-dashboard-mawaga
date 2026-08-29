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

    public function test_role_seeder_bootstrap_ceo_accounts_get_login_enabled(): void
    {
        (new \Database\Seeders\RoleSeeder)->run();

        $ceo = User::where('email', 'hello523studio@gmail.com')->firstOrFail();
        $this->assertTrue((bool) $ceo->login_enabled);
    }
}
