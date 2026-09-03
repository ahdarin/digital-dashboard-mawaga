<?php

namespace Tests\Feature;

use App\Models\AiStrategyInsight;
use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
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
 * FINAL REMAINING-GAPS CLOSURE PASS - Section 6, "LIVE / TESTED IDOR
 * CROSS-CLIENT MATRIX". Deterministic HTTP-level tests: User A (scoped to
 * Client A only) attempts to reach Client B's resources by ID substitution
 * across the surfaces the directive names that were NOT already covered by
 * RoleAccessMatrixTest/DashboardScopeTest/ProductionWorkflowScopeTest
 * (client detail, content status, content plan). No exploitation beyond
 * asserting the HTTP response code/redirect - deterministic, non-destructive.
 */
class CrossClientIdorTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name = 'Client'): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => $name.' '.uniqid(),
            'status' => 'active',
        ]);
    }

    private function scopedUser(Client $ownClient, array $modulesActions): User
    {
        $role = Role::create(['name' => 'IDOR Test Role '.uniqid()]);
        foreach ($modulesActions as [$module, $action]) {
            $permission = Permission::firstOrCreate(['module' => $module, 'action' => $action]);
            $role->permissions()->attach($permission->id);
        }
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        $user->assignedClients()->attach($ownClient->id);

        return $user;
    }

    // ===== Client Detail =====

    public function test_scoped_user_cannot_view_another_clients_detail_page(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['client', 'view']]);

        $response = $this->actingAs($userA)->get(route('client-management.show', $clientB));

        $this->assertContains($response->status(), [403, 404]);
    }

    // ===== Analytics export =====

    public function test_scoped_user_cannot_export_another_clients_analytics(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['analytics', 'view']]);

        $response = $this->actingAs($userA)->get(route('analytics.export', ['client_id' => $clientB->id]));

        $this->assertSame(403, $response->status());
    }

    // ===== Analytics sync dispatch/status/retry (task_id belongs to another client's integration) =====

    public function test_scoped_user_cannot_dispatch_sync_for_another_clients_id(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['settings', 'manage']]);

        $response = $this->actingAs($userA)->postJson(route('analytics.sync'), ['client_id' => $clientB->id]);

        $this->assertSame(403, $response->status());
    }

    public function test_scoped_user_cannot_retry_a_sync_task_belonging_to_another_client(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['settings', 'manage']]);

        $integrationB = ApiIntegration::create([
            'client_id' => $clientB->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake',
        ]);
        $runB = AnalyticsSyncRun::create([
            'client_id' => $clientB->id, 'trigger' => 'manual',
            'initiated_by' => User::factory()->create()->id, 'status' => 'failed', 'started_at' => now(),
        ]);
        $taskB = AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $runB->id, 'api_integration_id' => $integrationB->id,
            'subjob' => 'instagram_content', 'status' => 'failed', 'finished_at' => now(),
        ]);

        $response = $this->actingAs($userA)->postJson(route('analytics.sync.retry-task'), ['task_id' => $taskB->id]);

        $this->assertSame(403, $response->status());
        $this->assertSame('failed', $taskB->fresh()->status, 'Task client lain TIDAK BOLEH ke-retry sama sekali.');
    }

    public function test_scoped_user_cannot_view_sync_status_of_another_client(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['analytics', 'view']]);

        $response = $this->actingAs($userA)->getJson(route('analytics.sync-status', ['client_id' => $clientB->id]));

        $this->assertSame(403, $response->status());
    }

    // ===== Publishing Tracker link (apiIntegration belongs to another client) =====

    public function test_scoped_user_cannot_view_unmatched_instagram_for_another_clients_integration(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['publishing', 'manage']]);

        $integrationB = ApiIntegration::create([
            'client_id' => $clientB->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake',
        ]);

        $response = $this->actingAs($userA)->get(route('publishing-tracker.instagram.unmatched', $integrationB));

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_scoped_user_cannot_link_media_for_another_clients_integration(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['publishing', 'manage']]);

        $integrationB = ApiIntegration::create([
            'client_id' => $clientB->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake',
        ]);

        $response = $this->actingAs($userA)->post(route('publishing-tracker.instagram.link', $integrationB), [
            'content_item_id' => 1, 'external_post_id' => 'x',
        ]);

        $this->assertContains($response->status(), [403, 404]);
    }

    // ===== AI Strategy endpoint (aiStrategyInsight belongs to another client) =====

    public function test_scoped_user_cannot_chat_with_another_clients_ai_strategy_insight(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['analytics', 'view']]);

        $insightB = AiStrategyInsight::create([
            'client_id' => $clientB->id,
            'generated_by' => User::factory()->create()->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'summary' => 'Ringkasan uji.',
            'action_items' => [],
            'status' => 'completed',
        ]);

        $response = $this->actingAs($userA)->postJson(route('analytics.ai-strategy.chat', $insightB), [
            'message' => 'halo',
        ]);

        $this->assertContains($response->status(), [403, 404]);
    }

    // ===== Content Plan (content-plan.show) =====

    public function test_scoped_user_cannot_view_another_clients_content_plan(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['content_plan', 'create']]);

        $planB = ContentPlan::create([
            'client_id' => $clientB->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);

        $response = $this->actingAs($userA)->get(route('content-plan.show', $planB));

        $this->assertContains($response->status(), [403, 404]);
    }

    // ===== Content item detail =====

    public function test_scoped_user_cannot_view_another_clients_content_item_detail(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $userA = $this->scopedUser($clientA, [['workflow', 'view']]);

        $planB = ContentPlan::create([
            'client_id' => $clientB->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $itemB = ContentItem::create([
            'content_plan_id' => $planB->id, 'client_id' => $clientB->id,
            'title' => 'Item B', 'deadline_at' => now()->addDays(3),
        ]);
        ContentWorkflow::create(['content_item_id' => $itemB->id, 'current_status' => 'brief_ready', 'is_overdue' => false]);

        $response = $this->actingAs($userA)->get(route('content-items.show', $itemB));

        $this->assertContains($response->status(), [403, 404]);
    }

    // ===== Client Portal - wrong/invalid token never reaches another client's data =====

    public function test_client_portal_invalid_token_returns_404_not_another_clients_data(): void
    {
        $clientB = $this->client('B');
        $clientB->update(['portal_token' => 'genuine-token-for-b', 'portal_access_enabled' => true]);

        $response = $this->get(route('client.portal.dashboard', 'not-a-real-token'));

        $response->assertNotFound();
    }

    public function test_client_portal_token_cannot_view_another_clients_content_item_via_id_substitution(): void
    {
        $clientA = $this->client('A');
        $clientB = $this->client('B');
        $clientA->update(['portal_token' => 'token-for-a', 'portal_access_enabled' => true]);

        $planB = ContentPlan::create([
            'client_id' => $clientB->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $itemB = ContentItem::create([
            'content_plan_id' => $planB->id, 'client_id' => $clientB->id,
            'title' => 'Item B', 'deadline_at' => now()->addDays(3),
        ]);
        ContentWorkflow::create(['content_item_id' => $itemB->id, 'current_status' => 'waiting_review', 'is_overdue' => false]);

        $response = $this->get(route('client.portal.approval.show', ['token' => 'token-for-a', 'contentItem' => $itemB->id]));

        $response->assertNotFound();
    }
}
