<?php

namespace App\Kpi\Dto;

use App\Enums\CoverageStatus;

/**
 * Hasil Process KPI SATU user pada SATU konteks role (existing role + jenis
 * aktivitas, lihat KpiRoleContextResolver) untuk satu periode - dibangun
 * RoleProcessKpiService. `metrics` menyimpan tiap indikator proses (median
 * waktu, on-time rate, dst - lihat PROCESS_METRICS.md) dengan status
 * coverage MASING-MASING (bukan cuma satu status untuk semuanya), karena
 * satu indikator bisa unavailable sementara indikator lain full.
 */
final class ProcessScoreBreakdown
{
    public function __construct(
        public readonly int $userId,
        /** @var array<string, array{value: mixed, unit: ?string, coverage: CoverageStatus, sample_size: int, notes: ?string}> */
        public readonly array $metrics,
        /** Skor proses 0-100 gabungan seluruh indikator yang usable, NULL kalau semuanya unavailable. */
        public readonly ?float $processScore,
        public readonly CoverageStatus $overallCoverage,
        public readonly int $sampleSize,
    ) {}

    public function isUsable(): bool
    {
        return $this->processScore !== null;
    }
}
