<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentMetric;
use App\Models\InstagramMediaSnapshot;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYSTEM CONSISTENCY PASS (Part L/M) - BUG DITEMUKAN & DIPERBAIKI: setiap
 * link "Hubungkan Konten"/"Hubungkan" di Analytics (Ringkasan & tab Konten,
 * desktop & mobile) HARDCODE ke route('publishing-tracker.instagram.unmatched',
 * ...) buat SEMUA baris, terlepas platform baris itu sendiri. Baris TikTok
 * yang belum ke-link jadi mengarah ke
 * /publishing-tracker/instagram/{tiktok_integration_id}/unmatched, yang
 * 404 (ContentPublicationController::unmatchedInstagram() abort_unless
 * platform integration itu Instagram). Fix: App\Models\Platform::
 * unmatchedTrackerRouteName() - SATU tempat pemetaan platform baris ->
 * nama route, dipakai konsisten di semua titik link generation.
 */
class PublishingTrackerPlatformRoutingTest extends TestCase
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
        $permissions = collect(['analytics,view', 'publishing,manage'])->map(function ($pair) {
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

    // ===== Unit: pemetaan platform -> route name =====

    public function test_instagram_platform_maps_to_instagram_tracker_route(): void
    {
        $this->assertSame(
            'publishing-tracker.instagram.unmatched',
            Platform::unmatchedTrackerRouteName('Instagram')
        );
    }

    public function test_tiktok_platform_maps_to_tiktok_tracker_route(): void
    {
        $this->assertSame(
            'publishing-tracker.tiktok.unmatched',
            Platform::unmatchedTrackerRouteName('TikTok')
        );
    }

    // ===== Integrasi: TikTok tidak pernah mengarah ke /instagram/ =====

    public function test_unmatched_tiktok_content_link_uses_tiktok_route_not_instagram(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);
        $integration = $this->tiktokIntegration($client);

        $video = TikTokVideoSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'tt-'.uniqid(),
            'match_status' => 'unmatched',
            'published_at' => now()->subDays(2),
            'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'tiktok_video_snapshot_id' => $video->id, 'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'tiktok_video_snapshot_id' => $video->id,
            'snapshot_date' => now()->subDays(1)->toDateString(), 'views' => 10,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'tiktok_video_snapshot_id' => $video->id,
            'snapshot_date' => now()->toDateString(), 'views' => 20,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        // BUG lama: route selalu publishing-tracker.instagram.unmatched
        // dengan $integration->id (TikTok) sebagai parameter - link ini
        // TIDAK BOLEH muncul sama sekali di halaman ini.
        $response->assertDontSee('/publishing-tracker/instagram/'.$integration->id, false);
        $response->assertSee(route('publishing-tracker.tiktok.unmatched', $integration), false);
    }

    public function test_unmatched_tiktok_tracker_route_itself_never_404s_via_generated_link(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);

        // Membuktikan endpoint TUJUAN link yang di-generate benar-benar
        // hidup (bukan cuma string URL yang "kelihatan benar") - baris ini
        // PERSIS apa yang di-generate Platform::unmatchedTrackerRouteName().
        $response = $this->actingAs($manager)->get(
            route(Platform::unmatchedTrackerRouteName('TikTok'), $integration)
        );

        $response->assertOk();
    }

    public function test_unmatched_instagram_content_link_still_uses_instagram_route(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);

        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched',
            'media_type' => 'IMAGE',
            'published_at' => now()->subDays(2),
            'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->subDays(1)->toDateString(), 'views' => 10,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->toDateString(), 'views' => 20,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        // Instagram existing behavior TIDAK regresi (Part L, "Instagram
        // existing behavior does not regress").
        $response->assertSee(route('publishing-tracker.instagram.unmatched', $integration), false);
    }

    public function test_all_platforms_view_routes_each_row_to_its_own_platform_tracker(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $igPlatform = Platform::firstOrCreate(['name' => 'Instagram']);
        $ttPlatform = Platform::firstOrCreate(['name' => 'TikTok']);
        $igIntegration = $this->instagramIntegration($client);
        $ttIntegration = $this->tiktokIntegration($client);

        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $igIntegration->id, 'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched', 'media_type' => 'IMAGE',
            'published_at' => now()->subDays(2), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $igPlatform->id,
            'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $igPlatform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->subDays(1)->toDateString(), 'views' => 5,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $igPlatform->id, 'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => now()->toDateString(), 'views' => 15,
        ]);

        $video = TikTokVideoSnapshot::create([
            'api_integration_id' => $ttIntegration->id, 'external_post_id' => 'tt-'.uniqid(),
            'match_status' => 'unmatched', 'published_at' => now()->subDays(2), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id,
            'tiktok_video_snapshot_id' => $video->id, 'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id, 'tiktok_video_snapshot_id' => $video->id,
            'snapshot_date' => now()->subDays(1)->toDateString(), 'views' => 7,
        ]);
        \App\Models\ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id, 'tiktok_video_snapshot_id' => $video->id,
            'snapshot_date' => now()->toDateString(), 'views' => 22,
        ]);

        // "All Platforms" - TIDAK ada platform_id filter sama sekali.
        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        $response->assertSee(route('publishing-tracker.instagram.unmatched', $igIntegration), false);
        $response->assertSee(route('publishing-tracker.tiktok.unmatched', $ttIntegration), false);
        // Silang platform TIDAK PERNAH terjadi - integration Instagram
        // tidak pernah muncul di route tiktok, begitu juga sebaliknya.
        $response->assertDontSee('/publishing-tracker/tiktok/'.$igIntegration->id, false);
        $response->assertDontSee('/publishing-tracker/instagram/'.$ttIntegration->id, false);
    }
}
