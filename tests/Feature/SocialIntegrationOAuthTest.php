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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KI-08 - verifikasi runtime integrasi Instagram & TikTok SEJAUH MUNGKIN
 * tanpa akun tester nyata (tidak tersedia di lingkungan ini - lihat
 * docs/USER_MANUAL_SOURCE_OF_TRUTH.md Bagian 22, KI-08). Yang benar-benar
 * butuh consent manusia di layar Instagram/TikTok asli (App Review, akun
 * tester terdaftar) TETAP EXTERNAL_BLOCKED dan tidak diuji di sini - tapi
 * seluruh jalur yang bisa dijangkau tanpa consent manusia itu (redirect
 * OAuth terbentuk benar, PKCE, state mismatch, penolakan user, kegagalan
 * token exchange, upsert ApiIntegration) diverifikasi lewat HTTP fake.
 */
class SocialIntegrationOAuthTest extends TestCase
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
        $permission = Permission::firstOrCreate(['module' => 'client', 'action' => 'manage']);
        $role->permissions()->attach($permission->id);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    // ===== Instagram =====

    public function test_instagram_connect_redirects_to_meta_authorize_url_with_state(): void
    {
        config(['services.instagram.client_id' => 'ig-client-id', 'services.instagram.client_secret' => 'ig-secret', 'services.instagram.redirect' => 'http://127.0.0.1:8000/client-management/instagram/callback']);
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('client-management.instagram.connect', $client));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize', $location);
        $this->assertStringContainsString('client_id=ig-client-id', $location);
        $this->assertStringContainsString('state=', $location);
        $this->assertNotNull(session('instagram_oauth_state'));
    }

    public function test_instagram_connect_shows_friendly_error_when_not_configured(): void
    {
        config(['services.instagram.client_id' => null, 'services.instagram.client_secret' => null]);
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('client-management.instagram.connect', $client));

        $response->assertRedirect();
        $response->assertSessionHas('import_error');
    }

    public function test_instagram_callback_handles_user_denial_gracefully(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->actingAs($manager)->withSession(['instagram_oauth_state' => 'abc', 'instagram_oauth_client_id' => $client->id]);

        $response = $this->actingAs($manager)->get(route('client-management.instagram.callback', ['error' => 'access_denied']));

        $response->assertRedirect(route('client-management.show', $client->id));
        $response->assertSessionHas('import_error');
    }

    public function test_instagram_callback_rejects_state_mismatch(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->withSession(['instagram_oauth_state' => 'correct-state', 'instagram_oauth_client_id' => $client->id]);

        $response = $this->actingAs($manager)->get(route('client-management.instagram.callback', ['code' => 'x', 'state' => 'wrong-state']));

        $response->assertRedirect(route('client-management.index'));
        $response->assertSessionHas('import_error');
        $this->assertDatabaseMissing('api_integrations', ['client_id' => $client->id]);
    }

    public function test_instagram_callback_success_creates_active_integration(): void
    {
        Platform::firstOrCreate(['name' => 'Instagram']);
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->withSession(['instagram_oauth_state' => 'good-state', 'instagram_oauth_client_id' => $client->id]);

        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 123], 200),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-lived', 'expires_in' => 5184000], 200),
            'graph.instagram.com/me*' => Http::response(['id' => '123', 'username' => 'test_ig_account'], 200),
            'graph.instagram.com/v*/me*' => Http::response(['id' => '123', 'username' => 'test_ig_account'], 200),
        ]);

        $response = $this->actingAs($manager)->get(route('client-management.instagram.callback', ['code' => 'auth-code', 'state' => 'good-state']));

        $response->assertRedirect(route('client-management.show', $client->id));
        $response->assertSessionHas('import_success');
        $this->assertDatabaseHas('api_integrations', [
            'client_id' => $client->id,
            'status' => 'active',
            'external_username' => 'test_ig_account',
        ]);
    }

    public function test_instagram_callback_token_exchange_failure_marks_integration_inactive_without_crashing(): void
    {
        Platform::firstOrCreate(['name' => 'Instagram']);
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->withSession(['instagram_oauth_state' => 'good-state', 'instagram_oauth_client_id' => $client->id]);

        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $response = $this->actingAs($manager)->get(route('client-management.instagram.callback', ['code' => 'bad-code', 'state' => 'good-state']));

        $response->assertRedirect(route('client-management.show', $client->id));
        $response->assertSessionHas('import_error');
        $this->assertDatabaseHas('api_integrations', ['client_id' => $client->id, 'status' => 'inactive']);
    }

    // ===== TikTok =====

    public function test_tiktok_connect_redirects_with_pkce_challenge(): void
    {
        config(['services.tiktok.client_key' => 'tt-client-key', 'services.tiktok.client_secret' => 'tt-secret', 'services.tiktok.redirect' => 'http://127.0.0.1:8000/client-management/tiktok/callback']);
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('client-management.tiktok.connect', $client));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.tiktok.com/v2/auth/authorize/', $location);
        $this->assertStringContainsString('client_key=tt-client-key', $location);
        $this->assertStringContainsString('code_challenge=', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertNotNull(session('tiktok_oauth_code_verifier'));
    }

    public function test_tiktok_callback_handles_user_denial_gracefully(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->withSession(['tiktok_oauth_state' => 'abc', 'tiktok_oauth_client_id' => $client->id]);

        $response = $this->actingAs($manager)->get(route('client-management.tiktok.callback', ['error' => 'access_denied']));

        $response->assertRedirect(route('client-management.show', $client->id));
        $response->assertSessionHas('import_error');
    }

    public function test_tiktok_callback_success_creates_active_integration_with_rotating_refresh_token(): void
    {
        Platform::firstOrCreate(['name' => 'TikTok']);
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->withSession(['tiktok_oauth_state' => 'good-state', 'tiktok_oauth_client_id' => $client->id, 'tiktok_oauth_code_verifier' => 'verifier']);

        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'access_token' => 'tt-access', 'expires_in' => 86400,
                'refresh_token' => 'tt-refresh', 'refresh_expires_in' => 31536000,
                'open_id' => 'open123', 'scope' => 'user.info.basic,video.list',
            ], 200),
            'open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => ['user' => ['open_id' => 'open123', 'display_name' => 'Test TikTok']],
            ], 200),
        ]);

        $response = $this->actingAs($manager)->get(route('client-management.tiktok.callback', ['code' => 'auth-code', 'state' => 'good-state']));

        $response->assertRedirect(route('client-management.show', $client->id));
        $response->assertSessionHas('import_success');
        $integration = ApiIntegration::where('client_id', $client->id)->first();
        $this->assertSame('active', $integration->status);
        $this->assertSame('tt-refresh', $integration->refresh_token);
        $this->assertNotNull($integration->refresh_token_expires_at);
    }

    public function test_tiktok_callback_token_exchange_failure_marks_integration_inactive_without_crashing(): void
    {
        Platform::firstOrCreate(['name' => 'TikTok']);
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->withSession(['tiktok_oauth_state' => 'good-state', 'tiktok_oauth_client_id' => $client->id, 'tiktok_oauth_code_verifier' => 'verifier']);

        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'error' => 'invalid_grant', 'error_description' => 'authorization code invalid',
            ], 400),
        ]);

        $response = $this->actingAs($manager)->get(route('client-management.tiktok.callback', ['code' => 'bad-code', 'state' => 'good-state']));

        $response->assertRedirect(route('client-management.show', $client->id));
        $response->assertSessionHas('import_error');
        $this->assertDatabaseHas('api_integrations', ['client_id' => $client->id, 'status' => 'inactive']);
    }
}
