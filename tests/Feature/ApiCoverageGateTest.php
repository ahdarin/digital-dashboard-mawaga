<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\InstagramMediaSnapshot;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\AnalyticsPeriodResolver;
use App\Services\InstagramAnalyticsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FINAL API COVERAGE + REAL METRIC ACCEPTANCE GATE.
 *
 * Part 3 ("implement useful gaps") - account_type/media_count SUDAH SELALU
 * ada di response Instagram getProfile() (fields=...account_type,media_
 * count...) sejak Pass 1B, tapi dulu dibuang - nol biaya API tambahan buat
 * mulai mempersistnya (BUKAN metric baru yang butuh scope/request baru).
 *
 * Part 8/9 (real-data X vs Y acceptance) - reproduksi persis item real yang
 * memicu seluruh pekerjaan ini (client "Metro Software Indonesia", media
 * Reels id=3, current total 18.573 vs gain periode kecil) via fixture yang
 * SAMA bentuknya (PHPUnit tidak bisa akses DB dev asli lintas test-run,
 * RefreshDatabase mengosongkan DB tiap test - jadi dibuktikan lewat
 * fixture identik strukturnya, BUKAN klaim mengakses row asli itu lagi).
 * Trace row asli itu sendiri sudah dilakukan live lewat tinker (lihat
 * laporan akhir).
 */
class ApiCoverageGateTest extends TestCase
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
        $permissions = collect(['analytics,view', 'settings,view', 'client,view'])->map(function ($pair) {
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

    // ===== Part 3: account_type / media_count now persisted =====

    public function test_account_type_and_media_count_persist_from_sync_profile_response(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $user = User::factory()->create(['status' => 'active']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $syncLog = \App\Models\AnalyticsSyncLog::create([
            'client_id' => $integration->client_id, 'platform_id' => $platform->id,
            'api_integration_id' => $integration->id, 'imported_by' => $user->id,
            'source_type' => 'api_sync', 'status' => 'pending', 'sync_mode' => 'default',
            'range_from' => now()->subDays(60)->toDateString(), 'range_to' => now()->toDateString(),
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/me/media')) {
                return Http::response(['data' => [], 'paging' => []], 200);
            }
            if (str_contains($request->url(), '/me')) {
                return Http::response([
                    'id' => 'ig-account-1', 'username' => 'creator', 'name' => 'Creator Display Name',
                    'account_type' => 'BUSINESS', 'media_count' => 42,
                    'profile_picture_url' => 'https://example.com/pic.jpg',
                ], 200);
            }

            return Http::response([], 404);
        });

        app(InstagramAnalyticsSyncService::class)->sync($integration, $syncLog, now()->subDays(60), now(), $user->id);

        $integration->refresh();
        $this->assertSame('BUSINESS', $integration->external_account_type);
        $this->assertSame(42, $integration->external_media_count);
    }

    // ===== INSTAGRAM PROVIDER CAPABILITY RECONCILIATION GATE - Reels
    // avg watch-time end-to-end persistence via the real sync flow =====

    public function test_reels_avg_watch_time_persists_end_to_end_via_full_sync(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $user = User::factory()->create(['status' => 'active']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $syncLog = \App\Models\AnalyticsSyncLog::create([
            'client_id' => $integration->client_id, 'platform_id' => $platform->id,
            'api_integration_id' => $integration->id, 'imported_by' => $user->id,
            'source_type' => 'api_sync', 'status' => 'pending', 'sync_mode' => 'default',
            'range_from' => now()->subDays(60)->toDateString(), 'range_to' => now()->toDateString(),
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, '/me/media')) {
                return Http::response(['data' => [[
                    'id' => 'reel-ext-1', 'caption' => 'Test reel', 'media_type' => 'VIDEO',
                    'media_product_type' => 'REELS', 'permalink' => 'https://instagram.com/p/x',
                    'timestamp' => now()->subDays(5)->toIso8601String(), 'username' => 'creator',
                ]], 'paging' => []], 200);
            }
            if (str_contains($url, 'ig_reels_avg_watch_time')) {
                return Http::response(['data' => [
                    ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 8200]]],
                ]], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 300]]],
                    ['name' => 'likes', 'values' => [['value' => 20]]],
                    ['name' => 'comments', 'values' => [['value' => 2]]],
                    ['name' => 'shares', 'values' => [['value' => 1]]],
                    ['name' => 'saved', 'values' => [['value' => 4]]],
                    ['name' => 'total_interactions', 'values' => [['value' => 27]]],
                    ['name' => 'views', 'values' => [['value' => 600]]],
                ]], 200);
            }
            if (str_contains($url, '/me')) {
                return Http::response(['id' => 'ig-account-1', 'username' => 'creator', 'account_type' => 'CREATOR', 'media_count' => 1], 200);
            }

            return Http::response([], 404);
        });

        app(InstagramAnalyticsSyncService::class)->sync($integration, $syncLog, now()->subDays(60), now(), $user->id);

        $snapshot = InstagramMediaSnapshot::where('external_post_id', 'reel-ext-1')->first();
        $this->assertNotNull($snapshot);
        $metric = ContentMetric::where('instagram_media_snapshot_id', $snapshot->id)->first();

        // 8200ms -> 8s (dibulatkan) - kolom SAMA yang sudah dipakai TikTok
        // (satu semantik, bukan overload beda makna - Part 7).
        $this->assertSame(8, $metric->watch_time_avg);
        // Core metrics genuinely tersimpan lengkap, sama sekali tidak
        // terpengaruh oleh request metric opsional yang terpisah.
        $this->assertSame(600, $metric->views);
        $this->assertSame(20, $metric->likes);
        $this->assertSame(1, $metric->shares);
        $this->assertSame(4, $metric->saves);

        $snap = ContentMetricSnapshot::where('instagram_media_snapshot_id', $snapshot->id)->latest('snapshot_date')->first();
        $this->assertNotNull($snap);
        $this->assertSame(8, $snap->watch_time_avg);

        // Part 8 (schema semantics) - watch_time_avg BUKAN bagian delta
        // periode (rata-rata, bukan kumulatif) - PeriodPerformanceService
        // TIDAK PERNAH menghitung deltanya, TETAP hanya views/likes/
        // comments/shares/saves/reach/impressions.
        $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();
        $agg = app(\App\Services\PeriodPerformanceService::class)->computeClientPeriod(
            $client->id, $currentMonth->dateFrom->copy()->subDays(30), $currentMonth->effectiveDateTo, $platform->id
        );
        $row = collect($agg['rows'])->first();
        if ($row) {
            $this->assertArrayNotHasKey('watch_time_avg', $row['result']->delta);
        }
    }

    public function test_content_detail_shows_reels_watch_time_via_existing_video_metrics_section(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);

        $plan = \App\Models\ContentPlan::create([
            'client_id' => $client->id, 'created_by' => $manager->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $item = \App\Models\ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'content_type_id' => \App\Models\ContentType::firstOrCreate(['name' => 'Video'])->id,
            'title' => 'Reel Detail '.uniqid(), 'deadline_at' => now()->subDay(),
        ]);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'matched', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
            'published_at' => now()->subDays(10), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'content_item_id' => $item->id, 'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
            'metric_date' => now()->subDays(10), 'views' => 500, 'engagement_rate' => 3.0,
            'watch_time_avg' => 11,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics.show', $item->id));

        $response->assertOk();
        // Section "Rata-rata Watch Time" SUDAH ADA lebih dulu (dipakai
        // TikTok) - Instagram Reels SEKARANG ikut memanfaatkannya secara
        // otomatis (kolom sama, tidak butuh perubahan Blade) begitu
        // watch_time_avg terisi.
        $response->assertSee('Rata-rata Watch Time');
        $response->assertSee('11s');
    }

    // ===== FINAL INSTAGRAM OPTIONAL INSIGHTS COMPLETENESS GATE - 4
    // metric baru (watch_time_total, skip_rate, profile_activity,
    // attributed_follows) + profile_visits->profile_visit, semua
    // end-to-end lewat sync flow asli =====

    public function test_all_new_optional_metrics_persist_end_to_end_and_do_not_affect_period_engine(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $user = User::factory()->create(['status' => 'active']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $syncLog = \App\Models\AnalyticsSyncLog::create([
            'client_id' => $integration->client_id, 'platform_id' => $platform->id,
            'api_integration_id' => $integration->id, 'imported_by' => $user->id,
            'source_type' => 'api_sync', 'status' => 'pending', 'sync_mode' => 'default',
            'range_from' => now()->subDays(60)->toDateString(), 'range_to' => now()->toDateString(),
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, '/me/media')) {
                return Http::response(['data' => [
                    ['id' => 'reel-cov-1', 'caption' => 'Reel', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
                        'permalink' => 'https://instagram.com/p/reel', 'timestamp' => now()->subDays(3)->toIso8601String(), 'username' => 'creator'],
                    ['id' => 'feed-cov-1', 'caption' => 'Feed', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
                        'permalink' => 'https://instagram.com/p/feed', 'timestamp' => now()->subDays(4)->toIso8601String(), 'username' => 'creator'],
                ], 'paging' => []], 200);
            }
            if (str_contains($url, 'ig_reels_avg_watch_time')) {
                return Http::response(['data' => [
                    ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 7000]]],
                    ['name' => 'ig_reels_video_view_total_time', 'values' => [['value' => 350000]]],
                    ['name' => 'reels_skip_rate', 'values' => [['value' => 8.75]]],
                ]], 200);
            }
            if (str_contains($url, 'profile_visits')) {
                // Bentuk values[0].value (bukan breakdown) - diverifikasi
                // LANGSUNG terhadap integration real (lihat laporan akhir).
                return Http::response(['data' => [
                    ['name' => 'profile_visits', 'values' => [['value' => 22]]],
                    ['name' => 'follows', 'values' => [['value' => 3]]],
                    ['name' => 'profile_activity', 'values' => [['value' => 2]]],
                ]], 200);
            }
            if (str_contains($url, 'reel-cov-1/insights') || (str_contains($url, '/insights') && str_contains($url, 'reach'))) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 400]]],
                    ['name' => 'likes', 'values' => [['value' => 30]]],
                    ['name' => 'comments', 'values' => [['value' => 3]]],
                    ['name' => 'shares', 'values' => [['value' => 2]]],
                    ['name' => 'saved', 'values' => [['value' => 6]]],
                    ['name' => 'total_interactions', 'values' => [['value' => 41]]],
                    ['name' => 'views', 'values' => [['value' => 700]]],
                ]], 200);
            }
            if (str_contains($url, '/me')) {
                return Http::response(['id' => 'ig-account-1', 'username' => 'creator', 'account_type' => 'CREATOR', 'media_count' => 2], 200);
            }

            return Http::response([], 404);
        });

        app(InstagramAnalyticsSyncService::class)->sync($integration, $syncLog, now()->subDays(60), now(), $user->id);

        $reel = InstagramMediaSnapshot::where('external_post_id', 'reel-cov-1')->first();
        $reelMetric = ContentMetric::where('instagram_media_snapshot_id', $reel->id)->first();
        $this->assertSame(7, $reelMetric->watch_time_avg);
        $this->assertSame(350, $reelMetric->watch_time_total);
        $this->assertEquals(8.75, (float) $reelMetric->skip_rate);
        // Reel TIDAK punya metric FEED (profile_visits/profile_activity/
        // attributed_follows) - null, bukan 0.
        $this->assertNull($reelMetric->profile_visit);
        $this->assertNull($reelMetric->profile_activity);
        $this->assertNull($reelMetric->attributed_follows);

        $feed = InstagramMediaSnapshot::where('external_post_id', 'feed-cov-1')->first();
        $feedMetric = ContentMetric::where('instagram_media_snapshot_id', $feed->id)->first();
        $this->assertSame(22, $feedMetric->profile_visit);
        $this->assertSame(3, $feedMetric->attributed_follows);
        $this->assertSame(2, $feedMetric->profile_activity);
        // FEED TIDAK punya metric Reels - null, bukan 0.
        $this->assertNull($feedMetric->watch_time_avg);
        $this->assertNull($feedMetric->watch_time_total);
        $this->assertNull($feedMetric->skip_rate);

        // Part 5/8 (schema semantics) - PeriodPerformanceService TIDAK
        // BERUBAH, field baru TIDAK PERNAH masuk delta computation.
        $agg = app(\App\Services\PeriodPerformanceService::class)->computeClientPeriod(
            $client->id, now()->subDays(60), now(), $platform->id
        );
        foreach (collect($agg['rows']) as $row) {
            foreach (['watch_time_avg', 'watch_time_total', 'skip_rate', 'profile_visit', 'profile_activity', 'attributed_follows'] as $newField) {
                $this->assertArrayNotHasKey($newField, $row['result']->delta, "{$newField} TIDAK BOLEH masuk delta periode.");
            }
        }
    }

    public function test_ai_strategy_ignores_new_optional_metrics_entirely(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);
        $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
            'published_at' => now()->subDays(20), 'last_fetched_at' => now(),
        ]);
        // watch_time_avg/skip_rate diisi TAPI TIDAK di-request eksplisit
        // oleh manapun - AI HARUS tetap jalan normal, tidak crash, tidak
        // memperlakukan field yang tidak ada di array sebagai 0.
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
            'metric_date' => now()->subDays(20), 'views' => 500, 'engagement_rate' => 2.5,
            'watch_time_avg' => 15, 'watch_time_total' => 900, 'skip_rate' => 5.0,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $currentMonth->dateFrom->copy()->subDay()->toDateString(), 'views' => 400,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $currentMonth->effectiveDateTo->toDateString(), 'views' => 500,
        ]);

        $summary = app(\App\Services\AiStrategyService::class)->buildPerformanceSummary($client, now()->format('Y-m'), $integration->platform_id);

        // Tidak crash, top_5_content genuinely berisi baris ini (delta
        // views = 100) - field opsional baru TIDAK muncul sama sekali di
        // struktur ini (AI tidak pernah diberi/menafsirkan field yang
        // tidak semantically dipakainya).
        $row = collect($summary['top_5_content'])->first();
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('watch_time_avg', $row);
        $this->assertArrayNotHasKey('skip_rate', $row);
    }

    public function test_account_type_label_renders_in_settings_and_client_detail(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $integration->update(['external_account_type' => 'MEDIA_CREATOR']);

        // Settings.
        $settingsResponse = $this->actingAs($manager)->get(route('settings', ['tab' => 'integrasi', 'client_id' => $client->id]));
        $settingsResponse->assertOk();
        $settingsResponse->assertSee('Akun Creator');

        // Client Detail - SAMA wording (satu kosakata, Part 6).
        $clientDetailResponse = $this->actingAs($manager)->get(route('client-management.show', $client));
        $clientDetailResponse->assertOk();
        $clientDetailResponse->assertSee('Akun Creator');
    }

    public function test_account_type_never_guessed_when_absent(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        // external_account_type TIDAK diisi (null) - label tidak boleh
        // muncul sama sekali, TIDAK ditebak.

        $response = $this->actingAs($manager)->get(route('settings', ['tab' => 'integrasi', 'client_id' => $client->id]));

        $response->assertOk();
        $response->assertDontSee('Akun Business');
        $response->assertDontSee('Akun Creator');
    }

    // ===== Part 8/9: real-data-shaped X (current) vs Y (period gain) acceptance =====

    public function test_real_shaped_reel_shows_correct_current_total_and_small_period_gain(): void
    {
        // Reproduksi PERSIS struktur item real yang memicu pekerjaan ini:
        // Reels lama (published jauh sebelum periode), riwayat snapshot
        // baru mulai belakangan (baseline=current, gain kecil), TAPI total
        // provider (X) besar. X=18573, Y=0 - angka SAMA PERSIS dengan yang
        // ditemukan lewat trace data real (lihat laporan akhir).
        Carbon::setTestNow(Carbon::parse('2026-09-03'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => '18612460072009523',
                'match_status' => 'unmatched', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
                'published_at' => Carbon::parse('2026-07-26'), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => '2026-07-26', 'views' => 18573, 'engagement_rate' => 4.2,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => '2026-09-02', 'views' => 18573,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
                'snapshot_date' => '2026-09-03', 'views' => 18573,
            ]);

            $agg = app(\App\Services\PeriodPerformanceService::class)->computeClientPeriod(
                $client->id, $currentMonth->dateFrom, $currentMonth->effectiveDateTo, $platform->id
            );
            $row = collect($agg['rows'])->first();

            // X (current total, ContentMetric.views) - HARUS 18,573.
            $this->assertSame(18573, $row['content_metric']->views);
            // Y (period gain) - HARUS 0 (dua observasi berdekatan identik),
            // BUKAN 18573 dan BUKAN dihilangkan.
            $this->assertSame(0, $row['result']->views());
            $this->assertNotSame($row['content_metric']->views, $row['result']->views());

            // UI: table primary = X, secondary = Y berlabel "periode ini".
            $response = $this->actingAs($manager)->get(route('analytics', ['tab' => 'table', 'client_id' => $client->id]));
            $response->assertOk();
            $response->assertSee('18,573');
            $response->assertSee('+0 periode ini');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_null_current_total_stays_null_not_zero_when_never_synced(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);

        // ContentMetric TANPA snapshot sama sekali - unavailable, TIDAK
        // masuk baris usable (tidak ada X maupun Y yang bisa
        // dipertanggungjawabkan).
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched', 'media_type' => 'IMAGE',
            'published_at' => now()->subDay(), 'last_fetched_at' => now(),
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
        $this->assertNull($row['result']->views());
    }
}
