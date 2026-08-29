<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentPlan;
use App\Models\ContentWorkflow;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression untuk KI-03 (Detail Konten gagal dimuat begitu client punya
 * staf aktif ter-assign) dan KI-04 (Ganti Penanggung Jawab) - lihat
 * docs/USER_MANUAL_SOURCE_OF_TRUTH.md Bagian 22.
 */
class ContentItemDetailTest extends TestCase
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

    private function userWithRole(string $module, string $action, ?Client $assignedTo = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::create(['name' => 'Role Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => $module, 'action' => $action]);
        $role->permissions()->attach($permission->id);

        // Sama seperti role produksi nyata (Content Creator dst) - workflow,view
        // selalu menyertai workflow,update, jadi test pakai kombinasi yang sama.
        if ($module === 'workflow' && $action === 'update') {
            $viewPermission = Permission::firstOrCreate(['module' => 'workflow', 'action' => 'view']);
            $role->permissions()->attach($viewPermission->id);
        }

        $user->roles()->attach($role->id);

        if ($assignedTo) {
            $user->assignedClients()->attach($assignedTo->id);
        }

        return $user;
    }

    private function itemFor(Client $client, User $pic): ContentItem
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => $pic->id,
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
            'current_pic_id' => $pic->id,
            'current_status' => 'in_progress',
        ]);

        ContentItemAssignment::create([
            'content_item_id' => $item->id,
            'user_id' => $pic->id,
            'assignment_role' => 'primary',
        ]);

        return $item;
    }

    // ===== KI-03 =====

    public function test_detail_page_renders_when_client_has_at_least_one_active_assigned_staff(): void
    {
        $client = $this->client();
        $pic = $this->userWithRole('workflow', 'update', $client);
        $item = $this->itemFor($client, $pic);

        $response = $this->actingAs($pic)->get(route('content-items.show', $item));

        $response->assertOk();
        $response->assertSee($item->title);
    }

    public function test_detail_page_renders_with_multiple_staff_and_shows_active_task_counts(): void
    {
        $client = $this->client();
        $pic = $this->userWithRole('workflow', 'update', $client);
        $other = $this->userWithRole('workflow', 'update', $client);
        $item = $this->itemFor($client, $pic);

        $response = $this->actingAs($pic)->get(route('content-items.show', $item));

        $response->assertOk();
        $response->assertSee($other->name);
        $response->assertSee('task aktif');
    }

    // ===== KI-04 =====

    public function test_reassign_moves_pic_and_notifies_new_pic(): void
    {
        $client = $this->client();
        $manager = $this->userWithRole('workflow', 'update', $client);
        $oldPic = $this->userWithRole('workflow', 'update', $client);
        $newPic = $this->userWithRole('workflow', 'update', $client);
        $item = $this->itemFor($client, $oldPic);

        $response = $this->actingAs($manager)->patch(route('content-items.reassign', $item), [
            'pic_user_id' => $newPic->id,
        ]);

        $response->assertRedirect();
        $this->assertSame($newPic->id, $item->workflow->fresh()->current_pic_id);
        $this->assertSame($newPic->name, $item->fresh()->external_pic_name);
        $this->assertDatabaseHas('content_item_assignments', [
            'content_item_id' => $item->id,
            'user_id' => $newPic->id,
            'assignment_role' => 'primary',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $newPic->id,
            'type' => 'task',
        ]);
    }

    public function test_reassign_rejects_pic_not_assigned_to_client(): void
    {
        $client = $this->client();
        $otherClient = $this->client();
        $manager = $this->userWithRole('workflow', 'update', $client);
        $oldPic = $this->userWithRole('workflow', 'update', $client);
        $outsider = $this->userWithRole('workflow', 'update', $otherClient);
        $item = $this->itemFor($client, $oldPic);

        $response = $this->actingAs($manager)->patch(route('content-items.reassign', $item), [
            'pic_user_id' => $outsider->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame($oldPic->id, $item->workflow->fresh()->current_pic_id);
    }

    public function test_unauthorized_user_cannot_reassign(): void
    {
        $client = $this->client();
        $noPermission = $this->userWithRole('client', 'manage', $client);
        $oldPic = $this->userWithRole('workflow', 'update', $client);
        $newPic = $this->userWithRole('workflow', 'update', $client);
        $item = $this->itemFor($client, $oldPic);

        $response = $this->actingAs($noPermission)->patch(route('content-items.reassign', $item), [
            'pic_user_id' => $newPic->id,
        ]);

        $response->assertForbidden();
    }
}
