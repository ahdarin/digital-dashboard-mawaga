<?php

namespace Tests\Feature;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncLog;
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
use App\Services\InstagramAnalyticsSyncService;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regresi Phase 2 (Snapshot Collection Only) - content_metric_snapshots
 * ditulis DI SAMPING content_metrics oleh InstagramAnalyticsSyncService &
 * TikTokAnalyticsSyncService::recordSnapshot(), TANPA mengubah content_metrics
 * atau read path Analytics (AnalyticsSummaryService dkk - itu Phase 3).
 *
 * Test A-M sesuai spesifikasi Phase 2 yang di-approve user:
 * A/B - snapshot ditulis dengan identitas benar (snapshot_id + snapshot_date)
 * C   - snapshot_date = tanggal SYNC, bukan tanggal publish
 * D   - sync berulang di hari sama = upsert, bukan duplicate
 * E/F - disiplin NULL != 0 (Instagram metric hilang & TikTok metric yang
 *       structurally tidak tersedia)
 * G   - unmatched media tetap dapat baris snapshot (content_item_id null)
 * H   - content_metrics existing TIDAK berubah/regresi
 * I   - first-observation-only, tidak ada backfill historis
 * J   - manual link cuma update snapshot HARI INI, bukan mass rewrite histori
 * K/L - kegagalan recordSnapshot() TIDAK merusak content_metrics & TIDAK
 *       didiamkan (partial failure tercatat)
 * M   - engagement_rate NULL kalau denominator benar2 tidak diketahui,
 *       BUKAN 0.0 (beda dari "diketahui nol")
 */
class ContentMetricSnapshotCollectionTest extends TestCase
{
    use RefreshDatabase;

    // Http::fake(closure) MENGAKUMULASI stub, tidak menggantikan yang lama
    // (stubCallbacks di-merge, bukan di-assign) - memanggil Http::fake()
    // kedua kalinya di test yang sama (mis. buat ganti insight metric antar
    // sync ke-1 dan ke-2) TIDAK akan pernah kepakai, closure PERTAMA tetap
    // menang buat request berikutnya. Solusinya: daftarkan closure SEKALI
    // per test (idempotent lewat flag ini), lalu baca state MUTABLE dari
    // properti instance - fakeInstagramApi()/fakeTikTokApi() tinggal update
    // properti ini, bukan register ulang. Closure-nya domain-scoped
    // (graph.instagram.com vs open.tiktokapis.com) biar dua-duanya aman
    // dipakai bareng di 1 test (lihat test M).
    private bool $httpFakeRegistered = false;

    private array $igMediaList = [];

    private array $igInsightsData = [];

    private array $ttVideos = [];

    private function ensureHttpFakeRegistered(): void
    {
        if ($this->httpFakeRegistered) {
            return;
        }
        $this->httpFakeRegistered = true;

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'graph.instagram.com')) {
                if (str_contains($url, '/media')) {
                    return Http::response(['data' => $this->igMediaList], 200);
                }

                if (str_contains($url, '/insights')) {
                    return Http::response(['data' => $this->igInsightsData], 200);
                }

                return Http::response([
                    'id' => 'ig-user-1', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => count($this->igMediaList),
                ], 200);
            }

            if (str_contains($url, 'open.tiktokapis.com')) {
                if (str_contains($url, '/video/list/')) {
                    return Http::response(['data' => ['videos' => $this->ttVideos, 'has_more' => false, 'cursor' => null]], 200);
                }

                return Http::response(['data' => ['user' => ['open_id' => 'open123', 'username' => 'creator']]], 200);
            }

            return Http::response(['error' => 'unexpected URL in Phase 2 test fake: '.$url], 404);
        });
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

    private function instagramIntegration(Client $client): ApiIntegration
    {
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'integration_name' => 'Instagram API (OAuth)',
            'status' => 'active',
            'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    private function tiktokIntegration(Client $client): ApiIntegration
    {
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

    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'publishing', 'action' => 'manage']);
        $role->permissions()->attach($permission->id);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    private function userId(): int
    {
        return User::factory()->create(['status' => 'active'])->id;
    }

    private function mediaFixture(string $id, Carbon $createdAt, string $productType = 'IMAGE'): array
    {
        return [
            'id' => $id,
            'caption' => "Caption {$id}",
            'media_type' => 'IMAGE',
            'media_product_type' => $productType,
            'permalink' => "https://instagram.com/p/{$id}",
            'timestamp' => $createdAt->toIso8601String(),
            'username' => 'creator',
            'media_url' => "https://example.com/{$id}.jpg",
            'thumbnail_url' => "https://example.com/{$id}-thumb.jpg",
        ];
    }

    /**
     * @param  array<string, int|float|null>  $values  override metric values; null = metric BENAR2 TIDAK ADA di response API (bukan 0)
     */
    private function insightsFixture(array $values = []): array
    {
        $defaults = ['reach' => 500, 'likes' => 40, 'comments' => 5, 'shares' => 2, 'saved' => 3, 'total_interactions' => 50];
        $merged = array_merge($defaults, $values);

        $entries = [];
        foreach ($merged as $name => $value) {
            if ($value === null) {
                continue;
            }
            $entries[] = ['name' => $name, 'values' => [['value' => $value]]];
        }

        return $entries;
    }

    private function fakeInstagramApi(array $mediaList, ?array $insightsData = null): void
    {
        $this->igMediaList = $mediaList;
        $this->igInsightsData = $insightsData ?? $this->insightsFixture();
        $this->ensureHttpFakeRegistered();
    }

    /**
     * @param  array<string, mixed>  $overrides  null value = key benar2 dihapus dari response (bukan diisi 0)
     */
    private function videoFixture(string $id, Carbon $createdAt, array $overrides = []): array
    {
        $base = [
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

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function fakeTikTokApi(array $videos): void
    {
        $this->ttVideos = $videos;
        $this->ensureHttpFakeRegistered();
    }

    private function runInstagramSync(ApiIntegration $integration, int $userId): void
    {
        [$syncMode, $since, $until] = app(InstagramAnalyticsSyncService::class)->resolveSyncWindow(null);
        $job = new SyncInstagramAnalyticsJob($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $userId);
        $job->handle(app(InstagramAnalyticsSyncService::class));
    }

    private function runTikTokSync(ApiIntegration $integration, int $userId): void
    {
        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow(null);
        $job = new SyncTikTokAnalyticsJob($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $userId);
        $job->handle(app(TikTokAnalyticsSyncService::class));
    }

    // ===== A/B: snapshot ditulis dengan identitas benar =====

    public function test_instagram_sync_writes_content_metric_snapshot_keyed_on_media_and_date(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $this->fakeInstagramApi([$this->mediaFixture('ig-1', now()->subDays(2))]);

        $this->runInstagramSync($integration, $userId);

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-1')->firstOrFail();
        $snapshot = ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)->firstOrFail();

        $this->assertSame(now()->toDateString(), $snapshot->snapshot_date->toDateString());
        $this->assertSame($client->id, $snapshot->client_id);
        $this->assertNull($snapshot->tiktok_video_snapshot_id);
    }

    public function test_tiktok_sync_writes_content_metric_snapshot_keyed_on_video_and_date(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $userId = $this->userId();
        $this->fakeTikTokApi([$this->videoFixture('tt-1', now()->subDays(2))]);

        $this->runTikTokSync($integration, $userId);

        $videoSnapshot = TikTokVideoSnapshot::where('external_post_id', 'tt-1')->firstOrFail();
        $snapshot = ContentMetricSnapshot::where('tiktok_video_snapshot_id', $videoSnapshot->id)->firstOrFail();

        $this->assertSame(now()->toDateString(), $snapshot->snapshot_date->toDateString());
        $this->assertSame($client->id, $snapshot->client_id);
        $this->assertNull($snapshot->instagram_media_snapshot_id);
    }

    // ===== C: snapshot_date = tanggal SYNC, bukan tanggal publish =====

    public function test_snapshot_date_is_sync_date_not_publish_date(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $publishedAt = now()->subDays(45);
        $this->fakeInstagramApi([$this->mediaFixture('ig-old', $publishedAt)]);

        $this->runInstagramSync($integration, $userId);

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-old')->firstOrFail();
        $metric = ContentMetric::where('instagram_media_snapshot_id', $mediaSnapshot->id)->firstOrFail();
        $snapshot = ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)->firstOrFail();

        $this->assertSame($publishedAt->toDateString(), $metric->metric_date->toDateString(), 'content_metrics.metric_date harus tetap terkunci ke tanggal publish (tidak berubah oleh Phase 2).');
        $this->assertSame(now()->toDateString(), $snapshot->snapshot_date->toDateString(), 'content_metric_snapshots.snapshot_date harus tanggal SYNC hari ini, bukan tanggal publish.');
        $this->assertNotSame($metric->metric_date->toDateString(), $snapshot->snapshot_date->toDateString());
    }

    // ===== D: sync berulang di hari sama = upsert, bukan duplicate =====

    public function test_running_instagram_sync_twice_same_day_upserts_not_duplicates(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();

        $this->fakeInstagramApi([$this->mediaFixture('ig-dup', now())], $this->insightsFixture(['likes' => 10]));
        $this->runInstagramSync($integration, $userId);

        $this->fakeInstagramApi([$this->mediaFixture('ig-dup', now())], $this->insightsFixture(['likes' => 99]));
        $this->runInstagramSync($integration, $userId);

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-dup')->firstOrFail();
        $this->assertSame(1, ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)->count());

        $snapshot = ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)->first();
        $this->assertSame(99, $snapshot->likes);
    }

    // ===== E/F: disiplin NULL != 0 =====

    public function test_instagram_null_metric_preserved_not_defaulted_to_zero(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $this->fakeInstagramApi([$this->mediaFixture('ig-null', now())], $this->insightsFixture(['saved' => null]));

        $this->runInstagramSync($integration, $userId);

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-null')->firstOrFail();
        $snapshot = ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)->firstOrFail();

        $this->assertNull($snapshot->saves, 'Metric yang tidak tersedia dari API harus NULL, bukan 0.');
        $this->assertNotNull($snapshot->reach, 'Metric yang memang tersedia harus tetap tersimpan apa adanya.');
    }

    public function test_tiktok_structurally_unavailable_metrics_always_null(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $userId = $this->userId();
        $this->fakeTikTokApi([$this->videoFixture('tt-avail', now())]);

        $this->runTikTokSync($integration, $userId);

        $videoSnapshot = TikTokVideoSnapshot::where('external_post_id', 'tt-avail')->firstOrFail();
        $snapshot = ContentMetricSnapshot::where('tiktok_video_snapshot_id', $videoSnapshot->id)->firstOrFail();

        $this->assertNull($snapshot->reach);
        $this->assertNull($snapshot->impressions);
        $this->assertNull($snapshot->saves);
        $this->assertNull($snapshot->profile_visit);
        $this->assertNotNull($snapshot->views);
    }

    // ===== G: unmatched media tetap dapat baris snapshot =====

    public function test_unmatched_instagram_media_still_gets_snapshot_row_with_null_content_item(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $this->fakeInstagramApi([$this->mediaFixture('ig-unmatched', now())]);

        $this->runInstagramSync($integration, $userId);

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-unmatched')->firstOrFail();
        $this->assertSame('unmatched', $mediaSnapshot->match_status);

        $snapshot = ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)->firstOrFail();
        $this->assertNull($snapshot->content_item_id);
    }

    // ===== H: content_metrics existing TIDAK berubah/regresi =====

    public function test_existing_content_metric_write_unaffected_by_snapshot_addition(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $publishedAt = now()->subDay();
        $this->fakeInstagramApi(
            [$this->mediaFixture('ig-regress', $publishedAt)],
            $this->insightsFixture(['likes' => 12, 'comments' => 3, 'shares' => 1, 'saved' => 2, 'reach' => 200])
        );

        $this->runInstagramSync($integration, $userId);

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-regress')->firstOrFail();
        $metric = ContentMetric::where('instagram_media_snapshot_id', $mediaSnapshot->id)->firstOrFail();

        $this->assertSame($publishedAt->toDateString(), $metric->metric_date->toDateString());
        $this->assertSame(200, $metric->reach);
        $this->assertSame(12, $metric->likes);
        $this->assertSame(1, ContentMetric::count(), 'Phase 2 tidak boleh menambah/mengubah jumlah baris content_metrics.');
    }

    // ===== I: first-observation-only, tidak ada backfill historis =====

    public function test_first_observation_only_creates_exactly_one_snapshot_row_regardless_of_publish_age(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $this->fakeInstagramApi([$this->mediaFixture('ig-old-publish', now()->subDays(60))]);

        $this->runInstagramSync($integration, $userId);

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-old-publish')->firstOrFail();
        $this->assertSame(1, ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)->count());
        $this->assertSame(
            0,
            ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)
                ->where('snapshot_date', '<', now()->toDateString())
                ->count(),
            'TIDAK boleh ada baris snapshot dengan tanggal sebelum hari sync ini dijalankan (no fake historical backfill).'
        );
    }

    // ===== J: manual link cuma update snapshot HARI INI, bukan mass rewrite histori =====

    public function test_manual_link_updates_only_todays_snapshot_content_item_id_not_historical_rows(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $this->fakeInstagramApi([$this->mediaFixture('ig-link', now())]);
        $this->runInstagramSync($integration, $userId);

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-link')->firstOrFail();

        // Simulasikan histori observasi KEMARIN (dari sync hari sebelumnya,
        // sebelum link manual ini terjadi) - baris ini TIDAK BOLEH ikut
        // ter-update oleh link action.
        $yesterdaySnapshot = ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => $integration->platform_id,
            'content_item_id' => null,
            'instagram_media_snapshot_id' => $mediaSnapshot->id,
            'snapshot_date' => now()->subDay()->toDateString(),
            'views' => 10,
        ]);

        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => $userId,
            'month' => now()->month,
            'year' => now()->year,
            'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $contentItem = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => $contentType->id,
            'platform_id' => $integration->platform_id,
            'title' => 'Item Link Test',
            'deadline_at' => now()->addDays(3),
        ]);

        $manager = $this->managerFor($client);
        $response = $this->actingAs($manager)->post(route('publishing-tracker.instagram.link', $integration), [
            'content_item_id' => $contentItem->id,
            'external_post_id' => 'ig-link',
        ]);
        $response->assertRedirect();

        $todaySnapshot = ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)
            ->where('snapshot_date', now()->toDateString())
            ->firstOrFail();
        $this->assertSame($contentItem->id, $todaySnapshot->content_item_id);

        $this->assertNull(
            $yesterdaySnapshot->fresh()->content_item_id,
            'Baris histori KEMARIN tidak boleh ikut ter-update oleh manual link (no mass historical rewrite).'
        );
    }

    // ===== K/L: kegagalan recordSnapshot() tidak merusak content_metrics & tidak didiamkan =====

    public function test_instagram_snapshot_write_failure_does_not_break_content_metric_and_is_not_silently_swallowed(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $this->fakeInstagramApi([$this->mediaFixture('ig-fail', now())]);

        ContentMetricSnapshot::saving(function () {
            throw new \RuntimeException('Simulated content_metric_snapshots write failure (test only)');
        });

        try {
            $this->runInstagramSync($integration, $userId);
        } finally {
            ContentMetricSnapshot::flushEventListeners();
        }

        $mediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-fail')->firstOrFail();
        $this->assertSame(1, ContentMetric::where('instagram_media_snapshot_id', $mediaSnapshot->id)->count(), 'content_metrics harus tetap tersimpan walau content_metric_snapshots gagal.');
        $this->assertSame(0, ContentMetricSnapshot::where('instagram_media_snapshot_id', $mediaSnapshot->id)->count());

        $log = AnalyticsSyncLog::where('api_integration_id', $integration->id)->latest()->first();
        $this->assertSame('success', $log->status, 'Sync keseluruhan tidak boleh dianggap gagal total hanya karena snapshot gagal.');
        $this->assertStringContainsString('content_metric_snapshots gagal', $log->error_message, 'Kegagalan partial HARUS tercatat, tidak boleh didiamkan seolah sync sempurna.');
    }

    public function test_tiktok_snapshot_write_failure_does_not_break_content_metric_and_is_not_silently_swallowed(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $userId = $this->userId();
        $this->fakeTikTokApi([$this->videoFixture('tt-fail', now())]);

        ContentMetricSnapshot::saving(function () {
            throw new \RuntimeException('Simulated content_metric_snapshots write failure (test only)');
        });

        try {
            $this->runTikTokSync($integration, $userId);
        } finally {
            ContentMetricSnapshot::flushEventListeners();
        }

        $videoSnapshot = TikTokVideoSnapshot::where('external_post_id', 'tt-fail')->firstOrFail();
        $this->assertSame(1, ContentMetric::where('tiktok_video_snapshot_id', $videoSnapshot->id)->count());
        $this->assertSame(0, ContentMetricSnapshot::where('tiktok_video_snapshot_id', $videoSnapshot->id)->count());

        $log = AnalyticsSyncLog::where('api_integration_id', $integration->id)->latest()->first();
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('content_metric_snapshots gagal', $log->error_message);
    }

    // ===== M: engagement_rate NULL kalau denominator tidak diketahui, BUKAN 0.0 =====

    public function test_engagement_rate_null_when_denominator_unknown_not_zero(): void
    {
        $client = $this->client();
        $userId = $this->userId();

        // Instagram: reach & views dua-duanya tidak tersedia -> TIDAK BISA
        // dihitung, harus NULL.
        $igIntegration = $this->instagramIntegration($client);
        $this->fakeInstagramApi(
            [$this->mediaFixture('ig-noreach', now())],
            $this->insightsFixture(['reach' => null, 'likes' => 5, 'comments' => 1, 'shares' => 0, 'saved' => 0, 'total_interactions' => 6])
        );
        $this->runInstagramSync($igIntegration, $userId);
        $igMediaSnapshot = InstagramMediaSnapshot::where('external_post_id', 'ig-noreach')->firstOrFail();
        $igSnapshot = ContentMetricSnapshot::where('instagram_media_snapshot_id', $igMediaSnapshot->id)->firstOrFail();
        $this->assertNull($igSnapshot->engagement_rate, 'reach & views dua-duanya tidak tersedia -> engagement_rate TIDAK BISA dihitung, harus NULL bukan 0.');

        // Instagram: reach tersedia tapi 0 interaksi -> engagement_rate
        // GENUINELY 0.00 (diketahui nol), bukan NULL.
        $this->fakeInstagramApi(
            [$this->mediaFixture('ig-zero', now())],
            $this->insightsFixture(['reach' => 100, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saved' => 0, 'total_interactions' => 0])
        );
        $this->runInstagramSync($igIntegration, $userId);
        $igMediaSnapshot2 = InstagramMediaSnapshot::where('external_post_id', 'ig-zero')->firstOrFail();
        $igSnapshot2 = ContentMetricSnapshot::where('instagram_media_snapshot_id', $igMediaSnapshot2->id)->firstOrFail();
        $this->assertNotNull($igSnapshot2->engagement_rate);
        $this->assertSame('0.00', $igSnapshot2->engagement_rate);

        // TikTok: view_count key benar2 tidak ada di response -> engagement_rate NULL.
        $ttIntegration = $this->tiktokIntegration($client);
        $video = $this->videoFixture('tt-noviews', now(), ['view_count' => null]);
        $this->fakeTikTokApi([$video]);
        $this->runTikTokSync($ttIntegration, $userId);
        $ttVideoSnapshot = TikTokVideoSnapshot::where('external_post_id', 'tt-noviews')->firstOrFail();
        $ttSnapshot = ContentMetricSnapshot::where('tiktok_video_snapshot_id', $ttVideoSnapshot->id)->firstOrFail();
        $this->assertNull($ttSnapshot->engagement_rate, 'view_count benar2 tidak ada di response TikTok -> engagement_rate TIDAK BISA dihitung, harus NULL bukan 0.');
    }
}
