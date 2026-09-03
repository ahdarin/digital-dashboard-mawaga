<?php

namespace App\Console\Commands;

use App\Kpi\Services\KpiCalculationService;
use App\Kpi\Support\KpiCalculationLock;
use App\Models\KpiCalculationRun;
use App\Models\KpiFormulaVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fase 5 - kalkulasi KPI manual/terjadwal untuk satu periode bulanan. Ini
 * ALAT DEVELOPER (debugging lokal, backfill periode lama secara sengaja) -
 * BUKAN syarat pemakaian fitur (lihat docs/kpi/JOBS_AND_OPERATIONS.md).
 * Kalau command ini tidak pernah dijalankan sama sekali, KPI tetap
 * terhitung otomatis lewat job background (`RecalculateKpiPeriod`,
 * dipicu `KpiRecalculationTrigger` dari aktivitas existing).
 *
 * D+7/D+30 TIDAK butuh trigger job terpisah - itu properti UMUR publication
 * relatif terhadap saat kalkulasi berjalan (lihat ContentOutcomeScoringService::
 * computePublicationDelta()), bukan properti periode. Menjalankan command ini
 * berkali-kali untuk periode yang sama itu WAJAR dan aman (idempotent,
 * deterministic) - publication yang dulu provisional otomatis usable begitu
 * cukup umur di run berikutnya.
 *
 * Lock EKSEKUSI (`App\Kpi\Support\KpiCalculationLock`) dipakai BERSAMA
 * dengan job background `RecalculateKpiPeriod` - command manual dan job
 * otomatis untuk periode+formula yang SAMA tidak pernah menghitung
 * bersamaan. TIDAK PERNAH menimpa run lama - setiap eksekusi sukses selalu
 * bikin baris KpiCalculationRun baru (histori penuh, lihat model docblock).
 */
class CalculateKpi extends Command
{
    protected $signature = 'kpi:calculate {--month=} {--formula-version=}';

    protected $description = 'Hitung KPI (process/outcome/portfolio) untuk satu periode bulanan (Asia/Jakarta) - alat developer, bukan syarat pemakaian fitur';

    public function handle(KpiCalculationService $service): int
    {
        $month = $this->option('month')
            ? Carbon::parse($this->option('month').'-01', 'Asia/Jakarta')
            : Carbon::now('Asia/Jakarta');

        $periodStart = $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();

        if ($this->option('formula-version')) {
            $formulaVersion = KpiFormulaVersion::where('version', $this->option('formula-version'))->first();

            if (! $formulaVersion) {
                $this->error("Formula version '{$this->option('formula-version')}' tidak ditemukan.");

                return self::FAILURE;
            }
        } else {
            // Self-bootstrapping - TIDAK PERNAH gagal karena "belum ada
            // formula version" (dulu menyuruh jalankan KpiReferenceSeeder,
            // yang sudah dihapus total per koreksi produk).
            $formulaVersion = KpiFormulaVersion::resolveCurrent($periodEnd);
        }

        $lock = KpiCalculationLock::acquire($periodStart, $periodEnd, $formulaVersion->id);

        if (! $lock->get()) {
            $this->warn("Kalkulasi untuk periode {$periodStart->toDateString()} s/d {$periodEnd->toDateString()} (formula {$formulaVersion->version}) masih berjalan di proses lain - dilewati.");

            return self::SUCCESS;
        }

        try {
            $run = KpiCalculationRun::create([
                'kpi_formula_version_id' => $formulaVersion->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => KpiCalculationRun::STATUS_PENDING,
                'triggered_by' => null,
            ]);

            $this->info("Menghitung KPI periode {$periodStart->translatedFormat('F Y')} (formula {$formulaVersion->version}, run #{$run->id})...");

            $service->calculate($run);

            $run->refresh();

            if ($run->status === KpiCalculationRun::STATUS_COMPLETED) {
                $this->info("Selesai - run #{$run->id} completed ({$run->started_at->diffInSeconds($run->finished_at)} detik).");

                return self::SUCCESS;
            }

            $this->error("Run #{$run->id} gagal: {$run->error_message}");

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    public static function cacheLockKey(Carbon $periodStart, Carbon $periodEnd, int $formulaVersionId): string
    {
        return KpiCalculationLock::key($periodStart, $periodEnd, $formulaVersionId);
    }
}
