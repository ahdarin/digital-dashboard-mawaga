<?php

namespace Tests\Feature;

use App\Models\AiStrategyInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Regression untuk 2 authorization leak baru yang ditemukan saat white-box
 * re-audit (Phase L) - sama kelas bug dengan KI-09, belum tercatat di audit
 * awal (docs/USER_MANUAL_SOURCE_OF_TRUTH.md):
 *
 * 1. AnalyticsController::aiStrategyHistory() - client_id dari query string
 *    tidak dicek AssignedClient, role ter-scope bisa baca riwayat AI
 *    Strategy client manapun.
 * 2. AudienceController::importCsv() - client_id dari form tidak dicek
 *    AssignedClient, role ter-scope bisa MENULIS data audiens client manapun.
 */
class PhaseLAuthorizationLeaksTest extends TestCase
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

    private function smoAssignedTo(Client $client): User
    {
        $role = Role::create(['name' => 'SMO Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $smo = User::factory()->create(['status' => 'active']);
        $smo->roles()->attach($role->id);
        $smo->assignedClients()->attach($client->id);

        return $smo;
    }

    public function test_scoped_role_cannot_view_ai_strategy_history_of_a_client_outside_their_roster(): void
    {
        $ownClient = $this->client();
        $otherClient = $this->client();
        AiStrategyInsight::create([
            'client_id' => $otherClient->id,
            'generated_by' => User::factory()->create()->id,
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'summary' => 'Rahasia client lain',
            'action_items' => [],
        ]);
        $smo = $this->smoAssignedTo($ownClient);

        $response = $this->actingAs($smo)->get(route('analytics.ai-strategy.history', ['client_id' => $otherClient->id]));

        $response->assertSessionHasErrors('client_id');
    }

    public function test_scoped_role_can_view_ai_strategy_history_of_their_own_client(): void
    {
        $ownClient = $this->client();
        $smo = $this->smoAssignedTo($ownClient);

        $response = $this->actingAs($smo)->get(route('analytics.ai-strategy.history', ['client_id' => $ownClient->id]));

        $response->assertOk();
    }

    public function test_scoped_role_cannot_import_audience_csv_for_a_client_outside_their_roster(): void
    {
        Platform::firstOrCreate(['name' => 'Instagram']);
        $ownClient = $this->client();
        $otherClient = $this->client();
        $smo = $this->smoAssignedTo($ownClient);

        $content = "platform,snapshot_date,follower_count\nInstagram,2026-08-01,1000\n";
        $file = UploadedFile::fake()->createWithContent('audience.csv', $content);

        $response = $this->actingAs($smo)->post(route('audience.import'), [
            'client_id' => $otherClient->id,
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('client_id');
        $this->assertDatabaseMissing('audience_insights', ['client_id' => $otherClient->id]);
    }

    public function test_scoped_role_can_import_audience_csv_for_their_own_client(): void
    {
        Platform::firstOrCreate(['name' => 'Instagram']);
        $ownClient = $this->client();
        $smo = $this->smoAssignedTo($ownClient);

        $content = "platform,snapshot_date,follower_count\nInstagram,2026-08-01,1000\n";
        $file = UploadedFile::fake()->createWithContent('audience.csv', $content);

        $response = $this->actingAs($smo)->post(route('audience.import'), [
            'client_id' => $ownClient->id,
            'file' => $file,
        ]);

        $response->assertSessionDoesntHaveErrors('client_id');
        $this->assertDatabaseHas('audience_insights', ['client_id' => $ownClient->id, 'follower_count' => 1000]);
    }
}
