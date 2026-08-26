<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Regression untuk KI-09 (Import CSV Performa tidak memeriksa client scope) -
 * lihat docs/USER_MANUAL_SOURCE_OF_TRUTH.md Bagian 22.
 */
class ImportPerformanceScopeTest extends TestCase
{
    use RefreshDatabase;

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

    private function smoAssignedTo(Client $client): User
    {
        $role = Role::create(['name' => 'SMO Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'settings', 'action' => 'manage']);
        $role->permissions()->attach($permission->id);
        $smo = User::factory()->create(['status' => 'active']);
        $smo->roles()->attach($role->id);
        $smo->assignedClients()->attach($client->id);

        return $smo;
    }

    private function csvFile(): UploadedFile
    {
        $content = "content_title,platform,metric_date,views,engagement_rate\nJudul,Instagram,2026-08-01,100,2.5\n";

        return UploadedFile::fake()->createWithContent('performa.csv', $content);
    }

    public function test_smo_cannot_import_performance_for_a_client_outside_their_roster(): void
    {
        $ownClient = $this->client();
        $otherClient = $this->client();
        $smo = $this->smoAssignedTo($ownClient);

        $response = $this->actingAs($smo)->post(route('settings.import-performance'), [
            'client_id' => $otherClient->id,
            'file' => $this->csvFile(),
        ]);

        $response->assertForbidden();
    }

    public function test_smo_can_import_performance_for_their_own_client(): void
    {
        $ownClient = $this->client();
        $smo = $this->smoAssignedTo($ownClient);

        $response = $this->actingAs($smo)->post(route('settings.import-performance'), [
            'client_id' => $ownClient->id,
            'file' => $this->csvFile(),
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('import_error');
    }

    public function test_import_page_only_lists_clients_in_smo_roster(): void
    {
        $ownClient = $this->client();
        $otherClient = $this->client();
        $smo = $this->smoAssignedTo($ownClient);

        $response = $this->actingAs($smo)->get(route('settings.import'));

        $response->assertOk();
        $response->assertViewHas('clientOptions', function ($clientOptions) use ($ownClient, $otherClient) {
            $ids = collect($clientOptions)->pluck('id');

            return $ids->contains($ownClient->id) && ! $ids->contains($otherClient->id);
        });
    }
}
