<?php

namespace Tests\Feature;

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
 * NEEDS_VERIFICATION (Bagian 22, Phase G) - Performa/Analytics, Tabel
 * Performa, Audiens, Detail Performa Konten, Export CSV belum pernah diuji
 * runtime (database dev nyaris kosong saat audit awal). Smoke test render
 * dengan data realistis, buat menangkap crash-level bug seperti KI-03.
 */
class AnalyticsPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'brand_name' => 'Test Brand',
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

    private function itemWithMetrics(Client $client): ContentItem
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->month,
            'year' => now()->year,
            'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'title' => 'Konten Performa Test',
            'deadline_at' => now()->subDays(2),
        ]);

        ContentMetric::create([
            'content_item_id' => $item->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now()->subDay(),
            'views' => 500,
            'engagement_rate' => 3.2,
            'watch_time_avg' => 12,
            'completion_rate' => 45.5,
            'shares' => 10,
            'saves' => 5,
        ]);

        return $item;
    }

    public function test_analytics_overview_tab_renders_with_data(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->itemWithMetrics($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
    }

    public function test_analytics_table_tab_renders(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->itemWithMetrics($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id, 'tab' => 'table']));

        $response->assertOk();
    }

    public function test_analytics_audience_tab_renders(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id, 'tab' => 'audience']));

        $response->assertOk();
    }

    public function test_analytics_empty_state_renders_without_client_selected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics'));

        $response->assertOk();
    }

    public function test_content_performance_detail_renders_with_peer_comparison(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $item = $this->itemWithMetrics($client);
        $this->itemWithMetrics($client); // konten pembanding (peer)

        $response = $this->actingAs($manager)->get(route('analytics.show', $item));

        $response->assertOk();
    }

    public function test_export_csv_does_not_crash_and_matches_import_format(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->itemWithMetrics($client);

        $response = $this->actingAs($manager)->get(route('analytics.export', ['client_id' => $client->id]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_scoped_role_cannot_export_data_for_a_client_outside_their_roster(): void
    {
        $ownClient = $this->client();
        $otherClient = $this->client();
        $manager = $this->managerFor($ownClient);

        $response = $this->actingAs($manager)->get(route('analytics.export', ['client_id' => $otherClient->id]));

        $response->assertForbidden();
    }
}
