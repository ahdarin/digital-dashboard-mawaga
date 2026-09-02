<?php

namespace Tests\Feature;

use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi Phase 1 item 1 - bug lama: AnalyticsController::buildAudienceTabData()
 * hardcode 'audienceSource' => AudienceInsight::SOURCE_API ('instagram_api')
 * begitu $hasApiData true, walau datanya sebenarnya tiktok_api - akibatnya
 * TikTok ke-render pakai UI Instagram (badge "Instagram API", Reach/Active
 * Hours/Demographics Instagram, teks "belum tersedia dari Instagram").
 */
class AudienceSourceTest extends TestCase
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

    public function test_tiktok_audience_source_is_never_instagram_api(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);

        AudienceInsight::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'source' => AudienceInsight::SOURCE_TIKTOK_API,
            'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(),
            'follower_count' => 204,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id, 'platform_id' => $platform->id,
        ]));

        $response->assertOk();
        $response->assertDontSee('Instagram API');
        $response->assertDontSee('Data belum tersedia dari Instagram');
    }

    public function test_tiktok_audience_shows_tiktok_badge_and_follower_count(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);

        AudienceInsight::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'source' => AudienceInsight::SOURCE_TIKTOK_API,
            'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(),
            'follower_count' => 204,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id, 'platform_id' => $platform->id,
        ]));

        $response->assertOk();
        $response->assertSee('TikTok API');
        $response->assertSee('204');
    }

    public function test_tiktok_audience_does_not_render_instagram_only_sections(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);

        AudienceInsight::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'source' => AudienceInsight::SOURCE_TIKTOK_API,
            'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(),
            'follower_count' => 204,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id, 'platform_id' => $platform->id,
        ]));

        $response->assertOk();
        $response->assertDontSee('Reach Akun');
        $response->assertDontSee('Tren Reach');
        $response->assertDontSee('Jam Aktif Audiens');
        $response->assertDontSee('Follower Demographics');
        $response->assertDontSee('Reached Audience');
        $response->assertDontSee('Engaged Audience');
        $response->assertSee('Insight audiens lanjutan tidak tersedia melalui TikTok API yang digunakan');
    }

    public function test_instagram_audience_still_renders_full_sections(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        AudienceInsight::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'source' => AudienceInsight::SOURCE_API,
            'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(),
            'follower_count' => 5000,
            'reach' => 1200,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id, 'platform_id' => $platform->id,
        ]));

        $response->assertOk();
        $response->assertSee('Instagram API');
        $response->assertSee('Reach Akun');
        $response->assertSee('Tren Reach');
        $response->assertSee('Jam Aktif Audiens');
        $response->assertDontSee('Insight audiens lanjutan tidak tersedia melalui TikTok API');
    }

    public function test_csv_fallback_still_works_when_no_api_data(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        AudienceInsight::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'source' => AudienceInsight::SOURCE_CSV,
            'demographic_type' => AudienceInsight::TYPE_GENERIC,
            'snapshot_date' => now()->toDateString(),
            'follower_count' => 8000,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id, 'platform_id' => $platform->id,
        ]));

        $response->assertOk();
        $response->assertSee('CSV Import');
        $response->assertSee('8,000');
    }

    public function test_all_platforms_selected_does_not_merge_demographics_and_asks_to_pick_one(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);

        AudienceInsight::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'source' => AudienceInsight::SOURCE_API, 'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(), 'follower_count' => 5000,
        ]);
        AudienceInsight::create([
            'client_id' => $client->id, 'platform_id' => $tiktok->id,
            'source' => AudienceInsight::SOURCE_TIKTOK_API, 'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(), 'follower_count' => 204,
        ]);

        // Tanpa platform_id sama sekali = "Semua Platform" di filter global.
        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        $response->assertSee('Pilih platform untuk melihat detail audiens');
        // Angka follower dua-duanya TIDAK boleh muncul tergabung di halaman
        // ini. Phase 4.4 (Langkah 7) - substring polos '204'/'5,000' rentan
        // false-positive collision dengan ID auto-increment lain di halaman
        // (client_id/platform_id/dst bisa jadi multi-digit besar di full
        // suite run, MySQL auto_increment tidak reset oleh transaction
        // rollback RefreshDatabase) - sama kelas flakiness yang sudah
        // diperbaiki di AnalyticsGlobalFilterTest (Phase 1). follower_count
        // di-render sebagai `<p ...>{{ number_format($lastCount) }}</p>`
        // (lihat _audience-section.blade.php) - anchor '>N<' memastikan
        // yang dicek benar konten tag angka itu sendiri, bukan substring
        // yang kebetulan nyangkut di atribut/URL/ID lain.
        $response->assertDontSee('>5,000<', false);
        $response->assertDontSee('>204<', false);
    }
}
