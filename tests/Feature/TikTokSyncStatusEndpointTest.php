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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Endpoint status sync TikTok (GET client-management.tiktok.sync-status) -
 * dipoll JS di client-management.show buat feedback real-time tombol
 * "Sinkronkan Analitik Konten" (job async, tanpa ini halaman nggak pernah
 * tahu kapan job selesai selain refresh manual).
 */
class TikTokSyncStatusEndpointTest extends TestCase
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

    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'client', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    private function integration(Client $client): ApiIntegration
    {
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);

        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'integration_name' => 'TikTok API (OAuth)',
            'status' => 'active',
            'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    public function test_status_reports_not_connected_when_no_integration(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->getJson(route('client-management.tiktok.sync-status', $client));

        $response->assertOk()->assertJson(['status' => 'not_connected']);
    }

    public function test_status_reports_idle_when_connected_but_never_synced(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->integration($client);

        $response = $this->actingAs($manager)->getJson(route('client-management.tiktok.sync-status', $client));

        $response->assertOk()->assertJson(['status' => 'idle']);
    }

    public function test_status_reports_running_when_overlap_lock_is_held(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->integration($client);

        $lock = Cache::lock(SyncTikTokAnalyticsJob::cacheLockKey($integration->id), 600);
        $lock->get();

        try {
            $response = $this->actingAs($manager)->getJson(route('client-management.tiktok.sync-status', $client));
            $response->assertOk()->assertJson(['status' => 'running']);
        } finally {
            $lock->release();
        }
    }

    public function test_status_reports_queued_when_job_row_exists_but_lock_not_yet_held(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->integration($client);

        // Push betulan ke koneksi 'database' (bukan 'sync' yang dipakai
        // default di test env) - biar baris job SUNGGUHAN masuk tabel
        // `jobs`, TANPA benar-benar dieksekusi (tidak ada worker jalan).
        Queue::connection('database')->push(
            new SyncTikTokAnalyticsJob($integration->id, 'default', now()->subMonths(2)->toDateString(), now()->toDateString(), $manager->id)
        );

        $response = $this->actingAs($manager)->getJson(route('client-management.tiktok.sync-status', $client));

        $response->assertOk()->assertJson(['status' => 'queued']);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_status_reports_success_with_result_from_latest_sync_log(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->integration($client);

        AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $manager->id,
            'source_type' => 'api_sync',
            'status' => 'success',
            'sync_mode' => 'default',
            'range_from' => now()->subMonths(2)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 4,
            'skipped_count' => 1,
        ]);

        $response = $this->actingAs($manager)->getJson(route('client-management.tiktok.sync-status', $client));

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'result' => ['metrics_saved' => 4, 'unmatched_or_failed' => 1],
            ]);
    }

    public function test_status_reports_failed_with_error_message(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->integration($client);

        AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $manager->id,
            'source_type' => 'api_sync',
            'status' => 'failed',
            'sync_mode' => 'default',
            'range_from' => now()->subMonths(2)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
            'error_message' => 'Token TikTok kadaluarsa.',
        ]);

        $response = $this->actingAs($manager)->getJson(route('client-management.tiktok.sync-status', $client));

        $response->assertOk()->assertJson([
            'status' => 'failed',
            'result' => ['error_message' => 'Token TikTok kadaluarsa.'],
        ]);
    }

    /**
     * Scoping - user yang TIDAK di-assign ke client ini harus ditolak
     * middleware client.scope, SAMA seperti halaman show() (endpoint ini
     * ada di grup middleware yang sama persis) - status/hasil sync client
     * lain tidak boleh bisa diintip.
     */
    public function test_status_endpoint_is_scoped_to_assigned_clients_only(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $this->integration($clientB);
        $staffOnlyOnA = $this->managerFor($clientA);

        $response = $this->actingAs($staffOnlyOnA)->getJson(route('client-management.tiktok.sync-status', $clientB));

        $response->assertForbidden();
    }

    /**
     * SYSTEM CONSISTENCY PASS (Part AH-AM) - polling custom lama (endpoint
     * client-management.tiktok.sync-status ini SENDIRI, dipoll manual dari
     * script inline di halaman) DIGANTI shared engine+presenter yang SAMA
     * dipakai Analytics & Settings (AnalyticsSyncOrchestrator +
     * public/js/analytics-sync-panel.js, poll ke analytics.sync-status).
     * Endpoint client-management.tiktok.sync-status di atas TETAP ada
     * (tidak dihapus, legacy/tidak dipakai UI lagi) - test lain di file
     * ini yang langsung memanggilnya TETAP valid.
     */
    public function test_show_page_renders_with_shared_sync_panel_when_connected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->integration($client);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        $response->assertSee('id="tt-sync-button"', false);
        $response->assertSee('id="tt-sync-panel"', false);
        $response->assertSee(asset('js/analytics-sync-panel.js'), false);
        // URL diteruskan lewat @json() Blade (json_encode escape "/" jadi
        // "\/") - bandingkan terhadap bentuk yang sama, bukan URL polos.
        $response->assertSee(trim(json_encode(route('analytics.sync-status')), '"'), false);
        // Polling custom lama TIDAK BOLEH ada lagi.
        $response->assertDontSee('tiktok-sync-badge', false);
    }

    // ===== Client Detail: data tambahan (follower/scope/video result) =====

    public function test_show_page_reports_stats_unavailable_when_scope_not_granted(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->integration($client);
        $integration->update(['scopes' => 'user.info.basic,video.list']);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        $response->assertSee('Statistik profil tidak tersedia dari scope TikTok yang diberikan.');
    }

    public function test_show_page_displays_follower_count_when_stats_scope_granted(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->integration($client);
        $integration->update(['scopes' => 'user.info.basic,user.info.stats,video.list']);

        \App\Models\AudienceInsight::create([
            'client_id' => $client->id,
            'platform_id' => $integration->platform_id,
            'source' => \App\Models\AudienceInsight::SOURCE_TIKTOK_API,
            'demographic_type' => \App\Models\AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(),
            'follower_count' => 12345,
        ]);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        $response->assertSee('12,345');
    }

    public function test_show_page_reports_zero_videos_honestly_after_successful_sync(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->integration($client);
        $integration->update(['scopes' => 'user.info.basic,video.list', 'last_synced_at' => now()]);

        AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $manager->id,
            'source_type' => 'api_sync',
            'status' => 'success',
            'sync_mode' => 'default',
            'range_from' => now()->subMonths(2)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
        ]);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        $response->assertSee('Tidak ada video yang dikembalikan TikTok untuk akun ini.');
    }
}
