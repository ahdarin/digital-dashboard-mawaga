<?php

namespace Tests\Feature\Kpi;

use App\Enums\UserRole;
use App\Jobs\RecalculateKpiPeriod;
use App\Kpi\Services\KpiCalculationService;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentType;
use App\Models\KpiCalculationRun;
use App\Models\KpiFormulaVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TeamPerformanceController: scope permission tetap terjaga, route detail
 * anggota ikut permission yang sama, halaman tetap render aman walau belum
 * ada KpiCalculationRun sama sekali (tidak boleh crash/500 - "sedang
 * disiapkan otomatis" bukan error, dan TIDAK ADA instruksi command manual
 * yang ditampilkan ke pengguna).
 */
class TeamPerformanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder)->run();
    }

    private function actingAsRole(string $roleName): User
    {
        $user = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $role = Role::where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_index_renders_without_error_when_no_calculation_run_exists(): void
    {
        // Queue::fake() - QUEUE_CONNECTION testing di-set 'sync' (lihat
        // phpunit.xml), jadi tanpa fake ini job RecalculateKpiPeriod yang
        // di-dispatch controller akan LANGSUNG jalan di request yang sama
        // dan bikin run beneran ada sebelum halaman dirender - membuat
        // test ini tidak pernah benar-benar menguji state "belum ada run
        // sama sekali" yang seharusnya muncul saat job masih antre di
        // background (production pakai queue worker async, bukan sync).
        Queue::fake();

        $ceo = $this->actingAsRole(UserRole::CEO->value);

        $response = $this->actingAs($ceo)->get(route('team-performance.index', ['tab' => 'ringkasan']));

        $response->assertOk();
        $response->assertSee('Data KPI sedang disiapkan otomatis', false);
        $response->assertDontSee('kpi:calculate');
        $response->assertDontSee('php artisan');
        $response->assertDontSee('docs/kpi');
        Queue::assertPushed(RecalculateKpiPeriod::class);
    }

    public function test_anggota_tab_renders_without_error_when_no_run_exists(): void
    {
        $ceo = $this->actingAsRole(UserRole::CEO->value);

        $response = $this->actingAs($ceo)->get(route('team-performance.index', ['tab' => 'anggota']));

        $response->assertOk();
    }

    public function test_content_creator_cannot_access_team_performance(): void
    {
        $creator = $this->actingAsRole(UserRole::ContentCreator->value);

        $response = $this->actingAs($creator)->get(route('team-performance.index'));

        $response->assertForbidden();
    }

    public function test_member_detail_route_requires_same_permission_as_index(): void
    {
        $creator = $this->actingAsRole(UserRole::ContentCreator->value);
        $target = User::factory()->create();

        $response = $this->actingAs($creator)->get(route('team-performance.show', $target));

        $response->assertForbidden();
    }

    public function test_member_detail_shows_kpi_results_once_a_run_exists(): void
    {
        $ceo = $this->actingAsRole(UserRole::CEO->value);

        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $item = ContentItem::factory()->create(['deadline_at' => now()->subDays(20), 'content_type_id' => $videoType->id]);

        $staff = $this->actingAsRole(UserRole::ContentCreator->value);
        ContentItemAssignment::factory()->create([
            'content_item_id' => $item->id, 'user_id' => $staff->id, 'assignment_role' => 'primary',
        ]);
        // Aktivitas produksi NYATA di periode ini (koreksi lanjutan #1) -
        // tanpa ini item dianggap tidak aktif periode ini.
        \App\Models\ContentStatusLog::factory()->create(['content_item_id' => $item->id]);

        $formula = KpiFormulaVersion::factory()->create();
        $run = KpiCalculationRun::create([
            'kpi_formula_version_id' => $formula->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => KpiCalculationRun::STATUS_PENDING,
        ]);
        app(KpiCalculationService::class)->calculate($run);

        $response = $this->actingAs($ceo)->get(route('team-performance.show', [
            'user' => $staff, 'period_start' => now()->format('Y-m'),
        ]));

        $response->assertOk();
        $response->assertSee($staff->name);
        $response->assertSee('Content Creator');
        $response->assertDontSee('docs/kpi');
    }

    /** UI tidak pernah menampilkan path dokumentasi developer, walau pada state "tidak ada aktivitas KPI sama sekali". */
    public function test_member_detail_empty_state_does_not_reference_developer_docs(): void
    {
        $ceo = $this->actingAsRole(UserRole::CEO->value);
        $staffWithNoActivity = $this->actingAsRole(UserRole::ContentCreator->value);

        $run = KpiCalculationRun::factory()->create();

        $response = $this->actingAs($ceo)->get(route('team-performance.show', [
            'user' => $staffWithNoActivity, 'period_start' => $run->period_start->format('Y-m'),
        ]));

        $response->assertOk();
        $response->assertSee('tidak punya aktivitas KPI', false);
        $response->assertDontSee('docs/kpi');
        $response->assertDontSee('RBAC');
    }

    /** Fase 4/6: run yang sudah ADA tapi stale (>30 menit) tetap ditampilkan (snapshot lama) SAMBIL kalkulasi baru di-dispatch di background - bukan diganti kosong/"sedang disiapkan". */
    public function test_stale_run_still_shows_previous_snapshot_while_recalculation_is_dispatched(): void
    {
        Queue::fake();

        $ceo = $this->actingAsRole(UserRole::CEO->value);
        $staff = $this->actingAsRole(UserRole::ContentCreator->value);
        $creatorRole = Role::where('name', UserRole::ContentCreator->value)->firstOrFail();

        $staleRun = KpiCalculationRun::factory()->create([
            'finished_at' => now()->subMinutes(45),
        ]);
        \App\Models\UserKpiResult::factory()->create([
            'kpi_calculation_run_id' => $staleRun->id,
            'user_id' => $staff->id,
            'role_id' => $creatorRole->id,
            'composite_score' => 82.0,
        ]);

        $response = $this->actingAs($ceo)->get(route('team-performance.index', ['tab' => 'anggota']));

        $response->assertOk();
        // Snapshot lama (run stale) tetap tampil - skor 82 harus tetap
        // terlihat, BUKAN halaman kosong/"sedang disiapkan".
        $response->assertSee('Nilai KPI: 82', false);
        $response->assertSee('Data sedang diperbarui otomatis di latar belakang.', false);
        Queue::assertPushed(RecalculateKpiPeriod::class);
    }

    /** Koreksi #13: baris dengan status Data Belum Cukup TIDAK PERNAH menampilkan angka composite (walau composite_score tetap tersimpan di DB untuk audit). */
    public function test_data_belum_cukup_row_never_displays_a_composite_number(): void
    {
        $ceo = $this->actingAsRole(UserRole::CEO->value);
        $staff = $this->actingAsRole(UserRole::ContentCreator->value);
        $creatorRole = Role::where('name', UserRole::ContentCreator->value)->firstOrFail();

        $run = KpiCalculationRun::factory()->create();
        \App\Models\UserKpiResult::factory()->create([
            'kpi_calculation_run_id' => $run->id,
            'user_id' => $staff->id,
            'role_id' => $creatorRole->id,
            // composite_score SENGAJA tetap diisi (disimpan untuk audit -
            // lihat docblock migration user_kpi_results) walau status
            // Data Belum Cukup - halaman TIDAK BOLEH menampilkannya.
            'composite_score' => 63.5,
            'status_label' => \App\Enums\KpiStatusLabel::DataBelumCukup,
            'coverage_status' => \App\Enums\CoverageStatus::Unavailable,
            'sample_size' => 1,
        ]);

        $response = $this->actingAs($ceo)->get(route('team-performance.index', ['tab' => 'anggota']));

        $response->assertOk();
        $response->assertDontSee('Nilai KPI: 63', false);
        $response->assertSee('Nilai KPI: Data belum cukup', false);
    }

    /** Koreksi lanjutan #4: filter klien menampilkan staf PRODUKSI (Content Creator/Copywriter/dst) yang terlibat pada klien itu - dulu client_id operasional selalu NULL sehingga filter klien tidak pernah menampilkan siapa pun selain leadership. */
    public function test_client_filter_shows_production_staff_involved_with_that_client(): void
    {
        $ceo = $this->actingAsRole(UserRole::CEO->value);
        $creator = $this->actingAsRole(UserRole::ContentCreator->value);
        $creatorRole = Role::where('name', UserRole::ContentCreator->value)->firstOrFail();
        $client = \App\Models\Client::factory()->create();

        $run = KpiCalculationRun::factory()->create();
        \App\Models\UserKpiResult::factory()->create([
            'kpi_calculation_run_id' => $run->id,
            'user_id' => $creator->id,
            'role_id' => $creatorRole->id,
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($ceo)->get(route('team-performance.index', [
            'tab' => 'anggota', 'client_id' => $client->id, 'period_start' => $run->period_start->format('Y-m'),
        ]));

        $response->assertOk();
        $response->assertSee($creator->name);
    }
}
