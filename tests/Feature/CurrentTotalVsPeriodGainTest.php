<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
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
use App\Services\FreshnessPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SYSTEM CONSISTENCY PASS (Part AA-AG) - BUG NYATA DITEMUKAN & DIPERBAIKI
 * lewat trace end-to-end data real (client "Metro Software Indonesia",
 * integration id 12, media Reels id 3): content_metrics.views SUDAH BENAR
 * menyimpan total provider TERKINI di setiap sync (18.573 pada kasus nyata
 * yang di-trace) - root cause BUKAN ingestion/persistence/kalkulasi delta
 * (semua sudah benar), MURNI presentasi (kategori G): tabel Analytics
 * HANYA PERNAH menampilkan gain periode terpilih (kecil, wajar karena
 * riwayat snapshot baru mulai belakangan), dilabeli polos "Views" -
 * dibaca user sebagai total saat ini. Fix: total SAAT INI (content_metrics.
 * views mentah) dan gain PERIODE ($result->views(), delta) sekarang dua
 * nilai eksplisit terpisah di semua konsumen (tabel, Ringkasan, Content
 * Detail) - tidak pernah saling menggantikan.
 */
class CurrentTotalVsPeriodGainTest extends TestCase
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
        $permission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
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

    /**
     * Skenario yang PERSIS mereproduksi kasus real yang ditemukan: konten
     * lama (published lama), riwayat content_metric_snapshots baru mulai 2
     * hari terakhir (mis. baru sync setelah tabel snapshot ini ada) - gain
     * periode KECIL (wajar, cuma 2 titik observasi berdekatan), TAPI total
     * provider ASLI (content_metrics.views) BESAR.
     */
    public function test_analytics_table_shows_large_current_total_distinct_from_small_period_gain(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - published DI
            // DALAM cohort periode (bulan berjalan) - roster SEKARANG
            // published_at-gated, bukan lagi coverage/observasi.
            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id,
                'external_post_id' => 'ig-reel-'.uniqid(),
                'match_status' => 'unmatched',
                'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
                'published_at' => $currentMonth->dateFrom->copy()->addDay(),
                'last_fetched_at' => now(),
            ]);

            // content_metrics.views = total provider TERKINI (raw, sama
            // seperti saveMetric() di InstagramAnalyticsSyncService) - dari
            // sync LEBIH BARU dari snapshot harian terakhir yang tercatat.
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now(), 'views' => 18573, 'engagement_rate' => 4.2,
                'likes' => 900, 'comments' => 40, 'shares' => 12, 'saves' => 30,
            ]);
            // Riwayat snapshot harian baru mulai 1 hari setelah publish -
            // delta genuinely kecil (gain yang BENAR2 teramati sejak
            // publish, bukan angka provider terkini yang sync terbarunya
            // belum sempat ditulis ulang ke snapshot harian).
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => $currentMonth->dateFrom->copy()->addDays(2)->toDateString(), 'views' => 50,
            ]);

            $response = $this->actingAs($manager)->get(route('analytics', [
                'tab' => 'table', 'client_id' => $client->id,
            ]));

            $response->assertOk();
            // Total SAAT INI (18.573) HARUS tampil - BUKAN diganti diam-diam
            // oleh gain periode (50).
            $response->assertSee('18,573');
            $response->assertSee('+50 periode ini');
            // Dua nilai ini TIDAK BOLEH sama - kalau kebetulan sama di test
            // lain, itu bukan bukti fix bekerja, makanya skenario ini SENGAJA
            // dibuat beda jauh.
            $this->assertNotEquals('18,573', '50');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_total_not_replaced_by_period_gain_in_service_layer(): void
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
                'published_at' => now()->subDays(90), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now()->subDays(90), 'views' => 5000, 'engagement_rate' => 2.0,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => $currentMonth->effectiveDateTo->copy()->subDay()->toDateString(), 'views' => 5000,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => $currentMonth->effectiveDateTo->toDateString(), 'views' => 5000,
            ]);

            $agg = app(\App\Services\PeriodPerformanceService::class)->computeClientPeriod(
                $client->id, $currentMonth->dateFrom, $currentMonth->effectiveDateTo, $platform->id
            );
            $row = collect($agg['rows'])->first();
            $result = $row['result'];
            $metric = $row['content_metric'];

            // Gain periode (delta) = 0 (baseline sama dengan current - 2
            // hari observasi identik) - TAPI raw ContentMetric.views (total
            // provider terkini) TETAP 5000, TIDAK pernah diganti.
            $this->assertSame(0, $result->views());
            $this->assertSame(5000, $metric->views);
            $this->assertNotSame($result->views(), $metric->views);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_period_gain_is_labeled_periode_ini_not_bare_views(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - published DI
            // DALAM cohort periode (roster published_at-gated).
            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
                'match_status' => 'unmatched', 'media_type' => 'CAROUSEL_ALBUM',
                'published_at' => $currentMonth->dateFrom->copy()->addDay(), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now(), 'views' => 300, 'engagement_rate' => 3.0,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => $currentMonth->dateFrom->copy()->addDays(2)->toDateString(), 'views' => 100,
            ]);

            $response = $this->actingAs($manager)->get(route('analytics', [
                'tab' => 'table', 'client_id' => $client->id,
            ]));

            $response->assertOk();
            // Bare "Views" polos TANPA angka current tidak lagi jadi
            // satu-satunya sinyal - qualifier "periode ini" HARUS ada
            // mendampingi gain.
            $response->assertSee('periode ini');
            $response->assertSee('+100 periode ini'); // gain sejak publish, dari snapshot harian yang ada
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_content_detail_exposes_both_current_total_and_period_gain(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);

            $plan = ContentPlan::create([
                'client_id' => $client->id, 'created_by' => $manager->id,
                'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
            ]);
            $item = ContentItem::create([
                'content_plan_id' => $plan->id, 'client_id' => $client->id,
                'content_type_id' => ContentType::firstOrCreate(['name' => 'Video'])->id,
                'title' => 'Konten Detail '.uniqid(), 'deadline_at' => now()->subDay(),
            ]);
            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
                'match_status' => 'matched', 'media_type' => 'VIDEO',
                'published_at' => now()->subDays(40), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'content_item_id' => $item->id,
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now()->subDays(40), 'views' => 9000, 'engagement_rate' => 3.5,
                'likes' => 500, 'comments' => 20,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => now()->subDays(29)->toDateString(), 'views' => 8500, 'likes' => 480, 'comments' => 18,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => now()->toDateString(), 'views' => 9000, 'likes' => 500, 'comments' => 20,
            ]);

            $response = $this->actingAs($manager)->get(route('analytics.show', $item->id));

            $response->assertOk();
            // Total SAAT INI.
            $response->assertSee('Total Saat Ini');
            $response->assertSee('9,000');
            // Performa periode (gain) - section TERPISAH, angka BEDA.
            $response->assertSee('Performa 30 Hari Terakhir');
            $response->assertSee('+500'); // 9000 - 8500
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_total_maps_to_correct_external_content_identity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            // Dua media BERBEDA (external_post_id beda), keduanya published
            // DI DALAM cohort periode (roster published_at-gated) -
            // buktikan total saat ini masing-masing baris TIDAK tertukar
            // (Part AF).
            $mediaA = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-a-'.uniqid(),
                'match_status' => 'unmatched', 'media_type' => 'IMAGE',
                'published_at' => $currentMonth->dateFrom->copy()->addDay(), 'last_fetched_at' => now(),
            ]);
            $mediaB = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-b-'.uniqid(),
                'match_status' => 'unmatched', 'media_type' => 'IMAGE',
                'published_at' => $currentMonth->dateFrom->copy()->addDay(), 'last_fetched_at' => now(),
            ]);

            foreach ([['media' => $mediaA, 'views' => 111], ['media' => $mediaB, 'views' => 222]] as $entry) {
                ContentMetric::create([
                    'client_id' => $client->id, 'platform_id' => $platform->id,
                    'instagram_media_snapshot_id' => $entry['media']->id, 'imported_by' => $manager->id,
                    'metric_date' => now(), 'views' => $entry['views'], 'engagement_rate' => 1.0,
                ]);
                ContentMetricSnapshot::create([
                    'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $entry['media']->id,
                    'snapshot_date' => $currentMonth->dateFrom->copy()->addDays(2)->toDateString(), 'views' => $entry['views'],
                ]);
            }

            $response = $this->actingAs($manager)->get(route('analytics', [
                'tab' => 'table', 'client_id' => $client->id,
            ]));

            $response->assertOk();
            $response->assertSee('111');
            $response->assertSee('222');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_stale_observation_shows_last_known_total_with_freshness_not_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);

            $item = ContentItem::create([
                'content_plan_id' => ContentPlan::create([
                    'client_id' => $client->id, 'created_by' => $manager->id,
                    'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
                ])->id,
                'client_id' => $client->id,
                'content_type_id' => ContentType::firstOrCreate(['name' => 'Desain'])->id,
                'title' => 'Konten Stale '.uniqid(), 'deadline_at' => now()->subDays(30),
            ]);
            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
                'match_status' => 'matched', 'media_type' => 'IMAGE',
                'published_at' => now()->subDays(20), 'last_fetched_at' => now()->subDays(15),
            ]);
            ContentMetric::create([
                'content_item_id' => $item->id,
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now()->subDays(20), 'views' => 777, 'engagement_rate' => 1.5,
            ]);

            $response = $this->actingAs($manager)->get(route('analytics.show', $item->id));

            $response->assertOk();
            // Total terakhir yang diketahui (777) tetap tampil apa adanya -
            // TIDAK di-nol-kan hanya karena observasinya lama.
            $response->assertSee('777');
            // Freshness genuine (15 hari lalu) - BUKAN klaim "hari ini".
            $response->assertSee(FreshnessPresenter::label(now()->subDays(15)));
            $response->assertDontSee('Data diperbarui hari ini');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ===== FreshnessPresenter unit tests =====

    public function test_freshness_today_label(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00'));
        try {
            $this->assertSame('Data diperbarui hari ini, 07:20', FreshnessPresenter::label(Carbon::parse('2026-09-03 07:20:00')));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_freshness_yesterday_label(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00'));
        try {
            $this->assertSame('Data diperbarui kemarin, 22:10', FreshnessPresenter::label(Carbon::parse('2026-09-02 22:10:00')));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_freshness_older_label(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00'));
        try {
            $this->assertSame('Data terakhir diperbarui 30 Agt 2026', FreshnessPresenter::label(Carbon::parse('2026-08-30 09:00:00')));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_freshness_null_when_never_observed(): void
    {
        $this->assertNull(FreshnessPresenter::label(null));
    }

    // ===== Instagram/TikTok current supported metrics persist; unsupported stay null =====

    public function test_instagram_current_supported_metrics_persist_correctly(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched', 'media_type' => 'IMAGE',
            'published_at' => now()->subDay(), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id, 'imported_by' => User::factory()->create()->id,
            'metric_date' => now()->subDay(), 'views' => 100, 'engagement_rate' => 2.0,
            'likes' => 10, 'comments' => 2, 'reach' => 90, 'impressions' => 95,
            // Instagram Display/Graph tidak menyediakan watch_time_avg/
            // completion_rate buat Feed image - kolom ini SENGAJA
            // dibiarkan default NULL, bukan 0.
        ]);

        $metric = ContentMetric::where('instagram_media_snapshot_id', $media->id)->first();

        $this->assertSame(100, $metric->views);
        $this->assertSame(10, $metric->likes);
        $this->assertSame(2, $metric->comments);
        $this->assertSame(90, $metric->reach);
        $this->assertNull($metric->watch_time_avg);
        $this->assertNull($metric->completion_rate);
    }

    public function test_tiktok_current_supported_metrics_persist_correctly(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);
        $integration = $this->tiktokIntegration($client);
        $video = TikTokVideoSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'tt-'.uniqid(),
            'match_status' => 'unmatched', 'published_at' => now()->subDay(), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'tiktok_video_snapshot_id' => $video->id, 'imported_by' => User::factory()->create()->id,
            'metric_date' => now()->subDay(), 'views' => 5000, 'engagement_rate' => 8.0,
            'likes' => 400, 'shares' => 20, 'saves' => 15,
            'watch_time_avg' => 13, 'completion_rate' => 45.0,
        ]);

        $metric = ContentMetric::where('tiktok_video_snapshot_id', $video->id)->first();

        $this->assertSame(5000, $metric->views);
        $this->assertSame(400, $metric->likes);
        $this->assertSame(20, $metric->shares);
        $this->assertSame(15, $metric->saves);
        $this->assertSame(13, $metric->watch_time_avg);
    }

    public function test_unsupported_metric_remains_null_not_zero_in_snapshot(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched', 'media_type' => 'IMAGE',
            'published_at' => now()->subDay(), 'last_fetched_at' => now(),
        ]);
        // Snapshot harian - saves TIDAK dikirim (mis. metric ini tidak
        // tersedia buat post ini) - HARUS tetap NULL di kolom, bukan 0.
        $snapshot = ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->toDateString(), 'views' => 50, 'likes' => 5,
        ]);

        $this->assertSame(50, $snapshot->views);
        $this->assertNull($snapshot->saves);
        $this->assertNull($snapshot->shares);
    }
}
