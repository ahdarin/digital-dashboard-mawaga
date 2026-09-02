<?php

namespace Tests\Feature;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncInstagramAudienceJob;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\Platform;
use App\Models\User;
use App\Services\AnalyticsSyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regresi Phase 4 (Common Social Sync UX) - unit-level, langsung ke
 * AnalyticsSyncOrchestrator (tanpa lewat HTTP endpoint - itu ada di
 * AnalyticsSyncEndpointTest.php terpisah). Test 2-14, 20, 23, 25 dari
 * spesifikasi 25-item.
 */
class AnalyticsSyncOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private function orchestrator(): AnalyticsSyncOrchestrator
    {
        return app(AnalyticsSyncOrchestrator::class);
    }

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);
    }

    private function userId(): int
    {
        return User::factory()->create(['status' => 'active'])->id;
    }

    private function instagramPlatform(): Platform
    {
        return Platform::firstOrCreate(['name' => 'Instagram']);
    }

    private function tiktokPlatform(): Platform
    {
        return Platform::firstOrCreate(['name' => 'TikTok']);
    }

    private function instagramIntegration(Client $client, string $status = 'active'): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $this->instagramPlatform()->id,
            'integration_name' => 'Instagram API (OAuth)',
            'status' => $status,
            'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    private function tiktokIntegration(Client $client, string $status = 'active'): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $this->tiktokPlatform()->id,
            'integration_name' => 'TikTok API (OAuth)',
            'status' => $status,
            'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    // ===== 2/3/4: dispatch selection per platform filter =====

    public function test_instagram_selected_dispatches_content_and_audience_only(): void
    {
        Queue::fake();
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        $this->orchestrator()->dispatch($client, $ig->platform_id, $this->userId());

        Queue::assertPushed(SyncInstagramAnalyticsJob::class, fn ($job) => $job->apiIntegrationId === $ig->id);
        Queue::assertPushed(SyncInstagramAudienceJob::class, fn ($job) => $job->apiIntegrationId === $ig->id);
        Queue::assertNotPushed(SyncTikTokAnalyticsJob::class);
    }

    public function test_tiktok_selected_dispatches_tiktok_only(): void
    {
        Queue::fake();
        $client = $this->client();
        $tt = $this->tiktokIntegration($client);

        $this->orchestrator()->dispatch($client, $tt->platform_id, $this->userId());

        Queue::assertPushed(SyncTikTokAnalyticsJob::class, fn ($job) => $job->apiIntegrationId === $tt->id);
        Queue::assertNotPushed(SyncInstagramAnalyticsJob::class);
        Queue::assertNotPushed(SyncInstagramAudienceJob::class);
    }

    public function test_all_platforms_dispatches_all_syncable_connected_integrations_for_selected_client_only(): void
    {
        Queue::fake();
        $client = $this->client();
        $otherClient = $this->client();
        $ig = $this->instagramIntegration($client);
        $tt = $this->tiktokIntegration($client);
        $otherIg = $this->instagramIntegration($otherClient);

        $this->orchestrator()->dispatch($client, null, $this->userId());

        Queue::assertPushed(SyncInstagramAnalyticsJob::class, fn ($job) => $job->apiIntegrationId === $ig->id);
        Queue::assertPushed(SyncInstagramAudienceJob::class, fn ($job) => $job->apiIntegrationId === $ig->id);
        Queue::assertPushed(SyncTikTokAnalyticsJob::class, fn ($job) => $job->apiIntegrationId === $tt->id);
        // ===== 5: integration client LAIN tidak boleh ikut ke-dispatch =====
        Queue::assertNotPushed(SyncInstagramAnalyticsJob::class, fn ($job) => $job->apiIntegrationId === $otherIg->id);
    }

    // ===== 6: integration inactive/needs-reconnect TIDAK di-dispatch seolah normal =====

    public function test_inactive_integration_is_not_dispatched_as_normal_sync(): void
    {
        Queue::fake();
        $client = $this->client();
        $ig = $this->instagramIntegration($client, 'inactive');

        $result = $this->orchestrator()->dispatch($client, $ig->platform_id, $this->userId());

        Queue::assertNotPushed(SyncInstagramAnalyticsJob::class);
        Queue::assertNotPushed(SyncInstagramAudienceJob::class);
        $this->assertSame('needs_reconnect', $result['skipped'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]);
        $this->assertSame('needs_reconnect', $result['skipped'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE]);
    }

    public function test_not_connected_platform_is_skipped_not_errored(): void
    {
        Queue::fake();
        $client = $this->client();

        $result = $this->orchestrator()->dispatch($client, $this->tiktokPlatform()->id, $this->userId());

        Queue::assertNotPushed(SyncTikTokAnalyticsJob::class);
        $this->assertSame('not_connected', $result['skipped'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]);
    }

    // ===== 7: queued state visible SEBELUM overlap lock di-acquire =====

    public function test_queued_state_visible_before_overlap_lock_acquired(): void
    {
        $client = $this->client();
        $tt = $this->tiktokIntegration($client);

        // Push betulan ke koneksi 'database' (bukan 'sync' default test env)
        // - baris job SUNGGUHAN masuk tabel `jobs`, TANPA benar-benar
        // dieksekusi (sama pola dengan TikTokSyncStatusEndpointTest).
        Queue::connection('database')->push(
            new SyncTikTokAnalyticsJob($tt->id, 'default', now()->subDays(90)->toDateString(), now()->toDateString(), $this->userId())
        );

        $status = $this->orchestrator()->statusForClient($client, $tt->platform_id);

        $this->assertSame('queued', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]['status']);
        $this->assertSame('queued', $status['overall_status']);
    }

    // ===== 8: running state resolved correctly =====

    public function test_running_state_resolved_when_overlap_lock_held(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        $lock = Cache::lock(SyncInstagramAnalyticsJob::cacheLockKey($ig->id), 600);
        $lock->get();

        try {
            $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);
            $this->assertSame('running', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status']);
            $this->assertSame('running', $status['overall_status']);
        } finally {
            $lock->release();
        }
    }

    // ===== 9/10/11: success status per subjob =====

    private function syncLog(Client $client, ApiIntegration $integration, string $sourceType, string $status, array $extra = []): AnalyticsSyncLog
    {
        return AnalyticsSyncLog::create(array_merge([
            'client_id' => $client->id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $this->userId(),
            'source_type' => $sourceType,
            'status' => $status,
            'sync_mode' => 'default',
            'range_from' => now()->subDays(90)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
        ], $extra));
    }

    public function test_instagram_content_success_status(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $this->syncLog($client, $ig, 'api_sync', 'success', ['synced_count' => 5]);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('success', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status']);
        $this->assertSame(5, $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['synced_count']);
    }

    public function test_instagram_audience_success_status(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $this->syncLog($client, $ig, 'audience_api_sync', 'success', ['synced_count' => 3]);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('success', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE]['status']);
    }

    public function test_tiktok_success_status(): void
    {
        $client = $this->client();
        $tt = $this->tiktokIntegration($client);
        $this->syncLog($client, $tt, 'api_sync', 'success', ['synced_count' => 7]);

        $status = $this->orchestrator()->statusForClient($client, $tt->platform_id);

        $this->assertSame('success', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]['status']);
    }

    // ===== 12: satu subjob gagal + lainnya sukses -> overall partial =====

    public function test_one_subjob_failure_with_others_success_gives_overall_partial(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $tt = $this->tiktokIntegration($client);

        $this->syncLog($client, $ig, 'api_sync', 'success');
        $this->syncLog($client, $ig, 'audience_api_sync', 'failed', ['error_message' => 'Rate limit']);
        $this->syncLog($client, $tt, 'api_sync', 'success');

        $status = $this->orchestrator()->statusForClient($client, null);

        $this->assertSame('partial', $status['overall_status']);
    }

    // ===== 13: semua gagal -> overall failed =====

    public function test_all_failure_gives_overall_failed(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        $this->syncLog($client, $ig, 'api_sync', 'failed', ['error_message' => 'Token invalid']);
        $this->syncLog($client, $ig, 'audience_api_sync', 'failed', ['error_message' => 'Token invalid']);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('failed', $status['overall_status']);
    }

    // ===== 14: snapshot-history failure TIDAK dirender sebagai perfect success =====

    public function test_snapshot_history_failure_does_not_render_as_perfect_success(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        // Pakai SnapshotFailureMarker::wrap() langsung (kontrak SATU-
        // SATUNYA sumber format marker, Phase 4.1 Langkah 5 - jangan hand-
        // roll string di test, itu persis kelas bug drift yang mau
        // dicegah marker ini).
        $this->syncLog($client, $ig, 'api_sync', 'success', [
            'synced_count' => 3,
            'error_message' => \App\Services\SnapshotFailureMarker::wrap('Media ig-1', 'simulated'),
        ]);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('partial', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'Sync log status success TAPI mengandung marker snapshot-history gagal harus dilaporkan partial, bukan success sempurna.');
    }

    // ===== Snapshot maintenance correction: KnownContentRefreshFailureMarker =====

    /**
     * Scenario G/H - sibling KnownContentRefreshFailureMarker (Langkah 5)
     * HARUS terdeteksi orchestrator PERSIS sama seperti SnapshotFailureMarker
     * di atas - sync utama 'success' tapi known-content refresh (rotasi
     * observasi) sebagian gagal TIDAK BOLEH dilaporkan sebagai success
     * sempurna.
     */
    public function test_known_content_refresh_failure_downgrades_subjob_and_overall_status(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        $this->syncLog($client, $ig, 'api_sync', 'success', [
            'synced_count' => 5,
            'error_message' => \App\Services\KnownContentRefreshFailureMarker::wrap(2, 10),
        ]);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('partial', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'error_message dengan marker refresh-partial harus menurunkan subjob jadi partial, bukan success sempurna.');
        $this->assertNotSame('success', $status['overall_status']);
        $this->assertSame('partial', $status['overall_status']);
    }

    /**
     * Kata generik "refresh"/"gagal"/"partial" TIDAK BOLEH memicu false-
     * positive - hanya marker bracket exact ([KNOWN_CONTENT_REFRESH_PARTIAL])
     * yang valid, sama disiplin dengan SnapshotFailureMarker di atas.
     */
    public function test_generic_refresh_wording_does_not_trigger_false_positive_partial(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        $this->syncLog($client, $ig, 'api_sync', 'success', [
            'synced_count' => 5,
            'error_message' => 'refresh gagal sebagian tapi tidak masalah',
        ]);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('success', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'Kata generik TIDAK BOLEH memicu false-positive partial - hanya marker bracket exact yang valid.');
    }

    /**
     * Pre-manual-QA gate (Langkah 4) - melengkapi test di atas dengan 2
     * assertion yang belum eksplisit dicek di sana: overall_status (bukan
     * cuma subjob) TIDAK BOLEH jadi 'success' kalau ada snapshot-history
     * partial di dalamnya, dan payload status-nya TIDAK PERNAH menyertakan
     * raw exception/token - cuma marker bracket + pesan aman yang memang
     * didesain buat ditampilkan (lihat kontrak SnapshotFailureMarker::wrap()).
     */
    public function test_snapshot_history_failure_downgrades_overall_status_and_leaks_no_raw_detail(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        $this->syncLog($client, $ig, 'api_sync', 'success', [
            'synced_count' => 3,
            'error_message' => \App\Services\SnapshotFailureMarker::wrap('Media ig-1', 'simulated network timeout'),
        ]);

        $status = $this->orchestrator()->statusForClient($client, null);

        $this->assertNotSame('success', $status['overall_status'], 'overall_status TIDAK BOLEH success kalau salah satu subjob-nya snapshot-history partial.');
        $this->assertSame('partial', $status['overall_status']);

        $encoded = json_encode($status);
        $this->assertStringNotContainsString('fake-token', $encoded, 'Access token integration TIDAK BOLEH bocor ke status payload.');
        $this->assertStringNotContainsString('Exception', $encoded, 'Raw exception class name TIDAK BOLEH bocor ke status payload.');
        $this->assertStringNotContainsString('Stack trace', $encoded);
    }

    // ===== Phase 4.1 Langkah 5: marker contract exact (bracket prefix, bukan kata generik) =====

    public function test_marker_detection_requires_exact_bracket_prefix_not_generic_words(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        // Pesan yang KEBETULAN mengandung kata "snapshot"/"warning"/"failed"
        // TAPI BUKAN marker sungguhan - TIDAK BOLEH ke-detect sebagai
        // snapshot-history failure (Langkah 5, "jangan scan generic words").
        $this->syncLog($client, $ig, 'api_sync', 'success', [
            'synced_count' => 2,
            'error_message' => 'Warning: snapshot lama sempat failed sebelum retry berhasil.',
        ]);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('success', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'Kata generik "snapshot"/"warning"/"failed" TIDAK BOLEH memicu false-positive partial - hanya marker bracket exact yang valid.');
    }

    public function test_marker_detection_finds_real_marker_via_shared_helper(): void
    {
        $client = $this->client();
        $tt = $this->tiktokIntegration($client);

        $this->syncLog($client, $tt, 'api_sync', 'success', [
            'synced_count' => 4,
            'error_message' => \App\Services\SnapshotFailureMarker::wrap('Video tt-1', 'DB write timeout'),
        ]);

        $status = $this->orchestrator()->statusForClient($client, $tt->platform_id);

        $this->assertSame('partial', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]['status']);
    }

    // ===== 20: coverage tidak berubah jadi full hanya karena baru sync =====

    public function test_period_coverage_unchanged_by_successful_sync_when_history_insufficient(): void
    {
        // Phase 4 TIDAK BOLEH menyentuh semantik PeriodPerformanceService -
        // buktikan orchestrator dispatch() TIDAK memanggil/override
        // computeContentDelta() sama sekali; coverage tetap murni fungsi
        // dari snapshot history yang benar2 ada (Phase 3.1), independen
        // dari sync BARU SAJA sukses atau tidak.
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = \App\Models\InstagramMediaSnapshot::create([
            'api_integration_id' => $this->instagramIntegration($client)->id,
            'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched',
            'published_at' => now()->subDays(200),
            'last_fetched_at' => now(),
        ]);

        // History HANYA sejak hari ini (baru "sync sukses" hari ini) - TIDAK
        // ada baseline 29 hari lalu.
        ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->toDateString(),
            'views' => 100,
        ]);

        $periodService = app(\App\Services\PeriodPerformanceService::class);
        $result = $periodService->computeContentDelta(
            'instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at,
            now()->subDays(29)->startOfDay(), now()->startOfDay()
        );

        $this->assertNotSame(\App\Services\ContentPeriodResult::FULL, $result->coverageStatus, '30 hari filter dengan histori baru mulai hari ini TIDAK BOLEH dilaporkan full, walau sync barusan sukses.');
    }

    // ===== 23: duplicate/double dispatch protected =====

    public function test_duplicate_dispatch_is_protected_by_existing_queued_job(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        Queue::connection('database')->push(
            new SyncInstagramAnalyticsJob($ig->id, 'default', now()->subDays(90)->toDateString(), now()->toDateString(), $this->userId())
        );
        $this->assertDatabaseCount('jobs', 1);

        $this->orchestrator()->dispatch($client, $ig->platform_id, $this->userId());

        // TIDAK ADA baris job baru ditambahkan buat instagram_content -
        // audience TETAP boleh dispatch (subjob independen).
        $jobsCount = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('payload', 'like', '%SyncInstagramAnalyticsJob%')
            ->count();
        $this->assertSame(1, $jobsCount, 'Klik/dispatch kedua tidak boleh menambah baris job baru buat subjob yang sudah queued.');
    }

    // ===== 25: freshness timestamp dari genuine observation =====

    public function test_freshness_timestamp_derives_from_genuine_observation_time(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $media = \App\Models\InstagramMediaSnapshot::create([
            'api_integration_id' => $ig->id,
            'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched',
            'published_at' => now()->subDays(10),
            'last_fetched_at' => now(),
        ]);

        $snapshot = ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => $ig->platform_id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->toDateString(),
            'views' => 100,
        ]);

        $lastObservation = $this->orchestrator()->lastObservationAt($client, $ig->platform_id);

        $this->assertNotNull($lastObservation);
        $this->assertEqualsWithDelta($snapshot->updated_at->timestamp, $lastObservation->timestamp, 2, 'Freshness harus derive dari updated_at snapshot GENUINE, bukan waktu yang dikarang.');
    }

    public function test_freshness_is_null_when_no_observation_exists(): void
    {
        $client = $this->client();
        $this->instagramIntegration($client);

        $this->assertNull($this->orchestrator()->lastObservationAt($client, null));
    }

    // ===== 22: CSV/manual-only state tidak menampilkan seolah API sync tersedia =====

    public function test_manual_only_platform_shows_manual_data_message_not_generic_not_connected(): void
    {
        $client = $this->client();
        $platform = $this->tiktokPlatform();

        // Content CSV/manual (dua-duanya snapshot FK null) buat TikTok -
        // TIDAK ADA ApiIntegration TikTok sama sekali buat client ini.
        ContentMetric::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => $this->userId(),
            'metric_date' => now()->subDays(2),
            'views' => 500,
            'engagement_rate' => 3.2,
        ]);

        $status = $this->orchestrator()->statusForClient($client, $platform->id);

        $subjob = $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT];
        // Phase 4.1 Langkah 2 - status TERSENDIRI 'manual_data' (bukan lagi
        // disamarkan sebagai 'not_connected'), supaya konsumen bisa
        // membedakan "tidak ada data apapun" vs "ada data, dari manual".
        $this->assertSame('manual_data', $subjob['status']);
        $this->assertStringContainsString('input manual', $subjob['message'], 'Client dengan data CSV-only harus dapat pesan yang jujur soal sumber data, bukan cuma "belum terhubung" generik.');
    }

    /**
     * DB::table (bukan Eloquent update()) - Eloquent auto-touch updated_at
     * ke NOW lagi kalau lewat model, jadi backdating HARUS bypass itu.
     */
    private function backdateLog(AnalyticsSyncLog $log, int $secondsAgo): void
    {
        DB::table('analytics_sync_logs')->where('id', $log->id)->update([
            'updated_at' => now()->subSeconds($secondsAgo),
        ]);
    }

    // ===== Phase 4.1 Langkah 2: mixed needs_reconnect semantics =====

    public function test_single_inactive_instagram_gives_overall_needs_reconnect(): void
    {
        $client = $this->client();
        $this->instagramIntegration($client, 'inactive');

        $status = $this->orchestrator()->statusForClient($client, $this->instagramPlatform()->id);

        $this->assertSame('needs_reconnect', $status['overall_status']);
    }

    public function test_single_inactive_tiktok_gives_overall_needs_reconnect(): void
    {
        $client = $this->client();
        $this->tiktokIntegration($client, 'inactive');

        $status = $this->orchestrator()->statusForClient($client, $this->tiktokPlatform()->id);

        $this->assertSame('needs_reconnect', $status['overall_status']);
    }

    public function test_all_platforms_instagram_success_tiktok_needs_reconnect_gives_partial(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $this->tiktokIntegration($client, 'inactive');

        $this->syncLog($client, $ig, 'api_sync', 'success');
        $this->syncLog($client, $ig, 'audience_api_sync', 'success');

        $status = $this->orchestrator()->statusForClient($client, null);

        $this->assertSame('partial', $status['overall_status'], 'Refresh yang diminta tidak lengkap (TikTok butuh reconnect) - TIDAK boleh overall=success.');
    }

    public function test_all_active_and_all_success_gives_overall_success(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $tt = $this->tiktokIntegration($client);

        $this->syncLog($client, $ig, 'api_sync', 'success');
        $this->syncLog($client, $ig, 'audience_api_sync', 'success');
        $this->syncLog($client, $tt, 'api_sync', 'success');

        $status = $this->orchestrator()->statusForClient($client, null);

        $this->assertSame('success', $status['overall_status']);
    }

    public function test_manual_only_data_does_not_create_fake_failure_alongside_successful_api_platform(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        // TikTok: TIDAK ADA ApiIntegration, TAPI ada data manual/CSV.
        ContentMetric::create([
            'client_id' => $client->id,
            'platform_id' => $this->tiktokPlatform()->id,
            'imported_by' => $this->userId(),
            'metric_date' => now()->subDays(2),
            'views' => 300,
            'engagement_rate' => 2.1,
        ]);

        $this->syncLog($client, $ig, 'api_sync', 'success');
        $this->syncLog($client, $ig, 'audience_api_sync', 'success');

        $status = $this->orchestrator()->statusForClient($client, null);

        $this->assertSame('success', $status['overall_status'], 'Platform manual-only TIDAK BOLEH menyeret overall API sync yang sukses jadi partial/failed.');
    }

    public function test_all_relevant_integrations_inactive_gives_needs_reconnect(): void
    {
        $client = $this->client();
        $this->instagramIntegration($client, 'inactive');
        $this->tiktokIntegration($client, 'inactive');

        $status = $this->orchestrator()->statusForClient($client, null);

        $this->assertSame('needs_reconnect', $status['overall_status']);
    }

    // ===== Phase 4.1 Langkah 3: stale pending/running protection =====

    public function test_fresh_pending_transition_is_handled_safely(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $this->syncLog($client, $ig, 'api_sync', 'pending'); // updated_at = baru saja

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('running', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'Pending yang masih baru harus dianggap running (grace window), bukan langsung failed.');
    }

    public function test_stale_pending_with_no_queue_or_lock_does_not_poll_forever(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $log = $this->syncLog($client, $ig, 'api_sync', 'pending');
        $this->backdateLog($log, 700); // > 660 (600 LOCK_EXPIRE + 60 margin) buat content job

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $subjob = $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT];
        $this->assertSame('failed', $subjob['status'], 'Pending yang sudah stale (worker crash) TIDAK BOLEH dilaporkan running selamanya.');
        $this->assertStringContainsString('terhenti', $subjob['message']);
    }

    public function test_stale_running_state_no_longer_polls_forever(): void
    {
        // "Stale running" = skenario BUG LAMA: pending log tua dipetakan
        // 'running' tanpa batas waktu (Phase 4 asli). Dites lewat subjob
        // audience (threshold beda, 360s) buat variasi cakupan.
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $log = $this->syncLog($client, $ig, 'audience_api_sync', 'pending');
        $this->backdateLog($log, 400); // > 360 (300 LOCK_EXPIRE + 60 margin) buat audience job

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('failed', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE]['status']);
    }

    public function test_actual_queue_row_still_wins_as_queued_over_stale_pending_log(): void
    {
        $client = $this->client();
        $tt = $this->tiktokIntegration($client);
        $log = $this->syncLog($client, $tt, 'api_sync', 'pending');
        $this->backdateLog($log, 700);

        Queue::connection('database')->push(
            new SyncTikTokAnalyticsJob($tt->id, 'default', now()->subDays(90)->toDateString(), now()->toDateString(), $this->userId())
        );

        $status = $this->orchestrator()->statusForClient($client, $tt->platform_id);

        $this->assertSame('queued', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]['status'], 'Live signal (baris jobs table) HARUS menang atas log pending yang stale.');
    }

    public function test_actual_lock_still_wins_as_running_over_stale_pending_log(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $log = $this->syncLog($client, $ig, 'api_sync', 'pending');
        $this->backdateLog($log, 700);

        $lock = Cache::lock(SyncInstagramAnalyticsJob::cacheLockKey($ig->id), 600);
        $lock->get();

        try {
            $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);
            $this->assertSame('running', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'Live signal (lock benar2 dipegang) HARUS menang atas log pending yang stale.');
        } finally {
            $lock->release();
        }
    }

    // ===== Phase 4.1 Langkah 2: subjob log scoping (IG vs TikTok tidak boleh cross-contaminate) =====

    public function test_instagram_and_tiktok_logs_do_not_cross_contaminate_when_both_use_api_sync_source_type(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $tt = $this->tiktokIntegration($client);

        // Instagram Content DAN TikTok Content SAMA-SAMA source_type
        // 'api_sync' (Langkah 2 audit) - satu-satunya pembeda di query
        // adalah api_integration_id (integration row yang BEDA per
        // platform), BUKAN cuma client_id+source_type.
        $this->syncLog($client, $ig, 'api_sync', 'success', ['synced_count' => 5]);
        $this->syncLog($client, $tt, 'api_sync', 'failed', ['error_message' => 'Token TikTok kadaluarsa.']);

        $status = $this->orchestrator()->statusForClient($client, null);

        $this->assertSame('success', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'Instagram Content harus baca log Instagram sendiri, bukan TikTok.');
        $this->assertSame('failed', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]['status'], 'TikTok Content harus baca log TikTok sendiri, bukan Instagram.');
    }

    public function test_instagram_and_tiktok_logs_do_not_cross_contaminate_reversed(): void
    {
        // Kebalikannya (Langkah 2, "dan kebalikannya") - Instagram gagal,
        // TikTok sukses.
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        $tt = $this->tiktokIntegration($client);

        $this->syncLog($client, $ig, 'api_sync', 'failed', ['error_message' => 'Rate limit Instagram.']);
        $this->syncLog($client, $tt, 'api_sync', 'success', ['synced_count' => 7]);

        $status = $this->orchestrator()->statusForClient($client, null);

        $this->assertSame('failed', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status']);
        $this->assertSame('success', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]['status']);
    }

    // ===== Phase 4.1 Langkah 4: mixed active/inactive dispatch (bukan cuma status resolution) =====

    public function test_mixed_instagram_inactive_tiktok_active_still_dispatches_tiktok(): void
    {
        Queue::fake();
        $client = $this->client();
        $ig = $this->instagramIntegration($client, 'inactive');
        $tt = $this->tiktokIntegration($client);

        $result = $this->orchestrator()->dispatch($client, null, $this->userId());

        Queue::assertPushed(SyncTikTokAnalyticsJob::class, fn ($job) => $job->apiIntegrationId === $tt->id);
        Queue::assertNotPushed(SyncInstagramAnalyticsJob::class);
        Queue::assertNotPushed(SyncInstagramAudienceJob::class);
        $this->assertSame('needs_reconnect', $result['skipped'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]);
        $this->assertContains(AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, $result['dispatched'], 'TikTok TIDAK boleh ikut ter-block cuma karena Instagram butuh reconnect.');
    }

    // ===== Phase 4.1 Langkah 7: manual-only platform tidak pernah ikut dispatch (All Platforms) =====

    public function test_all_platforms_dispatch_never_fake_syncs_manual_only_platform(): void
    {
        Queue::fake();
        $client = $this->client();
        $ig = $this->instagramIntegration($client);
        // TikTok: TIDAK ADA ApiIntegration, TAPI ada data manual/CSV -
        // dispatch() TIDAK PERNAH boleh mencoba "sync" platform yang
        // sebenarnya tidak punya integration sama sekali.
        \App\Models\ContentMetric::create([
            'client_id' => $client->id,
            'platform_id' => $this->tiktokPlatform()->id,
            'imported_by' => $this->userId(),
            'metric_date' => now()->subDays(2),
            'views' => 500,
            'engagement_rate' => 3.2,
        ]);

        $result = $this->orchestrator()->dispatch($client, null, $this->userId());

        Queue::assertNotPushed(SyncTikTokAnalyticsJob::class);
        Queue::assertPushed(SyncInstagramAnalyticsJob::class, fn ($job) => $job->apiIntegrationId === $ig->id);
        $this->assertSame('not_connected', $result['skipped'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]);
    }

    // ===== Log scoping C/D (cross-client leak + Instagram Audience separation) =====

    public function test_another_clients_newer_log_never_affects_selected_client_status(): void
    {
        $client = $this->client();
        $otherClient = $this->client();
        $ig = $this->instagramIntegration($client);
        $otherIg = $this->instagramIntegration($otherClient);

        // Log client SENDIRI - lebih tua.
        $this->syncLog($client, $ig, 'api_sync', 'failed', ['error_message' => 'Gagal untuk client ini.']);
        // Log client LAIN - dibuat SETELAHNYA (lebih baru secara timestamp),
        // TAPI integration-nya beda (milik client lain) - status client
        // SENDIRI harus tetap baca log MILIKNYA sendiri, tidak boleh
        // "ketiban" log client lain walau lebih baru.
        $this->syncLog($otherClient, $otherIg, 'api_sync', 'success', ['synced_count' => 99]);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('failed', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'Log client lain (walau lebih baru) TIDAK BOLEH memengaruhi status client yang sedang dilihat.');
    }

    public function test_instagram_audience_status_remains_separate_from_instagram_content_status(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        // Content sukses, Audience gagal - DUA source_type berbeda
        // ('api_sync' vs 'audience_api_sync') pada integration yang SAMA -
        // masing-masing subjob harus baca log source_type-nya sendiri,
        // tidak boleh tercampur.
        $this->syncLog($client, $ig, 'api_sync', 'success', ['synced_count' => 3]);
        $this->syncLog($client, $ig, 'audience_api_sync', 'failed', ['error_message' => 'Audience API gagal.']);

        $status = $this->orchestrator()->statusForClient($client, $ig->platform_id);

        $this->assertSame('success', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status']);
        $this->assertSame('failed', $status['subjobs'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE]['status'], 'Instagram Audience TIDAK BOLEH ikut terbaca sukses cuma karena Instagram Content sukses - source_type beda, subjob independen.');
    }
}
