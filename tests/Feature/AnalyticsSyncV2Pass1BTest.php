<?php

namespace Tests\Feature;

use App\Console\Commands\AutoSyncAnalytics;
use App\Exceptions\InstagramApiException;
use App\Exceptions\TikTokApiException;
use App\Models\AnalyticsSyncFailure;
use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\AnalyticsSyncOrchestrator;
use App\Services\InstagramAnalyticsSyncService;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * PASS 1B - "ANALYTICS V2 COVERAGE + HARDENING". Regression buat:
 * TikTok username scope fix, TikTok stats/profile-identity/height-width
 * persistence, TikTok cover_image_url/duration rotation-refresh, Instagram
 * name/profile_picture_url/shortcode persistence, Instagram granted-scope
 * persistence (tri-state), bounded item-level transient retry (Instagram +
 * TikTok), reconciliation single-outcome-per-item, scheduler timezone, dan
 * Reach history backfill verification.
 */
class AnalyticsSyncV2Pass1BTest extends TestCase
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

    private function instagramIntegration(Client $client, array $overrides = []): ApiIntegration
    {
        return ApiIntegration::create(array_merge([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake-token',
            'external_username' => 'creator', 'external_account_id' => 'ig-user-'.uniqid(),
        ], $overrides));
    }

    private function tiktokIntegration(Client $client, array $overrides = []): ApiIntegration
    {
        return ApiIntegration::create(array_merge([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'TikTok'])->id,
            'integration_name' => 'TT', 'status' => 'active', 'access_token' => 'fake-token',
            'external_username' => 'creator', 'scopes' => 'user.info.basic,user.info.profile,user.info.stats,video.list',
        ], $overrides));
    }

    private function userId(): int
    {
        return User::factory()->create(['status' => 'active'])->id;
    }

    private function syncLog(ApiIntegration $integration): AnalyticsSyncLog
    {
        return AnalyticsSyncLog::create([
            'client_id' => $integration->client_id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $this->userId(),
            'source_type' => 'api_sync',
            'status' => 'pending',
            'sync_mode' => 'default',
            'range_from' => now()->subDays(90)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
        ]);
    }

    private function task(ApiIntegration $integration, string $subjob): AnalyticsSyncTask
    {
        $run = AnalyticsSyncRun::create(['client_id' => $integration->client_id, 'trigger' => 'manual', 'status' => 'queued']);

        return AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id,
            'api_integration_id' => $integration->id,
            'subjob' => $subjob,
            'status' => 'queued',
            'attempt' => 1,
        ]);
    }

    // ===== 1. TikTok Display API coverage =====

    public function test_tiktok_username_field_only_requested_when_profile_scope_granted(): void
    {
        $client = $this->client();
        $integrationWithProfile = $this->tiktokIntegration($client, ['scopes' => 'user.info.basic,user.info.profile']);
        $integrationBasicOnly = $this->tiktokIntegration($this->client(), ['scopes' => 'user.info.basic']);

        $capturedFields = [];
        Http::fake(function ($request) use (&$capturedFields) {
            if (str_contains($request->url(), 'user/info')) {
                $capturedFields[] = $request['fields'] ?? '';

                return Http::response(['data' => ['user' => ['open_id' => 'x', 'display_name' => 'Creator']]], 200);
            }

            return Http::response(['data' => ['videos' => []]], 200);
        });

        (new \App\Services\TikTokAnalyticsService($integrationWithProfile))->getUserInfo();
        (new \App\Services\TikTokAnalyticsService($integrationBasicOnly))->getUserInfo();

        $this->assertStringContainsString('username', $capturedFields[0], 'user.info.profile granted -> username HARUS diminta.');
        $this->assertStringNotContainsString('username', $capturedFields[1], 'user.info.profile TIDAK granted -> username TIDAK BOLEH diminta (fix bug scope lama).');
    }

    public function test_tiktok_stats_fields_previously_discarded_are_now_persisted(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, 'tiktok_content');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user/info')) {
                return Http::response(['data' => ['user' => [
                    'open_id' => 'tt-1', 'display_name' => 'Creator', 'username' => 'creator_handle',
                    'bio_description' => 'A cool creator', 'is_verified' => true,
                    'profile_deep_link' => 'https://tiktok.com/@creator_handle',
                    'avatar_large_url' => 'https://p16.tiktokcdn.com/large.jpg',
                    'follower_count' => 5000, 'following_count' => 120, 'likes_count' => 99000, 'video_count' => 42,
                ]]], 200);
            }
            if (str_contains($request->url(), 'video/list')) {
                return Http::response(['data' => ['videos' => [], 'has_more' => false, 'cursor' => 0]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(TikTokAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), $this->userId(), $task);

        $insight = AudienceInsight::where('client_id', $client->id)->where('demographic_type', 'summary')->first();
        $this->assertSame(5000, $insight->follower_count);
        $this->assertSame(120, $insight->following_count, 'following_count sebelumnya diminta tapi dibuang - sekarang harus tersimpan.');
        $this->assertSame(99000, $insight->likes_count);
        $this->assertSame(42, $insight->video_count);

        $integration->refresh();
        $this->assertSame('Creator', $integration->external_display_name);
        $this->assertSame('https://p16.tiktokcdn.com/large.jpg', $integration->external_avatar_url, 'avatar_large_url diprioritaskan atas avatar_url biasa.');
        $this->assertSame('A cool creator', $integration->external_bio);
        $this->assertTrue($integration->external_verified);
        $this->assertSame('https://tiktok.com/@creator_handle', $integration->external_profile_url);
    }

    public function test_tiktok_video_height_width_persisted(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, 'tiktok_content');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-1', 'display_name' => 'C']]], 200);
            }
            if (str_contains($request->url(), 'video/list')) {
                return Http::response(['data' => ['videos' => [[
                    'id' => 'v1', 'create_time' => now()->timestamp, 'view_count' => 10, 'like_count' => 1,
                    'comment_count' => 0, 'share_count' => 0, 'height' => 1920, 'width' => 1080,
                ]], 'has_more' => false, 'cursor' => 0]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(TikTokAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), $this->userId(), $task);

        $snapshot = TikTokVideoSnapshot::where('api_integration_id', $integration->id)->where('external_post_id', 'v1')->first();
        $this->assertSame(1920, $snapshot->height);
        $this->assertSame(1080, $snapshot->width);
    }

    // ===== PASS 1 MICRO-FIX: is_aigc =====

    private function tiktokVideoListResponse(array $videoOverrides): array
    {
        return ['data' => ['videos' => [array_merge([
            'id' => 'v1', 'create_time' => now()->timestamp, 'view_count' => 10,
            'like_count' => 1, 'comment_count' => 0, 'share_count' => 0,
        ], $videoOverrides)], 'has_more' => false, 'cursor' => 0]];
    }

    public function test_tiktok_is_aigc_true_persists_true(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, 'tiktok_content');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-1', 'display_name' => 'C']]], 200);
            }
            if (str_contains($request->url(), 'video/list')) {
                return Http::response($this->tiktokVideoListResponse(['is_aigc' => true]), 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(TikTokAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), $this->userId(), $task);

        $snapshot = TikTokVideoSnapshot::where('api_integration_id', $integration->id)->where('external_post_id', 'v1')->first();
        $this->assertTrue($snapshot->is_aigc);
    }

    public function test_tiktok_is_aigc_false_persists_false(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, 'tiktok_content');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-1', 'display_name' => 'C']]], 200);
            }
            if (str_contains($request->url(), 'video/list')) {
                return Http::response($this->tiktokVideoListResponse(['is_aigc' => false]), 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(TikTokAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), $this->userId(), $task);

        $snapshot = TikTokVideoSnapshot::where('api_integration_id', $integration->id)->where('external_post_id', 'v1')->first();
        $this->assertNotNull($snapshot->is_aigc, 'false eksplisit HARUS tersimpan sebagai false, bukan null.');
        $this->assertFalse($snapshot->is_aigc);
    }

    public function test_tiktok_is_aigc_omitted_remains_null_and_does_not_fail_sync(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, 'tiktok_content');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-1', 'display_name' => 'C']]], 200);
            }
            if (str_contains($request->url(), 'video/list')) {
                // is_aigc SENGAJA tidak disebut sama sekali di response ini -
                // simulasi provider/akun yang belum roll out field ini.
                return Http::response($this->tiktokVideoListResponse([]), 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $result = app(TikTokAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), $this->userId(), $task);

        $this->assertSame(1, $result['metrics_saved'], 'is_aigc absen TIDAK BOLEH menggagalkan sync media ini sama sekali.');
        $snapshot = TikTokVideoSnapshot::where('api_integration_id', $integration->id)->where('external_post_id', 'v1')->first();
        $this->assertNull($snapshot->is_aigc, 'Absen di response -> NULL (unknown), TIDAK PERNAH ditebak false.');
        $this->assertSame('success', $task->fresh()->status);
    }

    public function test_tiktok_targeted_refresh_updates_is_aigc(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $video = TikTokVideoSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'tt-aigc-refresh-1',
            'is_aigc' => null,
            'published_at' => now()->subDays(50),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(30),
        ]);
        $task = $this->task($integration, 'tiktok_content');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'video/query')) {
                return Http::response(['data' => ['videos' => [[
                    'id' => 'tt-aigc-refresh-1', 'create_time' => now()->subDays(50)->timestamp,
                    'view_count' => 500, 'like_count' => 20, 'comment_count' => 2, 'share_count' => 1,
                    'is_aigc' => true,
                ]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $this->syncLog($integration), $this->userId(), $task);

        $video->refresh();
        $this->assertTrue($video->is_aigc, 'Rotasi refresh HARUS bisa mengubah is_aigc dari NULL jadi nilai baru yang genuinely dilaporkan provider - sama seperti metadata lain (cover_image_url dkk).');
    }

    public function test_tiktok_cover_image_url_and_duration_refreshed_on_rotation(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $video = TikTokVideoSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'tt-rotate-1',
            'cover_image_url' => 'https://p16.tiktokcdn.com/old-expired-thumb.jpg',
            'duration' => 10,
            'published_at' => now()->subDays(50),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(30),
        ]);
        $task = $this->task($integration, 'tiktok_content');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'video/query')) {
                return Http::response(['data' => ['videos' => [[
                    'id' => 'tt-rotate-1', 'create_time' => now()->subDays(50)->timestamp,
                    'view_count' => 500, 'like_count' => 20, 'comment_count' => 2, 'share_count' => 1,
                    'cover_image_url' => 'https://p16.tiktokcdn.com/fresh-thumb.jpg',
                    'duration' => 12, 'height' => 1920, 'width' => 1080,
                ]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $this->syncLog($integration), $this->userId(), $task);

        $video->refresh();
        $this->assertSame('https://p16.tiktokcdn.com/fresh-thumb.jpg', $video->cover_image_url, 'cover_image_url HARUS disegarkan tiap rotasi (TTL terbatas) - bug lama membiarkannya macet di URL kadaluarsa.');
        $this->assertSame(12, $video->duration);
    }

    // ===== 2. Instagram coverage gap =====

    public function test_instagram_profile_name_and_picture_persisted(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, 'instagram_content');

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/me') || (str_contains($url, 'graph.instagram.com') && ! str_contains($url, 'media') && ! str_contains($url, 'insights'))) {
                return Http::response(['id' => '999', 'username' => 'creator', 'name' => 'Creator Display Name', 'account_type' => 'BUSINESS', 'media_count' => 0, 'profile_picture_url' => 'https://scontent.cdninstagram.com/avatar.jpg'], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        app(InstagramAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), now(), $this->userId(), $task);

        $integration->refresh();
        $this->assertSame('Creator Display Name', $integration->external_display_name);
        $this->assertSame('https://scontent.cdninstagram.com/avatar.jpg', $integration->external_avatar_url);
    }

    public function test_instagram_shortcode_persisted(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, 'instagram_content');

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => [
                    ['id' => 'ig-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->toIso8601String(), 'permalink' => 'https://instagram.com/p/Cx1Y2z3', 'shortcode' => 'Cx1Y2z3'],
                ]], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }
            if (str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        app(InstagramAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), now(), $this->userId(), $task);

        $this->assertDatabaseHas('instagram_media_snapshots', ['external_post_id' => 'ig-1', 'shortcode' => 'Cx1Y2z3']);
    }

    // ===== 3. Instagram granted-scope persistence =====

    public function test_instagram_oauth_callback_persists_actually_granted_scopes(): void
    {
        Platform::firstOrCreate(['name' => 'Instagram']);
        $client = $this->client();
        $manager = User::factory()->create(['status' => 'active']);
        $role = \App\Models\Role::create(['name' => 'Manager Test '.uniqid()]);
        $perm = \App\Models\Permission::firstOrCreate(['module' => 'client', 'action' => 'manage']);
        $role->permissions()->attach($perm->id);
        $manager->roles()->attach($role->id);
        $this->withSession(['instagram_oauth_state' => 'good-state', 'instagram_oauth_client_id' => $client->id]);

        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 123, 'permissions' => ['instagram_business_basic']], 200),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-lived', 'expires_in' => 5184000], 200),
            'graph.instagram.com/me*' => Http::response(['id' => '123', 'username' => 'test_ig_account'], 200),
            'graph.instagram.com/v*/me*' => Http::response(['id' => '123', 'username' => 'test_ig_account'], 200),
        ]);

        $this->actingAs($manager)->get(route('client-management.instagram.callback', ['code' => 'auth-code', 'state' => 'good-state']));

        $integration = ApiIntegration::where('client_id', $client->id)->first();
        $this->assertSame('instagram_business_basic', $integration->scopes, 'Scope yang BENAR-BENAR granted (dari response provider) yang tersimpan - BUKAN daftar scope yang kita request (instagram_business_manage_insights tidak ada di sini, TIDAK BOLEH ikut tersimpan).');
        $this->assertTrue($integration->hasKnownScope('instagram_business_basic'));
        $this->assertFalse($integration->hasKnownScope('instagram_business_manage_insights'), 'Scope yang TIDAK ada di response granted HARUS false (missing), bukan diasumsikan true.');
    }

    public function test_instagram_oauth_callback_does_not_fabricate_scope_when_provider_omits_permissions_field(): void
    {
        Platform::firstOrCreate(['name' => 'Instagram']);
        $client = $this->client();
        $manager = User::factory()->create(['status' => 'active']);
        $role = \App\Models\Role::create(['name' => 'Manager Test '.uniqid()]);
        $perm = \App\Models\Permission::firstOrCreate(['module' => 'client', 'action' => 'manage']);
        $role->permissions()->attach($perm->id);
        $manager->roles()->attach($role->id);
        $this->withSession(['instagram_oauth_state' => 'good-state', 'instagram_oauth_client_id' => $client->id]);

        // Response TIDAK menyertakan 'permissions' sama sekali.
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 123], 200),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-lived', 'expires_in' => 5184000], 200),
            'graph.instagram.com/me*' => Http::response(['id' => '123', 'username' => 'test_ig_account'], 200),
            'graph.instagram.com/v*/me*' => Http::response(['id' => '123', 'username' => 'test_ig_account'], 200),
        ]);

        $this->actingAs($manager)->get(route('client-management.instagram.callback', ['code' => 'auth-code', 'state' => 'good-state']));

        $integration = ApiIntegration::where('client_id', $client->id)->first();
        $this->assertNull($integration->scopes, 'TIDAK ADA info permissions dari provider -> scopes TETAP null (unknown), TIDAK BOLEH diisi daftar scope yang KITA request.');
        $this->assertNull($integration->hasKnownScope('instagram_business_basic'), 'Tri-state: null berarti unknown, BUKAN false.');
    }

    public function test_legacy_instagram_integration_with_null_scopes_is_unknown_not_missing(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client, ['scopes' => null]);

        $this->assertNull($integration->hasKnownScope('instagram_business_manage_insights'), 'Legacy integration (connect sebelum fix ini) - unknown, BUKAN dianggap "missing"/false.');
    }

    // ===== 4. Item-level transient retry =====

    public function test_instagram_bounded_retry_recovers_from_single_transient_blip(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-transient-1',
            'media_product_type' => 'IMAGE',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, 'instagram_content');

        $attempt = 0;
        Http::fake(function ($request) use (&$attempt) {
            if (str_contains($request->url(), '/insights')) {
                $attempt++;
                // Percobaan pertama (dan fallback safe-metric-nya) gagal 500,
                // percobaan KEDUA (bounded retry) sukses.
                return $attempt <= 2
                    ? Http::response(['error' => ['message' => 'temporarily down']], 500)
                    : Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 300]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration), $this->userId(), $task);

        $task->refresh();
        $this->assertSame(1, $task->success_count, 'Bounded retry SEHARUSNYA berhasil pulih dari 1 blip transient tanpa perlu retryFailedItemsForTask() eksplisit.');
        $this->assertSame(0, $task->failed_count);
        $this->assertSame(0, AnalyticsSyncFailure::where('analytics_sync_task_id', $task->id)->count());
    }

    public function test_instagram_bounded_retry_gives_up_after_one_retry_no_infinite_loop(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-always-down-1',
            'media_product_type' => 'IMAGE',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, 'instagram_content');

        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            if (str_contains($request->url(), '/insights')) {
                $callCount++;

                return Http::response(['error' => ['message' => 'down']], 500);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration), $this->userId(), $task);

        // 2 metric set (preferred+safe) x 2 percobaan (awal + 1 bounded
        // retry) = maksimal 4 panggilan - DIBATASI, bukan tak terbatas.
        $this->assertLessThanOrEqual(4, $callCount);
        $this->assertSame(1, $task->fresh()->failed_count);
        $failure = AnalyticsSyncFailure::where('analytics_sync_task_id', $task->id)->first();
        $this->assertNotNull($failure, 'Setelah bounded retry tetap gagal, HARUS tercatat sebagai unresolved failure buat retryFailedItemsForTask() nanti.');
        $this->assertTrue($failure->retryable);
    }

    public function test_instagram_authentication_failure_is_never_retried(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-auth-1',
            'media_product_type' => 'IMAGE',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, 'instagram_content');

        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            if (str_contains($request->url(), '/insights')) {
                $callCount++;

                return Http::response(['error' => ['message' => 'Invalid token', 'code' => 190]], 401);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration), $this->userId(), $task);

        $this->assertSame(1, $callCount, 'Auth failure TIDAK PERNAH diretry - 1 panggilan saja lalu langsung berhenti (break loop).');
        $this->assertSame('needs_reconnect', $task->fresh()->status);
    }

    public function test_instagram_unsupported_metric_is_never_retried(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-story-retry-1',
            'media_product_type' => 'STORY',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, 'instagram_content');

        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;

            return Http::response(['error' => 'should never be called for STORY'], 500);
        });

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration), $this->userId(), $task);

        $this->assertSame(0, $callCount, 'STORY unsupported terdeteksi SEBELUM panggilan API apapun - tidak ada retry karena tidak ada API call sama sekali.');
        $this->assertSame(1, $task->fresh()->unavailable_count);
    }

    public function test_tiktok_bounded_batch_retry_recovers_from_transient_failure(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        TikTokVideoSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'tt-transient-1',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, 'tiktok_content');

        $attempt = 0;
        Http::fake(function ($request) use (&$attempt) {
            if (str_contains($request->url(), 'video/query')) {
                $attempt++;

                return $attempt === 1
                    ? Http::response(['error' => ['code' => 'internal_error', 'message' => 'down']], 500)
                    : Http::response(['data' => ['videos' => [[
                        'id' => 'tt-transient-1', 'create_time' => now()->subDays(10)->timestamp,
                        'view_count' => 40, 'like_count' => 2, 'comment_count' => 0, 'share_count' => 0,
                    ]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $this->syncLog($integration), $this->userId(), $task);

        $this->assertSame(2, $attempt, 'Percobaan pertama gagal (500), bounded retry kedua sukses.');
        $this->assertSame(1, $task->fresh()->success_count);
        $this->assertSame(0, $task->fresh()->failed_count);
    }

    public function test_tiktok_rate_limit_retry_applies_backoff_before_retrying(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        TikTokVideoSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'tt-ratelimit-1',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, 'tiktok_content');

        $attempt = 0;
        Http::fake(function ($request) use (&$attempt) {
            if (str_contains($request->url(), 'video/query')) {
                $attempt++;

                return $attempt === 1
                    ? Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'slow down']], 429)
                    : Http::response(['data' => ['videos' => [[
                        'id' => 'tt-ratelimit-1', 'create_time' => now()->subDays(10)->timestamp,
                        'view_count' => 10, 'like_count' => 0, 'comment_count' => 0, 'share_count' => 0,
                    ]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $start = microtime(true);
        app(TikTokAnalyticsSyncService::class)->refreshKnownVideos($integration, $this->syncLog($integration), $this->userId(), $task);
        $elapsed = microtime(true) - $start;

        $this->assertSame(2, $attempt);
        $this->assertGreaterThanOrEqual(1.0, $elapsed, 'Rate-limit retry HARUS didahului jeda pendek (provider-aware backoff), bukan retry instan.');
        $this->assertSame(1, $task->fresh()->success_count);
    }

    // ===== 5. Reconciliation - one item, exactly one outcome =====

    public function test_reconciliation_counts_exactly_one_outcome_per_discovered_media_regardless_of_metric_fallback(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-reel-fallback-1',
            'media_product_type' => 'REELS',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, 'instagram_content');

        // Preferred metric set (dengan total_interactions dkk) DITOLAK,
        // safe metric set (subset lebih kecil) BERHASIL - 1 media, 2
        // "upaya" metric set internal, TAPI harus tetap 1 outcome saja.
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            if (str_contains($request->url(), '/insights')) {
                $callCount++;
                $metric = $request['metric'] ?? '';
                if (str_contains($metric, 'total_interactions')) {
                    return Http::response(['error' => ['message' => 'Unsupported metric', 'code' => 100]], 400);
                }

                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 900]]],
                    ['name' => 'likes', 'values' => [['value' => 50]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration), $this->userId(), $task);

        $task->refresh();
        $this->assertGreaterThanOrEqual(2, $callCount, 'Preferred set gagal lalu fallback ke safe set - beberapa panggilan HTTP internal.');
        $this->assertSame(1, $task->discovered_count, 'HANYA 1 media yang genuinely discovered.');
        $this->assertSame(1, $task->success_count, 'Fallback metric-set internal TIDAK BOLEH menghasilkan >1 outcome buat 1 media yang sama.');
        $this->assertSame(0, $task->failed_count);
        $this->assertSame(0, $task->unavailable_count);
        $this->assertSame(1, $task->success_count + $task->unavailable_count + $task->skipped_count + $task->failed_count, 'Invariant: SATU media = SATU outcome, tidak pernah double-counted.');
        $this->assertTrue($task->isReconciled());
    }

    // ===== 6. Scheduler timezone =====

    public function test_auto_sync_schedule_uses_configured_timezone_and_time(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $event = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'analytics:auto-sync'));

        $this->assertNotNull($event, 'analytics:auto-sync HARUS terdaftar di scheduler.');
        $this->assertSame(config('app.timezone'), $event->timezone, 'Timezone HARUS eksplisit dari config(app.timezone), bukan implisit.');
        $this->assertSame(config('analytics.auto_sync_time'), '03:15', 'Default config value (kalau ANALYTICS_AUTO_SYNC_TIME kosong).');

        // Cuma SATU jadwal terdaftar buat auto-sync analytics (bukan 3
        // command lama yang sudah dikonsolidasi).
        $autoSyncEvents = collect($events)->filter(fn ($e) => str_contains($e->command ?? '', 'analytics:sync-all-') || str_contains($e->command ?? '', 'analytics:auto-sync'));
        $this->assertCount(1, $autoSyncEvents, 'HARUS cuma 1 jadwal otomatis analytics harian (3 command lama sudah tidak lagi di-schedule).');
    }

    // ===== 7. One-time Instagram Reach history backfill =====

    public function test_new_eligible_instagram_integration_triggers_backfill_once(): void
    {
        Platform::firstOrCreate(['name' => 'Instagram']);
        $client = $this->client();
        $manager = User::factory()->create(['status' => 'active']);
        $role = \App\Models\Role::create(['name' => 'Manager Test '.uniqid()]);
        $perm = \App\Models\Permission::firstOrCreate(['module' => 'client', 'action' => 'manage']);
        $role->permissions()->attach($perm->id);
        $manager->roles()->attach($role->id);
        $this->withSession(['instagram_oauth_state' => 'good-state', 'instagram_oauth_client_id' => $client->id]);

        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 123, 'permissions' => ['instagram_business_basic', 'instagram_business_manage_insights']], 200),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-lived', 'expires_in' => 5184000], 200),
            'graph.instagram.com/me*' => Http::response(['id' => '123', 'username' => 'test_ig_account'], 200),
            'graph.instagram.com/v*/me*' => Http::response(['id' => '123', 'username' => 'test_ig_account'], 200),
            'graph.instagram.com/*/insights*' => Http::response(['data' => [['values' => [
                ['end_time' => now()->subDays(1)->toIso8601String(), 'value' => 10],
                ['end_time' => now()->toIso8601String(), 'value' => 20],
            ]]]], 200),
        ]);

        $this->actingAs($manager)->get(route('client-management.instagram.callback', ['code' => 'auth-code', 'state' => 'good-state']));

        $integration = ApiIntegration::where('client_id', $client->id)->first();
        $this->assertNotNull($integration->reach_history_backfilled_at, 'Integration BARU HARUS trigger backfill otomatis sekali.');
        $this->assertDatabaseHas('audience_insights', ['client_id' => $client->id, 'source' => 'instagram_api']);
    }

    public function test_backfill_failure_does_not_falsely_mark_complete(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client, ['reach_history_backfilled_at' => null]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid token', 'code' => 190]], 401)]);

        try {
            (new \App\Services\InstagramAudienceInsightsService($integration))->backfillReachHistory();
        } catch (\Throwable $e) {
            // Auth exception genuinely dilempar - expected.
        }

        $this->assertNull($integration->fresh()->reach_history_backfilled_at, 'Backfill yang GAGAL TIDAK BOLEH menandai marker seolah sudah selesai.');
    }

    public function test_backfill_marks_complete_even_with_zero_history_days(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client, ['reach_history_backfilled_at' => null]);

        Http::fake(['*' => Http::response(['data' => [['values' => []]]], 200)]);

        $days = (new \App\Services\InstagramAudienceInsightsService($integration))->backfillReachHistory();

        $this->assertSame(0, $days);
        $this->assertNotNull($integration->fresh()->reach_history_backfilled_at, 'Akun baru genuinely tanpa histori reach TETAP ditandai "sudah dicoba" - bukan retry otomatis berulang tiap hari.');
    }

    public function test_backfill_retry_is_idempotent_no_duplicate_snapshots(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client, ['reach_history_backfilled_at' => null]);
        // getReachHistory() memetakan end_time -> (end_time - 1 hari) buat
        // tanggal representasi (lihat docblock method itu) - end_time hari
        // ke-4 -> snapshot_date hari ke-5.
        $date = now()->subDays(5)->toDateString();

        Http::fake(['*' => Http::response(['data' => [['values' => [
            ['end_time' => now()->subDays(4)->toIso8601String(), 'value' => 77],
        ]]]], 200)]);

        $service = new \App\Services\InstagramAudienceInsightsService($integration);
        $service->backfillReachHistory();
        $service->backfillReachHistory(); // panggil lagi (retry manual)

        $this->assertSame(1, AudienceInsight::where('client_id', $client->id)->where('snapshot_date', $date)->count(), 'updateOrCreate - retry TIDAK PERNAH menduplikasi baris.');
    }

    public function test_normal_daily_sync_does_not_repeat_reach_backfill(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client, ['reach_history_backfilled_at' => now()->subDays(3)]);
        $task = $this->task($integration, 'instagram_audience');

        $backfillCalled = false;
        Http::fake(function ($request) use (&$backfillCalled) {
            $url = $request->url();
            if (str_contains($url, 'followers_count')) {
                return Http::response(['followers_count' => 100], 200);
            }
            if (str_contains($url, 'metric=reach')) {
                // Daily sync getTodayReach() minta window PENDEK (2 hari) -
                // kalau ini dipanggil dengan window 180 hari, itu tanda
                // backfill KELIRU ke-trigger lagi.
                $since = (int) ($request['since'] ?? 0);
                $until = (int) ($request['until'] ?? 0);
                $spanDays = ($until - $since) / 86400;
                $backfillCalled = $backfillCalled || $spanDays > 10;

                return Http::response(['data' => [['values' => [['end_time' => now()->toIso8601String(), 'value' => 5]]]]], 200);
            }
            if (str_contains($url, 'online_followers')) {
                return Http::response(['data' => [['values' => []]]], 200);
            }
            if (str_contains($url, 'demographics')) {
                return Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        (new \App\Services\InstagramAudienceInsightsService($integration))->sync($this->syncLog($integration), $task);

        $this->assertFalse($backfillCalled, 'Daily sync() TIDAK PERNAH memicu window backfill 180 hari - cuma getTodayReach() (window pendek).');
    }

    public function test_backfill_historical_dates_are_genuine_provider_dates_not_fabricated(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client, ['reach_history_backfilled_at' => null]);
        // end_time -> (end_time - 1 hari) buat snapshot_date (lihat
        // getReachHistory() docblock) - kirim end_time 1 hari LEBIH BARU
        // dari tanggal yang diharapkan tersimpan.
        $realDate1 = now()->subDays(100)->toDateString();
        $realDate2 = now()->subDays(50)->toDateString();

        Http::fake(['*' => Http::response(['data' => [['values' => [
            ['end_time' => now()->subDays(99)->toIso8601String(), 'value' => 15],
            ['end_time' => now()->subDays(49)->toIso8601String(), 'value' => 25],
        ]]]], 200)]);

        (new \App\Services\InstagramAudienceInsightsService($integration))->backfillReachHistory();

        $this->assertDatabaseHas('audience_insights', ['client_id' => $client->id, 'snapshot_date' => $realDate1, 'reach' => 15]);
        $this->assertDatabaseHas('audience_insights', ['client_id' => $client->id, 'snapshot_date' => $realDate2, 'reach' => 25]);
        // TIDAK ADA tanggal DI ANTARA yang tidak disebut provider (mis. hari
        // ke-75) yang ikut ter-fabricate.
        $this->assertDatabaseMissing('audience_insights', ['client_id' => $client->id, 'snapshot_date' => now()->subDays(75)->toDateString()]);
    }
}
