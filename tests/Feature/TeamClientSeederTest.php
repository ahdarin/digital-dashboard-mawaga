<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TeamClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TeamClientSeeder menggantikan ContentPlannerSeeder/
 * ContentPlannerPrerequisiteSeeder untuk instalasi baru - hanya membawa
 * roster staf + Client asli dari data lama, TANPA histori Content Plan/
 * Content Item sama sekali.
 */
class TeamClientSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedTeamAndClients(): void
    {
        (new RoleSeeder)->run();
        (new MasterDataSeeder)->run();
        (new TeamClientSeeder)->run();
    }

    public function test_seeds_real_clients_without_any_content_plan_or_item(): void
    {
        $this->seedTeamAndClients();

        $this->assertSame(14, Client::count());
        $this->assertTrue(Client::where('name', 'Top Scorer Arena')->exists());

        $this->assertSame(0, \App\Models\ContentPlan::count());
        $this->assertSame(0, \App\Models\ContentItem::count());
    }

    public function test_seeds_team_roster_with_roles_and_client_assignments(): void
    {
        $this->seedTeamAndClients();

        $admin = User::where('email', 'ahdaalamin2506@gmail.com')->firstOrFail();
        $this->assertTrue($admin->roles->pluck('name')->contains('Admin'));

        $ceo = User::where('email', 'surdik2811@gmail.com')->firstOrFail();
        $this->assertTrue($ceo->roles->pluck('name')->contains('CEO'));
        $this->assertTrue($ceo->assignedClients->pluck('name')->contains('Top Scorer Arena'));

        $staff = User::where('email', 'hagisiraj123@gmail.com')->firstOrFail();
        $this->assertFalse((bool) $staff->login_enabled);
        $this->assertTrue($staff->assignedClients->pluck('name')->contains('Odamilk'));
    }

    public function test_excludes_testing_only_and_duplicate_accounts(): void
    {
        $this->seedTeamAndClients();

        $this->assertFalse(User::where('email', 'ghazifadhlullah31@gmail.com')->exists());
        $this->assertFalse(User::where('email', 'ahdarindang@gmail.com')->exists());
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        $this->seedTeamAndClients();
        (new TeamClientSeeder)->run();

        $this->assertSame(14, Client::count());
        $this->assertSame(1, User::where('email', 'surdik2811@gmail.com')->count());
    }
}
