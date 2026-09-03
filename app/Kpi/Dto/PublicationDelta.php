<?php

namespace App\Kpi\Dto;

use App\Enums\CoverageStatus;

/**
 * Delta metric mentah SATU publication (content_item + platform) pada satu
 * measurement window - dibangun ContentOutcomeScoringService::computePublicationDelta().
 * Nilai NULL berarti metric itu tidak diketahui (belum ada snapshot/tidak
 * didukung platform), BUKAN 0 - konsumen (scoreVideoFormat/scoreDesignFormat)
 * WAJIB null-safe.
 */
final class PublicationDelta
{
    public function __construct(
        public readonly CoverageStatus $coverageStatus,
        public readonly ?int $views,
        public readonly ?int $reach,
        public readonly ?int $likes,
        public readonly ?int $comments,
        public readonly ?int $shares,
        public readonly ?int $saves,
        public readonly ?float $watchTimeAvg,
        public readonly ?float $completionRate,
        public readonly string $platformType,
    ) {}

    public static function unavailable(string $platformType): self
    {
        return new self(CoverageStatus::Unavailable, null, null, null, null, null, null, null, null, $platformType);
    }

    public static function provisional(string $platformType): self
    {
        return new self(CoverageStatus::Provisional, null, null, null, null, null, null, null, null, $platformType);
    }

    /** @return array<string, int|float|null> */
    public function toRawArray(): array
    {
        return [
            'views' => $this->views,
            'reach' => $this->reach,
            'likes' => $this->likes,
            'comments' => $this->comments,
            'shares' => $this->shares,
            'saves' => $this->saves,
            'watch_time_avg' => $this->watchTimeAvg,
            'completion_rate' => $this->completionRate,
        ];
    }
}
