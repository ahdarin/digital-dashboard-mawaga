<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentWorkflow;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression untuk temuan Phase L (re-audit white-box) -
 * production-workflow.update-status (dipakai drag-and-drop kanban) tidak
 * dipasangi client.scope, padahal sibling-nya (content-items.transition,
 * dipakai Detail Konten) sudah - role ter-scope bisa memindahkan status
 * content item client MANAPUN, bukan cuma roster-nya. Bukan bagian dari
 * KI-01...KI-20 (bukan di audit awal), tapi sama kelas bug dengan KI-09/KI-10.
 */
class ProductionWorkflowScopeTest extends TestCase
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

    private function itemFor(Client $client): ContentItem
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->month,
            'year' => now()->year,
            'status' => 'draft',
        ]);

        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'title' => 'Konten Test '.uniqid(),
            'deadline_at' => now()->addDays(3),
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_status' => 'brief_ready',
            'is_overdue' => false,
        ]);

        return $item;
    }

    public function test_scoped_staff_cannot_move_status_of_content_item_outside_their_roster(): void
    {
        $ownClient = $this->client();
        $otherClient = $this->client();
        $itemOutsideRoster = $this->itemFor($otherClient);

        $role = Role::create(['name' => 'Content Creator Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'workflow', 'action' => 'update']);
        $role->permissions()->attach($permission->id);
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($role->id);
        $staff->assignedClients()->attach($ownClient->id);

        $response = $this->actingAs($staff)->patch(route('production-workflow.update-status', $itemOutsideRoster), [
            'to_status' => 'in_progress',
        ]);

        $response->assertForbidden();
        $this->assertSame('brief_ready', $itemOutsideRoster->workflow->fresh()->current_status);
    }

    public function test_scoped_staff_can_move_status_of_content_item_within_their_roster(): void
    {
        $ownClient = $this->client();
        $item = $this->itemFor($ownClient);

        $role = Role::create(['name' => 'Content Creator Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'workflow', 'action' => 'update']);
        $role->permissions()->attach($permission->id);
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($role->id);
        $staff->assignedClients()->attach($ownClient->id);

        $response = $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'in_progress',
        ]);

        $response->assertOk();
        $this->assertSame('in_progress', $item->workflow->fresh()->current_status);
    }
}
