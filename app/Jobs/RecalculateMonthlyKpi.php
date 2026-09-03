<?php

namespace App\Jobs;

use App\Services\TeamPerformanceKpiCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Satu-satunya jalur KPI Team Performance dihitung. Dipicu dari dua tempat
 * saja (lihat routes/console.php untuk jadwal harian, dan
 * TeamPerformanceController::index()/show() untuk trigger saat halaman
 * dibuka dan hasil bulan berjalan belum ada/sudah basi) - tidak ada trigger
 * lain, dan pengguna tidak pernah diminta menjalankan command manual.
 */
class RecalculateMonthlyKpi implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(public readonly string $periodStart)
    {
    }

    /** Unik per bulan - dispatch beruntun untuk bulan yang sama tidak menghasilkan job dobel. */
    public function uniqueId(): string
    {
        return $this->periodStart;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    /**
     * Lock eksekusi terpisah dari ShouldBeUnique (yang cuma mencegah dispatch
     * dobel) - memastikan dua eksekusi untuk bulan yang sama (mis. jadwal
     * harian dan trigger buka halaman beririsan) tidak pernah menghitung
     * bersamaan.
     */
    public function handle(TeamPerformanceKpiCalculator $calculator): void
    {
        $lock = Cache::lock('kpi-recalculate-'.$this->periodStart, 600);

        if (! $lock->get()) {
            return;
        }

        try {
            $calculator->calculateForPeriod(Carbon::parse($this->periodStart));
        } finally {
            $lock->release();
        }
    }
}
