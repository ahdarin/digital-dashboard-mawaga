<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentWorkflow;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refactor arsitektur Client Portal - Client BUKAN User, akses portal lewat
 * permanent capability URL (Client::portal_token), tanpa Laravel Auth sama
 * sekali. Lihat app/Http/Middleware/ResolveClientPortal.php dan
 * app/Http/Controllers/Client/*.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $attrs = []): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create(array_merge([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'brand_name' => 'Test Brand',
            'status' => 'active',
        ], $attrs));
    }

    private function contentItemFor(Client $client, string $status = 'waiting_review'): ContentItem
    {
        $package = ClientPackage::create([
            'client_id' => $client->id,
            'package_name_snapshot' => 'Basic',
            'monthly_content_quota' => 10,
            'monthly_design_quota' => 10,
            'start_date' => now(),
            'status' => 'active',
        ]);

        $creator = $this->internalUser();

        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'client_package_id' => $package->id,
            'created_by' => $creator->id,
            'month' => now()->month,
            'year' => now()->year,
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
        ]);

        return $item;
    }

    private function internalUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attrs));
    }

    private function managerWithClientManage(): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'client', 'action' => 'manage']);
        $role->permissions()->attach($permission->id);

        $user = $this->internalUser();
        $user->roles()->attach($role->id);

        return $user;
    }

    // ===== A. Portal access =====

    public function test_valid_token_opens_portal_without_login(): void
    {
        $client = $this->client();

        $response = $this->get(route('client.portal.dashboard', $client->portal_token));

        $response->assertOk();
        $response->assertSee($client->brand_name);
    }

    public function test_invalid_token_returns_404(): void
    {
        $response = $this->get('/portal/'.str_repeat('a', 64));

        $response->assertNotFound();
    }

    public function test_disabled_portal_returns_404(): void
    {
        $client = $this->client(['portal_access_enabled' => false]);

        $response = $this->get(route('client.portal.dashboard', $client->portal_token));

        $response->assertNotFound();
    }

    public function test_permanent_token_is_reusable_and_does_not_expire(): void
    {
        $client = $this->client();

        // "Permanent" - dipakai berkali-kali, tidak ada mekanisme sekali-pakai
        // atau expiry sama sekali (beda dari MagicLoginToken lama yang dihapus).
        $this->get(route('client.portal.dashboard', $client->portal_token))->assertOk();
        $this->get(route('client.portal.dashboard', $client->portal_token))->assertOk();
        $this->travel(30)->days();
        $this->get(route('client.portal.dashboard', $client->portal_token))->assertOk();
    }

    public function test_portal_does_not_use_auth_middleware(): void
    {
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'client.portal.dashboard');

        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertContains('client.portal', $route->gatherMiddleware());
    }

    // ===== B. Client isolation =====

    public function test_token_a_cannot_see_client_b_content_in_approval(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $itemB = $this->contentItemFor($clientB);

        $response = $this->get(route('client.portal.approval.show', ['token' => $clientA->portal_token, 'contentItem' => $itemB->id]));

        $response->assertNotFound();
    }

    public function test_token_a_cannot_approve_client_b_content(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $itemB = $this->contentItemFor($clientB);

        $response = $this->post(route('client.portal.approval.approve', ['token' => $clientA->portal_token, 'contentItem' => $itemB->id]));

        $response->assertNotFound();
        $this->assertNull($itemB->workflow->fresh()->client_reviewed_at);
    }

    public function test_token_a_cannot_request_revision_for_client_b_content(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $itemB = $this->contentItemFor($clientB);

        $response = $this->post(route('client.portal.approval.request-revision', ['token' => $clientA->portal_token, 'contentItem' => $itemB->id]), [
            'revision_note' => 'mencoba revisi lintas client',
        ]);

        $response->assertNotFound();
        $this->assertSame('waiting_review', $itemB->workflow->fresh()->current_status);
    }

    // ===== C. Approval =====

    public function test_client_can_approve_waiting_review_content(): void
    {
        $client = $this->client();
        $item = $this->contentItemFor($client, 'waiting_review');

        $response = $this->post(route('client.portal.approval.approve', ['token' => $client->portal_token, 'contentItem' => $item->id]));

        $response->assertRedirect();
        $workflow = $item->workflow->fresh();
        $this->assertSame($client->id, $workflow->client_reviewed_by_client_id);
        $this->assertSame('approved', $workflow->client_review_result);
        $this->assertNotNull($workflow->client_reviewed_at);
    }

    public function test_approve_does_not_require_authenticated_user(): void
    {
        $this->assertGuest();

        $client = $this->client();
        $item = $this->contentItemFor($client, 'waiting_review');

        $this->post(route('client.portal.approval.approve', ['token' => $client->portal_token, 'contentItem' => $item->id]))
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_approve_triggers_internal_notification(): void
    {
        $manager = $this->internalUser();
        $role = Role::create(['name' => 'SMO Test '.uniqid()]);
        // notifyInternalCheckers() cari role bernama persis 'Manager' atau 'SMO'.
        $smoRole = Role::firstOrCreate(['name' => 'SMO']);
        $manager->roles()->attach($smoRole->id);

        $client = $this->client();
        $item = $this->contentItemFor($client, 'waiting_review');

        $this->post(route('client.portal.approval.approve', ['token' => $client->portal_token, 'contentItem' => $item->id]));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'type' => 'client_approved',
        ]);
    }

    // ===== D. Revision =====

    public function test_client_can_request_revision(): void
    {
        $client = $this->client();
        $item = $this->contentItemFor($client, 'waiting_review');

        $response = $this->post(route('client.portal.approval.request-revision', ['token' => $client->portal_token, 'contentItem' => $item->id]), [
            'revision_note' => 'Tolong ubah warna background.',
        ]);

        $response->assertRedirect();

        $revision = $item->revisions()->latest()->first();
        $this->assertSame($client->id, $revision->requested_by_client_id);
        $this->assertNull($revision->requested_by_user_id);
        $this->assertSame('Tolong ubah warna background.', $revision->revision_note);

        $statusLog = \App\Models\ContentStatusLog::where('content_item_id', $item->id)->latest()->first();
        $this->assertSame($client->id, $statusLog->changed_by_client_id);
        $this->assertNull($statusLog->changed_by_user_id);

        $this->assertSame('revision', $item->workflow->fresh()->current_status);
    }

    public function test_revision_requires_note(): void
    {
        $client = $this->client();
        $item = $this->contentItemFor($client, 'waiting_review');

        $response = $this->post(route('client.portal.approval.request-revision', ['token' => $client->portal_token, 'contentItem' => $item->id]), []);

        $response->assertSessionHasErrors('revision_note');
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status);
    }

    // ===== E. Portal management (internal) =====

    /**
     * Regresi urutan route: '/client-management/create' (literal) sempat
     * ketutup '/client-management/{client}' (wildcard, terdaftar duluan)
     * yang gagal implicit-bind Client dengan ID "create" -> 404. Lihat
     * catatan urutan di routes/web.php.
     */
    public function test_client_management_create_page_is_reachable(): void
    {
        $manager = $this->managerWithClientManage();

        $this->actingAs($manager)->get(route('client-management.create'))->assertOk();
    }

    public function test_manager_can_regenerate_portal_token(): void
    {
        $manager = $this->managerWithClientManage();
        $client = $this->client();
        $oldToken = $client->portal_token;

        $response = $this->actingAs($manager)->post(route('client-management.portal.regenerate', $client));

        $response->assertRedirect();
        $client->refresh();
        $this->assertNotSame($oldToken, $client->portal_token);
    }

    public function test_old_token_404s_after_regenerate(): void
    {
        $manager = $this->managerWithClientManage();
        $client = $this->client();
        $oldToken = $client->portal_token;

        $this->actingAs($manager)->post(route('client-management.portal.regenerate', $client));

        $this->get('/portal/'.$oldToken)->assertNotFound();
    }

    public function test_new_token_is_valid_after_regenerate(): void
    {
        $manager = $this->managerWithClientManage();
        $client = $this->client();

        $this->actingAs($manager)->post(route('client-management.portal.regenerate', $client));
        $client->refresh();

        $this->get(route('client.portal.dashboard', $client->portal_token))->assertOk();
    }

    public function test_disable_makes_token_unusable(): void
    {
        $manager = $this->managerWithClientManage();
        $client = $this->client();

        $this->actingAs($manager)->patch(route('client-management.portal.disable', $client));

        $this->get(route('client.portal.dashboard', $client->portal_token))->assertNotFound();
    }

    public function test_enable_reactivates_token(): void
    {
        $manager = $this->managerWithClientManage();
        $client = $this->client(['portal_access_enabled' => false]);

        $this->actingAs($manager)->patch(route('client-management.portal.enable', $client));

        $this->get(route('client.portal.dashboard', $client->portal_token))->assertOk();
    }

    public function test_user_without_permission_cannot_change_portal_link(): void
    {
        $staff = $this->internalUser();
        $client = $this->client();

        $this->actingAs($staff)->post(route('client-management.portal.regenerate', $client))->assertForbidden();
        $this->actingAs($staff)->patch(route('client-management.portal.disable', $client))->assertForbidden();
        $this->actingAs($staff)->patch(route('client-management.portal.enable', $client))->assertForbidden();
    }

    // ===== F. Internal authentication / architecture =====

    public function test_users_table_has_no_client_id_or_phone_number_column(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'client_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'phone_number'));
    }

    public function test_client_owner_role_does_not_exist(): void
    {
        $this->assertDatabaseMissing('roles', ['name' => 'Client Owner']);
        $this->assertDatabaseMissing('roles', ['name' => 'Client Member']);
    }

    public function test_client_new_client_does_not_create_a_user(): void
    {
        $manager = $this->managerWithClientManage();
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $usersBefore = User::count();

        $this->actingAs($manager)->post(route('client-management.store'), [
            'name' => 'Klien Baru Test',
            'brand_name' => 'Brand Baru',
            'client_category_id' => $category->id,
        ])->assertRedirect();

        $this->assertSame($usersBefore, User::count());
        $client = Client::where('name', 'Klien Baru Test')->firstOrFail();
        $this->assertNotEmpty($client->portal_token);
        $this->assertTrue($client->portal_access_enabled);
    }

    public function test_client_factory_created_client_has_portal_token(): void
    {
        $client = $this->client();

        $this->assertNotEmpty($client->portal_token);
        $this->assertSame(64, strlen($client->portal_token));
    }
}
