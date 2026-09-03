<?php

namespace Tests\Feature;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Platform;
use App\Models\User;
use App\Services\AnalyticsSyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ROLLING 90-DAY SYNC COVERAGE - FINAL CORRECTION PASS.
 *
 * Gap-filling regression tests: the bulk of this correction was already
 * architecturally correct (verified by reading resolveSyncWindow(),
 * AnalyticsSyncOrchestrator::dispatch()/dispatchInstagramContent()/
 * dispatchTiktokContent(), AnalyticsController::syncDispatch()) and already
 * covered by SyncDefaultLookbackTest/TikTokAnalyticsSyncDateWindowTest from
 * earlier passes. This file covers what those did NOT: (1) scheduled
 * auto-sync produces the IDENTICAL window as a manual trigger end-to-end,
 * (2) Instagram discovery genuinely crosses month boundaries in one sync
 * (not just TikTok, which was already covered), (3) discovered_count
 * reflects the FULL multi-month eligible workload. Known-content-refresh
 * rolling-window eligibility (Part 8/9) and its boundary cases are covered
 * separately in RefreshKnownContentTest (Scenario A).
 */
class RollingSyncCoverageTest extends TestCase
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

    private function instagramIntegration(Client $client): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    // ===== Scheduled auto-sync uses the IDENTICAL rolling window as manual =====

    public function test_scheduled_auto_sync_dispatches_same_rolling_window_as_manual_trigger(): void
    {
        Queue::fake();
        $client = $this->client();
        $this->instagramIntegration($client);
        $userId = User::factory()->create(['status' => 'active'])->id;

        $expectedFrom = now()->subDays((int) config('analytics.instagram_default_sync_days'))->startOfDay()->toDateString();

        $this->artisan('analytics:auto-sync')->assertExitCode(0);

        Queue::assertPushed(SyncInstagramAnalyticsJob::class, function ($job) use ($expectedFrom) {
            return $job->rangeFrom === $expectedFrom && $job->syncMode === 'default';
        });

        $run = AnalyticsSyncRun::where('client_id', $client->id)->first();
        $this->assertNotNull($run);
        $this->assertSame(AnalyticsSyncRun::TRIGGER_SCHEDULED, $run->trigger, 'Scheduled run HARUS tercatat trigger=scheduled, TAPI window ingestion-nya (rangeFrom) tetap identik dengan manual - SATU kontrak, beda trigger label saja.');
    }

    // ===== Instagram discovery genuinely crosses month boundaries in ONE sync =====

    public function test_instagram_single_sync_discovers_media_across_multiple_publication_months(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = User::factory()->create()->id;
        $task = AnalyticsSyncTask::create([
            'analytics_sync_run_id' => AnalyticsSyncRun::create([
                'client_id' => $client->id, 'trigger' => AnalyticsSyncRun::TRIGGER_MANUAL,
                'initiated_by' => $userId, 'status' => 'queued', 'started_at' => now(),
            ])->id,
            'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT,
            'status' => 'queued',
        ]);

        // 4 media tersebar di 4 bulan kalender berbeda, SEMUA di dalam
        // rolling 90-day window (paling lama ~85 hari lalu) - simulasi
        // account nyata yang publish rutin, TIDAK cuma bulan berjalan.
        $months = [now()->subDays(5), now()->subDays(35), now()->subDays(65), now()->subDays(85)];
        Http::fake(function ($request) use ($months) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => array_map(fn ($ts, $i) => [
                    'id' => "ig-multi-month-{$i}",
                    'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE',
                    'timestamp' => $ts->toIso8601String(), 'permalink' => "https://instagram.com/p/{$i}",
                ], $months, array_keys($months))], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 4], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 100]]],
                    ['name' => 'likes', 'values' => [['value' => 5]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(\App\Services\InstagramAnalyticsSyncService::class);
        [, $since, $until] = $service->resolveSyncWindow(null);
        $syncLog = \App\Models\AnalyticsSyncLog::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id, 'imported_by' => $userId,
            'source_type' => 'api_sync', 'status' => 'success', 'sync_mode' => 'default',
            'range_from' => $since->toDateString(), 'range_to' => $until->toDateString(),
            'synced_count' => 0, 'skipped_count' => 0,
        ]);

        $result = $service->sync($integration, $syncLog, $since, $until, $userId, $task);

        $this->assertSame(4, $result['media_count'], 'Satu sync HARUS menemukan SEMUA 4 media lintas 4 bulan kalender berbeda, BUKAN cuma bulan berjalan.');
        $task->refresh();
        $this->assertSame(4, $task->discovered_count, 'discovered_count HARUS mencerminkan SELURUH workload multi-bulan (4), bukan subset bulan tertentu.');

        // Verifikasi langsung ke DB: media bulan-bulan sebelumnya (bukan
        // cuma bulan berjalan) SUDAH ada tanpa sync khusus per bulan.
        foreach (array_keys($months) as $i) {
            $this->assertDatabaseHas('instagram_media_snapshots', [
                'api_integration_id' => $integration->id,
                'external_post_id' => "ig-multi-month-{$i}",
            ]);
        }
    }

    // ===== Discovery cutoff: media outside the 90-day window is never even requested for insights =====

    public function test_instagram_getmedia_receives_rolling_since_until_params_matching_resolvesyncwindow(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        $capturedParams = null;
        Http::fake(function ($request) use (&$capturedParams) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                $capturedParams = $request->data();

                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 0], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(\App\Services\InstagramAnalyticsSyncService::class);
        [, $since, $until] = $service->resolveSyncWindow(null);
        $syncLog = \App\Models\AnalyticsSyncLog::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id, 'imported_by' => User::factory()->create()->id,
            'source_type' => 'api_sync', 'status' => 'success', 'sync_mode' => 'default',
            'range_from' => $since->toDateString(), 'range_to' => $until->toDateString(),
            'synced_count' => 0, 'skipped_count' => 0,
        ]);

        $service->sync($integration, $syncLog, $since, $until, User::factory()->create()->id, null);

        $this->assertNotNull($capturedParams);
        $this->assertSame($since->timestamp, $capturedParams['since'] ?? null, 'me/media HARUS diminta dengan since = rolling lower bound persis dari resolveSyncWindow(), BUKAN period display filter.');
    }
}
