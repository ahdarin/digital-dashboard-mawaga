<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Final QA (Langkah 1) - EFFECTIVE authorization matrix: route access +
 * controller authorization + (implicitly, via full HTTP request through
 * the real middleware stack) UI gating, sebagai SATU sistem - bukan cuma
 * Blade rendering terisolasi. Setiap test di sini pakai $this->actingAs()
 * ->get()/post() lewat route() asli, yang berarti request BENAR-BENAR
 * lewat seluruh middleware stack (auth, permission:module,action,
 * client.scope) - persis jalur yang akan dilalui request sungguhan.
 *
 * Skenario A-F dari spesifikasi Final QA.
 */
class FinalQaEffectivePermissionMatrixTest extends TestCase
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

    /**
     * @param  array<int, array{0: string, 1: string}>  $permissions
     */
    private function userWith(Client $client, array $permissions): User
    {
        $role = Role::create(['name' => 'Role Test '.uniqid()]);
        $ids = [];
        foreach ($permissions as [$module, $action]) {
            $ids[] = Permission::firstOrCreate(['module' => $module, 'action' => $action])->id;
        }
        $role->permissions()->attach($ids);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        $user->assignedClients()->attach($client->id);

        return $user;
    }

    // ===== A: analytics,view only =====

    public function test_a_analytics_view_only_can_read_but_not_mutate(): void
    {
        $client = $this->client();
        $user = $this->userWith($client, [['analytics', 'view']]);

        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id, 'tab' => 'overview']))->assertOk();
        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id, 'tab' => 'table']))->assertOk();
        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id, 'tab' => 'audience']))->assertOk();
        $this->actingAs($user)->get(route('analytics.ai-strategy.history', ['client_id' => $client->id]))->assertOk();
        $this->actingAs($user)->get(route('analytics.sync-status', ['client_id' => $client->id]))->assertOk();

        Queue::fake();
        $this->actingAs($user)->post(route('analytics.ai-strategy'), ['client_id' => $client->id])->assertForbidden();
        $this->actingAs($user)->postJson(route('analytics.sync'), ['client_id' => $client->id])->assertForbidden();
        $this->actingAs($user)->post(route('audience.import'), ['client_id' => $client->id])->assertForbidden();
        Queue::assertNothingPushed();
    }

    // ===== B: analytics,view + analytics,manage =====

    public function test_b_analytics_view_plus_manage_allows_ai_mutation_not_sync(): void
    {
        $client = $this->client();
        $user = $this->userWith($client, [['analytics', 'view'], ['analytics', 'manage']]);

        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]))->assertOk();

        // AI mutation endpoint authorized (lolos 403 - hasil aktualnya
        // redirect balik dengan ai_error kalau tidak ada data performa,
        // itu BUKAN authorization failure, itu business-logic response).
        $response = $this->actingAs($user)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);
        $response->assertStatus(302);
        $this->assertNotEquals(403, $response->status());

        Queue::fake();
        $this->actingAs($user)->postJson(route('analytics.sync'), ['client_id' => $client->id])->assertForbidden();
        Queue::assertNothingPushed();
    }

    // ===== C: analytics,view + settings,manage =====

    public function test_c_analytics_view_plus_settings_manage_allows_sync_not_ai(): void
    {
        $client = $this->client();
        $user = $this->userWith($client, [['analytics', 'view'], ['settings', 'manage']]);

        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]))->assertOk();

        Queue::fake();
        $response = $this->actingAs($user)->postJson(route('analytics.sync'), ['client_id' => $client->id]);
        $response->assertOk();
        $this->assertNotEquals(403, $response->status());

        $this->actingAs($user)->post(route('analytics.ai-strategy'), ['client_id' => $client->id])->assertForbidden();
        $this->actingAs($user)->post(route('audience.import'), ['client_id' => $client->id])->assertForbidden();
    }

    // ===== D: full management (all three) =====

    public function test_d_full_management_allows_both_ai_and_sync(): void
    {
        $client = $this->client();
        $user = $this->userWith($client, [['analytics', 'view'], ['analytics', 'manage'], ['settings', 'manage']]);

        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]))->assertOk();

        $aiResponse = $this->actingAs($user)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);
        $this->assertNotEquals(403, $aiResponse->status());

        Queue::fake();
        $syncResponse = $this->actingAs($user)->postJson(route('analytics.sync'), ['client_id' => $client->id]);
        $syncResponse->assertOk();
    }

    // ===== E: analytics,manage WITHOUT analytics,view =====

    /**
     * Audit EFFECTIVE behavior, bukan memaksakan ekspektasi. Route
     * middleware /analytics (halaman) mewajibkan analytics,view - user
     * dengan analytics,manage SAJA (tanpa view) TIDAK bisa membuka
     * halamannya. TAPI route mutating (/analytics/ai-strategy dkk) HANYA
     * dijaga analytics,manage (tidak ADA middleware analytics,view
     * tambahan di grup itu) - jadi user profil ini SECARA TEKNIS tetap
     * bisa trigger mutation via direct POST walau tidak bisa lihat
     * halamannya. Ini BUKAN privilege escalation (permission yang dipakai
     * PERSIS permission yang memang di-grant untuk aksi itu - manage,
     * yang notabene permission LEBIH KUAT daripada view, bukan lebih
     * lemah) - cuma kombinasi permission yang tidak realistis (role asli
     * Manager/SMO SELALU dapat keduanya bareng, PermissionSeeder.php
     * Phase 4.2). Dilaporkan sebagai audit finding, bukan bug.
     */
    public function test_e_analytics_manage_without_view_effective_behavior(): void
    {
        $client = $this->client();
        $user = $this->userWith($client, [['analytics', 'manage']]);

        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]))
            ->assertForbidden(); // TIDAK bisa buka halaman - route memang mewajibkan analytics,view.

        // TAPI direct mutation endpoint tetap authorized (route itu sendiri
        // cuma cek analytics,manage) - dicatat sebagai audit finding.
        $response = $this->actingAs($user)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);
        $this->assertNotEquals(403, $response->status(), 'AUDIT FINDING: analytics,manage tanpa analytics,view TETAP bisa trigger AI mutation via direct POST (bukan privilege escalation - permission yang dipakai sesuai, tapi kombinasi tidak realistis).');
    }

    // ===== F: settings,manage WITHOUT analytics,view =====

    public function test_f_settings_manage_without_view_effective_behavior(): void
    {
        $client = $this->client();
        $user = $this->userWith($client, [['settings', 'manage']]);

        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]))
            ->assertForbidden(); // TIDAK bisa buka halaman.

        Queue::fake();
        $response = $this->actingAs($user)->postJson(route('analytics.sync'), ['client_id' => $client->id]);
        $this->assertNotEquals(403, $response->status(), 'AUDIT FINDING: settings,manage tanpa analytics,view TETAP bisa trigger sync via direct POST (bukan privilege escalation, permission sesuai - tapi kombinasi tidak realistis).');
    }

    // ===== No permission at all =====

    public function test_no_relevant_permission_is_forbidden_everywhere(): void
    {
        $client = $this->client();
        $user = $this->userWith($client, [['dashboard', 'view']]); // permission tidak relevan sama sekali

        $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]))->assertForbidden();

        Queue::fake();
        $this->actingAs($user)->post(route('analytics.ai-strategy'), ['client_id' => $client->id])->assertForbidden();
        $this->actingAs($user)->postJson(route('analytics.sync'), ['client_id' => $client->id])->assertForbidden();
        Queue::assertNothingPushed();
    }
}
