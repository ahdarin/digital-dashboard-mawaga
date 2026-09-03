<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Services\InstagramAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INSTAGRAM PROVIDER CAPABILITY RECONCILIATION - FINAL GATE. Part 4
 * ("core vs optional") - ig_reels_avg_watch_time diverifikasi LANGSUNG
 * lewat WebFetch terhadap referensi resmi Meta (developers.facebook.com/
 * docs/instagram-platform/reference/instagram-media/insights/, v25.0,
 * 2026-09-03): Active, media type REELS, scope
 * instagram_business_manage_insights (scope yang SUDAH dipakai app ini).
 * Request-nya TERPISAH dari PREFERRED_METRICS/SAFE_METRICS ("core") -
 * file ini membuktikan kegagalan metric opsional ini TIDAK PERNAH
 * menjatuhkan core metrics yang sudah berhasil.
 */
class InstagramReelsOptionalMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): InstagramAnalyticsService
    {
        return new InstagramAnalyticsService(new ApiIntegration(['access_token' => 'fake-token']));
    }

    public function test_reels_avg_watch_time_persists_converted_to_seconds(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'ig_reels_avg_watch_time')) {
                // Meta balikin milidetik - 12500ms = 12.5s, dibulatkan 13.
                return Http::response(['data' => [
                    ['name' => 'ig_reels_avg_watch_time', 'title' => 'Average watch time', 'values' => [['value' => 12500]]],
                ]], 200);
            }

            // Core metrics (PREFERRED_METRICS['REELS']) - genuinely berhasil,
            // TIDAK BOLEH terpengaruh oleh apapun yang terjadi di request
            // opsional di atas.
            return Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 500]]],
                ['name' => 'likes', 'values' => [['value' => 40]]],
                ['name' => 'comments', 'values' => [['value' => 5]]],
                ['name' => 'shares', 'values' => [['value' => 3]]],
                ['name' => 'saved', 'values' => [['value' => 8]]],
                ['name' => 'total_interactions', 'values' => [['value' => 56]]],
                ['name' => 'views', 'values' => [['value' => 900]]],
            ]], 200);
        });

        $result = $this->service()->getMediaInsights('reel-media-1', 'REELS');

        $this->assertNull($result['error']);
        $this->assertSame(13, $result['metrics']['watch_time_avg']);
        // Core metrics UTUH, sama sekali tidak terpengaruh metric opsional.
        $this->assertSame(500, $result['metrics']['reach']);
        $this->assertSame(40, $result['metrics']['likes']);
        $this->assertSame(3, $result['metrics']['shares']);
        $this->assertSame(8, $result['metrics']['saves']);
        $this->assertSame(900, $result['metrics']['views']);
    }

    public function test_optional_reels_metric_failure_does_not_affect_core_metrics(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'ig_reels_avg_watch_time')) {
                // Metric opsional GAGAL total (mis. belum tersedia buat
                // Reel ini) - core metrics TETAP harus lengkap.
                return Http::response(['error' => ['message' => 'Unsupported metric for this media', 'code' => 100]], 400);
            }

            return Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 700]]],
                ['name' => 'likes', 'values' => [['value' => 60]]],
                ['name' => 'comments', 'values' => [['value' => 9]]],
                ['name' => 'shares', 'values' => [['value' => 4]]],
                ['name' => 'saved', 'values' => [['value' => 11]]],
                ['name' => 'total_interactions', 'values' => [['value' => 84]]],
                ['name' => 'views', 'values' => [['value' => 1200]]],
            ]], 200);
        });

        $result = $this->service()->getMediaInsights('reel-media-2', 'REELS');

        // Metric opsional null (bukan 0, bukan exception) - core metrics
        // TETAP genuinely lengkap & sukses.
        $this->assertNull($result['error']);
        $this->assertNull($result['metrics']['watch_time_avg']);
        $this->assertSame(700, $result['metrics']['reach']);
        $this->assertSame(60, $result['metrics']['likes']);
        $this->assertSame(4, $result['metrics']['shares']);
        $this->assertSame(11, $result['metrics']['saves']);
        $this->assertSame(1200, $result['metrics']['views']);
    }

    public function test_watch_time_not_requested_for_non_reels_media(): void
    {
        $requestedUrls = [];
        Http::fake(function (Request $request) use (&$requestedUrls) {
            $requestedUrls[] = $request->url();

            return Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 100]]],
                ['name' => 'likes', 'values' => [['value' => 5]]],
                ['name' => 'comments', 'values' => [['value' => 1]]],
            ]], 200);
        });

        $result = $this->service()->getMediaInsights('image-media-1', 'FEED');

        $this->assertNull($result['error']);
        // Key watch_time_avg TIDAK ADA sama sekali di hasil (bukan cuma
        // null) - normalizeMetrics() FEED/IMAGE/CAROUSEL_ALBUM tidak
        // pernah menyertakannya.
        $this->assertArrayNotHasKey('watch_time_avg', $result['metrics']);
        // Request opsional TIDAK PERNAH dikirim buat media non-Reels -
        // nol biaya API tambahan buat Image/Carousel.
        foreach ($requestedUrls as $url) {
            $this->assertStringNotContainsString('ig_reels_avg_watch_time', $url);
        }
    }

    public function test_optional_metric_request_uses_pinned_api_version(): void
    {
        $requestedUrls = [];
        Http::fake(function (Request $request) use (&$requestedUrls) {
            $requestedUrls[] = $request->url();

            return Http::response(['data' => [
                ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 5000]]],
            ]], 200);
        });

        $this->service()->getMediaInsights('reel-media-3', 'REELS');

        $pinnedVersion = config('services.instagram.api_version');
        $this->assertNotEmpty($pinnedVersion, 'INSTAGRAM_API_VERSION harus dipin (Part 3 gate ini) - bukan kosong.');
        $matched = false;
        foreach ($requestedUrls as $url) {
            if (str_contains($url, "/{$pinnedVersion}/")) {
                $matched = true;
            }
        }
        $this->assertTrue($matched, 'Request insights harus memakai versi API yang dipin, bukan endpoint unversioned.');
    }

    // ===== FINAL INSTAGRAM OPTIONAL INSIGHTS COMPLETENESS GATE =====

    public function test_all_three_reels_optional_metrics_batched_in_one_request(): void
    {
        $optionalInsightRequests = 0;
        Http::fake(function (Request $request) use (&$optionalInsightRequests) {
            $url = $request->url();

            if (str_contains($url, 'ig_reels_avg_watch_time')) {
                $optionalInsightRequests++;
                // Ketiganya HARUS ada di SATU query string yang sama -
                // membuktikan genuinely 1 request batched, bukan 3
                // request terpisah yang kebetulan semua mengandung nama
                // metric pertama.
                $this->assertStringContainsString('ig_reels_video_view_total_time', $url);
                $this->assertStringContainsString('reels_skip_rate', $url);

                return Http::response(['data' => [
                    ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 9000]]],
                    ['name' => 'ig_reels_video_view_total_time', 'values' => [['value' => 450000]]],
                    ['name' => 'reels_skip_rate', 'values' => [['value' => 12.5]]],
                ]], 200);
            }

            return Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 200]]],
                ['name' => 'likes', 'values' => [['value' => 15]]],
                ['name' => 'comments', 'values' => [['value' => 3]]],
                ['name' => 'shares', 'values' => [['value' => 2]]],
                ['name' => 'saved', 'values' => [['value' => 5]]],
                ['name' => 'total_interactions', 'values' => [['value' => 25]]],
                ['name' => 'views', 'values' => [['value' => 300]]],
            ]], 200);
        });

        $result = $this->service()->getMediaInsights('reel-media-4', 'REELS');

        $this->assertSame(1, $optionalInsightRequests, 'Ketiga metric opsional Reels HARUS 1 request batched, bukan 1 request per metric.');
        $this->assertSame(9, $result['metrics']['watch_time_avg']); // 9000ms -> 9s
        $this->assertSame(450, $result['metrics']['watch_time_total']); // 450000ms -> 450s
        $this->assertSame(12.5, $result['metrics']['skip_rate']);
    }

    public function test_feed_optional_metrics_batched_and_persisted(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'profile_visits')) {
                $this->assertStringContainsString('profile_activity', $url);
                $this->assertStringContainsString('follows', $url);

                // Bentuk INI (values[0].value, sama seperti profile_visits/
                // follows) diverifikasi LANGSUNG terhadap integration real
                // (lihat laporan akhir) - profile_activity BUKAN breakdown
                // seperti dugaan awal.
                return Http::response(['data' => [
                    ['name' => 'profile_visits', 'values' => [['value' => 40]]],
                    ['name' => 'follows', 'values' => [['value' => 6]]],
                    ['name' => 'profile_activity', 'values' => [['value' => 7]]],
                ]], 200);
            }

            return Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 800]]],
                ['name' => 'likes', 'values' => [['value' => 50]]],
                ['name' => 'comments', 'values' => [['value' => 6]]],
                ['name' => 'shares', 'values' => [['value' => 4]]],
                ['name' => 'saved', 'values' => [['value' => 9]]],
                ['name' => 'total_interactions', 'values' => [['value' => 69]]],
            ]], 200);
        });

        $result = $this->service()->getMediaInsights('feed-media-1', 'FEED');

        $this->assertNull($result['error']);
        $this->assertSame(40, $result['metrics']['profile_visits']);
        $this->assertSame(6, $result['metrics']['attributed_follows']);
        // Angka tunggal apa adanya (bukan breakdown yang dijumlahkan).
        $this->assertSame(7, $result['metrics']['profile_activity']);
        // Core metrics FEED tetap utuh.
        $this->assertSame(800, $result['metrics']['reach']);
        $this->assertSame(50, $result['metrics']['likes']);
    }

    public function test_feed_optional_metrics_not_requested_for_reels(): void
    {
        $requestedUrls = [];
        Http::fake(function (Request $request) use (&$requestedUrls) {
            $requestedUrls[] = $request->url();

            return Http::response(['data' => [
                ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 1000]]],
            ]], 200);
        });

        $this->service()->getMediaInsights('reel-media-5', 'REELS');

        foreach ($requestedUrls as $url) {
            $this->assertStringNotContainsString('profile_visits', $url);
        }
    }

    public function test_optional_metric_failure_never_becomes_zero(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'ig_reels_avg_watch_time') || str_contains($url, 'profile_visits')) {
                return Http::response(['error' => ['message' => 'temporary', 'code' => 1]], 500);
            }

            return Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 10]]],
            ]], 200);
        });

        $reels = $this->service()->getMediaInsights('reel-fail', 'REELS');
        $this->assertNull($reels['metrics']['watch_time_avg']);
        $this->assertNull($reels['metrics']['watch_time_total']);
        $this->assertNull($reels['metrics']['skip_rate']);
        $this->assertNotSame(0, $reels['metrics']['watch_time_avg']);

        $feed = $this->service()->getMediaInsights('feed-fail', 'FEED');
        $this->assertNull($feed['metrics']['profile_visits']);
        $this->assertNull($feed['metrics']['profile_activity']);
        $this->assertNull($feed['metrics']['attributed_follows']);
    }
}
