<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi Phase 1 item 2/3 - filter GLOBAL (Client/Period/Platform) HARUS
 * konsisten & preserved di ketiga tab Performa (Overview/Table/Audience).
 * Bug lama: tabHref cuma bawa tab+client_id (period/platform_id hilang),
 * Table punya dropdown platform lokal terpisah dari Audience, Overview
 * nggak punya filter platform sama sekali.
 */
class AnalyticsGlobalFilterTest extends TestCase
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

    /**
     * Path+query HTML-escaped saja (bukan full URL) - route() dipanggil
     * LANGSUNG di test (di luar request context) bisa resolve host beda
     * dari request context asli begitu test HTTP client jalan, jadi
     * membandingkan full URL rawan false negative. Path+query cukup buat
     * membuktikan tabHref bawa param yang benar.
     *
     * PASS 2 - tabHref sekarang bawa AnalyticsPeriod::toQueryParams()
     * (period_mode=... & month=.../date_from=../date_to=..), BUKAN lagi
     * period=N mentah (Langkah "NEW LINKS USE NEW MODEL") - $legacyDays di
     * sini murni buat MEMBANGUN expected query yang sama persis lewat
     * AnalyticsPeriodResolver::buildLegacyDays(), key order HARUS match
     * urutan array_merge() di tabHref() controller (tab, client_id,
     * platform_id, lalu period params).
     */
    private function tabHrefQuery(string $tab, Client $client, ?int $legacyDays = null, ?int $platformId = null): string
    {
        $periodParams = $legacyDays
            ? app(\App\Services\AnalyticsPeriodResolver::class)->buildLegacyDays($legacyDays)->toQueryParams()
            : [];

        $params = array_filter(array_merge([
            'tab' => $tab,
            'client_id' => $client->id,
            'platform_id' => $platformId,
        ], $periodParams));

        return e('/analytics?'.http_build_query($params));
    }

    private function metricFor(Client $client, Platform $platform, int $views): void
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->month,
            'year' => now()->year,
            'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);

        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'title' => 'Konten '.$platform->name.' '.uniqid(),
            'deadline_at' => now()->subDays(2),
        ]);

        ContentMetric::create([
            'content_item_id' => $item->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now()->subDay(),
            'views' => $views,
            'engagement_rate' => 3.2,
        ]);
    }

    // ===== Tab state preservation =====

    public function test_client_period_platform_survive_tab_switch(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $this->metricFor($client, $instagram, 100);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period' => 30, 'platform_id' => $instagram->id,
        ]));

        $response->assertOk();

        $response->assertSee($this->tabHrefQuery('table', $client, 30, $instagram->id), false);
        $response->assertSee($this->tabHrefQuery('audience', $client, 30, $instagram->id), false);
    }

    public function test_period_survives_switching_from_table_to_other_tabs(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id, 'period' => 90,
        ]));

        $response->assertOk();
        $response->assertSee($this->tabHrefQuery('overview', $client, 90), false);
    }

    public function test_table_local_filters_do_not_leak_into_other_tabs(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id, 'period' => 30,
            'search' => 'topsecretquery', 'content_type_id' => 3, 'sort' => 'title', 'dir' => 'asc', 'page' => 2,
        ]));

        $response->assertOk();
        $html = $response->getContent();

        // Overview/Audience href harus PERSIS tab/client_id/period saja.
        $response->assertSee($this->tabHrefQuery('overview', $client, 30), false);
        $response->assertSee($this->tabHrefQuery('audience', $client, 30), false);

        // Search box tab Table SENDIRI wajar echo balik value-nya - itu
        // BUKAN kebocoran. Yang dicek di sini: link tab Overview/Audience
        // itu sendiri (tag <a> lengkap) TIDAK mengandung param local-only.
        preg_match('/<a href="[^"]*tab=overview[^"]*"/', $html, $overviewAnchor);
        preg_match('/<a href="[^"]*tab=audience[^"]*"/', $html, $audienceAnchor);
        $this->assertNotEmpty($overviewAnchor);
        $this->assertNotEmpty($audienceAnchor);
        $this->assertStringNotContainsString('topsecretquery', $overviewAnchor[0]);
        $this->assertStringNotContainsString('content_type_id', $overviewAnchor[0]);
        $this->assertStringNotContainsString('topsecretquery', $audienceAnchor[0]);
        $this->assertStringNotContainsString('content_type_id', $audienceAnchor[0]);
    }

    // ===== Platform filter scoping =====

    public function test_overview_platform_filter_excludes_other_platform(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);
        $this->metricFor($client, $instagram, 1000);
        $this->metricFor($client, $tiktok, 5000);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'platform_id' => $instagram->id,
        ]));

        $response->assertOk();
        $response->assertSee('1,000');
        $response->assertDontSee('5,000');
    }

    public function test_table_global_platform_filter_excludes_other_platform_rows(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);
        $this->metricFor($client, $instagram, 111);
        $this->metricFor($client, $tiktok, 222);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id, 'platform_id' => $tiktok->id,
        ]));

        $response->assertOk();
        // '>222<'/'>111<' (bukan substring polos '222'/'111') - substring
        // polos rentan collision kebetulan dengan uniqid() hex string
        // (mis. "...e01119337" mengandung "111"), sudah diverifikasi
        // menyebabkan flaky test pada run tertentu.
        $response->assertSee('>222<', false);
        $response->assertDontSee('>111<', false);
    }

    public function test_table_no_longer_has_its_own_local_platform_dropdown(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        Platform::firstOrCreate(['name' => 'Instagram']);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        // Cuma 1 dropdown <select name="platform_id"> (yang global) - form
        // filter lokal tab Table boleh punya hidden input platform_id
        // (buat preserve nilai global saat submit search/tipe), itu bukan
        // dropdown duplicate.
        $count = substr_count($response->getContent(), '<select name="platform_id"');
        $this->assertSame(1, $count);
    }

    public function test_period_selector_now_visible_on_table_tab_too(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        // PASS 2 - selector period=7/30/90 diganti period_mode (Bulan
        // Kalender/Rentang Kustom, Langkah "PRIMARY PRODUCT CHANGE") -
        // intent regresi ini TETAP SAMA (selector filter periode GLOBAL
        // harus tampil di tab Table juga), cuma nama field-nya berubah.
        $response->assertSee('name="period_mode"', false);
    }

    // ===== Pre-Phase-2 correction: connected-but-no-data platforms =====

    public function test_connected_integration_with_zero_analytics_rows_still_appears_in_platform_filter(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);

        // Baru connect via OAuth, belum pernah sync sama sekali - TIDAK
        // ada ContentMetric ATAUPUN AudienceInsight buat platform ini.
        ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $tiktok->id,
            'integration_name' => 'TikTok API (OAuth)',
            'status' => 'active',
            'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        $response->assertSee('<option value="'.$tiktok->id.'"', false);
        $response->assertSee('>TikTok</option>', false);
    }
}
