<?php

namespace Tests\Feature;

use App\Console\Commands\DetectPerformanceAnomalies;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\InstagramMediaSnapshot;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\PerformanceAnomaly;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\AiStrategyService;
use App\Services\PeriodPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regresi Phase 3 test 14-25 (consumer integration) - membuktikan
 * Overview/Table/Export/Content Detail/Dashboard/Report/AI Strategy/Anomaly
 * Detection/Platform filter/CSV semantics/partial coverage BENERAN pakai
 * PeriodPerformanceService (delta genuine dari content_metric_snapshots),
 * BUKAN lagi sum(content_metrics.views) whereBetween(metric_date=publish
 * date). Unit-level engine correctness ada di PeriodPerformanceServiceTest
 * (test 1-13) - file ini fokus ke "apakah consumer-nya BENERAN kepasang
 * ke engine", bukan re-test math yang sudah dites di sana.
 */
class PeriodPerformanceConsumerTest extends TestCase
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

    private function managerFor(Client $client, string $module = 'analytics'): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => $module, 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    private function instagramIntegration(Client $client): ApiIntegration
    {
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'integration_name' => 'IG',
            'status' => 'active',
            'access_token' => 'fake',
            'external_username' => 'creator',
        ]);
    }

    /**
     * Bikin 1 content Instagram API-sourced lengkap: InstagramMediaSnapshot
     * + ContentMetric (current/latest, metric_date DIKUNCI ke tanggal
     * publish - SENGAJA jauh di luar window periode manapun yang dites,
     * biar kalau ada consumer yang diam-diam masih pakai whereBetween(
     * metric_date) lama, angkanya PASTI 0/hilang, bukan kebetulan match) +
     * 2 baris ContentMetricSnapshot (baseline & current) buat delta genuine.
     *
     * @return array{contentItem: ?ContentItem, media: InstagramMediaSnapshot, metric: ContentMetric}
     */
    private function apiContentWithDelta(
        Client $client,
        ApiIntegration $integration,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $baselineViews,
        int $currentViews,
        bool $linkToContentItem = false,
    ): array {
        $platform = Platform::find($integration->platform_id);
        $publishedAt = now()->subDays(150); // jauh di luar SEMUA window periode yang dites di file ini

        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'caption' => 'Test caption '.uniqid(),
            'match_status' => $linkToContentItem ? 'matched' : 'unmatched',
            'published_at' => $publishedAt,
            'last_fetched_at' => now(),
        ]);

        $contentItem = null;
        if ($linkToContentItem) {
            $plan = ContentPlan::create([
                'client_id' => $client->id,
                'created_by' => User::factory()->create()->id,
                'month' => now()->month,
                'year' => now()->year,
                'status' => 'draft',
            ]);
            $contentType = ContentType::firstOrCreate(['name' => 'Video']);
            $contentItem = ContentItem::create([
                'content_plan_id' => $plan->id,
                'client_id' => $client->id,
                'content_type_id' => $contentType->id,
                'platform_id' => $platform->id,
                'title' => 'Linked Content '.uniqid(),
                'deadline_at' => now()->subDays(2),
            ]);
        }

        $metric = ContentMetric::create([
            'content_item_id' => $contentItem?->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id,
            'instagram_media_snapshot_id' => $media->id,
            'metric_date' => $publishedAt->toDateString(),
            'views' => $currentViews,
            'engagement_rate' => 5.0,
        ]);

        ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'content_item_id' => $contentItem?->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $periodStart->copy()->subDay()->toDateString(),
            'views' => $baselineViews,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'content_item_id' => $contentItem?->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $periodEnd->toDateString(),
            'views' => $currentViews,
        ]);

        return ['contentItem' => $contentItem, 'media' => $media, 'metric' => $metric];
    }

    // ===== 14: Overview uses period engine =====

    public function test_overview_shows_period_delta_not_lifetime_cumulative_locked_to_publish_date(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 2000, currentViews: 5000);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 7,
        ]));

        $response->assertOk();
        // Delta genuine (5000-2000=3000) HARUS tampil - dengan bug lama
        // (whereBetween metric_date, publish date 150 hari lalu, DI LUAR
        // window 7 hari), angka ini akan 0/hilang sama sekali.
        $response->assertSee('3,000');
        $response->assertDontSee('5,000');
    }

    // ===== 15: Performance Table uses period engine =====

    public function test_table_shows_period_delta_not_lifetime_cumulative(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 1000, currentViews: 4500);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id, 'period' => 7,
        ]));

        $response->assertOk();
        $response->assertSee('>3,500<', false);
    }

    // ===== 16: Export uses period engine / honest labeling =====

    public function test_export_uses_period_delta_and_honest_columns(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 500, currentViews: 2000);

        $response = $this->actingAs($manager)->get(route('analytics.export', [
            'client_id' => $client->id, 'period' => 7,
        ]));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('content_title,platform,period_start,period_end,coverage_status,views,engagement_rate', $csv);
        $this->assertStringContainsString(',1500,', $csv, 'Views di CSV harus delta periode (2000-500=1500), bukan lifetime cumulative.');
        $this->assertStringContainsString('full', $csv, 'coverage_status harus diikutkan di export.');
    }

    // ===== 17: Content Detail uses snapshot history =====

    public function test_content_detail_days_tracked_uses_snapshot_history_not_metric_date_count(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        $result = $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 100, currentViews: 400, linkToContentItem: true);

        // Tambah 1 snapshot lagi (total 3 hari genuine: baseline, tengah, current)
        // - content_metrics TETAP cuma 1 baris (locked ke publish date),
        // jadi daysTracked lama (distinct metric_date) akan selalu 1.
        ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => Platform::find($integration->platform_id)->id,
            'content_item_id' => $result['contentItem']->id,
            'instagram_media_snapshot_id' => $result['media']->id,
            'snapshot_date' => now()->subDays(3)->toDateString(),
            'views' => 250,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics.show', $result['contentItem']));

        $response->assertOk();
        $response->assertSee('>3</p>', false);
    }

    // ===== 18: Executive Dashboard no longer uses publish-date semantics =====

    public function test_dashboard_total_views_uses_period_delta_for_linked_api_content(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client, 'dashboard');
        $integration = $this->instagramIntegration($client);

        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now();
        $this->apiContentWithDelta($client, $integration, $startOfMonth, $endOfMonth, baselineViews: 3000, currentViews: 9500, linkToContentItem: true);

        $response = $this->actingAs($manager)->get(route('dashboard'));

        $response->assertOk();
        // 9500-3000 = 6500 - JAUH beda dari lifetime cumulative (9500) atau
        // 0 (kalau masih whereBetween metric_date publish 150 hari lalu).
        $response->assertSee('6,500');
    }

    // ===== 19: Reports use period engine =====

    public function test_report_performance_data_uses_period_delta(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        $result = $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 800, currentViews: 3300, linkToContentItem: true);

        $controller = new \App\Http\Controllers\ReportController();
        $method = new \ReflectionMethod($controller, 'buildPerformanceData');
        $method->setAccessible(true);

        $data = $method->invoke($controller, [
            'client_id' => $client->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ]);

        $this->assertSame(2500, $data['total_views'], '3300-800=2500, bukan lifetime cumulative (3300) atau 0.');
        $this->assertSame('full', $data['coverage_status']);
    }

    // ===== 20: AI Strategy input uses corrected period performance =====

    public function test_ai_strategy_performance_summary_uses_period_delta(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        $analysisStart = now()->subMonthNoOverflow()->startOfMonth();
        $analysisEnd = now()->subMonthNoOverflow()->endOfMonth();
        $result = $this->apiContentWithDelta($client, $integration, $analysisStart, $analysisEnd, baselineViews: 1200, currentViews: 4700, linkToContentItem: true);

        // apiContentWithDelta() menaruh baseline 1 hari SEBELUM periodStart
        // (by design, itu boundary ideal) - artinya baseline itu di LUAR
        // window [analysisStart, analysisEnd], cuma snapshot "current"
        // (tanggal analysisEnd) yang genuinely di dalam window. Tambah 1
        // snapshot lagi DI DALAM window biar tracked_days bisa dibuktikan
        // >= 2 (bukan cuma 1, yang akan sama persis dgn semantik lama).
        ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => Platform::find($integration->platform_id)->id,
            'content_item_id' => $result['contentItem']->id,
            'instagram_media_snapshot_id' => $result['media']->id,
            'snapshot_date' => $analysisStart->copy()->addDays(5)->toDateString(),
            'views' => 3000,
        ]);

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client);

        $this->assertSame(4700 - 1200, $summary['total_views']);
        $this->assertGreaterThanOrEqual(2, $summary['tracked_days'], 'tracked_days harus dari snapshot_date genuine (2 baris dibuat), bukan distinct metric_date (selalu 1).');
    }

    // ===== 21: Anomaly detection uses genuine observations =====

    public function test_anomaly_detection_uses_daily_gain_not_raw_cumulative_views(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $platform = Platform::find($integration->platform_id);

        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $contentItem = ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id, 'content_type_id' => $contentType->id,
            'platform_id' => $platform->id, 'title' => 'Anomaly Content', 'deadline_at' => now()->subDays(2),
        ]);

        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-anomaly',
            'match_status' => 'matched', 'published_at' => now()->subDays(60), 'last_fetched_at' => now(),
        ]);

        ContentMetric::create([
            'content_item_id' => $contentItem->id, 'client_id' => $client->id, 'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id, 'instagram_media_snapshot_id' => $media->id,
            'metric_date' => now()->subDays(60), 'views' => 50000, 'engagement_rate' => 5.0,
        ]);

        // Baseline: gain harian ~100/hari selama 5 hari terakhir (stabil).
        $cumulative = 49000;
        for ($i = 5; $i >= 1; $i--) {
            $cumulative += 100;
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'content_item_id' => $contentItem->id,
                'instagram_media_snapshot_id' => $media->id, 'snapshot_date' => now()->subDays($i)->toDateString(),
                'views' => $cumulative,
            ]);
        }
        // Hari ini: lonjakan gain jauh di atas rata-rata (+2000, bukan ~100).
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'content_item_id' => $contentItem->id,
            'instagram_media_snapshot_id' => $media->id, 'snapshot_date' => now()->toDateString(),
            'views' => $cumulative + 2000,
        ]);

        $this->artisan('analytics:detect-anomalies')->assertExitCode(0);

        $anomaly = PerformanceAnomaly::where('content_item_id', $contentItem->id)->first();
        $this->assertNotNull($anomaly, 'Lonjakan GAIN HARIAN harus terdeteksi walau raw cumulative views (51100) tidak "aneh" dibanding histori manapun.');
        $this->assertSame('spike', $anomaly->type);
        $this->assertSame(2000, $anomaly->views_on_date, 'views_on_date buat sumber API sekarang berarti GAIN hari ini, bukan raw cumulative views.');
    }

    // ===== 22: Platform filtering continues working =====

    public function test_overview_platform_filter_isolates_period_engine_results_per_platform(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $igIntegration = $this->instagramIntegration($client);
        $ttPlatform = Platform::firstOrCreate(['name' => 'TikTok']);
        $ttIntegration = ApiIntegration::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id, 'integration_name' => 'TT',
            'status' => 'active', 'access_token' => 'fake', 'external_username' => 'creator',
        ]);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        $this->apiContentWithDelta($client, $igIntegration, $periodStart, $periodEnd, baselineViews: 1000, currentViews: 6000); // IG delta 5000

        $ttVideo = \App\Models\TikTokVideoSnapshot::create([
            'api_integration_id' => $ttIntegration->id, 'external_post_id' => 'tt-1',
            'match_status' => 'unmatched', 'published_at' => now()->subDays(150), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id,
            'imported_by' => User::factory()->create()->id, 'tiktok_video_snapshot_id' => $ttVideo->id,
            'metric_date' => now()->subDays(150), 'views' => 8000, 'engagement_rate' => 5.0,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id, 'tiktok_video_snapshot_id' => $ttVideo->id,
            'snapshot_date' => $periodStart->copy()->subDay()->toDateString(), 'views' => 500,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id, 'tiktok_video_snapshot_id' => $ttVideo->id,
            'snapshot_date' => $periodEnd->toDateString(), 'views' => 8000,
        ]); // TT delta 7500

        $igOnly = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 7, 'platform_id' => $igIntegration->platform_id,
        ]));
        $igOnly->assertOk();
        $igOnly->assertSee('5,000');
        $igOnly->assertDontSee('7,500');

        $ttOnly = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 7, 'platform_id' => $ttPlatform->id,
        ]));
        $ttOnly->assertOk();
        $ttOnly->assertSee('7,500');
        $ttOnly->assertDontSee('5,000');
    }

    // ===== 23: CSV/manual semantics remain compatible =====

    public function test_csv_sourced_metrics_unaffected_by_delta_engine(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id, 'content_type_id' => $contentType->id,
            'platform_id' => $platform->id, 'title' => 'CSV Content', 'deadline_at' => now()->subDays(2),
        ]);

        // CSV row: snapshot FK dua-duanya NULL, metric_date = nilai
        // per-periode ASLI dari user (bukan cumulative) - TIDAK PUNYA
        // content_metric_snapshots sama sekali.
        ContentMetric::create([
            'content_item_id' => $item->id, 'client_id' => $client->id, 'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id, 'metric_date' => now()->subDays(2),
            'views' => 777, 'engagement_rate' => 4.4,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 7,
        ]));

        $response->assertOk();
        $response->assertSee('777');
    }

    // ===== 24: Partial coverage is surfaced in UI, not hidden =====

    public function test_partial_coverage_message_shown_on_overview(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $platform = Platform::find($integration->platform_id);

        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-partial',
            'match_status' => 'unmatched', 'published_at' => now()->subDays(150), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id, 'instagram_media_snapshot_id' => $media->id,
            'metric_date' => now()->subDays(150), 'views' => 900, 'engagement_rate' => 5.0,
        ]);

        // Riwayat snapshot baru mulai DI TENGAH periode 30 hari (CASE C) -
        // tidak ada baseline sebelum period_start sama sekali.
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->subDays(10)->toDateString(), 'views' => 400,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->startOfDay()->toDateString(), 'views' => 900,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 30,
        ]));

        $response->assertOk();
        $response->assertSee('belum tersedia penuh');
    }

    // ===== 25: Content published before window with only 1 current snapshot never shows as exact N-day gain =====

    public function test_content_with_only_one_snapshot_never_shown_as_exact_period_gain(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $platform = Platform::find($integration->platform_id);

        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-onlyone',
            'match_status' => 'unmatched', 'published_at' => now()->subDays(150), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id, 'instagram_media_snapshot_id' => $media->id,
            'metric_date' => now()->subDays(150), 'views' => 12345, 'engagement_rate' => 5.0,
        ]);

        // Cuma SATU snapshot yang pernah ada, hari ini - tidak ada baseline
        // apapun, tidak ada observasi kedua. TIDAK BOLEH ditampilkan
        // sebagai "gain 12.345 dalam 30 hari" (itu lifetime cumulative,
        // bukan gain periode apapun).
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->startOfDay()->toDateString(), 'views' => 12345,
        ]);

        $result = app(PeriodPerformanceService::class)->computeContentDelta(
            'instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at,
            now()->subDays(29)->startOfDay(), now()->startOfDay()
        );
        $this->assertSame(\App\Services\ContentPeriodResult::UNAVAILABLE, $result->coverageStatus);

        // Dan di UI (Table), konten ini TIDAK BOLEH muncul sebagai baris
        // "12,345 views / 30 hari" - harus benar2 tidak ditampilkan (Langkah
        // 12, unavailable tidak dirender seolah 0/exact).
        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id, 'period' => 30,
        ]));
        $response->assertOk();
        $response->assertDontSee('>12,345<', false);
    }
}
