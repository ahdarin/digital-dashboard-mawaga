<?php

namespace App\Kpi\Services;

use App\Enums\CoverageStatus;
use App\Enums\KpiStatusLabel;

/**
 * Satu-satunya tempat yang memutuskan STATUS TAMPIL (Sehat/Perlu Perhatian/
 * Sementara/Data Belum Cukup) dari kombinasi sample size + coverage status +
 * composite score - docs/kpi/DATA_COVERAGE.md. TIDAK PERNAH menampilkan 0
 * untuk data yang belum cukup; label INI yang dipakai UI, bukan composite
 * score mentah.
 */
class KpiCoverageService
{
    public function determineStatusLabel(
        ?float $compositeScore,
        CoverageStatus $coverageStatus,
        int $sampleSize,
        int $minSampleSize,
        float $healthyThreshold = 70.0,
    ): KpiStatusLabel {
        if ($sampleSize < $minSampleSize || $coverageStatus === CoverageStatus::Unavailable || $compositeScore === null) {
            return KpiStatusLabel::DataBelumCukup;
        }

        if ($coverageStatus === CoverageStatus::Provisional) {
            return KpiStatusLabel::Sementara;
        }

        return $compositeScore >= $healthyThreshold ? KpiStatusLabel::Sehat : KpiStatusLabel::PerluPerhatian;
    }

    /**
     * "Ranking antara user dengan coverage berbeda" DILARANG spesifikasi -
     * helper ini dipakai UI/controller untuk mem-filter/menandai baris yang
     * TIDAK BOLEH dibandingkan langsung dengan baris coverage lain (bukan
     * untuk membuat sorting composite score lintas coverage berbeda).
     */
    public function comparableGroupKey(CoverageStatus $coverageStatus, KpiStatusLabel $statusLabel): string
    {
        return $coverageStatus->value.'|'.$statusLabel->value;
    }
}
