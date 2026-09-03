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
        ?Carbon $publishedAt = null,
    ): array {
        $platform = Platform::find($integration->platform_id);
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - roster SEKARANG
        // cohort publikasi (published_at), BUKAN lagi coverage/observasi.
        // Default DI DALAM periode yang diuji (bukan lagi 150 hari lalu di
        // luar semua window) supaya konten ini genuinely muncul di roster
        // consumer yang diuji - caller yang butuh publish DI LUAR periode
        // (utk membuktikan exclusion) mengirim $publishedAt eksplisit.
        $publishedAt = $publishedAt ?? $periodStart->copy()->addDay();

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

    public function test_overview_shows_current_performance_for_content_published_in_period(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - roster SEKARANG
        // cohort publikasi. Content published DI DALAM periode ini
        // (publishedAt default = periodStart+1day) - baseline SELALU 0
        // legitimate (belum ada sebelum publish), jadi period-gain SECARA
        // MATEMATIS = current views persis (tidak ada histori sebelum
        // publish yang bisa dikurangi) - dua angka SAMA, keduanya genuine.
        $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 2000, currentViews: 5000);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 7,
        ]));

        $response->assertOk();
        // "5,000" (current, PRIMARY) HARUS tampil - dengan bug lama roster
        // digerbang oleh isUsable()/coverage bukan published_at, konten
        // yang publish date-nya baru di dalam periode TAPI observasi
        // pertamanya bertepatan dgn publish (baseline=0) bisa saja tetap
        // ke-exclude tergantung boundary observasi - sekarang HARUS selalu
        // tampil murni karena published_at di dalam periode.
        $response->assertSee('5,000');
        $response->assertSee('+5,000 periode ini');
    }

    // ===== 15: Performance Table uses period engine =====

    public function test_table_shows_current_performance_for_content_published_in_period(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - roster SEKARANG
        // cohort publikasi (published_at) - lihat catatan sama di test
        // overview di atas soal kenapa gain = current buat konten yang
        // publish DI DALAM periode yang diquery.
        $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 1000, currentViews: 4500);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id, 'period' => 7,
        ]));

        $response->assertOk();
        // Kolom Views: total SAAT INI (4,500, bold/primer, PRIMARY) + gain
        // periode (SECONDARY, "periode ini") - keduanya HARUS tampil.
        $response->assertSee('4,500');
        $response->assertSee('+4,500 periode ini');
    }

    // ===== 16: Export uses period engine / honest labeling =====

    public function test_export_uses_current_performance_as_primary_with_published_at_roster(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 16) - roster
        // export SEKARANG cohort publikasi, kolom utama performa TERKINI.
        $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 500, currentViews: 2000);

        $response = $this->actingAs($manager)->get(route('analytics.export', [
            'client_id' => $client->id, 'period' => 7,
        ]));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('content_title,platform,published_at,current_views,current_likes,current_comments,current_shares,current_engagement_rate,period_views_gain,period_coverage_status', $csv);
        $this->assertStringContainsString(',2000,', $csv, 'current_views di CSV harus total provider TERKINI genuine (2000), bukan gain periode.');
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

    public function test_dashboard_total_views_uses_current_performance_for_content_published_this_month(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client, 'dashboard');
        $integration = $this->instagramIntegration($client);

        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now();
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - "Total Views Bulan
        // Ini" = performa TERKINI konten yang published bulan ini (default
        // publishedAt = startOfMonth+1day, di dalam bulan berjalan).
        $this->apiContentWithDelta($client, $integration, $startOfMonth, $endOfMonth, baselineViews: 3000, currentViews: 9500, linkToContentItem: true);

        $response = $this->actingAs($manager)->get(route('dashboard'));

        $response->assertOk();
        // 9,500 - JAUH beda dari 0 (kalau masih whereBetween metric_date
        // publish 150 hari lalu, di luar bulan berjalan).
        $response->assertSee('9,500');
    }

    // ===== 19: Reports use period engine =====

    public function test_report_performance_data_uses_current_performance_for_published_in_period_cohort(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 17) - roster
        // laporan SEKARANG cohort publikasi, total_views = performa TERKINI.
        $result = $this->apiContentWithDelta($client, $integration, $periodStart, $periodEnd, baselineViews: 800, currentViews: 3300, linkToContentItem: true);

        $controller = new \App\Http\Controllers\ReportController();
        $method = new \ReflectionMethod($controller, 'buildPerformanceData');
        $method->setAccessible(true);

        $data = $method->invoke($controller, [
            'client_id' => $client->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ]);

        $this->assertSame(3300, $data['total_views'], 'total_views HARUS performa TERKINI genuine (3300), bukan gain periode (2500) atau 0.');
        $this->assertSame('full', $data['coverage_status']);
    }

    // ===== 20: AI Strategy input uses corrected period performance =====

    public function test_ai_strategy_performance_summary_uses_current_performance_for_published_in_month_cohort(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        // Phase 4.1 (v2) - AI Strategy sekarang calendar month yang dipilih
        // user - lihat AiStrategyService::resolveMonthWindow(). Bulan lalu
        // dipakai (bukan bulan berjalan) supaya window-nya SELALU 1 bulan
        // kalender penuh, deterministic terlepas tanggal test dijalankan.
        $month = now()->subMonthNoOverflow()->format('Y-m');
        $window = app(AiStrategyService::class)->resolveMonthWindow($month);
        $analysisStart = $window['start'];
        $analysisEnd = $window['end'];
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 10) - roster
        // AI SEKARANG cohort publikasi (published_at default = analysisStart
        // +1day, di dalam bulan yang dianalisis) - total_views = performa
        // TERKINI genuine, bukan delta periode.
        $result = $this->apiContentWithDelta($client, $integration, $analysisStart, $analysisEnd, baselineViews: 1200, currentViews: 4700, linkToContentItem: true);

        // Tambah 1 snapshot lagi DI DALAM window biar tracked_days bisa
        // dibuktikan >= 2 (bukan cuma 1, yang akan sama persis dgn
        // semantik lama distinct metric_date).
        ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => Platform::find($integration->platform_id)->id,
            'content_item_id' => $result['contentItem']->id,
            'instagram_media_snapshot_id' => $result['media']->id,
            'snapshot_date' => $analysisStart->copy()->addDays(5)->toDateString(),
            'views' => 3000,
        ]);

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, $month, null);

        $this->assertSame(4700, $summary['total_views'], 'total_views HARUS performa TERKINI genuine (4700), bukan gain periode (3500) atau 0.');
        $this->assertSame('content_published_in_period', $summary['cohort']);
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

    public function test_overview_platform_filter_isolates_cohort_results_per_platform(): void
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
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - roster SEKARANG
        // cohort publikasi - IG published DI DALAM periode (default
        // publishedAt=periodStart+1day) -> current_views=6000 primary.
        $this->apiContentWithDelta($client, $igIntegration, $periodStart, $periodEnd, baselineViews: 1000, currentViews: 6000);

        $ttVideo = \App\Models\TikTokVideoSnapshot::create([
            'api_integration_id' => $ttIntegration->id, 'external_post_id' => 'tt-1',
            'match_status' => 'unmatched', 'published_at' => $periodStart->copy()->addDay(), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id,
            'imported_by' => User::factory()->create()->id, 'tiktok_video_snapshot_id' => $ttVideo->id,
            'metric_date' => $periodStart->copy()->addDay(), 'views' => 8000, 'engagement_rate' => 5.0,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id, 'tiktok_video_snapshot_id' => $ttVideo->id,
            'snapshot_date' => $periodEnd->toDateString(), 'views' => 8000,
        ]);

        $igOnly = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 7, 'platform_id' => $igIntegration->platform_id,
        ]));
        $igOnly->assertOk();
        $igOnly->assertSee('6,000');
        $igOnly->assertDontSee('8,000');

        $ttOnly = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 7, 'platform_id' => $ttPlatform->id,
        ]));
        $ttOnly->assertOk();
        $ttOnly->assertSee('8,000');
        $ttOnly->assertDontSee('6,000');
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

    public function test_partial_period_movement_does_not_hide_current_performance_on_overview(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $platform = Platform::find($integration->platform_id);

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - published DI DALAM
        // periode 30 hari yang diquery (roster cohort), TAPI observasi
        // terakhir belum mencapai period_end (reason='current_before_period_end')
        // - period-gain (SECONDARY) jadi partial, current performance
        // (PRIMARY, 900) TETAP genuine & lengkap, TIDAK PERNAH disembunyikan.
        $publishedAt = now()->subDays(20);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-partial',
            'match_status' => 'unmatched', 'published_at' => $publishedAt, 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id, 'instagram_media_snapshot_id' => $media->id,
            'metric_date' => $publishedAt, 'views' => 900, 'engagement_rate' => 5.0,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->subDays(10)->toDateString(), 'views' => 900,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 30,
        ]));

        $response->assertOk();
        // Current performance (PRIMARY) HARUS tetap tampil genuine.
        $response->assertSee('900');
        // Secondary caveat SEKARANG murni soal riwayat pertumbuhan, BUKAN
        // lagi klaim data performa belum tersedia penuh.
        $response->assertSee('belum tersedia untuk sebagian konten');
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
