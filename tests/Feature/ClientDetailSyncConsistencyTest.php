<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\AnalyticsSyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SYSTEM CONSISTENCY PASS (Part AH-AM) - Client Detail (client-management.
 * show) adalah SURFACE TERAKHIR yang masih pakai implementasi sync sendiri
 * (form POST langsung ke SettingsController::syncInstagram()/
 * ClientManagementController::syncInstagramAudience(), BYPASS
 * AnalyticsSyncOrchestrator; TikTok pakai polling custom ke endpoint
 * client-management-only) - flagged eksplisit sebagai "remaining limitation"
 * di laporan pass sebelumnya. Sekarang direwire ke engine+presenter yang
 * SAMA dipakai Analytics & Settings.
 */
class ClientDetailSyncConsistencyTest extends TestCase
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
        $permissions = collect(['client,view', 'settings,manage', 'analytics,view'])->map(function ($pair) {
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

    public function test_client_detail_instagram_card_has_one_perbarui_data_action(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'id="ig-sync-button"'));
        $response->assertDontSee('Sinkronkan Analitik Konten');
        $response->assertDontSee('Sinkronkan Insight Audiens');
        $response->assertDontSee('id="ig-audience-sync-button"', false);
    }

    public function test_client_detail_tiktok_card_has_one_perbarui_data_action(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->tiktokIntegration($client);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'id="tt-sync-button"'));
    }

    public function test_client_detail_uses_shared_orchestrator_endpoints(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        $response->assertSee(trim(json_encode(route('analytics.sync')), '"'), false);
        $response->assertSee(trim(json_encode(route('analytics.sync-status')), '"'), false);
        $response->assertSee(asset('js/analytics-sync-panel.js'), false);
    }

    public function test_run_started_from_client_detail_visible_from_analytics_and_settings(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        Queue::fake();
        // Simulasi "user klik Perbarui Data di Client Detail" - JS-nya
        // memanggil endpoint analytics.sync yang SAMA PERSIS dipakai
        // Analytics/Settings.
        $dispatch = $this->actingAs($manager)->postJson(route('analytics.sync'), [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]);
        $dispatch->assertOk();

        // Analytics & Settings (view berbeda, endpoint status yang SAMA)
        // HARUS melihat task yang SAMA.
        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));
        $status->assertOk();
        $this->assertNotNull($status->json('progress.tasks.instagram_content'));
    }

    public function test_run_started_from_analytics_visible_from_client_detail(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);

        Queue::fake();
        app(AnalyticsSyncOrchestrator::class)->dispatch($client, $integration->platform_id, $manager->id);

        // Client Detail poll (via endpoint yang sama, panel TikTok-nya)
        // HARUS melihat run yang sama tanpa dispatch ulang.
        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));
        $status->assertOk();
        $task = $status->json('progress.tasks.tiktok_content');
        $this->assertNotNull($task);
        $this->assertSame('queued', $task['status']);
    }

    public function test_reload_from_client_detail_does_not_duplicate_dispatch(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        Queue::fake();
        $before = \App\Models\AnalyticsSyncRun::count();
        app(AnalyticsSyncOrchestrator::class)->dispatch($client, $integration->platform_id, $manager->id);
        $afterFirst = \App\Models\AnalyticsSyncRun::count();
        $this->assertGreaterThan($before, $afterFirst);

        // "Reload Client Detail" - GET show() beberapa kali TIDAK PERNAH
        // dispatch apapun (halaman itu sendiri tidak dispatch on load,
        // hanya JS panel yang poll GET read-only setelahnya).
        $this->actingAs($manager)->get(route('client-management.show', $client));
        $this->actingAs($manager)->get(route('client-management.show', $client));
        $this->assertSame($afterFirst, \App\Models\AnalyticsSyncRun::count());
    }

    public function test_client_detail_shows_reconnect_link_for_inactive_instagram(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $integration->update(['status' => 'inactive']);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        // Kartu inactive render lewat cabang @elseif ($instagramOauthConfigured)
        // existing (tombol "Hubungkan Instagram"/"Sambungkan Ulang Instagram") -
        // TIDAK direwire pass ini (cabang connected/live sync yang direwire),
        // link connect/reconnect TETAP ada.
        $response->assertSee(route('client-management.instagram.connect', $client), false);
    }

    public function test_client_detail_never_exposes_tokens(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client);
        $this->tiktokIntegration($client);

        $response = $this->actingAs($manager)->get(route('client-management.show', $client));

        $response->assertOk();
        $response->assertDontSee('fake-token');
        $response->assertDontSee('access_token');
    }
}
