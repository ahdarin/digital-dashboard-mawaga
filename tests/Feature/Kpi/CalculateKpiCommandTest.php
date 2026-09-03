<?php

namespace Tests\Feature\Kpi;

use App\Console\Commands\CalculateKpi;
use App\Models\KpiCalculationRun;
use App\Models\KpiFormulaVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Fase 5/koreksi 2026-09-02 - command kpi:calculate: bikin run baru per
 * eksekusi (histori tidak ditimpa), self-bootstrap formula version kalau
 * belum ada satu pun (TIDAK PERNAH butuh seeder manual - KpiReferenceSeeder
 * sudah dihapus total), gagal jelas HANYA kalau --formula-version eksplisit
 * diberikan tapi tidak ditemukan, dan lock (dipakai bersama job background)
 * mencegah dua eksekusi bersamaan untuk periode+formula yang sama.
 */
class CalculateKpiCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Tidak ada satu pun KpiFormulaVersion tersimpan - command TETAP sukses (self-bootstrap default), TIDAK PERNAH menyuruh jalankan seeder. */
    public function test_command_self_bootstraps_default_formula_when_none_exists(): void
    {
        $this->assertSame(0, KpiFormulaVersion::count());

        $this->artisan('kpi:calculate', ['--month' => '2026-06'])
            ->assertSuccessful();

        $this->assertSame(1, KpiFormulaVersion::count(), 'Formula default harus dibuat otomatis - tidak ada langkah setup manual.');
        $this->assertSame(1, KpiCalculationRun::count());
    }

    /** --formula-version eksplisit yang TIDAK ADA di database tetap harus gagal jelas (beda dari "belum ada formula sama sekali", yang sekarang self-bootstrap). */
    public function test_command_fails_clearly_when_explicit_formula_version_not_found(): void
    {
        $this->artisan('kpi:calculate', ['--month' => '2026-06', '--formula-version' => 'tidak-ada'])
            ->assertFailed();

        $this->assertSame(0, KpiCalculationRun::count());
    }

    public function test_command_creates_a_new_completed_run(): void
    {
        KpiFormulaVersion::factory()->create(['effective_from' => '2026-01-01']);

        $this->artisan('kpi:calculate', ['--month' => '2026-06'])
            ->assertSuccessful();

        $run = KpiCalculationRun::first();
        $this->assertNotNull($run);
        $this->assertSame(KpiCalculationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('2026-06-01', $run->period_start->toDateString());
        $this->assertSame('2026-06-30', $run->period_end->toDateString());
    }

    public function test_running_twice_for_same_period_creates_two_separate_runs(): void
    {
        KpiFormulaVersion::factory()->create(['effective_from' => '2026-01-01']);

        $this->artisan('kpi:calculate', ['--month' => '2026-06'])->assertSuccessful();
        $this->artisan('kpi:calculate', ['--month' => '2026-06'])->assertSuccessful();

        $this->assertSame(2, KpiCalculationRun::count(), 'Recalculation tidak boleh menimpa run lama - harus jadi 2 baris histori.');
    }

    public function test_command_is_skipped_when_lock_is_already_held(): void
    {
        $formula = KpiFormulaVersion::factory()->create(['effective_from' => '2026-01-01']);

        $periodStart = Carbon::parse('2026-06-01');
        $periodEnd = Carbon::parse('2026-06-30');
        $lock = Cache::lock(CalculateKpi::cacheLockKey($periodStart, $periodEnd, $formula->id), 600);
        $lock->get();

        $this->artisan('kpi:calculate', ['--month' => '2026-06'])->assertSuccessful();

        // Lock masih dipegang -> command harus SKIP (bukan gagal, bukan
        // bikin run baru) supaya tidak dianggap error oleh scheduler.
        $this->assertSame(0, KpiCalculationRun::count());

        $lock->release();
    }
}
