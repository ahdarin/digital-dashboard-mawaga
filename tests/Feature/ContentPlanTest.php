<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression untuk KI-01 (Tambah Konten ke Rencana), KI-02 (Jobdesk
 * Tambahan), dan KI-13 (Rencana Ditolak buntu) - lihat
 * docs/USER_MANUAL_SOURCE_OF_TRUTH.md Bagian 22.
 */
class ContentPlanTest extends TestCase
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
        $user->roles()->attach($role->id);

        if ($assignedTo) {
            $user->assignedClients()->attach($assignedTo->id);
        }

        return $user;
    }

    private function plan(Client $client, string $status = 'draft'): ContentPlan
    {
        return ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->month,
            'year' => now()->year,
            'status' => $status,
        ]);
    }

    // ===== KI-01: Tambah Konten ke Rencana =====

    public function test_add_content_item_succeeds_with_assigned_pic(): void
    {
        $client = $this->client();
        $manager = $this->userWithRole('content_plan', 'create', $client);
        $pic = $this->userWithRole('workflow', 'update', $client);
        $plan = $this->plan($client);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $response = $this->actingAs($manager)->post(route('content-plan.items.store', $plan), [
            'title' => 'Konten Test',
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'deadline_at' => now()->addDays(3)->format('Y-m-d H:i'),
            'pic_user_id' => $pic->id,
        ]);

        $response->assertRedirect(route('content-plan.show', $plan));
        $this->assertDatabaseHas('content_items', [
            'content_plan_id' => $plan->id,
            'title' => 'Konten Test',
        ]);
        $item = $plan->contentItems()->first();
        $this->assertSame('brief_ready', $item->workflow->current_status);
        $this->assertSame($pic->id, $item->workflow->current_pic_id);
        $this->assertDatabaseHas('content_item_assignments', [
            'content_item_id' => $item->id,
            'user_id' => $pic->id,
            'assignment_role' => 'primary',
        ]);
    }

    public function test_add_content_item_rejects_pic_not_assigned_to_client(): void
    {
        $client = $this->client();
        $otherClient = $this->client();
        $manager = $this->userWithRole('content_plan', 'create', $client);
        $picElsewhere = $this->userWithRole('workflow', 'update', $otherClient);
        $plan = $this->plan($client);

        $response = $this->actingAs($manager)->post(route('content-plan.items.store', $plan), [
            'title' => 'Konten Test',
            'deadline_at' => now()->addDays(3)->format('Y-m-d H:i'),
            'pic_user_id' => $picElsewhere->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('content_items', ['title' => 'Konten Test']);
    }

    // ===== KI-02: Jobdesk Tambahan =====

    public function test_quick_create_urgent_creates_plan_and_notifies_pic(): void
    {
        $client = $this->client();
        $manager = $this->userWithRole('content_plan', 'create', $client);
        $pic = $this->userWithRole('workflow', 'update', $client);

        $this->assertDatabaseMissing('content_plans', [
            'client_id' => $client->id, 'month' => now()->month, 'year' => now()->year,
        ]);

        $response = $this->actingAs($manager)->post(route('content-items.quick-urgent'), [
            'client_id' => $client->id,
            'title' => 'Liputan mendadak',
            'deadline_at' => now()->addDay()->format('Y-m-d H:i'),
            'pic_id' => $pic->id,
        ]);

        $response->assertRedirect();
        $plan = ContentPlan::where('client_id', $client->id)
            ->where('month', now()->month)->where('year', now()->year)->first();
        $this->assertNotNull($plan, 'Plan bulan berjalan harus otomatis dibuat.');

        $item = $plan->contentItems()->first();
        $this->assertNotNull($item);
        $this->assertTrue((bool) $item->is_urgent);
        $this->assertSame($pic->id, $item->workflow->current_pic_id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pic->id,
            'type' => 'task',
        ]);
    }

    public function test_quick_create_urgent_works_without_pic(): void
    {
        $client = $this->client();
        $manager = $this->userWithRole('content_plan', 'create', $client);

        $response = $this->actingAs($manager)->post(route('content-items.quick-urgent'), [
            'client_id' => $client->id,
            'title' => 'Liputan tanpa PIC',
            'deadline_at' => now()->addDay()->format('Y-m-d H:i'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('content_items', ['title' => 'Liputan tanpa PIC', 'is_urgent' => true]);
    }

    // ===== KI-13: Rencana Ditolak buntu =====

    public function test_rejected_plan_can_be_reopened_fixed_and_resubmitted_to_approved(): void
    {
        $client = $this->client();
        $manager = $this->userWithRole('content_plan', 'approve', $client);
        $manager->roles->first()->permissions()->attach(
            Permission::firstOrCreate(['module' => 'content_plan', 'action' => 'create'])->id
        );
        $plan = $this->plan($client, 'pending');

        $reject = $this->actingAs($manager)->patch(route('content-plan.reject', $plan), [
            'rejection_note' => 'Target kuota belum sesuai paket.',
        ]);
        $reject->assertRedirect();
        $this->assertSame('rejected', $plan->fresh()->status);

        $reopen = $this->actingAs($manager)->patch(route('content-plan.reopen', $plan));
        $reopen->assertRedirect();
        $this->assertSame('draft', $plan->fresh()->status);

        $submit = $this->actingAs($manager)->patch(route('content-plan.submit', $plan));
        $submit->assertRedirect();
        $this->assertSame('pending', $plan->fresh()->status);

        $approve = $this->actingAs($manager)->patch(route('content-plan.approve', $plan));
        $approve->assertRedirect();
        $this->assertSame('approved', $plan->fresh()->status);

        // Riwayat lengkap tetap tersimpan, termasuk catatan penolakan lama.
        // orderBy('id') dipakai buat urutan (bukan changed_at) - keempat aksi
        // di test ini bisa jatuh di detik yang sama (kolom changed_at cuma
        // presisi detik), jadi id auto-increment satu-satunya yang reliably
        // preserve urutan insersi di sini.
        $logs = $plan->fresh()->statusLogs()->reorder('id')->get();
        $this->assertSame(['rejected', 'draft', 'pending', 'approved'], $logs->pluck('to_status')->all());
        $this->assertSame('Target kuota belum sesuai paket.', $logs->firstWhere('to_status', 'rejected')->notes);
    }

    public function test_reject_requires_a_note(): void
    {
        $client = $this->client();
        $manager = $this->userWithRole('content_plan', 'approve', $client);
        $plan = $this->plan($client, 'pending');

        $response = $this->actingAs($manager)->patch(route('content-plan.reject', $plan), []);

        $response->assertSessionHasErrors('rejection_note');
        $this->assertSame('pending', $plan->fresh()->status);
    }

    public function test_cannot_reopen_a_plan_that_is_not_rejected(): void
    {
        $client = $this->client();
        $manager = $this->userWithRole('content_plan', 'create', $client);
        $plan = $this->plan($client, 'draft');

        $response = $this->actingAs($manager)->patch(route('content-plan.reopen', $plan));

        $response->assertStatus(422);
    }
}
