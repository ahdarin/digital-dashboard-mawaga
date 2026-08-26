<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression untuk KI-10 (Dashboard sama sekali tidak dibatasi per client) -
 * lihat docs/USER_MANUAL_SOURCE_OF_TRUTH.md Bagian 22.
 */
class DashboardScopeTest extends TestCase
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

    private function itemFor(Client $client): ContentItem
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
            'title' => 'Konten '.uniqid(),
            'deadline_at' => now()->addDays(3),
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_status' => 'in_progress',
            'is_overdue' => false,
        ]);

        return $item;
    }

    private function dashboardPermission(): Permission
    {
        return Permission::firstOrCreate(['module' => 'dashboard', 'action' => 'view']);
    }

    public function test_scoped_role_only_sees_content_from_assigned_clients(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $this->itemFor($clientA);
        $this->itemFor($clientA);
        $this->itemFor($clientB);

        $smoRole = Role::create(['name' => 'SMO Test '.uniqid()]);
        $smoRole->permissions()->attach($this->dashboardPermission()->id);
        $smo = User::factory()->create(['status' => 'active']);
        $smo->roles()->attach($smoRole->id);
        $smo->assignedClients()->attach($clientA->id);

        $response = $this->actingAs($smo)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            $contentStat = collect($stats)->firstWhere('label', 'Konten Bulan Ini');

            // 2 konten milik clientA (roster SMO) - client B (bukan roster)
            // TIDAK ikut terhitung.
            return $contentStat['value'] === '2';
        });
    }

    public function test_ceo_sees_content_across_all_clients(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $this->itemFor($clientA);
        $this->itemFor($clientB);

        $ceoRole = Role::firstOrCreate(['name' => UserRole::CEO->value]);
        $ceoRole->permissions()->attach($this->dashboardPermission()->id);
        $ceo = User::factory()->create(['status' => 'active']);
        $ceo->roles()->attach($ceoRole->id);

        $response = $this->actingAs($ceo)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            $contentStat = collect($stats)->firstWhere('label', 'Konten Bulan Ini');

            return $contentStat['value'] === '2';
        });
    }

    public function test_scoped_role_does_not_see_recent_items_from_unassigned_clients(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $this->itemFor($clientA);
        $itemB = $this->itemFor($clientB);

        $smoRole = Role::create(['name' => 'SMO Test '.uniqid()]);
        $smoRole->permissions()->attach($this->dashboardPermission()->id);
        $smo = User::factory()->create(['status' => 'active']);
        $smo->roles()->attach($smoRole->id);
        $smo->assignedClients()->attach($clientA->id);

        $response = $this->actingAs($smo)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('recentItems', function ($recentItems) use ($itemB) {
            return ! collect($recentItems)->pluck('title')->contains($itemB->title);
        });
    }
}
