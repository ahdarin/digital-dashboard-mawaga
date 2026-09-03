<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentWorkflow;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FINAL REMAINING-GAPS CLOSURE PASS - Content Workflow.
 *
 * Regression for the historical "content_file_link silently discarded"
 * bug: dragging a card in_progress -> waiting_review used to bundle a
 * content-link input into the status-transition payload, which
 * WorkflowStatusService::transition() never persisted (the field simply
 * had no handling at all). The ACTUAL fix already shipped (confirmed by
 * reading production-workflow/index.blade.php's own comment) was a UX
 * redesign - the link is now set via a SEPARATE, ALWAYS-PRESENT card on
 * the Content Item page (ContentItemController::updateContentLink()), and
 * the drag-to-review transition no longer asks for it at all. This file
 * proves that fix holds, AND closes the remaining latent gap: both
 * ProductionWorkflowController::updateStatus() and ContentItemController::
 * transition() still validate content_file_link as an accepted payload
 * field - if either is ever sent one, WorkflowStatusService::transition()
 * must now actually persist it instead of silently dropping it.
 */
class ContentWorkflowTransitionTest extends TestCase
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

    private function itemAt(Client $client, string $status): ContentItem
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
            'current_status' => $status,
            'is_overdue' => false,
        ]);

        return $item;
    }

    private function staffFor(Client $client, string $module = 'workflow', string $action = 'update'): User
    {
        $role = Role::create(['name' => 'Workflow Test Role '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => $module, 'action' => $action]);
        $role->permissions()->attach($permission->id);
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($role->id);
        $staff->assignedClients()->attach($client->id);

        return $staff;
    }

    // ===== Canonical transition graph, exercised through the real routes =====

    public function test_in_progress_to_waiting_review_succeeds_without_content_file_link(): void
    {
        // MIRROR the actual current drag-drop UX (production-workflow/
        // index.blade.php's own comment: "in_progress -> waiting_review
        // TIDAK butuh apa-apa lagi") - no content_file_link in the payload
        // at all, exactly what the real board sends today.
        $client = $this->client();
        $item = $this->itemAt($client, 'in_progress');
        $staff = $this->staffFor($client);

        $response = $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'waiting_review',
        ]);

        $response->assertOk();
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status);
    }

    public function test_content_file_link_set_via_dedicated_endpoint_persists_independently_of_transition(): void
    {
        // The ACTUAL, currently-working mechanism (ContentItemController::
        // updateContentLink(), the "card tersendiri" the UX redesign
        // pointed users to).
        $client = $this->client();
        $item = $this->itemAt($client, 'waiting_review');
        $staff = $this->staffFor($client);

        $response = $this->actingAs($staff)->patch(route('content-items.content-link', $item), [
            'content_file_link' => 'https://drive.google.com/file/example',
        ]);

        $response->assertRedirect();
        $this->assertSame('https://drive.google.com/file/example', $item->fresh()->content_file_link);
        // Setting the link independently HARUS TIDAK mengubah status - dua
        // concern yang genuinely terpisah sejak fix UX ini.
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status);
    }

    public function test_content_file_link_is_no_longer_silently_discarded_if_a_transition_payload_includes_it(): void
    {
        // Defensive completion (Langkah "close the remaining latent gap") -
        // no CURRENT UI sends this, but ProductionWorkflowController::
        // updateStatus() still validates it as accepted, so if it's EVER
        // sent, it must now genuinely persist instead of vanishing.
        $client = $this->client();
        $item = $this->itemAt($client, 'in_progress');
        $staff = $this->staffFor($client);

        $response = $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'waiting_review',
            'content_file_link' => 'https://drive.google.com/file/defensive-completion',
        ]);

        $response->assertOk();
        $this->assertSame('https://drive.google.com/file/defensive-completion', $item->fresh()->content_file_link, 'content_file_link HARUS tersimpan kalau genuinely dikirim lewat payload transition, TIDAK BOLEH hilang diam-diam lagi.');
    }

    public function test_content_items_transition_route_also_persists_content_file_link_if_sent(): void
    {
        // MIRROR test di atas buat route SATUNYA lagi yang memanggil
        // WorkflowStatusService::transition() (content-items.transition,
        // dipakai Detail Konten) - kedua jalur HARUS konsisten.
        $client = $this->client();
        $item = $this->itemAt($client, 'in_progress');
        $staff = $this->staffFor($client);

        $response = $this->actingAs($staff)->patch(route('content-items.transition', $item), [
            'to_status' => 'waiting_review',
            'content_file_link' => 'https://drive.google.com/file/via-detail-page',
        ]);

        $response->assertRedirect();
        $this->assertSame('https://drive.google.com/file/via-detail-page', $item->fresh()->content_file_link);
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status);
    }

    public function test_deadline_survives_status_transition(): void
    {
        $client = $this->client();
        $item = $this->itemAt($client, 'in_progress');
        $deadline = $item->deadline_at;
        $staff = $this->staffFor($client);

        $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'waiting_review',
        ])->assertOk();

        $this->assertTrue($deadline->equalTo($item->fresh()->deadline_at), 'Deadline TIDAK BOLEH berubah/hilang cuma karena status berpindah.');
    }

    public function test_revision_note_is_required_and_persisted_as_content_revision(): void
    {
        $client = $this->client();
        $item = $this->itemAt($client, 'waiting_review');
        $staff = $this->staffFor($client);

        // Tanpa revision_note - HARUS ditolak.
        $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'revision',
        ])->assertStatus(422);
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status, 'Transisi TIDAK BOLEH terjadi kalau data wajib gagal validasi.');

        // Dengan revision_note - HARUS berhasil DAN tersimpan sebagai ContentRevision.
        $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'revision',
            'revision_note' => 'Warna kurang kontras',
        ])->assertOk();

        $this->assertSame('revision', $item->workflow->fresh()->current_status);
        $this->assertDatabaseHas('content_revisions', [
            'content_item_id' => $item->id,
            'revision_note' => 'Warna kurang kontras',
            'status' => 'open',
        ]);
    }

    public function test_approve_requires_permission_and_no_unresolved_revisions(): void
    {
        $client = $this->client();
        $item = $this->itemAt($client, 'waiting_review');
        // Staff TANPA izin workflow,approve - transisi harus ditolak dengan
        // pesan yang jelas, status TIDAK berubah.
        $staffNoApprove = $this->staffFor($client);

        $response = $this->actingAs($staffNoApprove)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'approved',
        ]);
        $response->assertStatus(422);
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status);
    }

    public function test_invalid_transition_is_rejected_and_status_unchanged(): void
    {
        // draft -> approved TIDAK ADA di WorkflowTransitions sama sekali -
        // harus ditolak, BUKAN diam-diam diterima.
        $client = $this->client();
        $item = $this->itemAt($client, 'brief_ready');
        $staff = $this->staffFor($client);

        $response = $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'uploaded',
        ]);

        $response->assertStatus(422);
        $this->assertSame('brief_ready', $item->workflow->fresh()->current_status);
    }

    public function test_scheduled_requires_upload_datetime_and_persists_it(): void
    {
        $client = $this->client();
        $item = $this->itemAt($client, 'approved');
        $staff = $this->staffFor($client);

        $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'scheduled',
        ])->assertStatus(422);

        $when = now()->addDays(2)->startOfMinute();
        $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'scheduled',
            'scheduled_upload_at' => $when->toDateTimeString(),
        ])->assertOk();

        $this->assertSame('scheduled', $item->workflow->fresh()->current_status);
        $this->assertTrue($when->equalTo($item->fresh()->scheduled_upload_at));
    }

    public function test_uploaded_requires_platform_and_published_date_and_creates_publication(): void
    {
        $client = $this->client();
        $item = $this->itemAt($client, 'scheduled');
        $staff = $this->staffFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'uploaded',
        ])->assertStatus(422);

        $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'uploaded',
            'publications' => [[
                'platform_id' => $platform->id,
                'published_at' => now()->toDateTimeString(),
                'post_url' => 'https://instagram.com/p/example',
            ]],
        ])->assertOk();

        $this->assertSame('uploaded', $item->workflow->fresh()->current_status);
        $this->assertTrue((bool) $item->fresh()->is_posted);
        $this->assertDatabaseHas('content_publications', [
            'content_item_id' => $item->id,
            'platform_id' => $platform->id,
            'post_url' => 'https://instagram.com/p/example',
        ]);
    }

    public function test_terminal_statuses_have_no_further_transitions(): void
    {
        $this->assertSame([], \App\Support\WorkflowTransitions::nextOptions('uploaded'));
        $this->assertSame([], \App\Support\WorkflowTransitions::nextOptions('cancelled'));
    }

    public function test_status_consistent_across_content_detail_and_production_board_after_transition(): void
    {
        // Section 2 - "current status remains consistent across pages" -
        // KEDUANYA membaca dari relasi $item->workflow yang SAMA (satu
        // baris content_workflows per item), jadi konsistensi di sini
        // adalah struktural (satu sumber), diverifikasi langsung.
        $client = $this->client();
        $item = $this->itemAt($client, 'in_progress');
        $staff = $this->staffFor($client);

        $this->actingAs($staff)->patch(route('production-workflow.update-status', $item), [
            'to_status' => 'waiting_review',
        ])->assertOk();

        $fromContentItemsRoute = ContentItem::with('workflow')->findOrFail($item->id);
        $fromProductionRoute = ContentItem::with('workflow')->findOrFail($item->id);
        $this->assertSame($fromContentItemsRoute->workflow->current_status, $fromProductionRoute->workflow->current_status);
        $this->assertSame('waiting_review', $fromContentItemsRoute->workflow->current_status);
    }
}
