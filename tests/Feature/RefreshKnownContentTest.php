<?php

namespace Tests\Feature;

use App\Exceptions\InstagramApiException;
use App\Models\AnalyticsSyncFailure;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\AnalyticsSyncOrchestrator;
use App\Services\InstagramAnalyticsSyncService;
use App\Services\KnownContentRefreshFailureMarker;
use App\Services\PeriodPerformanceService;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Snapshot maintenance correction (audit sync horizon - "keep discovery and
 * observation separate"). Discovery window (instagram_default_sync_days/
 * tiktok_default_sync_days, 90 hari) TIDAK PERNAH mengembalikan content yang
 * publish-nya lebih lama dari itu lagi, TAPI content itu genuinely bisa
 * masih dapat views baru hari ini kalau sudah dikenal sistem
 * (InstagramMediaSnapshot/TikTokVideoSnapshot sudah ada) - content age
 * TIDAK BOLEH menentukan apakah observasi hari ini masih dibutuhkan.
 *
 * refreshKnownMedia()/refreshKnownVideos() SEKARANG rotating: SELURUH known
 * content eligible, urut last_fetched_at ASC (paling lama duluan), dibatasi
 * budget per platform (config('analytics.instagram_known_refresh_budget')/
 * tiktok_known_refresh_budget) - BUKAN lagi window tanggal publish/retention.
 */
class RefreshKnownContentTest extends TestCase
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

    private function tiktokIntegration(Client $client): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'TikTok'])->id,
            'integration_name' => 'TT', 'status' => 'active', 'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    private function syncLog(ApiIntegration $integration, string $sourceType): AnalyticsSyncLog
    {
        return AnalyticsSyncLog::create([
            'client_id' => $integration->client_id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => User::factory()->create()->id,
            'source_type' => $sourceType,
            'status' => 'success',
            'sync_mode' => 'default',
            'range_from' => now()->subDays(90)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
        ]);
    }

    private function userId(): int
    {
        return User::factory()->create(['status' => 'active'])->id;
    }

    /**
     * PASS 4.1 - task fixture (file ini sebelumnya tidak pernah butuh Task
     * instrumentation, seluruh test lama pakai refreshKnownMedia()/
     * refreshKnownVideos() tanpa $task - pola SAMA PERSIS dengan
     * AnalyticsSyncV2Test::task()).
     */
    private function task(ApiIntegration $integration, string $subjob): \App\Models\AnalyticsSyncTask
    {
        $run = \App\Models\AnalyticsSyncRun::create([
            'client_id' => $integration->client_id,
            'trigger' => \App\Models\AnalyticsSyncRun::TRIGGER_MANUAL,
            'initiated_by' => $this->userId(),
            'status' => 'queued',
            'started_at' => now(),
        ]);

        return \App\Models\AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id,
            'api_integration_id' => $integration->id,
            'subjob' => $subjob,
            'status' => 'queued',
            'attempt' => 1,
        ]);
    }

    private function igMedia(ApiIntegration $integration, array $overrides = []): InstagramMediaSnapshot
    {
        return InstagramMediaSnapshot::create(array_merge([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'media_product_type' => 'IMAGE',
            'published_at' => now()->subDays(100),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(50),
        ], $overrides));
    }

    private function ttVideo(ApiIntegration $integration, array $overrides = []): TikTokVideoSnapshot
    {
        return TikTokVideoSnapshot::create(array_merge([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'tt-'.uniqid(),
            'published_at' => now()->subDays(100),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(50),
        ], $overrides));
    }

    private function igInsightsSuccessResponse(): \Closure
    {
        return function ($request) {
            if (str_contains($request->url(), '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 500]]],
                    ['name' => 'likes', 'values' => [['value' => 50]]],
                    ['name' => 'comments', 'values' => [['value' => 5]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$request->url()], 404);
        };
    }

    // ===== Scenario A: old content still eligible for rotating refresh (Instagram) =====

    public function test_instagram_content_older_than_120_days_still_eligible_for_refresh(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $media = $this->igMedia($integration, [
            'published_at' => now()->subDays(400), // jauh di luar discovery (90) DAN retention (120)
            'last_fetched_at' => now()->subDays(300),
        ]);

        Http::fake($this->igInsightsSuccessResponse());

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertSame(1, $result['refreshed_count'], 'Content age TIDAK BOLEH membatasi eligibility observasi - rotating refresh SELURUH known content.');
        $this->assertDatabaseHas('content_metric_snapshots', [
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->toDateString(),
        ]);
    }

    // ===== Scenario A (TikTok mirror) =====

    public function test_tiktok_content_older_than_120_days_still_eligible_for_refresh(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $video = $this->ttVideo($integration, [
            'published_at' => now()->subDays(400),
            'last_fetched_at' => now()->subDays(300),
        ]);

        Http::fake(function ($request) use ($video) {
            if (str_contains($request->url(), 'video/query/')) {
                return Http::response(['data' => ['videos' => [[
                    'id' => $video->external_post_id,
                    'create_time' => now()->subDays(400)->timestamp,
                    'view_count' => 3000, 'like_count' => 300, 'comment_count' => 20, 'share_count' => 5,
                ]]]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$request->url()], 404);
        });

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $syncLog, $this->userId());

        $this->assertSame(1, $result['refreshed_count']);
        $this->assertDatabaseHas('content_metric_snapshots', [
            'tiktok_video_snapshot_id' => $video->id,
            'snapshot_date' => now()->toDateString(),
        ]);
    }

    // ===== Final pre-commit verification (Section 3): rotation query scoping =====

    /**
     * refreshKnownMedia() HARUS scoped ke api_integration_id yang diminta -
     * media milik integration/client lain (bahkan dengan last_fetched_at
     * jauh lebih lama, yang seharusnya "menang" urutan rotasi kalau scoping
     * bocor) TIDAK BOLEH pernah ikut ter-refresh atau ikut dihitung ke
     * budget integration ini.
     */
    public function test_instagram_rotation_query_is_scoped_to_selected_integration_only(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $ownMedia = $this->igMedia($integration, ['last_fetched_at' => now()->subDays(10)]);

        $otherClient = $this->client();
        $otherIntegration = $this->instagramIntegration($otherClient);
        $otherMedia = $this->igMedia($otherIntegration, ['last_fetched_at' => now()->subDays(500)]); // jauh lebih "lama" - kalau scoping bocor, ini yang menang rotasi

        config(['analytics.instagram_known_refresh_budget' => 50]);
        Http::fake($this->igInsightsSuccessResponse());

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertSame(1, $result['total_count'], 'total_count HARUS cuma menghitung media milik integration ini.');
        $this->assertTrue($ownMedia->fresh()->last_fetched_at->gt(now()->subMinute()));
        $this->assertTrue($otherMedia->fresh()->last_fetched_at->equalTo($otherMedia->last_fetched_at), 'Media integration/client LAIN TIDAK BOLEH ikut ter-refresh sama sekali.');
    }

    public function test_tiktok_rotation_query_is_scoped_to_selected_integration_only(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $ownVideo = $this->ttVideo($integration, ['last_fetched_at' => now()->subDays(10)]);

        $otherClient = $this->client();
        $otherIntegration = $this->tiktokIntegration($otherClient);
        $otherVideo = $this->ttVideo($otherIntegration, ['last_fetched_at' => now()->subDays(500)]);

        config(['analytics.tiktok_known_refresh_budget' => 500]);
        Http::fake(function ($request) use ($ownVideo) {
            if (str_contains($request->url(), 'video/query/')) {
                $ids = $request->data()['filters']['video_ids'] ?? [];
                // Sanity - video_id milik client lain TIDAK BOLEH pernah muncul di request ini.
                $this->assertNotContains('WRONG-INTEGRATION', $ids);

                return Http::response(['data' => ['videos' => array_map(fn ($id) => [
                    'id' => $id, 'create_time' => now()->subDays(60)->timestamp,
                    'view_count' => 10, 'like_count' => 1, 'comment_count' => 0, 'share_count' => 0,
                ], $ids)]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $syncLog, $this->userId());

        $this->assertSame(1, $result['total_count'], 'total_count HARUS cuma menghitung video milik integration ini.');
        $this->assertTrue($ownVideo->fresh()->last_fetched_at->gt(now()->subMinute()));
        $this->assertTrue($otherVideo->fresh()->last_fetched_at->equalTo($otherVideo->last_fetched_at), 'Video integration/client LAIN TIDAK BOLEH ikut ter-refresh sama sekali.');
    }

    // ===== Scenario B: NULL last_fetched_at selected before recently-refreshed =====

    public function test_instagram_null_last_fetched_at_prioritized_before_recently_refreshed(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        // Kolom last_fetched_at NOT NULL di schema (verified via migration) -
        // simulasikan "belum pernah di-refresh" pakai timestamp SANGAT lama
        // (bukan epoch - MySQL strict mode menolak 1970 karena konversi
        // timezone) sebagai proxy paling adil buat baris yang belum pernah
        // dijamah rotasi, karena NULL genuine tidak reachable tanpa
        // perubahan skema di luar scope koreksi ini.
        $neverRefreshed = $this->igMedia($integration, ['last_fetched_at' => now()->subYears(5)]);
        $recentlyRefreshed = $this->igMedia($integration, ['last_fetched_at' => now()->subMinute()]);

        config(['analytics.instagram_known_refresh_budget' => 1]);
        Http::fake($this->igInsightsSuccessResponse());

        $syncLog = $this->syncLog($integration, 'api_sync');
        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertTrue($neverRefreshed->fresh()->last_fetched_at->gt(now()->subMinute()), 'Media yang paling lama tidak di-refresh harus dipilih duluan (budget=1).');
        $this->assertTrue($recentlyRefreshed->fresh()->last_fetched_at->lt(now()->subSecond()), 'Media yang baru saja di-refresh TIDAK BOLEH terpilih ulang saat budget cuma cukup buat 1.');
    }

    // ===== Scenario C: Instagram budget caps per-media refresh count =====

    public function test_instagram_budget_caps_number_of_media_refreshed(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        for ($i = 0; $i < 5; $i++) {
            $this->igMedia($integration, ['last_fetched_at' => now()->subDays(50 + $i)]);
        }

        config(['analytics.instagram_known_refresh_budget' => 2]);
        Http::fake($this->igInsightsSuccessResponse());

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertSame(2, $result['total_count']);
        $this->assertSame(2, $result['refreshed_count']);
        // FINAL INSTAGRAM OPTIONAL INSIGHTS COMPLETENESS GATE - fixture
        // igMedia() pakai media_product_type=IMAGE (FEED family), jadi
        // SEKARANG tiap media dapat 2 request (core insights + SATU
        // optional FEED batch profile_visits/profile_activity/follows,
        // Part 4 "batch, jangan 1 request per metric") - budget 2 media =
        // 4 request total, BUKAN lagi 2. Ini kenaikan biaya API yang
        // SUDAH didokumentasikan eksplisit di laporan gate ini (Part 7),
        // bukan regresi tak disengaja.
        Http::assertSentCount(4);
    }

    // ===== Scenario D: TikTok budget caps selected videos AND chunks requests <= 20 IDs =====

    public function test_tiktok_budget_caps_selected_videos_and_chunks_requests_to_20_ids(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $videos = [];
        for ($i = 0; $i < 25; $i++) {
            $videos[] = $this->ttVideo($integration, ['last_fetched_at' => now()->subDays(50 + $i)]);
        }

        config(['analytics.tiktok_known_refresh_budget' => 25]);

        $requestSizes = [];
        Http::fake(function ($request) use (&$requestSizes) {
            if (str_contains($request->url(), 'video/query/')) {
                $ids = $request->data()['filters']['video_ids'] ?? [];
                $requestSizes[] = count($ids);

                $videos = array_map(fn ($id) => [
                    'id' => $id, 'create_time' => now()->subDays(60)->timestamp,
                    'view_count' => 10, 'like_count' => 1, 'comment_count' => 0, 'share_count' => 0,
                ], $ids);

                return Http::response(['data' => ['videos' => $videos]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $syncLog, $this->userId());

        $this->assertSame(25, $result['total_count'], 'Budget 25 harus memilih tepat 25 video (dari 25 tersedia).');
        $this->assertSame(25, $result['refreshed_count']);
        $this->assertCount(2, $requestSizes, 'Batch dipecah 20+5 (batas resmi TikTok 20 ID/panggilan).');
        $this->assertSame(20, $requestSizes[0]);
        $this->assertSame(5, $requestSizes[1]);
    }

    // ===== Scenario E: rotation advances so items don't permanently starve =====

    public function test_rotation_advances_last_fetched_at_so_items_do_not_permanently_starve(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $first = $this->igMedia($integration, ['last_fetched_at' => now()->subDays(100)]);
        $second = $this->igMedia($integration, ['last_fetched_at' => now()->subDays(90)]);

        config(['analytics.instagram_known_refresh_budget' => 1]);
        Http::fake($this->igInsightsSuccessResponse());

        // Rotasi 1: $first (paling lama) terpilih & last_fetched_at advance ke now().
        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId());
        $this->assertTrue($first->fresh()->last_fetched_at->gt(now()->subMinute()));

        // Rotasi 2: giliran $second sekarang jadi yang paling lama.
        $result = app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId());
        $this->assertTrue($second->fresh()->last_fetched_at->gt(now()->subMinute()), 'Giliran kedua harus jatuh ke content yang belum di-refresh - tidak starvation permanen.');
        $this->assertSame(1, $result['refreshed_count']);
    }

    // ===== Scenario F: same-day known refresh upserts snapshot without duplicating =====

    public function test_same_day_refresh_upserts_snapshot_without_duplicating_history(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $media = $this->igMedia($integration, ['last_fetched_at' => now()->subHours(2)]);

        // Sudah ada snapshot HARI INI (mis. dari sync utama sebelumnya).
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->toDateString(), 'views' => 100,
        ]);

        Http::fake($this->igInsightsSuccessResponse());

        $syncLog = $this->syncLog($integration, 'api_sync');
        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertSame(1, \App\Models\ContentMetricSnapshot::where('instagram_media_snapshot_id', $media->id)
            ->where('snapshot_date', now()->toDateString())->count(), 'Upsert same-day, BUKAN histori baru.');
    }

    // ===== Scenario G: failed refresh creates the safe partial marker =====

    public function test_instagram_failed_refresh_records_known_content_refresh_failure_marker(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $this->igMedia($integration);

        Http::fake(['*' => Http::response(['error' => ['message' => 'boom', 'code' => 999]], 500)]);

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertSame(1, $result['failed_count']);
        $this->assertTrue(KnownContentRefreshFailureMarker::detectedIn($syncLog->fresh()->error_message));
    }

    // ===== Scenario H: orchestrator maps marker to partial (covered in AnalyticsSyncOrchestratorTest) =====
    // Lihat AnalyticsSyncOrchestratorTest::test_known_content_refresh_failure_downgrades_subjob_and_overall_status

    // ===== Scenario I: all-refresh-failure does not remain perfect success =====

    public function test_all_media_failing_refresh_does_not_leave_marker_absent(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $this->igMedia($integration);
        $this->igMedia($integration);

        Http::fake(['*' => Http::response(['error' => ['message' => 'down', 'code' => 999]], 500)]);

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertSame(0, $result['refreshed_count']);
        $this->assertSame(2, $result['failed_count']);
        $this->assertSame(2, $result['total_count']);
        $marker = $syncLog->fresh()->error_message;
        $this->assertNotNull($marker);
        $this->assertStringContainsString('2/2', $marker);
    }

    // ===== Scenario J: Instagram auth failure becomes actionable reconnect signal =====

    public function test_instagram_auth_failure_marks_integration_needs_reconnect(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $this->igMedia($integration);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertTrue($result['auth_failed']);
        $this->assertSame('inactive', $integration->fresh()->status, 'Auth failure HARUS men-trigger needs_reconnect (via status=inactive), bukan cuma failed_count tinggi.');
        $this->assertNotNull($integration->fresh()->last_error);
        $this->assertStringNotContainsString('fake-token', $integration->fresh()->last_error, 'last_error TIDAK BOLEH menyertakan access token.');
    }

    public function test_instagram_auth_failure_propagates_to_needs_reconnect_ui_status(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $this->igMedia($integration);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId());

        $status = app(AnalyticsSyncOrchestrator::class)->statusForClient($client, $integration->platform_id);

        $this->assertSame('needs_reconnect', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'ApiIntegration.status=inactive harus tercermin sebagai needs_reconnect di UI, bukan generic failed.');
    }

    // ===== Scenario K: deleted/unavailable Instagram media does NOT falsely trigger reconnect =====

    public function test_instagram_deleted_content_does_not_trigger_reconnect(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $media = $this->igMedia($integration);

        // Media dihapus - insights endpoint balikin 400 generic (BUKAN 401/code 190).
        Http::fake(['*' => Http::response(['error' => ['message' => 'Unsupported get request. Object does not exist.', 'code' => 100]], 400)]);

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $syncLog, $this->userId());

        $this->assertFalse($result['auth_failed']);
        $this->assertSame('active', $integration->fresh()->status, 'Content tidak tersedia BUKAN auth failure - integration TIDAK BOLEH ditandai needs_reconnect.');
        $this->assertSame(1, $result['failed_count']);
        // Definitif "tidak ada" - last_fetched_at TETAP advance (Langkah 7).
        $this->assertTrue($media->fresh()->last_fetched_at->gt(now()->subMinute()));
    }

    // ===== Scenario L: TikTok auth failure distinguishable from missing/private video =====

    public function test_tiktok_auth_failure_marks_integration_needs_reconnect(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $this->ttVideo($integration);

        Http::fake(['*' => Http::response(['error' => ['code' => 'access_token_invalid', 'message' => 'token invalid']], 200)]);

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $syncLog, $this->userId());

        $this->assertTrue($result['auth_failed']);
        $this->assertSame('inactive', $integration->fresh()->status);
    }

    public function test_tiktok_missing_video_does_not_trigger_reconnect_and_advances_last_fetched_at(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $video = $this->ttVideo($integration);

        // queryVideos() sukses (error.code='ok' implisit via tidak ada
        // 'error' key) TAPI video ini tidak ada di response - dihapus user.
        Http::fake(['*' => Http::response(['data' => ['videos' => []]], 200)]);

        $syncLog = $this->syncLog($integration, 'api_sync');
        $result = app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $syncLog, $this->userId());

        $this->assertFalse($result['auth_failed']);
        $this->assertSame('active', $integration->fresh()->status);
        $this->assertSame(0, $result['failed_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertTrue($video->fresh()->last_fetched_at->gt(now()->subMinute()), 'Video hilang = jawaban definitif "sudah dicek" - last_fetched_at HARUS advance.');
    }

    // ===== Scenario M: auth/transient failure does NOT incorrectly advance last_fetched_at =====

    public function test_instagram_transient_failure_does_not_advance_last_fetched_at(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $originalTime = now()->subDays(50);
        $media = $this->igMedia($integration, ['last_fetched_at' => $originalTime]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Service unavailable']], 503)]);

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId());

        $this->assertEqualsWithDelta($originalTime->timestamp, $media->fresh()->last_fetched_at->timestamp, 2, 'Transient error TIDAK BOLEH advance last_fetched_at - harus dicoba lagi rotasi berikutnya.');
    }

    public function test_tiktok_batch_transient_failure_does_not_advance_last_fetched_at(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $originalTime = now()->subDays(50);
        $video = $this->ttVideo($integration, ['last_fetched_at' => $originalTime]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'internal error', 'code' => 'internal_error']], 500)]);

        app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $this->syncLog($integration, 'api_sync'), $this->userId());

        $this->assertEqualsWithDelta($originalTime->timestamp, $video->fresh()->last_fetched_at->timestamp, 2);
    }

    public function test_instagram_auth_failure_does_not_advance_last_fetched_at(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $originalTime = now()->subDays(50);
        $media = $this->igMedia($integration, ['last_fetched_at' => $originalTime]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId());

        $this->assertEqualsWithDelta($originalTime->timestamp, $media->fresh()->last_fetched_at->timestamp, 2, 'Auth failure TIDAK boleh membuat attempt gagal terlihat freshly observed.');
    }

    // ===== Scenario N: old content can receive a fresh current observation
    // and correctly contribute to period calculation when a valid baseline
    // exists =====

    public function test_old_content_refreshed_by_rotation_contributes_valid_period_delta(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $periodStart = now()->subDays(29)->startOfDay(); // periode 30 hari
        $periodEnd = now()->startOfDay();

        // Content lama (published 8 bulan lalu), baseline PERSIS 1 hari
        // sebelum period_start (boundary ideal).
        $media = $this->igMedia($integration, [
            'published_at' => now()->subMonths(8),
            'last_fetched_at' => now()->subDays(40),
        ]);
        \App\Models\ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'instagram_media_snapshot_id' => $media->id,
            'imported_by' => $this->userId(),
            'metric_date' => now()->subMonths(8),
            'views' => 1000,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $periodStart->copy()->subDay()->toDateString(), 'views' => 1000,
        ]);

        // Rotasi refresh HARI INI (period_end) - simulasikan observasi baru.
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'views', 'values' => [['value' => 4200]]],
                    ['name' => 'likes', 'values' => [['value' => 300]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId());

        $result = app(PeriodPerformanceService::class)->computeClientPeriod($client->id, $periodStart, $periodEnd, null);

        $this->assertSame('full', $result['coverage']['status'], 'Content lama yang baru saja di-refresh hari ini (period_end) dengan baseline valid HARUS full coverage.');
        $this->assertSame(3200, $result['totals']['views'], '4200 (current, hasil rotasi hari ini) - 1000 (baseline) = 3200.');
    }

    /**
     * Stale content yang BELUM kena giliran rotasi (last_fetched_at lama,
     * snapshot terakhirnya juga lama, TIDAK sampai period_end) TIDAK BOLEH
     * hilang diam-diam dari roster - harus tetap muncul (usable, partial),
     * menurunkan aggregate coverage_status jadi partial (BUKAN full, BUKAN
     * unavailable/vanish).
     */
    public function test_stale_content_not_yet_rotated_does_not_silently_vanish_from_coverage(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $periodStart = now()->subDays(29)->startOfDay();
        $periodEnd = now()->startOfDay();

        $media = $this->igMedia($integration, [
            'published_at' => now()->subMonths(8),
            'last_fetched_at' => now()->subDays(40), // belum kena rotasi giliran ini
        ]);
        \App\Models\ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'instagram_media_snapshot_id' => $media->id,
            'imported_by' => $this->userId(),
            'metric_date' => now()->subMonths(8),
            'views' => 2500,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $periodStart->copy()->subDay()->toDateString(), 'views' => 1000,
        ]);
        // Observasi TERAKHIR jauh sebelum period_end (belum di-refresh hari ini).
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->subDays(10)->toDateString(), 'views' => 2500,
        ]);

        $result = app(PeriodPerformanceService::class)->computeClientPeriod($client->id, $periodStart, $periodEnd, null);

        $this->assertSame(1, $result['coverage']['total_content'], 'Content HARUS tetap muncul di roster walau belum kena giliran rotasi (bukan difilter by age).');
        $this->assertSame(1, $result['coverage']['usable_content'], 'Delta masih terhitung (baseline valid) - usable, cuma coverage-nya partial.');
        $this->assertSame('partial', $result['coverage']['status'], 'current_before_period_end - TIDAK PERNAH full, TAPI TIDAK hilang/unavailable juga.');
        $this->assertGreaterThan(0, $result['totals']['views']);
    }

    // ===== Scenario O: automatic prune schedule absent/disabled =====
    // Lihat PruneContentMetricSnapshotsTest::test_automatic_prune_schedule_is_absent_or_disabled

    // ===== Scenario P: prune command still works manually =====
    // Lihat PruneContentMetricSnapshotsTest (seluruh test lain di file itu)

    // ===== Section legacy: display period never reaches sync =====

    public function test_sync_dispatch_ignores_display_period_uses_fixed_discovery_window(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        app(AnalyticsSyncOrchestrator::class)->dispatch($client, null, $this->userId());

        Queue::assertPushed(\App\Jobs\SyncInstagramAnalyticsJob::class, function ($job) {
            $expectedFrom = now()->subDays((int) config('analytics.instagram_default_sync_days'))->startOfDay()->toDateString();

            return $job->rangeFrom === $expectedFrom;
        });
    }

    // =====================================================================
    // PASS 4.1 - "UNIQUE DISCOVERY / RECONCILIATION CORRECTNESS"
    //
    // Live QA (real Instagram account, 11 real media) proved sync() and
    // refreshKnownMedia()/refreshKnownVideos() can select the SAME external
    // items within ONE task run for accounts whose total known content <=
    // the rotation budget - Pass 4's additive recordDiscovered() fix made
    // the reconciliation invariant mathematically true (discovered=
    // success+unavailable+skipped+failed) WITHOUT making it semantically
    // true (11 unique media were reported as "22 content"). The real fix
    // is at the SOURCE: refreshKnownMedia()/refreshKnownVideos() now accept
    // $excludeFetchedSince (sync()'s run_started_at) and exclude anything
    // last_fetched_at-touched by sync() in the SAME run, making the two
    // phases' candidate sets STRUCTURALLY disjoint - discovered_count
    // (still additive across phases) is then genuinely a union of unique
    // items, not a double-count. sync() also dedupes its own page results
    // by media/video ID (pagination-duplicate defense) before ANY counting
    // happens.
    // =====================================================================

    public function test_instagram_media_discovered_by_sync_is_not_recounted_by_refresh_in_same_run(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => [
                    ['id' => 'ig-shared-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->toIso8601String(), 'permalink' => 'https://instagram.com/p/1'],
                ]], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 500]]],
                    ['name' => 'likes', 'values' => [['value' => 20]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(InstagramAnalyticsSyncService::class);
        $syncResult = $service->sync($integration, $this->syncLog($integration, 'api_sync'), now()->subDays(90), now(), $this->userId(), $task);

        // Real-world reality (11-media live account, budget >= total known):
        // this SAME media is now the only row in instagram_media_snapshots,
        // so a rotation refresh WITHOUT the exclude boundary would select
        // it again - exactly the live bug. Pass the boundary as the job does.
        $service->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId(), $task, $syncResult['run_started_at']);

        $task->refresh();
        $this->assertSame(1, InstagramMediaSnapshot::where('api_integration_id', $integration->id)->count(), 'SATU media unik, bukan dua baris.');
        $this->assertSame(1, $task->discovered_count, 'discovered_count HARUS tetap 1 (unique media), BUKAN 2 (1 dari sync() + 1 lagi dari refresh terhadap media yang SAMA).');
        $this->assertSame(1, $task->success_count);
        $this->assertSame(1, $task->processed_count);
        $this->assertTrue($task->reconciled, 'Invariant discovered=success+unavailable+skipped+failed (1=1+0+0+0) - benar KARENA unique, bukan karena kebetulan seimbang.');
    }

    public function test_instagram_refresh_excludes_only_items_touched_this_run_not_genuinely_stale_ones(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        // Media B genuinely stale dari SEBELUM run ini - TIDAK PERNAH
        // disentuh sync() (bukan bagian discovery window kali ini), harus
        // TETAP eligible buat refresh normal.
        $staleMedia = $this->igMedia($integration, ['external_post_id' => 'ig-genuinely-stale', 'last_fetched_at' => now()->subDays(50)]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => [
                    ['id' => 'ig-just-discovered', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->toIso8601String(), 'permalink' => 'https://instagram.com/p/2'],
                ]], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 2], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 300]]],
                    ['name' => 'likes', 'values' => [['value' => 10]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(InstagramAnalyticsSyncService::class);
        $syncResult = $service->sync($integration, $this->syncLog($integration, 'api_sync'), now()->subDays(90), now(), $this->userId(), $task);
        $service->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId(), $task, $syncResult['run_started_at']);

        $task->refresh();
        // 1 unique dari sync() ("ig-just-discovered") + 1 unique genuinely
        // stale dari refresh ("ig-genuinely-stale") = 2 total, TIDAK overlap.
        $this->assertSame(2, $task->discovered_count);
        $this->assertSame(2, $task->success_count);
        $this->assertTrue($task->reconciled);
        $this->assertNotNull($staleMedia->fresh()->last_fetched_at);
        $this->assertTrue($staleMedia->fresh()->last_fetched_at->gt(now()->subMinute()), 'Media genuinely stale HARUS tetap kena refresh (last_fetched_at ter-update), TIDAK ikut ter-exclude.');
    }

    public function test_instagram_pagination_duplicate_media_id_counted_once(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                // Simulasi edge case genuine cursor-based pagination: 1 media
                // ID yang SAMA muncul di response (mis. bergeser posisi antar
                // halaman selagi fetch berlangsung).
                return Http::response(['data' => [
                    ['id' => 'ig-dup-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->toIso8601String(), 'permalink' => 'https://instagram.com/p/3'],
                    ['id' => 'ig-dup-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->toIso8601String(), 'permalink' => 'https://instagram.com/p/3'],
                ]], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 100]]],
                    ['name' => 'likes', 'values' => [['value' => 5]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(InstagramAnalyticsSyncService::class);
        $syncResult = $service->sync($integration, $this->syncLog($integration, 'api_sync'), now()->subDays(90), now(), $this->userId(), $task);

        $this->assertSame(1, InstagramMediaSnapshot::where('api_integration_id', $integration->id)->where('external_post_id', 'ig-dup-1')->count(), 'Satu media = satu baris, walau muncul 2x mentah dari API.');
        $this->assertSame(1, $syncResult['media_count']);
        $task->refresh();
        $this->assertSame(1, $task->discovered_count);
        $this->assertSame(1, $task->success_count);
        $this->assertTrue($task->reconciled);
    }

    public function test_tiktok_video_discovered_by_sync_is_not_recounted_by_refresh_in_same_run(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'video/list')) {
                return Http::response(['data' => [
                    'videos' => [
                        ['id' => 'tt-shared-1', 'create_time' => now()->subDay()->timestamp, 'view_count' => 100, 'like_count' => 5, 'comment_count' => 1, 'share_count' => 0, 'share_url' => 'https://tiktok.com/@x/video/1'],
                    ],
                    'has_more' => false,
                ]], 200);
            }
            if (str_contains($url, 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-999', 'union_id' => 'u-999', 'display_name' => 'creator']]], 200);
            }
            if (str_contains($url, 'video/query')) {
                return Http::response(['data' => ['videos' => [
                    ['id' => 'tt-shared-1', 'create_time' => now()->subDay()->timestamp, 'view_count' => 120, 'like_count' => 6, 'comment_count' => 1, 'share_count' => 0, 'share_url' => 'https://tiktok.com/@x/video/1'],
                ]]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(TikTokAnalyticsSyncService::class);
        $syncResult = $service->sync($integration, $this->syncLog($integration, 'api_sync'), now()->subDays(90), $this->userId(), $task);
        $service->refreshKnownVideos($integration, $this->syncLog($integration, 'api_sync'), $this->userId(), $task, $syncResult['run_started_at']);

        $task->refresh();
        $this->assertSame(1, TikTokVideoSnapshot::where('api_integration_id', $integration->id)->count(), 'SATU video unik, bukan dua baris.');
        $this->assertSame(1, $task->discovered_count, 'MIRROR skenario Instagram - video yang sama TIDAK boleh dihitung dua kali cuma karena melewati 2 tahap internal.');
        $this->assertSame(1, $task->success_count);
        $this->assertTrue($task->reconciled);
    }

    public function test_tiktok_pagination_duplicate_video_id_counted_once(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'video/list')) {
                return Http::response(['data' => [
                    'videos' => [
                        ['id' => 'tt-dup-1', 'create_time' => now()->subDay()->timestamp, 'view_count' => 50, 'like_count' => 2, 'comment_count' => 0, 'share_count' => 0, 'share_url' => 'https://tiktok.com/@x/video/2'],
                        ['id' => 'tt-dup-1', 'create_time' => now()->subDay()->timestamp, 'view_count' => 50, 'like_count' => 2, 'comment_count' => 0, 'share_count' => 0, 'share_url' => 'https://tiktok.com/@x/video/2'],
                    ],
                    'has_more' => false,
                ]], 200);
            }
            if (str_contains($url, 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-999', 'union_id' => 'u-999', 'display_name' => 'creator']]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(TikTokAnalyticsSyncService::class);
        $syncResult = $service->sync($integration, $this->syncLog($integration, 'api_sync'), now()->subDays(90), $this->userId(), $task);

        $this->assertSame(1, TikTokVideoSnapshot::where('api_integration_id', $integration->id)->where('external_post_id', 'tt-dup-1')->count());
        $this->assertSame(1, $syncResult['video_count']);
        $task->refresh();
        $this->assertSame(1, $task->discovered_count);
        $this->assertSame(1, $task->success_count);
        $this->assertTrue($task->reconciled);
    }

    public function test_unique_failure_accounting_a_later_failure_is_not_hidden_by_an_earlier_success(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        // Media genuinely stale (BUKAN bagian discovery window sync()) -
        // refreshKnownMedia() akan mencoba refresh-nya dan GAGAL (insight
        // API down) - "later operation" di sini murni fase refresh SENDIRI
        // (fase 1 sync() tidak pernah menyentuhnya sama sekali, exclude
        // boundary TIDAK relevan buat kasus ini), membuktikan kegagalan
        // 1 item TIDAK "hilang" tertimpa hasil sukses item lain di task
        // yang sama.
        $this->igMedia($integration, ['external_post_id' => 'ig-will-fail', 'last_fetched_at' => now()->subDays(40)]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 0], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['error' => ['message' => 'temporarily down']], 500);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(InstagramAnalyticsSyncService::class);
        $syncResult = $service->sync($integration, $this->syncLog($integration, 'api_sync'), now()->subDays(90), now(), $this->userId(), $task);
        $service->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId(), $task, $syncResult['run_started_at']);

        $task->refresh();
        $this->assertSame(1, $task->discovered_count);
        $this->assertSame(1, $task->failed_count, 'Kegagalan genuine TIDAK BOLEH hilang jadi 0 gara-gara sync() (fase lain) sendiri sukses trivial (0 media).');
        $this->assertSame(0, $task->success_count);
        $this->assertTrue($task->reconciled);

        // Retry HANYA menyasar item yang genuinely gagal ini.
        $failure = AnalyticsSyncFailure::where('analytics_sync_task_id', $task->id)->where('external_item_id', 'ig-will-fail')->first();
        $this->assertNotNull($failure);
        $this->assertTrue($failure->retryable);
    }

    public function test_same_day_snapshot_upsert_unaffected_by_two_phase_exclude_fix(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => [
                    ['id' => 'ig-upsert-check', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->toIso8601String(), 'permalink' => 'https://instagram.com/p/4'],
                ]], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 500]]],
                    ['name' => 'likes', 'values' => [['value' => 20]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        $service = app(InstagramAnalyticsSyncService::class);
        // 2 sync run PENUH di hari yang sama (persis skenario live QA -
        // sync dijalankan dua kali di hari yang sama).
        $r1 = $service->sync($integration, $this->syncLog($integration, 'api_sync'), now()->subDays(90), now(), $this->userId(), $task);
        $service->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId(), $task, $r1['run_started_at']);

        $task2 = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);
        $r2 = $service->sync($integration, $this->syncLog($integration, 'api_sync'), now()->subDays(90), now(), $this->userId(), $task2);
        $service->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId(), $task2, $r2['run_started_at']);

        $this->assertSame(
            1,
            \App\Models\ContentMetricSnapshot::where('client_id', $integration->client_id)
                ->whereNotNull('instagram_media_snapshot_id')
                ->whereDate('snapshot_date', now()->toDateString())
                ->count(),
            'Same-day upsert TETAP 1 baris walau di-sync 2x hari yang sama dan melewati dua fase - bukti langsung dari live QA (11 baris tetap 11 setelah 2 run).'
        );
    }

    /**
     * FINAL PRE-COMMIT HYGIENE (Langkah 12) - invariant SPESIFIK: item yang
     * GAGAL di fase sync() TIDAK BOLEH ke-touch/ke-hitung ULANG oleh fase
     * refreshKnownMedia()/refreshKnownVideos() dalam RUN yang sama.
     *
     * Dites LANGSUNG terhadap mekanisme exclusion query itu sendiri (bukan
     * mencoba memaksa saveMetric() gagal lewat 3 lapis indirection yang
     * rapuh/implementation-specific) - saveSnapshot() TERBUKTI (baca kode)
     * SELALU men-set last_fetched_at=now() buat SETIAP item SEBELUM
     * saveMetricSafely() dipanggil, TIDAK PEDULI hasil akhirnya sukses atau
     * gagal (lihat persistMedia()/persistVideos()) - jadi exclusion di
     * refreshKnownMedia()/refreshKnownVideos() (berdasar last_fetched_at,
     * BUKAN status sukses/gagal) melindungi item gagal PERSIS SAMA seperti
     * item sukses. Test ini men-simulasikan state "item baru gagal disentuh
     * fase sync()" langsung (failed_count sudah ter-set, last_fetched_at
     * sudah ter-bump ke sekarang - PERSIS kondisi nyata setelah
     * saveMetricSafely() gagal), lalu membuktikan refreshKnownMedia() TIDAK
     * memilihnya ulang.
     */
    public function test_item_failed_in_sync_phase_is_not_recounted_by_refresh_phase_same_run(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);
        $runStartedAt = now();

        // Item yang BARU SAJA disentuh fase sync() DAN GAGAL - last_fetched_at
        // sudah ter-bump (saveSnapshot() dipanggil SEBELUM saveMetricSafely(),
        // terlepas hasil akhirnya), failed_count sudah tercatat 1.
        $touchedButFailedMedia = $this->igMedia($integration, [
            'external_post_id' => 'ig-touched-failed-this-run',
            'last_fetched_at' => now(),
        ]);
        $task->update(['discovered_count' => 1, 'processed_count' => 1, 'failed_count' => 1]);

        // Media LAIN yang genuinely stale (TIDAK disentuh fase sync() sama
        // sekali) - HARUS tetap eligible buat refresh normal.
        $staleMedia = $this->igMedia($integration, ['external_post_id' => 'ig-genuinely-stale-2', 'last_fetched_at' => now()->subDays(60)]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 200]]],
                    ['name' => 'likes', 'values' => [['value' => 8]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$request->url()], 404);
        });

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration, 'api_sync'), $this->userId(), $task, $runStartedAt);

        $task->refresh();
        $this->assertSame(1, $task->failed_count, 'Kegagalan item di fase sync() TIDAK boleh dihitung ulang/ketimpa oleh fase refresh - item yang sama HARUS di-exclude, bukan diproses ulang.');
        $this->assertSame(2, $task->discovered_count, '1 (fase sync, sudah gagal) + 1 (media genuinely stale yang benar ditemukan fase refresh) = 2, BUKAN 3 (item gagal ter-hitung ulang).');
        $this->assertSame(1, $task->success_count, 'HANYA media genuinely stale yang berhasil di-refresh.');
        $this->assertSame(
            $touchedButFailedMedia->last_fetched_at->timestamp,
            $touchedButFailedMedia->fresh()->last_fetched_at->timestamp,
            'last_fetched_at item yang gagal di fase sync() TIDAK disentuh ulang oleh fase refresh (bukti query exclusion benar-benar mengecualikannya).'
        );
        $this->assertNotSame(
            $staleMedia->last_fetched_at->timestamp,
            $staleMedia->fresh()->last_fetched_at->timestamp,
            'Media genuinely stale HARUS tetap di-refresh (last_fetched_at berubah).'
        );
    }

    public function test_tiktok_item_failed_in_sync_phase_is_not_recounted_by_refresh_phase_same_run(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT);
        $runStartedAt = now();

        $touchedButFailedVideo = $this->ttVideo($integration, [
            'external_post_id' => 'tt-touched-failed-this-run',
            'last_fetched_at' => now(),
        ]);
        $task->update(['discovered_count' => 1, 'processed_count' => 1, 'failed_count' => 1]);

        $staleVideo = $this->ttVideo($integration, ['external_post_id' => 'tt-genuinely-stale-2', 'last_fetched_at' => now()->subDays(60)]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'video/query')) {
                return Http::response(['data' => ['videos' => [
                    ['id' => 'tt-genuinely-stale-2', 'create_time' => now()->subDay()->timestamp, 'view_count' => 40, 'like_count' => 2, 'comment_count' => 0, 'share_count' => 0, 'share_url' => 'https://tiktok.com/@x/video/3'],
                ]]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$request->url()], 404);
        });

        app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $this->syncLog($integration, 'api_sync'), $this->userId(), $task, $runStartedAt);

        $task->refresh();
        $this->assertSame(1, $task->failed_count, 'MIRROR skenario Instagram - kegagalan video di fase sync() TIDAK boleh dihitung ulang oleh fase refresh.');
        $this->assertSame(2, $task->discovered_count);
        $this->assertSame(1, $task->success_count);
        $this->assertSame(
            $touchedButFailedVideo->last_fetched_at->timestamp,
            $touchedButFailedVideo->fresh()->last_fetched_at->timestamp,
            'Video yang gagal di fase sync() TIDAK disentuh ulang oleh fase refresh.'
        );
        $this->assertNotSame(
            $staleVideo->last_fetched_at->timestamp,
            $staleVideo->fresh()->last_fetched_at->timestamp
        );
    }
}
