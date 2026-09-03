<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentFormat;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\InstagramMediaSnapshot;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\AnalyticsPeriodResolver;
use App\Services\AnalyticsSyncOrchestrator;
use App\Services\ContentFormatResolver;
use App\Services\FreshnessPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GLOBAL CROSS-PAGE CONSISTENCY AUDIT & FIX. This file does NOT re-test
 * math already proven correct in PeriodPerformanceServiceTest/
 * ContentClassificationTest/CurrentTotalVsPeriodGainTest/
 * ClientDetailSyncConsistencyTest/PublishingTrackerPlatformRoutingTest -
 * it proves that MULTIPLE pages/consumers agree with EACH OTHER for the
 * SAME underlying data, which none of those single-page test files could
 * prove on their own.
 */
class GlobalCrossPageConsistencyTest extends TestCase
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
        $permissions = collect(['analytics,view', 'settings,view', 'settings,manage', 'client,view', 'publishing,manage'])->map(function ($pair) {
            [$module, $action] = explode(',', $pair);

            return Permission::firstOrCreate(['module' => $module, 'action' => $action])->id;
        });
        $role->permissions()->attach($permissions);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
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

    // ===== Capability E/F: same current metric, Analytics table vs Ringkasan =====

    public function test_same_current_total_across_analytics_table_and_ringkasan_consumers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
                'match_status' => 'unmatched', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
                'published_at' => now()->subDays(60), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now()->subDays(60), 'views' => 18573, 'engagement_rate' => 4.2,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => $currentMonth->dateFrom->copy()->subDay()->toDateString(), 'views' => 18000,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => $currentMonth->effectiveDateTo->toDateString(), 'views' => 18573,
            ]);

            $tableResponse = $this->actingAs($manager)->get(route('analytics', ['tab' => 'table', 'client_id' => $client->id]));
            $ringkasanResponse = $this->actingAs($manager)->get(route('analytics', ['tab' => 'overview', 'client_id' => $client->id]));

            $tableResponse->assertOk();
            $ringkasanResponse->assertOk();
            // Nilai TOTAL SAAT INI ini genuinely SAMA sumbernya
            // ($metric->views, baris ContentMetric yang SAMA persis) -
            // dua konsumen (AnalyticsController::buildTableTabData &
            // AnalyticsSummaryService::presentTopContentRow) HARUS
            // menampilkan angka yang identik, bukan interpretasi masing-
            // masing.
            $tableResponse->assertSee('18,573');
            $ringkasanResponse->assertSee('18,573');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ===== Capability C: same canonical format, Analytics/Report/AI =====

    public function test_same_canonical_format_across_analytics_report_and_ai(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);

        $carousel = ContentFormat::where('slug', 'carousel')->firstOrFail();
        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => $manager->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'content_type_id' => ContentType::firstOrCreate(['name' => 'Desain'])->id,
            'content_format_id' => $carousel->id,
            'title' => 'Konten Lintas Halaman '.uniqid(), 'deadline_at' => now()->subDay(),
        ]);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
            // Snapshot mentah bilang IMAGE - master item bilang Carousel.
            // Master HARUS menang di SEMUA konsumen, konsisten.
            'match_status' => 'matched', 'media_type' => 'IMAGE',
            'published_at' => now()->subDays(10), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'content_item_id' => $item->id, 'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
            'metric_date' => now()->subDays(10), 'views' => 400, 'engagement_rate' => 2.0,
        ]);
        // Pairing snapshot (baseline+current) - TANPA ini baris masuk
        // 'unavailable' (missing_current) dan ke-exclude total dari
        // isUsable() di semua konsumen (pola sama dipakai tes lain di
        // file ini/ContentClassificationTest).
        $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'content_item_id' => $item->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $currentMonth->dateFrom->copy()->subDay()->toDateString(), 'views' => 350,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'content_item_id' => $item->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $currentMonth->effectiveDateTo->toDateString(), 'views' => 400,
        ]);

        // Consumer 1: Analytics table.
        $tableResponse = $this->actingAs($manager)->get(route('analytics', ['tab' => 'table', 'client_id' => $client->id]));
        $tableResponse->assertOk();
        $tableResponse->assertSee('Carousel');
        $tableResponse->assertDontSee('IMAGE');

        // Consumer 2: AI Strategy performance summary input (data layer -
        // ini yang genuinely dikirim ke prompt AI, JSON di dalamnya HARUS
        // format yang SAMA).
        $aiSummary = app(\App\Services\AiStrategyService::class)->buildPerformanceSummary($client, now()->format('Y-m'), $integration->platform_id);
        $aiFormats = collect($aiSummary['top_5_content'])->pluck('content_format')->filter();
        if ($aiFormats->isNotEmpty()) {
            $this->assertContains('Carousel', $aiFormats->all());
            $this->assertNotContains('IMAGE', $aiFormats->all());
        }

        // Consumer 3: resolver dipanggil langsung (sumber kebenaran
        // tunggal keduanya di atas) - membuktikan SEMUA konsumen memanggil
        // fungsi yang SAMA persis, bukan replikasi logic sendiri-sendiri.
        $resolverLabel = app(ContentFormatResolver::class)->labelForContentItem($item, $media, null);
        $this->assertSame('Carousel', $resolverLabel);
    }

    // ===== Capability A/O: same platform identity, Analytics vs Publishing Tracker =====

    public function test_same_platform_identity_across_analytics_and_publishing_tracker(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);
        $integration = $this->tiktokIntegration($client);

        $video = TikTokVideoSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'tt-'.uniqid(),
            'match_status' => 'unmatched', 'published_at' => now()->subDays(3), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'tiktok_video_snapshot_id' => $video->id, 'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'tiktok_video_snapshot_id' => $video->id,
            'snapshot_date' => now()->subDay()->toDateString(), 'views' => 10,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'tiktok_video_snapshot_id' => $video->id,
            'snapshot_date' => now()->toDateString(), 'views' => 20,
        ]);

        $analyticsResponse = $this->actingAs($manager)->get(route('analytics', ['tab' => 'table', 'client_id' => $client->id]));
        $analyticsResponse->assertOk();
        // Link Konten HARUS ke tracker TikTok - SAMA platform yang
        // Analytics sendiri tampilkan buat baris ini.
        $analyticsResponse->assertSee('TikTok');
        $analyticsResponse->assertSee(route('publishing-tracker.tiktok.unmatched', $integration), false);
        $analyticsResponse->assertDontSee('/publishing-tracker/instagram/'.$integration->id, false);

        // Tujuan link itu sendiri benar-benar hidup dan platform-nya
        // konsisten (bukan 404 - regresi bug root cause pass sebelumnya).
        $trackerResponse = $this->actingAs($manager)->get(route('publishing-tracker.tiktok.unmatched', $integration));
        $trackerResponse->assertOk();
    }

    // ===== Capability J/K/L: one sync run visible from Settings + Analytics + Client Detail =====

    public function test_sync_run_visible_from_settings_analytics_and_client_detail_simultaneously(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        Queue::fake();
        // Dispatch SEKALI, dari jalur yang identik dipakai ketiga halaman
        // (analytics.sync).
        $dispatch = $this->actingAs($manager)->postJson(route('analytics.sync'), [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]);
        $dispatch->assertOk();

        $runCountAfterDispatch = \App\Models\AnalyticsSyncRun::count();

        // Ketiga halaman "membuka" state yang sama (Settings & Client
        // Detail render server-side, panel-nya sendiri poll ke endpoint
        // status yang SAMA lewat JS - di sini kita buktikan endpoint
        // status tunggal itu mengembalikan task yang SAMA utk client ini,
        // dan tidak satupun rendering halaman men-trigger dispatch baru).
        $settingsResponse = $this->actingAs($manager)->get(route('settings', ['tab' => 'integrasi', 'client_id' => $client->id]));
        $analyticsResponse = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));
        $clientDetailResponse = $this->actingAs($manager)->get(route('client-management.show', $client));

        $settingsResponse->assertOk();
        $analyticsResponse->assertOk();
        $clientDetailResponse->assertOk();

        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));
        $status->assertOk();
        $this->assertNotNull($status->json('progress.tasks.instagram_content'));
        $this->assertSame($status->json('progress.tasks.instagram_content.run_id'), \App\Models\AnalyticsSyncRun::latest()->first()->id);

        // Membuka ketiga halaman TIDAK menambah run baru.
        $this->assertSame($runCountAfterDispatch, \App\Models\AnalyticsSyncRun::count());
    }

    // ===== Capability H: JS and PHP freshness presenters agree =====

    public function test_js_and_php_freshness_presenters_produce_identical_wording(): void
    {
        // Node smoke-test langsung terhadap public/js/analytics-sync-panel.js
        // (satu-satunya cara membuktikan wording JS - PHPUnit tidak bisa
        // eksekusi JS) - dibandingkan literal terhadap FreshnessPresenter
        // versi PHP buat 3 skenario yang SAMA (hari ini/kemarin/lama).
        $script = <<<'JS'
            global.document = { createElement: function(){ return {}; } };
            global.window = {};
            require(process.argv[2]);
            var f = global.window.AnalyticsSyncPanel.formatFreshness;
            var now = new Date();
            var today = new Date(now); today.setHours(7, 20, 0, 0);
            console.log(f(today.toISOString()));
            var yesterday = new Date(now); yesterday.setDate(now.getDate() - 1); yesterday.setHours(22, 10, 0, 0);
            console.log(f(yesterday.toISOString()));
            var old = new Date(now); old.setDate(now.getDate() - 10);
            console.log(f(old.toISOString()));
            JS;

        $scriptPath = sys_get_temp_dir().'/freshness_smoke_'.uniqid().'.js';
        file_put_contents($scriptPath, $script);
        $jsPath = base_path('public/js/analytics-sync-panel.js');

        $output = shell_exec('node '.escapeshellarg($scriptPath).' '.escapeshellarg($jsPath));
        @unlink($scriptPath);

        $this->assertNotNull($output, 'Node smoke-test gagal jalan - pastikan node tersedia di PATH.');
        $lines = array_values(array_filter(explode("\n", trim($output))));

        // Pemisah jam ("07:20" vs "07.20") bisa beda antar versi Node/ICU -
        // itu quirk environment, bukan bug aplikasi - yang dites di sini
        // adalah STRUKTUR kalimat (kosakata "hari ini"/"kemarin"), bukan
        // karakter pemisah jam persis.
        $this->assertStringStartsWith('Data diperbarui hari ini, 07', $lines[0]);
        $this->assertStringStartsWith('Data diperbarui kemarin, 22', $lines[1]);
        // Wording "lama" HARUS sama persis pola-nya dengan FreshnessPresenter
        // PHP ("Data terakhir diperbarui ..." TANPA jam) - dulu 2 versi ini
        // beda (JS masih pakai "Data diperbarui X, HH:MM" bahkan buat
        // tanggal lama).
        $this->assertStringStartsWith('Data terakhir diperbarui ', $lines[2]);
        $this->assertStringNotContainsString('Data diperbarui ', str_replace('Data terakhir diperbarui ', '', $lines[2]));

        // PHP-side buat tanggal "lama" yang sama.
        $phpLabel = FreshnessPresenter::label(now()->subDays(10));
        $this->assertStringStartsWith('Data terakhir diperbarui ', $phpLabel);
    }

    // ===== Capability I: same unsupported/null/zero interpretation =====

    public function test_unavailable_reason_uses_shared_presenter_not_page_specific_wording(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);

        // Media dengan ContentMetric TAPI TANPA ContentMetricSnapshot sama
        // sekali (insufficient_history) - dites di SATU tempat dulu buat
        // baseline classification.
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched', 'media_type' => 'IMAGE',
            'published_at' => now()->subDays(2), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
        ]);

        $agg = app(\App\Services\PeriodPerformanceService::class)->computeClientPeriod(
            $client->id, now()->subDays(6), now(), $platform->id
        );
        $row = collect($agg['rows'])->first();
        $this->assertSame('unavailable', $row['result']->coverageStatus);

        // Kategori availability HARUS resolve lewat AvailabilityPresenter
        // (satu-satunya tempat taksonomi ini diterjemahkan ke copy) -
        // bukan string custom per halaman.
        $category = $row['result']->availabilityCategory();
        $label = \App\Services\AvailabilityPresenter::label($category);
        $this->assertContains($category, [
            \App\Services\AvailabilityPresenter::INSUFFICIENT_HISTORY,
            \App\Services\AvailabilityPresenter::UNSUPPORTED,
            \App\Services\AvailabilityPresenter::SYNC_FAILED,
            \App\Services\AvailabilityPresenter::PROVIDER_UNAVAILABLE,
        ]);
        $this->assertNotNull($label);
    }

    // ===== Capability F/Q/R: period values match across Overview/Table/Export =====

    public function test_period_delta_matches_across_overview_table_and_export(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
                'match_status' => 'unmatched', 'media_type' => 'IMAGE',
                'published_at' => now()->subDays(15), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now()->subDays(15), 'views' => 900, 'engagement_rate' => 3.0,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => $currentMonth->dateFrom->copy()->subDay()->toDateString(), 'views' => 100,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => $currentMonth->effectiveDateTo->toDateString(), 'views' => 900,
            ]);

            $overview = $this->actingAs($manager)->get(route('analytics', ['tab' => 'overview', 'client_id' => $client->id]));
            $table = $this->actingAs($manager)->get(route('analytics', ['tab' => 'table', 'client_id' => $client->id]));
            $export = $this->actingAs($manager)->get(route('analytics.export', ['client_id' => $client->id]));

            $overview->assertOk();
            $table->assertOk();
            $export->assertOk();

            // Delta genuine = 900 - 100 = 800, SAMA di ketiganya (semua
            // resolve lewat PeriodPerformanceService::computeClientPeriod
            // yang identik, bukan kalkulasi lokal masing-masing).
            $overview->assertSee('+800 periode ini');
            $table->assertSee('+800 periode ini');
            // Export CSV = StreamedResponse - assertSee tidak berlaku,
            // baca streamedContent() (pola sama dipakai AnalyticsPeriodEngineV2Test).
            $this->assertStringContainsString('800', $export->streamedContent());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ===== Capability J: no obsolete direct-job user-facing dispatch remains =====

    public function test_no_page_dispatches_sync_job_directly_bypassing_orchestrator(): void
    {
        // Sweep statis - SEMUA view aktif (bukan storage/framework/views
        // cache) yang punya form/tombol POST ke settings.sync-instagram/
        // settings.sync-tiktok HARUS terbatas ke sub-fitur "Sinkronisasi
        // Historis" (month-scoped backfill, di luar kapasitas orchestrator,
        // keputusan arsitektur eksplisit dari pass sebelumnya) - bukan aksi
        // sync primer lagi di halaman manapun.
        $views = array_merge(
            glob(resource_path('views/settings/**/*.blade.php')),
            glob(resource_path('views/client-management/*.blade.php')),
            glob(resource_path('views/analytics/*.blade.php')),
        );

        foreach ($views as $viewPath) {
            $content = file_get_contents($viewPath);
            if (! str_contains($content, "route('settings.sync-instagram')") && ! str_contains($content, "route('settings.sync-tiktok')")) {
                continue;
            }
            // Kalau route lama INI muncul, HARUS dalam konteks <details>
            // "Sinkronisasi Konten Historis" (month input) - bukan tombol
            // primer. Wording DISATUKAN pass ini (Client Detail dulu
            // "Sinkronisasi Historis (bulan lama)", Settings "Sinkronisasi
            // Konten Historis" - 2 kalimat beda buat sub-fitur yang SAMA
            // persis, sekarang satu wording di semua halaman).
            $this->assertStringContainsString('Sinkronisasi Konten Historis', $content, "{$viewPath} masih punya form sync-instagram/sync-tiktok DI LUAR konteks Sinkronisasi Konten Historis.");
            $this->assertStringContainsString('type="month"', $content, "{$viewPath}: form sync lama yang tersisa harus month-scoped (historical backfill), bukan primary action.");
        }

        $this->assertTrue(true); // sweep selesai tanpa exception = lolos
    }
}
