<?php

namespace App\Jobs;

use App\Kpi\Services\KpiCalculationService;
use App\Kpi\Support\KpiCalculationLock;
use App\Models\KpiCalculationRun;
use App\Models\KpiFormulaVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Background job KPI (Fase 4, koreksi produk 2026-09-02) - satu-satunya
 * cara KPI dihitung. TIDAK PERNAH mensyaratkan user/administrator
 * menjalankan command manual (`kpi:calculate` tetap ada untuk developer,
 * tapi bukan persyaratan pemakaian fitur - lihat docs/kpi/JOBS_AND_OPERATIONS.md).
 *
 * Dipicu otomatis dari titik-titik existing (lihat App\Kpi\Services\
 * KpiRecalculationTrigger) setiap kali ada aktivitas relevan: assignment
 * berubah, workflow status berubah, revision dibuat/diselesaikan,
 * publication dibuat/diubah, analytics sync selesai, audience insight
 * diperbarui - TIDAK ADA perubahan pada alur/controller aktivitas itu
 * sendiri, cuma tambahan SATU baris dispatch di titik akhir masing-masing.
 *
 * `ShouldBeUnique` + dispatch dengan delay (lihat KpiRecalculationTrigger)
 * = DEBOUNCE: banyak event beruntun dalam window yang sama HANYA
 * menghasilkan SATU job yang benar-benar dieksekusi (dispatch berikutnya
 * ditolak diam-diam selama lock unique masih dipegang), bukan job duplikat
 * per event.
 */
class RecalculateKpiPeriod implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(
        public readonly string $periodStart,
        public readonly string $periodEnd,
    ) {
    }

    /**
     * Unique per periode - dua dispatch untuk periode yang SAMA dalam
     * window debounce dianggap job yang sama (yang kedua ditolak, bukan
     * diqueue lagi).
     */
    public function uniqueId(): string
    {
        return "{$this->periodStart}:{$this->periodEnd}";
    }

    /**
     * Lock unique bertahan 5 menit - lebih lama dari delay debounce
     * (lihat KpiRecalculationTrigger::DEBOUNCE_SECONDS) supaya event
     * beruntun dalam window itu benar-benar collapse jadi satu eksekusi,
     * bukan cuma mencegah overlap PROSES (beda dari WithoutOverlapping
     * yang dipakai sync job Instagram/TikTok - itu mencegah overlap
     * eksekusi, ini mencegah duplikasi DISPATCH sebelum eksekusi dimulai).
     */
    public function uniqueFor(): int
    {
        return 300;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * Lock EKSEKUSI (`App\Kpi\Support\KpiCalculationLock`) - SAMA persis
     * dengan yang dipakai command developer `kpi:calculate` (koreksi:
     * sebelumnya job ini cuma mengandalkan `ShouldBeUnique`, yang mencegah
     * duplikasi DISPATCH tapi tidak mencegah command manual dan job ini
     * menghitung periode+formula yang SAMA secara bersamaan). Kalau lock
     * sedang dipegang proses lain, job ini SKIP tanpa membuat run baru
     * (bukan gagal) - sama seperti perilaku command manual.
     */
    public function handle(KpiCalculationService $service): void
    {
        $periodEnd = Carbon::parse($this->periodEnd);
        $formulaVersion = KpiFormulaVersion::resolveCurrent($periodEnd);

        $lock = KpiCalculationLock::acquire(Carbon::parse($this->periodStart), $periodEnd, $formulaVersion->id);

        if (! $lock->get()) {
            return;
        }

        try {
            $run = KpiCalculationRun::create([
                'kpi_formula_version_id' => $formulaVersion->id,
                'period_start' => $this->periodStart,
                'period_end' => $this->periodEnd,
                'status' => KpiCalculationRun::STATUS_PENDING,
            ]);

            $service->calculate($run);
        } finally {
            $lock->release();
        }
    }
}
