<?php

namespace Tests\Feature;

use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regresi Phase 4 - HTTP-level buat 2 endpoint baru (POST /analytics/sync,
 * GET /analytics/sync-status). Test 1, 5, 15, 16 dari spesifikasi 25-item
 * (yang unit-level ada di AnalyticsSyncOrchestratorTest.php).
 */
class AnalyticsSyncEndpointTest extends TestCase
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
     * "Manager Test" - profil permission SETARA Manager/SMO asli (lihat
     * PermissionSeeder.php): analytics,view (buat halaman + status) DAN
     * settings,manage (buat dispatch sync, Phase 4.1 Langkah 1).
     */
    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $viewPermission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $managePermission = Permission::firstOrCreate(['module' => 'settings', 'action' => 'manage']);
        $role->permissions()->attach([$viewPermission->id, $managePermission->id]);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    /**
     * "Admin Test" - profil permission SETARA role "Admin" asli (lihat
     * PermissionSeeder.php): analytics,view SAJA, TANPA settings,manage -
     * role ini SENGAJA didesain "tidak boleh mengubah data apa pun lewat
     * aplikasi". Dipakai buat membuktikan role read-only TIDAK bisa
     * dispatch sync (Phase 4.1 Langkah 1).
     */
    private function viewOnlyStaffFor(Client $client): User
    {
        $role = Role::create(['name' => 'Admin Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($role->id);
        $staff->assignedClients()->attach($client->id);

        return $staff;
    }

    private function tiktokIntegration(Client $client): ApiIntegration
    {
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);

        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'integration_name' => 'TikTok API (OAuth)',
            'status' => 'active',
            'access_token' => 'super-secret-token-value',
            'external_username' => 'creator',
        ]);
    }

    // ===== 1: no selected client -> cannot dispatch =====

    public function test_dispatch_without_client_id_is_rejected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->postJson(route('analytics.sync'), []);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Pilih client untuk menyinkronkan data.']);
    }

    // ===== Phase 4.1 Langkah 1: authorization hardening =====

    public function test_view_only_role_cannot_dispatch_sync(): void
    {
        $client = $this->client();
        $this->tiktokIntegration($client);
        $viewOnlyStaff = $this->viewOnlyStaffFor($client);

        Queue::fake();
        $response = $this->actingAs($viewOnlyStaff)->postJson(route('analytics.sync'), ['client_id' => $client->id]);

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_view_only_role_can_still_read_sync_status(): void
    {
        $client = $this->client();
        $viewOnlyStaff = $this->viewOnlyStaffFor($client);

        $response = $this->actingAs($viewOnlyStaff)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
    }

    public function test_manager_role_with_settings_manage_can_dispatch_sync(): void
    {
        $client = $this->client();
        $this->tiktokIntegration($client);
        $manager = $this->managerFor($client);

        Queue::fake();
        $response = $this->actingAs($manager)->postJson(route('analytics.sync'), ['client_id' => $client->id]);

        $response->assertOk();
    }

    // ===== 5: client lain tidak boleh di-dispatch (akses ditolak) =====

    public function test_dispatch_for_inaccessible_client_is_forbidden(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $this->tiktokIntegration($clientB);
        $staffOnlyOnA = $this->managerFor($clientA);

        Queue::fake();
        $response = $this->actingAs($staffOnlyOnA)->postJson(route('analytics.sync'), ['client_id' => $clientB->id]);

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_dispatch_with_guessed_platform_id_still_scoped_to_selected_client(): void
    {
        $client = $this->client();
        $otherClient = $this->client();
        $otherIntegration = $this->tiktokIntegration($otherClient);
        $manager = $this->managerFor($client);

        Queue::fake();
        // client_id BENAR (client sendiri), tapi platform_id valid milik
        // TikTok - integration client LAIN tidak boleh ikut kepilih walau
        // platform_id-nya "cocok" secara kebetulan (integrationFor() SELALU
        // query lewat $client->apiIntegrations(), bukan ID integration
        // langsung).
        $response = $this->actingAs($manager)->postJson(route('analytics.sync'), [
            'client_id' => $client->id,
            'platform_id' => $otherIntegration->platform_id,
        ]);

        $response->assertOk();
        Queue::assertNotPushed(SyncTikTokAnalyticsJob::class, fn ($job) => $job->apiIntegrationId === $otherIntegration->id);
    }

    // ===== 15: polling endpoint permission/client scoped =====

    public function test_status_endpoint_is_scoped_to_assigned_clients_only(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $this->tiktokIntegration($clientB);
        $staffOnlyOnA = $this->managerFor($clientA);

        $response = $this->actingAs($staffOnlyOnA)->getJson(route('analytics.sync-status', ['client_id' => $clientB->id]));

        $response->assertForbidden();
    }

    public function test_status_endpoint_requires_authentication(): void
    {
        $client = $this->client();

        // App ini redirect ke login buat request tidak terautentikasi (web
        // middleware group standar, bukan API-style 401) - sama seperti
        // route lain di app ini, bukan Phase 4 khusus.
        $response = $this->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertRedirect(route('login'));
    }

    // ===== 16: status endpoint tidak bocorkan secret apapun =====

    /**
     * Phase 4.2 (Langkah 5) - audit lengkap: response GET /analytics/sync-
     * status TIDAK BOLEH pernah mengandung access_token/refresh_token/
     * client_secret/code_verifier/APP_KEY/webhook secret/raw exception
     * trace apapun, bahkan kalau ApiIntegration/config yang mendasarinya
     * benar-benar punya nilai itu. statusPayload() (AnalyticsSyncOrchestrator)
     * SECARA STRUKTURAL cuma pernah return status/message/synced_count/
     * skipped_count/error_message/finished_at - field integration lain
     * TIDAK PERNAH disentuh - test ini membuktikan itu tetap benar dengan
     * nilai sensitif SUNGGUHAN di database/config, bukan cuma baca kode.
     */
    public function test_status_endpoint_leaks_no_secrets(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);
        $integration->update([
            'refresh_token' => 'super-secret-refresh-token-value',
            'access_token_expires_at' => now()->addHour(),
        ]);

        config([
            'services.tiktok.client_secret' => 'super-secret-client-secret-value',
            'services.instagram.client_secret' => 'super-secret-ig-client-secret-value',
        ]);

        AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $manager->id,
            'source_type' => 'api_sync',
            'status' => 'failed',
            'sync_mode' => 'default',
            'range_from' => now()->subDays(90)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
            'error_message' => 'Gagal menghubungi API.',
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));

        $response->assertOk();
        $body = $response->getContent();

        // Nilai literal sensitif SUNGGUHAN.
        $this->assertStringNotContainsString('super-secret-token-value', $body);
        $this->assertStringNotContainsString('super-secret-refresh-token-value', $body);
        $this->assertStringNotContainsString('super-secret-client-secret-value', $body);
        $this->assertStringNotContainsString('super-secret-ig-client-secret-value', $body);
        $this->assertStringNotContainsString(config('app.key'), $body);

        // Nama field yang TIDAK BOLEH pernah muncul sama sekali di kontrak
        // response ini (Langkah 5, daftar eksplisit).
        foreach (['access_token', 'refresh_token', 'client_secret', 'code_verifier', 'Authorization', 'APP_KEY', 'webhook', 'stack trace', 'Stack trace', '#0 '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "Response mengandung field/kata terlarang: {$forbidden}");
        }
    }

    // ===== 2 (Client Required UX): button disabled + hint copy tanpa client =====

    public function test_analytics_page_shows_disabled_sync_button_and_hint_without_client_selected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics'));

        $response->assertOk();
        $response->assertSee('Pilih client untuk menyinkronkan data.');
        preg_match('/<button[^>]*id="analytics-sync-button"[^>]*>/', $response->getContent(), $buttonTag);
        $this->assertNotEmpty($buttonTag);
        $this->assertStringContainsString('disabled', $buttonTag[0]);
    }

    public function test_analytics_page_shows_enabled_sync_button_with_client_selected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertDontSee('Pilih client untuk menyinkronkan data.');
        preg_match('/<button[^>]*id="analytics-sync-button"[^>]*>/', $response->getContent(), $buttonTag);
        $this->assertNotEmpty($buttonTag);
        $this->assertStringNotContainsString('disabled', $buttonTag[0]);
    }

    // ===== Phase 4.2 Langkah 1 A-E: UI must match server authorization =====

    public function test_view_only_role_can_open_analytics_page(): void
    {
        $client = $this->client();
        $viewOnlyStaff = $this->viewOnlyStaffFor($client);

        $response = $this->actingAs($viewOnlyStaff)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
    }

    public function test_view_only_role_does_not_receive_active_sync_button_in_rendered_ui(): void
    {
        $client = $this->client();
        $viewOnlyStaff = $this->viewOnlyStaffFor($client);

        $response = $this->actingAs($viewOnlyStaff)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        // TIDAK ADA elemen sync button SAMA SEKALI (bukan cuma disabled) -
        // "jangan render active Sync button" buat role yang memang tidak
        // berwenang. Ekspor Performa TETAP boleh (read-only action).
        $response->assertDontSee('id="analytics-sync-button"', false);
        $response->assertSee('Ekspor Performa');
    }

    public function test_settings_manage_role_sees_active_sync_button(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('id="analytics-sync-button"', false);
    }

    public function test_dispatch_endpoint_ignores_any_token_field_from_client(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);

        Queue::fake();
        // Kirim field access_token dari "browser" - HARUS diabaikan total
        // (Langkah 9, "jangan menerima access_token/API credential dari
        // browser") - dispatch tetap pakai token yang SUDAH tersimpan di DB
        // (integration->access_token), bukan yang dikirim payload ini.
        $response = $this->actingAs($manager)->postJson(route('analytics.sync'), [
            'client_id' => $client->id,
            'platform_id' => $integration->platform_id,
            'access_token' => 'attacker-supplied-token',
        ]);

        $response->assertOk();
        $this->assertSame('super-secret-token-value', $integration->fresh()->access_token);
    }
}
