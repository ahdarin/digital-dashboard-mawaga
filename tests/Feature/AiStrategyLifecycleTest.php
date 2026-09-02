<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NEEDS_VERIFICATION (Bagian 22, Phase G) - AI Strategy (generate, apply,
 * revert) belum pernah diuji runtime. Gemini API di-fake, tidak menyentuh
 * jaringan keluar.
 */
class AiStrategyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $client = Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);

        ClientPackage::create([
            'client_id' => $client->id,
            'package_name_snapshot' => 'Basic',
            'monthly_content_quota' => 8,
            'monthly_design_quota' => 4,
            'start_date' => now()->subMonths(2),
            'status' => 'active',
        ]);

        return $client;
    }

    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        // Phase 4.2 - AI Strategy generate/apply/revert MUTATING, butuh
        // analytics,manage (bukan cuma analytics,view) - sesuai profil
        // Manager asli setelah PermissionSeeder.php diupdate.
        $viewPermission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $managePermission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'manage']);
        $role->permissions()->attach([$viewPermission->id, $managePermission->id]);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    /** Metrik BULAN LALU - AiStrategyService::analysisPeriod() selalu bulan lalu, bukan bulan berjalan. */
    private function lastMonthMetric(Client $client): void
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->subMonthNoOverflow()->month,
            'year' => now()->subMonthNoOverflow()->year,
            'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'title' => 'Konten Bulan Lalu',
            'deadline_at' => now()->subMonthNoOverflow(),
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_status' => 'uploaded',
            'is_overdue' => false,
        ]);

        ContentMetric::create([
            'content_item_id' => $item->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2),
            'views' => 800,
            'engagement_rate' => 4.1,
        ]);
    }

    private function fakeGeminiStrategyResponse(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'summary' => 'Performa bulan lalu cukup baik, engagement rate stabil.',
                        'action_items' => ['Tingkatkan frekuensi posting Reels', 'Coba format carousel edukasi'],
                        'suggested_split' => [['label' => 'Education', 'value' => 60], ['label' => 'Entertainment', 'value' => 40]],
                        'top_pillars' => [['name' => 'Education', 'reasoning' => 'Engagement tertinggi bulan lalu']],
                        'content_ideas' => [
                            ['pillar' => 'Education', 'title' => 'Tips Hemat Ala UMKM', 'brief' => 'Konten edukasi singkat', 'type' => 'Video', 'platform' => 'Instagram'],
                        ],
                    ])]]]],
                ],
            ], 200),
        ]);
        config(['services.gemini.api_key' => 'fake-key-for-test']);
    }

    public function test_generate_apply_and_revert_ai_strategy_full_lifecycle(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->lastMonthMetric($client);
        $this->fakeGeminiStrategyResponse();

        $generate = $this->actingAs($manager)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);
        $generate->assertRedirect(route('analytics', ['client_id' => $client->id]));
        $generate->assertSessionHas('ai_success');

        $insight = \App\Models\AiStrategyInsight::where('client_id', $client->id)->latest()->firstOrFail();
        $this->assertSame('completed', $insight->status);
        $this->assertNotEmpty($insight->action_items);

        $history = $this->actingAs($manager)->get(route('analytics.ai-strategy.history', ['client_id' => $client->id]));
        $history->assertOk();

        $apply = $this->actingAs($manager)->post(route('analytics.ai-strategy.apply', $insight));
        $apply->assertRedirect();
        $this->assertNotNull($insight->fresh()->applied_at);
        $this->assertGreaterThan(0, ContentItem::where('ai_strategy_insight_id', $insight->id)->count());

        $revert = $this->actingAs($manager)->post(route('analytics.ai-strategy.revert', $insight));
        $revert->assertRedirect();
        $this->assertNull($insight->fresh()->applied_at);
        // ContentItem pakai SoftDeletes - revert() soft-delete, bukan hard
        // delete, jadi baris tetap ada di tabel (deleted_at terisi) tapi
        // tersingkir dari query default (withoutTrashed).
        $this->assertSame(0, ContentItem::where('ai_strategy_insight_id', $insight->id)->count());
        $this->assertGreaterThan(0, ContentItem::onlyTrashed()->where('ai_strategy_insight_id', $insight->id)->count());
    }

    public function test_generate_fails_gracefully_with_no_data_message_when_no_metrics_last_month(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->fakeGeminiStrategyResponse();

        $response = $this->actingAs($manager)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);

        $response->assertRedirect();
        $response->assertSessionHas('ai_error');
        $this->assertDatabaseMissing('ai_strategy_insights', ['client_id' => $client->id]);
    }

    // ===== Phase 4.2 Langkah 3: AI Strategy authorization =====

    /**
     * Profil permission SETARA role "Admin" asli (PermissionSeeder.php) -
     * analytics,view SAJA, TANPA analytics,manage. Role ini SENGAJA
     * didesain read-only.
     */
    private function viewOnlyStaffFor(Client $client): User
    {
        $role = Role::create(['name' => 'Admin Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($role->id);
        $staff->assignedClients()->attach($client->id);

        return $staff;
    }

    public function test_view_only_role_cannot_generate_ai_strategy(): void
    {
        $client = $this->client();
        $viewOnlyStaff = $this->viewOnlyStaffFor($client);
        $this->fakeGeminiStrategyResponse();

        $response = $this->actingAs($viewOnlyStaff)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('ai_strategy_insights', ['client_id' => $client->id]);
    }

    public function test_view_only_role_cannot_import_audience_csv(): void
    {
        $client = $this->client();
        $viewOnlyStaff = $this->viewOnlyStaffFor($client);

        $response = $this->actingAs($viewOnlyStaff)->post(route('audience.import'), ['client_id' => $client->id]);

        $response->assertForbidden();
    }

    public function test_view_only_role_can_still_read_ai_strategy_history(): void
    {
        $client = $this->client();
        $viewOnlyStaff = $this->viewOnlyStaffFor($client);

        $response = $this->actingAs($viewOnlyStaff)->get(route('analytics.ai-strategy.history', ['client_id' => $client->id]));

        $response->assertOk();
    }

    public function test_view_only_role_can_still_view_analytics_page(): void
    {
        $client = $this->client();
        $viewOnlyStaff = $this->viewOnlyStaffFor($client);

        $response = $this->actingAs($viewOnlyStaff)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
    }

    public function test_manager_role_with_analytics_manage_can_generate_ai_strategy(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->lastMonthMetric($client);
        $this->fakeGeminiStrategyResponse();

        $response = $this->actingAs($manager)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ai_strategy_insights', ['client_id' => $client->id]);
    }
}
