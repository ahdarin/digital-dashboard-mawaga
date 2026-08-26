<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentWorkflow;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Final Pre-Merge Verification (Step 8) - role-by-role UI verification
 * lintas SEMUA 6 role internal, memakai `PermissionSeeder` SUNGGUHAN (bukan
 * role ad-hoc) supaya menguji definisi permission produksi yang benar-benar
 * dipakai, bukan asumsi. Mencakup: sidebar/direct-URL access (harus 403,
 * bukan bisa di-bypass ketik URL) dan client scope (Client A ter-assign,
 * Client B tidak - role ter-scope harus bisa lihat A, TIDAK bisa lihat/
 * modifikasi B lewat crafted request).
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new \Database\Seeders\PermissionSeeder)->run();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $role = Role::where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Matriks akses per Bagian 3 USER_MANUAL_SOURCE_OF_TRUTH.md. 200 = harus
     * bisa dibuka, 403 = harus ditolak walau URL diketik langsung (bukan
     * cuma disembunyikan dari sidebar).
     */
    public static function accessMatrixProvider(): array
    {
        $routes = [
            '/beranda', '/dashboard', '/analytics', '/content-plan',
            '/production-workflow', '/team-performance', '/user-management',
            '/client-management', '/report', '/settings',
        ];

        $expected = [
            'CEO' => ['/beranda' => 200, '/dashboard' => 200, '/analytics' => 200, '/content-plan' => 200, '/production-workflow' => 200, '/team-performance' => 200, '/user-management' => 200, '/client-management' => 200, '/report' => 200, '/settings' => 200],
            'Manager' => ['/beranda' => 200, '/dashboard' => 200, '/analytics' => 200, '/content-plan' => 200, '/production-workflow' => 200, '/team-performance' => 200, '/user-management' => 200, '/client-management' => 200, '/report' => 200, '/settings' => 200],
            'SMO' => ['/beranda' => 200, '/dashboard' => 200, '/analytics' => 200, '/content-plan' => 200, '/production-workflow' => 200, '/team-performance' => 403, '/user-management' => 403, '/client-management' => 403, '/report' => 200, '/settings' => 200],
            'Copywriter' => ['/beranda' => 200, '/dashboard' => 403, '/analytics' => 403, '/content-plan' => 200, '/production-workflow' => 200, '/team-performance' => 403, '/user-management' => 403, '/client-management' => 403, '/report' => 403, '/settings' => 403],
            'Content Creator' => ['/beranda' => 200, '/dashboard' => 403, '/analytics' => 403, '/content-plan' => 200, '/production-workflow' => 200, '/team-performance' => 403, '/user-management' => 403, '/client-management' => 403, '/report' => 403, '/settings' => 403],
            'Desain Grafis' => ['/beranda' => 200, '/dashboard' => 403, '/analytics' => 403, '/content-plan' => 200, '/production-workflow' => 200, '/team-performance' => 403, '/user-management' => 403, '/client-management' => 403, '/report' => 403, '/settings' => 403],
        ];

        $cases = [];
        foreach ($expected as $role => $perRoute) {
            foreach ($routes as $route) {
                $cases["{$role} {$route}"] = [$role, $route, $perRoute[$route]];
            }
        }

        return $cases;
    }

    #[DataProvider('accessMatrixProvider')]
    public function test_role_access_matrix(string $roleName, string $path, int $expectedStatus): void
    {
        $user = $this->userWithRole($roleName);

        $response = $this->actingAs($user)->get($path);

        $response->assertStatus($expectedStatus);
    }

    // ===== Client scope dengan crafted request (Step 8) =====

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
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'title' => 'Konten '.uniqid(), 'deadline_at' => now()->addDays(3),
        ]);
        ContentWorkflow::create(['content_item_id' => $item->id, 'current_status' => 'brief_ready', 'is_overdue' => false]);

        return $item;
    }

    public function test_scoped_role_sees_client_a_but_not_client_b_via_direct_url(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $staff = $this->userWithRole('Content Creator');
        $staff->assignedClients()->attach($clientA->id);

        $this->actingAs($staff)->get(route('client-management.show', $clientA))->assertOk();
        $this->actingAs($staff)->get(route('client-management.show', $clientB))->assertForbidden();
    }

    public function test_scoped_role_cannot_move_client_b_content_status_via_crafted_request(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $itemA = $this->itemFor($clientA);
        $itemB = $this->itemFor($clientB);
        $staff = $this->userWithRole('Content Creator');
        $staff->assignedClients()->attach($clientA->id);

        $this->actingAs($staff)->patch(route('content-items.transition', $itemA), ['to_status' => 'in_progress'])->assertRedirect();
        $this->assertSame('in_progress', $itemA->workflow->fresh()->current_status);

        $this->actingAs($staff)->patch(route('content-items.transition', $itemB), ['to_status' => 'in_progress'])->assertForbidden();
        $this->assertSame('brief_ready', $itemB->workflow->fresh()->current_status, 'Content item client B tidak boleh berubah sama sekali.');
    }

    public function test_scoped_role_cannot_view_client_b_content_plan_via_crafted_request(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        $planA = ContentPlan::create(['client_id' => $clientA->id, 'created_by' => User::factory()->create()->id, 'month' => now()->month, 'year' => now()->year, 'status' => 'draft']);
        $planB = ContentPlan::create(['client_id' => $clientB->id, 'created_by' => User::factory()->create()->id, 'month' => now()->month, 'year' => now()->year, 'status' => 'draft']);
        $staff = $this->userWithRole('Copywriter');
        $staff->assignedClients()->attach($clientA->id);

        $this->actingAs($staff)->get(route('content-plan.show', $planA))->assertOk();
        $this->actingAs($staff)->get(route('content-plan.show', $planB))->assertForbidden();
    }
}
