<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
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
use Tests\TestCase;

/**
 * NEEDS_VERIFICATION (Bagian 22, Phase G) - "Laporan" belum pernah diuji
 * runtime. Smoke test render halaman index + generate PDF (progres & performa)
 * dengan data realistis, buat menangkap crash-level bug (undefined
 * variable/relasi kosong) di view Blade-nya, sama seperti KI-03.
 */
class ReportGenerationTest extends TestCase
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

    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'report', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    private function itemWithMetrics(Client $client): ContentItem
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->month,
            'year' => now()->year,
            'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'title' => 'Konten Performa Test',
            'deadline_at' => now()->subDays(2),
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
            'metric_date' => now()->subDay(),
            'views' => 500,
            'engagement_rate' => 3.2,
        ]);

        return $item;
    }

    public function test_report_index_page_renders(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('report.index'));

        $response->assertOk();
    }

    public function test_generate_progress_report_as_pdf_does_not_crash(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $item = $this->itemWithMetrics($client);

        $response = $this->actingAs($manager)->post(route('report.generate'), [
            'client_id' => $client->id,
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
            'format' => 'pdf',
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertDatabaseHas('generated_reports', [
            'client_id' => $client->id,
            'report_type' => 'monthly_summary',
        ]);
    }

    public function test_generate_performance_report_as_pdf_does_not_crash(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->itemWithMetrics($client);

        $response = $this->actingAs($manager)->post(route('report.generate-performance'), [
            'client_id' => $client->id,
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
            'format' => 'pdf',
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertDatabaseHas('generated_reports', [
            'client_id' => $client->id,
            'report_type' => 'performance_summary',
        ]);
    }
}
