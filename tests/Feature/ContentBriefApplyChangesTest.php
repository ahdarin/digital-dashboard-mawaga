<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentWorkflow;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression - "Terapkan perubahan ini" dari Diskusi Brief AI dulu pernah
 * crash (TypeError: array_values() Argument #1 must be of type array,
 * string given) begitu perubahan yang diusulkan AI menyentuh field
 * "scenes" (array of object). Root cause: hidden input di
 * ai-brief-discussion.blade.php cuma bisa nyimpen string, jadi array
 * scenes ke-toString() jadi "[object Object],[object Object]" (bukan
 * JSON) sebelum sampai ke backend.
 */
class ContentBriefApplyChangesTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $role = Role::create(['name' => 'Copywriter '.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['module' => 'content_plan', 'action' => 'create'])->id);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function brief(): ContentBriefDraft
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $client = Client::create(['client_category_id' => $category->id, 'name' => 'Test Client '.uniqid(), 'status' => 'active']);
        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'title' => 'Konten Test', 'deadline_at' => now()->addDays(5),
        ]);
        ContentWorkflow::create(['content_item_id' => $item->id, 'current_status' => 'in_progress', 'is_overdue' => false]);

        return ContentBriefDraft::create([
            'content_item_id' => $item->id,
            'hook_title' => 'Hook Awal',
            'scenes' => [['label' => 'ADEGAN 1', 'visual' => 'Awal', 'talent_script' => 'Halo']],
            'status' => 'draft',
        ]);
    }

    public function test_applying_discussion_scenes_change_sent_as_json_string_does_not_crash(): void
    {
        $actor = $this->actor();
        $brief = $this->brief();
        $actor->assignedClients()->attach($brief->contentItem->client_id);

        // JS mengirim scenes sebagai JSON string lewat hidden input (browser
        // tidak bisa menyimpan array/object apa adanya) - simulasikan bentuk
        // itu persis, bukan array PHP asli.
        $newScenes = [
            ['label' => 'ADEGAN 1', 'visual' => 'Baru', 'talent_script' => 'Halo semua, ini tutorial!'],
            ['label' => 'ADEGAN 2', 'visual' => 'Lanjutan', 'talent_script' => 'Langkah kedua...'],
        ];

        $response = $this->actingAs($actor)->post(route('content-brief.apply', $brief), [
            'fields' => [
                'scenes' => json_encode($newScenes),
                'hook_title' => 'Hook Baru dari Diskusi',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $fresh = $brief->fresh();
        $this->assertSame('Hook Baru dari Diskusi', $fresh->hook_title);
        $this->assertCount(2, $fresh->scenes);
        $this->assertSame('Baru', $fresh->scenes[0]['visual']);
        $this->assertSame(2, $fresh->slide_count, 'slide_count harus ikut kehitung ulang dari jumlah scene baru.');
    }
}
