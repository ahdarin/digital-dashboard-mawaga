<?php

namespace Tests\Feature;

use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\TikTokAnalyticsService;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regresi untuk bug production: pagination video/list TikTok berhenti di
 * video PERTAMA karena cutoff early-stop salah pakai $rangeTo (upper bound,
 * biasanya "hari ini") alih-alih $rangeFrom (lower bound). Akibatnya
 * video_count selalu 0 untuk sync default, dan sync tetap dianggap 'success'.
 *
 * Fix ada di 3 tempat independen yang SEMUA harus benar (masing-masing
 * dicek terpisah di sini):
 * - SyncTikTokAnalyticsJob::handle() (jalur dispatch tombol UI)
 * - SyncTikTokAnalytics (Artisan command, jalur --client manual)
 * - TikTokAnalyticsService::getVideoList() (logic cutoff itu sendiri)
 */
class TikTokAnalyticsSyncDateWindowTest extends TestCase
{
    use RefreshDatabase;

    private function integration(): ApiIntegration
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $client = Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);
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

    private function videoFixture(string $id, Carbon $createdAt): array
    {
        return [
            'id' => $id,
            'create_time' => $createdAt->timestamp,
            'title' => "Video {$id}",
            'video_description' => "Deskripsi {$id}",
            'duration' => 30,
            'cover_image_url' => "https://example.com/{$id}.jpg",
            'share_url' => "https://tiktok.com/@creator/video/{$id}",
            'view_count' => 100,
            'like_count' => 10,
            'comment_count' => 2,
            'share_count' => 1,
        ];
    }

    private function fakeTikTokApi(array $videos, bool $hasMore = false): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => ['user' => ['open_id' => 'open123', 'username' => 'creator']],
            ], 200),
            'open.tiktokapis.com/v2/video/list/*' => Http::response([
                'data' => ['videos' => $videos, 'has_more' => $hasMore, 'cursor' => null],
            ], 200),
        ]);
    }

    // ===== A/B: default window (2 bulan) tidak boleh berhenti di video pertama =====

    public function test_default_sync_includes_recent_video_that_is_older_than_range_to(): void
    {
        // Reproduksi PERSIS bug asli: 1 video dari kemarin. Dengan bug lama
        // (cutoff = rangeTo = "hari ini"), create_time video ini SELALU
        // < rangeTo->timestamp -> stop di video pertama -> video_count=0.
        $integration = $this->integration();
        $yesterday = now()->subDay();
        $this->fakeTikTokApi([$this->videoFixture('vid-yesterday', $yesterday)]);

        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow(null);

        $job = new SyncTikTokAnalyticsJob($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $this->userId());
        $job->handle(app(TikTokAnalyticsSyncService::class));

        $this->assertSame(1, TikTokVideoSnapshot::where('api_integration_id', $integration->id)->count());
        $log = AnalyticsSyncLog::where('api_integration_id', $integration->id)->latest()->first();
        $this->assertSame('success', $log->status);
        $this->assertSame(1, $log->synced_count);
    }

    public function test_default_sync_keeps_videos_within_default_lookback_and_stops_at_older_video(): void
    {
        $integration = $this->integration();

        // Newest-first, sama urutan yang dikembalikan TikTok asli. Video
        // "lama" sengaja JAUH di luar default lookback (bukan cuma sedikit
        // lewat) - default sekarang exact N hari (config
        // analytics.tiktok_default_sync_days), jangan pakai offset yang
        // deket batas persis (mis. "3 bulan" ~= 90-92 hari, bisa flaky
        // begitu default lookback jadi exact 90 hari).
        $lookbackDays = config('analytics.tiktok_default_sync_days');
        $videos = [
            $this->videoFixture('vid-5d', now()->subDays(5)),
            $this->videoFixture('vid-20d', now()->subDays(20)),
            $this->videoFixture('vid-old', now()->subDays($lookbackDays + 30)),
        ];
        $this->fakeTikTokApi($videos);

        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow(null);

        $job = new SyncTikTokAnalyticsJob($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $this->userId());
        $job->handle(app(TikTokAnalyticsSyncService::class));

        $snapshots = TikTokVideoSnapshot::where('api_integration_id', $integration->id)->pluck('external_post_id');
        $this->assertEqualsCanonicalizing(['vid-5d', 'vid-20d'], $snapshots->all());
        $this->assertFalse($snapshots->contains('vid-old'));
    }

    // ===== C: historical month - hanya lower boundary yang jadi cutoff =====

    public function test_historical_month_stops_at_lower_boundary_but_does_not_filter_upper_boundary(): void
    {
        $integration = $this->integration();

        $videos = [
            $this->videoFixture('vid-august', Carbon::parse('2026-08-10')),
            $this->videoFixture('vid-july', Carbon::parse('2026-07-15')),
            $this->videoFixture('vid-june', Carbon::parse('2026-06-15')),
        ];
        $this->fakeTikTokApi($videos);

        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow('2026-07');
        $this->assertSame('historical', $syncMode);

        $job = new SyncTikTokAnalyticsJob($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $this->userId());
        $job->handle(app(TikTokAnalyticsSyncService::class));

        $snapshots = TikTokVideoSnapshot::where('api_integration_id', $integration->id)->pluck('external_post_id');
        // vid-june (< lower boundary Juli) memicu stop dan tidak diproses.
        // vid-august (> upper boundary akhir Juli) TETAP masuk - pagination
        // saat ini murni lower-bound cutoff, bukan filter rentang penuh.
        $this->assertEqualsCanonicalizing(['vid-august', 'vid-july'], $snapshots->all());
    }

    // ===== D: 0 video dari API asli tetap success, bukan artefak cutoff bug =====

    public function test_zero_videos_from_real_api_is_still_reported_as_success(): void
    {
        $integration = $this->integration();
        $this->fakeTikTokApi([]);

        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow(null);

        $job = new SyncTikTokAnalyticsJob($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $this->userId());
        $job->handle(app(TikTokAnalyticsSyncService::class));

        $log = AnalyticsSyncLog::where('api_integration_id', $integration->id)->latest()->first();
        $this->assertSame('success', $log->status);
        $this->assertSame(0, TikTokVideoSnapshot::where('api_integration_id', $integration->id)->count());
    }

    // ===== Command CLI (analytics:sync-tiktok) - bug independen yang sama =====

    public function test_cli_command_default_sync_also_includes_recent_video(): void
    {
        $integration = $this->integration();
        $yesterday = now()->subDay();
        $this->fakeTikTokApi([$this->videoFixture('vid-yesterday', $yesterday)]);

        $this->artisan('analytics:sync-tiktok', ['--client' => $integration->client_id, '--user' => $this->userId()])
            ->assertExitCode(0);

        $this->assertSame(1, TikTokVideoSnapshot::where('api_integration_id', $integration->id)->count());
    }

    // ===== Unit langsung ke logic cutoff (dokumentasi kontrak method) =====

    public function test_get_video_list_stops_only_at_videos_older_than_cutoff_lower_bound(): void
    {
        $integration = $this->integration();
        $videos = [
            $this->videoFixture('a', now()->subDays(1)),
            $this->videoFixture('b', now()->subDays(40)),
            $this->videoFixture('c', now()->subDays(90)),
        ];
        $this->fakeTikTokApi($videos);

        $service = new TikTokAnalyticsService($integration);
        $result = $service->getVideoList(now()->subDays(60));

        $this->assertEqualsCanonicalizing(['a', 'b'], array_column($result['videos'], 'id'));
        $this->assertTrue($result['stopped_early']);
    }

    private function userId(): int
    {
        return User::factory()->create(['status' => 'active'])->id;
    }
}
