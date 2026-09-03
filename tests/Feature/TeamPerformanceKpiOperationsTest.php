<?php

namespace Tests\Feature;

use App\Jobs\RecalculateMonthlyKpi;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentPublication;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMonthlyKpiResult;
use App\Services\TeamPerformanceKpiCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pemicu otomatis (job + trigger halaman) dan pemastian fitur Team
 * Performance existing (Kehadiran, route, permission) tidak berubah - lihat
 * docs/KPI_TEAM_PERFORMANCE.md.
 */
class TeamPerformanceKpiOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeamPerformancePermission(): User
    {
        $role = Role::create(['name' => 'CEO Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'team_performance', 'action' => 'view']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_opening_the_page_schedules_the_background_job_when_no_result_exists_yet(): void
    {
        Queue::fake();

        $user = $this->userWithTeamPerformancePermission();

        $response = $this->actingAs($user)->get(route('team-performance.index', ['tab' => 'performa']));

        $response->assertOk();
        Queue::assertPushed(RecalculateMonthlyKpi::class);
    }

    public function test_job_is_safe_to_run_repeatedly_without_creating_duplicate_rows(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $period = Carbon::create(2026, 4, 1);

        $item = ContentItem::factory()->create(['client_id' => $client->id]);
        ContentPublication::factory()->create(['content_item_id' => $item->id, 'published_at' => $period->copy()->addDays(3)]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id]);

        $job = new RecalculateMonthlyKpi($period->toDateString());
        $job->handle(app(TeamPerformanceKpiCalculator::class));
        $job->handle(app(TeamPerformanceKpiCalculator::class));

        $this->assertSame(
            1,
            UserMonthlyKpiResult::where('user_id', $user->id)->where('period_start', $period->toDateString())->count()
        );
    }

    public function test_attendance_tab_route_and_permission_still_work(): void
    {
        $user = $this->userWithTeamPerformancePermission();

        $this->actingAs($user)
            ->get(route('team-performance.index', ['tab' => 'kehadiran']))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('team-performance.index', ['tab' => 'performa']))
            ->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->get(route('team-performance.index'))
            ->assertForbidden();
    }

    public function test_member_kpi_shows_up_on_their_profile_page_for_a_viewer_with_permission(): void
    {
        $viewer = $this->userWithTeamPerformancePermission();
        $member = User::factory()->create();

        $this->actingAs($viewer)
            ->get(route('profile.show', $member))
            ->assertOk()
            ->assertSee('KPI Bulan Ini');
    }

    public function test_profile_owner_can_see_their_own_kpi_without_team_performance_permission(): void
    {
        $member = User::factory()->create(['status' => 'active']);

        $this->actingAs($member)
            ->get(route('profile.show', $member))
            ->assertOk()
            ->assertSee('KPI Bulan Ini');
    }

    public function test_viewer_without_team_performance_permission_cannot_see_someone_elses_kpi(): void
    {
        $viewer = User::factory()->create(['status' => 'active']);
        $member = User::factory()->create();

        $this->actingAs($viewer)
            ->get(route('profile.show', $member))
            ->assertOk()
            ->assertDontSee('KPI Bulan Ini');
    }
}
