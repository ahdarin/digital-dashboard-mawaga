<?php

namespace Tests\Feature;

use App\Models\AiStrategyInsight;
use App\Models\AnalyticsSyncLog;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentFormat;
use App\Models\ContentItem;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPlan;
use App\Models\ContentPublication;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\DelayRiskScore;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\DelayRiskAccuracyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression untuk enam temuan audit pasca-documentation-freeze
 * (docs/POST_FREEZE_AUDIT_REPORT.md). Satu test = satu temuan, masing-masing
 * gagal kalau perbaikannya dicabut.
 */
class PostFreezeAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name = 'Klien Audit'): Client
    {
        return Client::create([
            'client_category_id' => ClientCategory::firstOrCreate(['name' => 'UMKM'])->id,
            'name' => $name.' '.uniqid(),
            'status' => 'active',
        ]);
    }

    /** @param array<int, array{0: string, 1: string}> $permissions */
    private function userWith(array $permissions, ?Client $assignedTo = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::create(['name' => 'Role Audit '.uniqid()]);

        foreach ($permissions as [$module, $action]) {
            $role->permissions()->attach(Permission::firstOrCreate(['module' => $module, 'action' => $action])->id);
        }

        $user->roles()->attach($role->id);

        if ($assignedTo) {
            $user->assignedClients()->attach($assignedTo->id);
        }

        return $user;
    }

    // =================================================================
    // Temuan 1 - analisis AI Strategy bulan berjalan tidak boleh hilang
    // begitu tanggal berganti.
    // =================================================================

    public function test_current_month_ai_strategy_stays_visible_on_a_later_day(): void
    {
        $client = $this->client();
        $smo = $this->userWith([['analytics', 'view']], $client);

        // Digenerate awal bulan: period_end = hari itu, BUKAN hari ini.
        $insight = AiStrategyInsight::create([
            'client_id' => $client->id,
            'platform_id' => null,
            'generated_by' => $smo->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->startOfMonth()->toDateString(),
            'performance_data' => [],
            'summary' => 'Ringkasan analisis bulan berjalan.',
            'action_items' => ['Pertahankan porsi konten edukasi.'],
            'status' => 'completed',
        ]);

        $response = $this->actingAs($smo)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $this->assertSame(
            $insight->id,
            $response->viewData('latestAiInsight')?->id,
            'Analisis bulan berjalan harus tetap tampil walau period_end-nya tanggal yang lebih awal di bulan yang sama.'
        );
    }

    public function test_ai_strategy_from_another_month_still_does_not_leak(): void
    {
        $client = $this->client();
        $smo = $this->userWith([['analytics', 'view']], $client);

        AiStrategyInsight::create([
            'client_id' => $client->id,
            'platform_id' => null,
            'generated_by' => $smo->id,
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'performance_data' => [],
            'summary' => 'Analisis bulan lalu.',
            'action_items' => [],
            'status' => 'completed',
        ]);

        $response = $this->actingAs($smo)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $this->assertNull(
            $response->viewData('latestAiInsight'),
            'Analisis bulan lalu tidak boleh muncul saat filter menunjuk bulan berjalan.'
        );
    }

    // =================================================================
    // Temuan 2 - hapus klien yang punya riwayat performa.
    // =================================================================

    public function test_deleting_client_with_analytics_history_pauses_instead_of_crashing(): void
    {
        $client = $this->client();
        $manager = $this->userWith([['client', 'manage']], $client);

        AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'source_type' => 'csv_import',
            'status' => 'success',
            'imported_by' => $manager->id,
            'records_imported' => 3,
        ]);

        $this->assertSame(0, $client->contentItems()->count());

        $response = $this->actingAs($manager)->delete(route('client-management.destroy', $client));

        $response->assertRedirect(route('client-management.index'));
        $this->assertSame('paused', $client->fresh()->status);
    }

    public function test_client_without_any_history_is_still_deleted(): void
    {
        $client = $this->client();
        $manager = $this->userWith([['client', 'manage']], $client);

        $this->actingAs($manager)->delete(route('client-management.destroy', $client));

        $this->assertNull(Client::find($client->id));
    }

    // =================================================================
    // Temuan 3 - menu Kelola Klien tidak boleh muncul untuk role yang
    // pasti kena 403 di halamannya.
    // =================================================================

    public function test_client_management_menu_hidden_for_roles_that_cannot_open_it(): void
    {
        $client = $this->client();
        // 'client,view' saja - persis kondisi SMO/Copywriter/Content Creator/
        // Graphic Designer.
        $viewer = $this->userWith([['workflow', 'view'], ['client', 'view']], $client);

        $this->actingAs($viewer)->get(route('client-management.index'))->assertForbidden();

        $this->actingAs($viewer)->get(route('profile.me'))
            ->assertOk()
            ->assertDontSee(route('client-management.index'));
    }

    public function test_client_management_menu_visible_for_roles_that_can_open_it(): void
    {
        $client = $this->client();
        $manager = $this->userWith([['workflow', 'view'], ['client', 'view'], ['client', 'manage']], $client);

        $this->actingAs($manager)->get(route('client-management.index'))->assertOk();

        $this->actingAs($manager)->get(route('profile.me'))
            ->assertOk()
            ->assertSee(route('client-management.index'));
    }

    // =================================================================
    // Temuan 4 - guard hapus Platform harus mencakup tabel baru.
    // =================================================================

    public function test_platform_used_only_by_metric_snapshot_cannot_be_deleted(): void
    {
        $client = $this->client();
        $manager = $this->userWith([['master_data', 'manage']], $client);
        $platform = Platform::create(['name' => 'Platform Audit '.uniqid()]);

        ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'content_item_id' => null,
            'snapshot_date' => now()->toDateString(),
            'views' => 120,
        ]);

        $this->actingAs($manager)
            ->delete(route('master-data.destroy', ['type' => 'platform', 'id' => $platform->id]))
            ->assertSessionHas('error');

        $this->assertNotNull(Platform::find($platform->id));
    }

    public function test_platform_used_only_by_ai_strategy_cannot_be_deleted(): void
    {
        $client = $this->client();
        $manager = $this->userWith([['master_data', 'manage']], $client);
        $platform = Platform::create(['name' => 'Platform Audit '.uniqid()]);

        AiStrategyInsight::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'generated_by' => $manager->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'performance_data' => [],
            'summary' => '-',
            'action_items' => [],
            'status' => 'completed',
        ]);

        $this->actingAs($manager)
            ->delete(route('master-data.destroy', ['type' => 'platform', 'id' => $platform->id]))
            ->assertSessionHas('error');

        $this->assertNotNull(Platform::find($platform->id));
    }

    public function test_unused_platform_is_still_deletable(): void
    {
        $manager = $this->userWith([['master_data', 'manage']]);
        $platform = Platform::create(['name' => 'Platform Audit '.uniqid()]);

        $this->actingAs($manager)
            ->delete(route('master-data.destroy', ['type' => 'platform', 'id' => $platform->id]))
            ->assertSessionHas('status');

        $this->assertNull(Platform::find($platform->id));
    }

    // =================================================================
    // Temuan 5 - rencana konten ganda untuk bulan yang sama.
    // =================================================================

    public function test_duplicate_content_plan_for_same_month_is_rejected(): void
    {
        $client = $this->client();
        $creator = $this->userWith([['content_plan', 'create']], $client);

        ClientPackage::create([
            'client_id' => $client->id,
            'package_name_snapshot' => 'Paket Audit',
            'monthly_content_quota' => 2,
            'monthly_design_quota' => 1,
            'start_date' => now(),
            'status' => 'active',
        ]);

        $payload = ['client_id' => $client->id, 'month' => now()->month, 'year' => now()->year];

        $this->actingAs($creator)->post(route('content-plan.store'), $payload);
        $this->assertSame(1, ContentPlan::where('client_id', $client->id)->count());
        $this->assertSame(3, ContentItem::where('client_id', $client->id)->count());

        $this->actingAs($creator)->post(route('content-plan.store'), $payload)
            ->assertSessionHasErrors('client_id', null, 'createContentPlan');

        $this->assertSame(1, ContentPlan::where('client_id', $client->id)->count(), 'Rencana kedua untuk bulan yang sama tidak boleh dibuat.');
        $this->assertSame(3, ContentItem::where('client_id', $client->id)->count(), 'Slot kuota tidak boleh digenerate dua kali.');
    }

    // =================================================================
    // Temuan 6 - evaluasi Delay Risk memakai tanggal target tayang.
    // =================================================================

    public function test_delay_risk_accuracy_uses_upload_deadline_when_available(): void
    {
        $client = $this->client();

        // Tayang PERSIS pada tanggal target upload, tapi 2 hari setelah
        // deadline pengerjaan - itu jadwal normal, bukan keterlambatan.
        $item = $this->uploadedItem(
            $client,
            deadlineAt: now()->subDays(12),
            uploadDeadlineAt: now()->subDays(10),
            uploadedAt: now()->subDays(10),
        );

        DelayRiskScore::create([
            'content_item_id' => $item->id,
            'risk_score' => 80,
            'risk_level' => 'high',
            'top_factor' => 'Uji regresi',
            'features_snapshot' => [],
        ])->forceFill(['created_at' => now()->subDays(13)])->save();

        $result = app(DelayRiskAccuracyService::class)->calculate();

        $this->assertSame(1, $result['breakdown']['high']['total']);
        $this->assertSame(0, $result['breakdown']['high']['late'], 'Tayang tepat pada tanggal target upload tidak boleh dihitung terlambat.');
    }

    public function test_delay_risk_accuracy_still_flags_content_published_past_its_upload_target(): void
    {
        $client = $this->client();

        $item = $this->uploadedItem(
            $client,
            deadlineAt: now()->subDays(12),
            uploadDeadlineAt: now()->subDays(10),
            uploadedAt: now()->subDays(6),
        );

        DelayRiskScore::create([
            'content_item_id' => $item->id,
            'risk_score' => 80,
            'risk_level' => 'high',
            'top_factor' => 'Uji regresi',
            'features_snapshot' => [],
        ])->forceFill(['created_at' => now()->subDays(13)])->save();

        $result = app(DelayRiskAccuracyService::class)->calculate();

        $this->assertSame(1, $result['breakdown']['high']['late']);
    }

    private function uploadedItem(Client $client, $deadlineAt, $uploadDeadlineAt, $uploadedAt): ContentItem
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->month,
            'year' => now()->year,
            'status' => 'approved',
        ]);

        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => ContentType::firstOrCreate(['name' => 'Video'])->id,
            'content_format_id' => ContentFormat::where('slug', 'video')->value('id'),
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'title' => 'Konten Audit '.uniqid(),
            'deadline_at' => $deadlineAt,
            'upload_deadline_at' => $uploadDeadlineAt,
            'is_posted' => true,
        ]);

        ContentWorkflow::withoutEvents(fn () => ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_status' => 'uploaded',
            'is_overdue' => false,
        ]));

        ContentStatusLog::create([
            'content_item_id' => $item->id,
            'from_status' => 'scheduled',
            'to_status' => 'uploaded',
            'changed_at' => $uploadedAt,
        ]);

        ContentPublication::create([
            'content_item_id' => $item->id,
            'platform_id' => $item->platform_id,
            'published_by' => $plan->created_by,
            'published_at' => $uploadedAt,
            'post_url' => 'https://example.com/posts/'.uniqid(),
        ]);

        return $item;
    }
}
