<?php

namespace Tests\Feature\Kpi;

use App\Jobs\RecalculateKpiPeriod;
use App\Kpi\Services\KpiRecalculationTrigger;
use App\Kpi\Support\KpiCalculationLock;
use App\Models\KpiCalculationRun;
use App\Models\KpiFormulaVersion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 4 - KpiRecalculationTrigger adalah SATU-SATUNYA titik pemicu
 * background job KPI, dipanggil dari titik-titik existing (assignment
 * berubah, workflow status berubah, revision, publication, analytics sync,
 * audience insight). Test ini fokus ke KONTRAK dispatch-nya (job yang benar,
 * periode yang benar, delay debounce, unik per periode) - BUKAN ke isi
 * kalkulasi (sudah dicakup KpiCalculationServiceTest).
 */
class KpiRecalculationTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_current_period_dispatches_recalculate_job_for_current_month(): void
    {
        Queue::fake();

        KpiRecalculationTrigger::scheduleCurrentPeriod();

        $now = Carbon::now('Asia/Jakarta');
        $expectedStart = $now->copy()->startOfMonth()->toDateString();
        $expectedEnd = $now->copy()->endOfMonth()->toDateString();

        Queue::assertPushed(
            RecalculateKpiPeriod::class,
            fn (RecalculateKpiPeriod $job) => $job->periodStart === $expectedStart && $job->periodEnd === $expectedEnd
        );
    }

    /** Debounce (Fase 4): job HARUS ShouldBeUnique per periode, supaya banyak event beruntun collapse jadi satu eksekusi (bukan job duplikat per event). */
    public function test_recalculate_kpi_period_job_is_unique_per_period(): void
    {
        $job = new RecalculateKpiPeriod('2026-06-01', '2026-06-30');

        $this->assertInstanceOf(ShouldBeUnique::class, $job, 'RecalculateKpiPeriod harus ShouldBeUnique - ini yang mencegah dispatch beruntun jadi job duplikat.');
        $this->assertSame('2026-06-01:2026-06-30', $job->uniqueId());

        $otherPeriodJob = new RecalculateKpiPeriod('2026-07-01', '2026-07-31');
        $this->assertNotSame($job->uniqueId(), $otherPeriodJob->uniqueId(), 'Periode berbeda harus jadi unique lock yang berbeda - tidak saling menghalangi.');
    }

    /** Queue::fake() tidak menjalankan job apapun (beda dari QUEUE_CONNECTION=sync di testing config yang jalan sinkron) - jadi sekadar men-schedule TIDAK BOLEH langsung menghasilkan KpiCalculationRun; run baru dibuat oleh job itu sendiri saat benar-benar dieksekusi. */
    public function test_scheduling_alone_never_synchronously_creates_a_calculation_run(): void
    {
        Queue::fake();

        KpiRecalculationTrigger::scheduleCurrentPeriod();

        Queue::assertPushed(RecalculateKpiPeriod::class);
        $this->assertSame(0, KpiCalculationRun::count(), 'Dispatch trigger TIDAK BOLEH langsung menghitung KPI secara sinkron - itu tugas job di background.');
    }

    /** Koreksi #6: command developer dan job background berbagi SATU lock eksekusi (periode+formula) - kalau command SEDANG memegang lock, job untuk periode+formula yang SAMA harus skip (bukan menghitung ganda bersamaan). */
    public function test_job_skips_when_command_already_holds_the_same_execution_lock(): void
    {
        $formula = KpiFormulaVersion::factory()->create(['effective_from' => '2020-01-01']);
        $periodStart = Carbon::parse('2026-06-01');
        $periodEnd = Carbon::parse('2026-06-30');

        // Simulasikan command developer SEDANG memegang lock eksekusi untuk
        // periode+formula ini (persis skenario "kpi:calculate sedang
        // berjalan pas job background juga mau jalan untuk periode yang sama").
        $lock = KpiCalculationLock::acquire($periodStart, $periodEnd, $formula->id);
        $lock->get();

        $job = new RecalculateKpiPeriod($periodStart->toDateString(), $periodEnd->toDateString());
        $job->handle(app(\App\Kpi\Services\KpiCalculationService::class));

        $this->assertSame(0, KpiCalculationRun::count(), 'Job HARUS skip (bukan membuat run baru) saat lock eksekusi periode+formula yang sama masih dipegang command developer.');

        $lock->release();
    }

    /** Koreksi lanjutan #3: satu tanggal historis dijadwalkan sebagai bulan kalender yang mencakupnya - dipakai titik trigger yang punya satu timestamp eksplisit (mis. published_at publication yang ditautkan manual). */
    public function test_schedule_for_date_dispatches_the_calendar_month_containing_that_date(): void
    {
        Queue::fake();

        KpiRecalculationTrigger::scheduleForDate(Carbon::parse('2026-03-17'));

        Queue::assertPushed(
            RecalculateKpiPeriod::class,
            fn (RecalculateKpiPeriod $job) => $job->periodStart === '2026-03-01' && $job->periodEnd === '2026-03-31'
        );
    }

    /** Koreksi lanjutan #3/#4: rentang yang mencakup BEBERAPA bulan kalender (mis. sync historis 90 hari) dijadwalkan sebagai dispatch TERPISAH untuk SETIAP bulan yang terdampak - bukan cuma bulan berjalan. */
    public function test_schedule_for_date_range_dispatches_every_calendar_month_covered(): void
    {
        Queue::fake();

        KpiRecalculationTrigger::scheduleForDateRange(Carbon::parse('2026-01-20'), Carbon::parse('2026-03-05'));

        Queue::assertPushed(RecalculateKpiPeriod::class, fn (RecalculateKpiPeriod $j) => $j->periodStart === '2026-01-01' && $j->periodEnd === '2026-01-31');
        Queue::assertPushed(RecalculateKpiPeriod::class, fn (RecalculateKpiPeriod $j) => $j->periodStart === '2026-02-01' && $j->periodEnd === '2026-02-28');
        Queue::assertPushed(RecalculateKpiPeriod::class, fn (RecalculateKpiPeriod $j) => $j->periodStart === '2026-03-01' && $j->periodEnd === '2026-03-31');
        Queue::assertPushed(RecalculateKpiPeriod::class, 3);
    }

    /** Rentang dalam SATU bulan kalender yang sama hanya dijadwalkan SEKALI, bukan dua dispatch untuk periode yang identik. */
    public function test_schedule_for_date_range_within_same_month_dispatches_once(): void
    {
        Queue::fake();

        KpiRecalculationTrigger::scheduleForDateRange(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-25'));

        Queue::assertPushed(RecalculateKpiPeriod::class, 1);
    }

    /** Koreksi lanjutan #3: PIC content item diganti - jadwalkan bulan berjalan DAN setiap bulan publication content item itu yang sudah ada (koreksi PIC konten yang sudah tayang bulan lalu harus menghitung ulang bulan itu juga). */
    public function test_schedule_for_content_item_covers_current_period_and_its_publication_months(): void
    {
        Queue::fake();

        $item = \App\Models\ContentItem::factory()->create();
        \App\Models\ContentPublication::factory()->create(['content_item_id' => $item->id, 'published_at' => '2026-02-10']);

        KpiRecalculationTrigger::scheduleForContentItem($item);

        $now = Carbon::now('Asia/Jakarta');
        Queue::assertPushed(RecalculateKpiPeriod::class, fn (RecalculateKpiPeriod $j) => $j->periodStart === $now->copy()->startOfMonth()->toDateString());
        Queue::assertPushed(RecalculateKpiPeriod::class, fn (RecalculateKpiPeriod $j) => $j->periodStart === '2026-02-01' && $j->periodEnd === '2026-02-28');
    }

    /** Koreksi lanjutan #3: membuka halaman Team Performance untuk PERIODE HISTORIS (bulan yang dipilih pengguna, bukan bulan berjalan) men-dispatch job untuk periode YANG DIPILIH itu, bukan diam-diam menghitung bulan sekarang. */
    public function test_opening_a_historical_period_on_team_performance_page_dispatches_that_period(): void
    {
        Queue::fake();

        $ceo = \App\Models\User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        (new \Database\Seeders\PermissionSeeder)->run();
        $ceo->roles()->attach(\App\Models\Role::where('name', \App\Enums\UserRole::CEO->value)->firstOrFail()->id);

        $this->actingAs($ceo)->get(route('team-performance.index', ['tab' => 'ringkasan', 'period_start' => '2026-01']));

        Queue::assertPushed(
            RecalculateKpiPeriod::class,
            fn (RecalculateKpiPeriod $j) => $j->periodStart === '2026-01-01' && $j->periodEnd === '2026-01-31'
        );
        Queue::assertNotPushed(
            RecalculateKpiPeriod::class,
            fn (RecalculateKpiPeriod $j) => $j->periodStart === Carbon::now('Asia/Jakarta')->startOfMonth()->toDateString()
        );
    }
}
