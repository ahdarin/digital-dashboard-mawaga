<?php

namespace App\Kpi\Dto;

use App\Enums\CoverageStatus;
use App\Enums\KpiStatusLabel;

/**
 * Hasil composite KPI SATU user pada SATU konteks (role EXISTING dan/atau
 * client tertentu, untuk satu periode) - dibangun KpiCalculationService dari
 * gabungan ProcessScoreBreakdown + ContentOutcomeScore (agregat direct) +
 * client portfolio score, DIBOBOT sesuai KpiFormulaConfig. Composite score
 * HANYA terisi (non-null) kalau sample size & coverage cukup - kalau tidak,
 * `compositeScore` NULL dan `statusLabel` menjelaskan alasannya (Sementara/
 * Data Belum Cukup), BUKAN 0. UI WAJIB menyembunyikan compositeScore kalau
 * statusLabel = DataBelumCukup, walau nilainya tersimpan (untuk audit).
 */
final class CompositeKpiResult
{
    public function __construct(
        public readonly int $userId,
        public readonly ?int $roleId,
        public readonly ?string $roleName,
        public readonly ?int $clientId,
        public readonly ?float $processScore,
        public readonly ?float $directOutcomeScore,
        public readonly ?float $portfolioOutcomeScore,
        public readonly ?float $compositeScore,
        public readonly CoverageStatus $coverageStatus,
        public readonly int $sampleSize,
        public readonly KpiStatusLabel $statusLabel,
        /** @var array<string, mixed> seluruh angka pendukung untuk audit - lihat FORMULAS.md "contoh hitung". */
        public readonly array $componentBreakdown,
    ) {}

    /** @return array<string, mixed> */
    public function toPersistArray(int $kpiCalculationRunId, string $periodStart, string $periodEnd): array
    {
        return [
            'kpi_calculation_run_id' => $kpiCalculationRunId,
            'user_id' => $this->userId,
            'role_id' => $this->roleId,
            'client_id' => $this->clientId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'process_score' => $this->processScore,
            'direct_outcome_score' => $this->directOutcomeScore,
            'portfolio_outcome_score' => $this->portfolioOutcomeScore,
            'composite_score' => $this->compositeScore,
            'coverage_status' => $this->coverageStatus->value,
            'sample_size' => $this->sampleSize,
            'status_label' => $this->statusLabel->value,
            'component_breakdown' => $this->componentBreakdown,
        ];
    }
}
