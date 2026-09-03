<?php

namespace App\Kpi\Dto;

use App\Enums\ContentFormatGroup;
use App\Enums\CoverageStatus;
use App\Enums\MeasurementWindow;

/**
 * Hasil scoring outcome SATU content item pada SATU measurement window -
 * dipisahkan tegas raw metric / normalized metric / component score /
 * composite score (persyaratan Fase 2 #7), sebelum dipersist ke
 * `ContentOutcomeResult`. Immutable, dibangun oleh ContentOutcomeScoringService.
 */
final class ContentOutcomeScore
{
    public function __construct(
        public readonly int $contentItemId,
        public readonly ContentFormatGroup $formatGroup,
        public readonly MeasurementWindow $window,
        public readonly CoverageStatus $coverageStatus,
        public readonly int $peerSampleSize,
        public readonly ?string $peerGroupKey,
        /** Skor komposit 0-100, NULL kalau coverage tidak cukup untuk dihasilkan sama sekali. */
        public readonly ?float $normalizedScore,
        /** @var array<string, array{status: string, weight: float, raw: mixed, normalized: ?float}> per komponen (visibility/engagement/retention ATAU reach/saves/shares/comments/likes). */
        public readonly array $componentScores,
        /** @var array<string, mixed> metric mentah sebelum normalisasi, untuk audit/tampilan detail. */
        public readonly array $rawMetrics,
        public readonly ?string $exclusionReason = null,
    ) {}

    public static function unavailable(int $contentItemId, ContentFormatGroup $formatGroup, MeasurementWindow $window, string $reason): self
    {
        return new self(
            contentItemId: $contentItemId,
            formatGroup: $formatGroup,
            window: $window,
            coverageStatus: CoverageStatus::Unavailable,
            peerSampleSize: 0,
            peerGroupKey: null,
            normalizedScore: null,
            componentScores: [],
            rawMetrics: [],
            exclusionReason: $reason,
        );
    }

    public function isUsable(): bool
    {
        return $this->normalizedScore !== null
            && in_array($this->coverageStatus, [CoverageStatus::Full, CoverageStatus::Partial], true);
    }

    /** @return array<string, mixed> */
    public function toPersistArray(int $kpiCalculationRunId): array
    {
        return [
            'kpi_calculation_run_id' => $kpiCalculationRunId,
            'content_item_id' => $this->contentItemId,
            'format_group' => $this->formatGroup->value,
            'measurement_window' => $this->window->value,
            'coverage_status' => $this->coverageStatus->value,
            'peer_sample_size' => $this->peerSampleSize,
            'peer_group_key' => $this->peerGroupKey,
            'normalized_score' => $this->normalizedScore,
            'component_scores' => $this->componentScores,
            'raw_metrics' => $this->rawMetrics,
            'exclusion_reason' => $this->exclusionReason,
            'computed_at' => now(),
        ];
    }
}
