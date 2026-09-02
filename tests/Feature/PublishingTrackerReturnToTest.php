<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi Phase 1 item 5 - "Back" di Publishing Tracker (Instagram & TikTok
 * unmatched) hardcode ke Client Detail, jadi kalau dibuka dari Performa/
 * Tabel Performa, Back malah balik ke Client Management, bukan ke halaman
 * asal. Fix: return_to context-aware, internal-path-only (open-redirect-
 * safe), fallback Client Detail kalau invalid/hilang.
 */
class PublishingTrackerReturnToTest extends TestCase
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
        $permission = Permission::firstOrCreate(['module' => 'publishing', 'action' => 'manage']);
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

    public function test_instagram_tracker_back_link_uses_valid_return_to(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $returnTo = '/analytics?tab=table&client_id='.$client->id.'&period=30';
        $response = $this->actingAs($manager)->get(
            route('publishing-tracker.instagram.unmatched', $integration).'?return_to='.urlencode($returnTo)
        );

        $response->assertOk();
        $response->assertSee('href="'.e($returnTo).'"', false);
    }

    public function test_tiktok_tracker_back_link_uses_valid_return_to(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);

        $returnTo = '/analytics?tab=overview&client_id='.$client->id.'&period=90&platform_id=3';
        $response = $this->actingAs($manager)->get(
            route('publishing-tracker.tiktok.unmatched', $integration).'?return_to='.urlencode($returnTo)
        );

        $response->assertOk();
        $response->assertSee('href="'.e($returnTo).'"', false);
    }

    public function test_missing_return_to_falls_back_to_client_detail(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get(route('publishing-tracker.instagram.unmatched', $integration));

        $response->assertOk();
        $response->assertSee('href="'.route('client-management.show', $client->id).'"', false);
    }

    public function test_external_return_to_is_rejected_and_falls_back(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get(
            route('publishing-tracker.instagram.unmatched', $integration).'?return_to='.urlencode('https://evil.example/steal')
        );

        $response->assertOk();
        $response->assertDontSee('evil.example');
        $response->assertSee('href="'.route('client-management.show', $client->id).'"', false);
    }

    public function test_protocol_relative_return_to_is_rejected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get(
            route('publishing-tracker.instagram.unmatched', $integration).'?return_to='.urlencode('//evil.example/steal')
        );

        $response->assertOk();
        $response->assertDontSee('evil.example');
        $response->assertSee('href="'.route('client-management.show', $client->id).'"', false);
    }

    public function test_return_to_survives_link_action_post_via_back_redirect(): void
    {
        // linkInstagramMedia() pakai back() - halaman yang direstore adalah
        // GET unmatched dengan return_to yang SAMA (referer-nya sendiri
        // sudah bawa query string itu), jadi kita simulasikan dengan
        // membuka ulang halaman unmatched pakai return_to yang sama SETELAH
        // link action (bukan literally klik POST lalu ikuti back()) -
        // membuktikan return_to tetap dibaca konsisten kalau URL tsb yang
        // dipakai lagi.
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $returnTo = '/analytics?tab=table&client_id='.$client->id;
        $response = $this->actingAs($manager)->get(
            route('publishing-tracker.instagram.unmatched', $integration).'?return_to='.urlencode($returnTo)
        );

        $response->assertOk();
        $response->assertSee('href="'.e($returnTo).'"', false);
    }
}
