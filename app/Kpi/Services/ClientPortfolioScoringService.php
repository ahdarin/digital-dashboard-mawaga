<?php

namespace App\Kpi\Services;

use App\Enums\CoverageStatus;
use App\Kpi\Formula\KpiFormulaConfig;
use App\Kpi\Support\RobustStats;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ContentMetricSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Client portfolio outcome (docs/kpi/ANALYTICS_NORMALIZATION.md "Client
 * Portfolio Outcome") - 45% visibility growth + 35% meaningful engagement +
 * 20% follower growth. Follower growth SELALU dibandingkan terhadap tren
 * akun itu SENDIRI (self-trend), TIDAK PERNAH raw follower count antarklien.
 *
 * Koreksi 2026-09-02:
 * - #1/#2 TIDAK PERNAH menjumlahkan nilai snapshot kumulatif mentah
 *   (`sum('views')` lintas baris SALAH - `views` kumulatif PER CONTENT,
 *   menjumlahkannya lintas hari/content mencampur total-to-date, bukan
 *   pertumbuhan). Delta dihitung PER CONTENT (snapshot terakhir dalam
 *   periode - baseline sebelum periode), baru dijumlah lintas content.
 * - #3 Engagement dihitung dari DELTA RAW METRICS (likes/comments/shares/
 *   saves delta / reach|views delta), BUKAN rata-rata kolom `engagement_rate`
 *   kumulatif yang tersimpan per snapshot.
 * - #8 Dihitung untuk SELURUH platform aktif client (bukan satu platform
 *   pertama yang kebetulan ditemukan) - scoreClient() tidak lagi menerima
 *   $platformId, menentukan sendiri platform mana yang aktif.
 *
 * Known limitation (v1): winsorization bound untuk growth% memakai
 * distribusi growth% SELURUH client+platform yang tersedia pada window yang
 * sama, semata-mata untuk membatasi outlier (bukan untuk membandingkan
 * antarklien).
 */
class ClientPortfolioScoringService
{
    /**
     * @return array{score: ?float, coverage: CoverageStatus, components: array<string, mixed>}
     */
    public function scoreClient(Client $client, Carbon $periodStart, Carbon $periodEnd, KpiFormulaConfig $config): array
    {
        $platformIds = $this->activePlatformIds($client->id, $periodStart, $periodEnd);

        if ($platformIds->isEmpty()) {
            return ['score' => null, 'coverage' => CoverageStatus::Unavailable, 'components' => []];
        }

        $perPlatform = $platformIds->map(fn (int $platformId) => $this->scoreClientPlatform($client->id, $platformId, $periodStart, $periodEnd, $config));

        $usable = $perPlatform->filter(fn (array $r) => $r['score'] !== null);

        if ($usable->isEmpty()) {
            return ['score' => null, 'coverage' => CoverageStatus::Unavailable, 'components' => ['per_platform' => $perPlatform->all()]];
        }

        $combinedScore = RobustStats::clampScore($usable->pluck('score')->avg());
        $coverage = CoverageStatus::weakest(...$usable->pluck('coverage')->all());

        return [
            'score' => $combinedScore,
            'coverage' => $coverage,
            'components' => ['per_platform' => $perPlatform->all()],
        ];
    }

    /**
     * Platform yang PUNYA aktivitas nyata (content metric snapshot ATAU
     * audience insight) untuk client ini dalam window relevan - "seluruh
     * platform aktif", bukan platform pertama yang kebetulan ditemukan.
     *
     * @return Collection<int, int>
     */
    private function activePlatformIds(int $clientId, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $fromMetrics = ContentMetricSnapshot::where('client_id', $clientId)
            ->whereBetween('snapshot_date', [$periodStart->copy()->subMonth()->toDateString(), $periodEnd->toDateString()])
            ->distinct()
            ->pluck('platform_id');

        $fromAudience = AudienceInsight::where('client_id', $clientId)
            ->whereIn('demographic_type', [AudienceInsight::TYPE_SUMMARY, AudienceInsight::TYPE_GENERIC])
            ->distinct()
            ->pluck('platform_id');

        return $fromMetrics->merge($fromAudience)->unique()->values();
    }

    /**
     * @return array{score: ?float, coverage: CoverageStatus, components: array<string, mixed>}
     */
    private function scoreClientPlatform(int $clientId, int $platformId, Carbon $periodStart, Carbon $periodEnd, KpiFormulaConfig $config): array
    {
        $weights = $config->clientPortfolio;

        $visibility = $this->visibilityGrowth($clientId, $platformId, $periodStart, $periodEnd);
        $engagement = $this->engagementPerformance($clientId, $platformId, $periodStart, $periodEnd);
        $follower = $this->followerGrowth($clientId, $platformId, $periodStart, $periodEnd);

        $components = [
            'visibility_growth' => ['status' => $visibility !== null ? 'available' : 'unavailable', 'weight' => $weights['visibility_growth'], 'raw' => $visibility, 'normalized' => $visibility],
            'meaningful_engagement' => ['status' => $engagement !== null ? 'available' : 'unavailable', 'weight' => $weights['engagement'], 'raw' => $engagement, 'normalized' => $engagement],
            'follower_growth' => ['status' => $follower !== null ? 'available' : 'unavailable', 'weight' => $weights['follower_growth'], 'raw' => $follower, 'normalized' => $follower],
        ];

        $available = array_filter($components, fn ($c) => $c['normalized'] !== null);

        if (empty($available)) {
            return ['score' => null, 'coverage' => CoverageStatus::Unavailable, 'components' => $components];
        }

        $totalWeight = array_sum(array_column($available, 'weight'));
        $sum = 0.0;
        foreach ($available as $c) {
            $sum += $c['normalized'] * ($c['weight'] / $totalWeight);
        }

        $coverage = count($available) === count($components) ? CoverageStatus::Full : CoverageStatus::Partial;

        return ['score' => RobustStats::clampScore($sum), 'coverage' => $coverage, 'components' => $components];
    }

    /**
     * Visibility growth: total DELTA views (bukan sum raw kumulatif) periode
     * ini vs periode sebelumnya durasi sama, dipercentile-rank-kan terhadap
     * distribusi growth% client+platform lain pada window yang sama.
     */
    private function visibilityGrowth(int $clientId, int $platformId, Carbon $periodStart, Carbon $periodEnd): ?float
    {
        $days = $periodStart->diffInDays($periodEnd) + 1;
        $previousStart = $periodStart->copy()->subDays($days);
        $previousEnd = $periodStart->copy()->subDay();

        $current = $this->totalViewsDelta($clientId, $platformId, $periodStart, $periodEnd);
        $previous = $this->totalViewsDelta($clientId, $platformId, $previousStart, $previousEnd);

        if ($current === null) {
            return null;
        }

        $growthRate = $previous !== null && $previous > 0
            ? (($current - $previous) / $previous) * 100
            : ($current > 0 ? 100.0 : 0.0);

        $peerGrowthRates = $this->peerGrowthRates($platformId, $periodStart, $periodEnd, $days, excludeClientId: $clientId);

        if (count($peerGrowthRates) < 1) {
            return null;
        }

        $pool = array_merge($peerGrowthRates, [$growthRate]);
        $pool = RobustStats::winsorize($pool);
        $clampedGrowth = min(max($growthRate, RobustStats::percentileValue($pool, 5)), RobustStats::percentileValue($pool, 95));

        return RobustStats::percentileRank($clampedGrowth, $pool);
    }

    /**
     * Delta TOTAL views client+platform pada [start, end] - dihitung PER
     * CONTENT (snapshot terakhir dalam window - baseline sebelum window),
     * baru dijumlah lintas content. TIDAK PERNAH `sum('views')` lintas baris
     * mentah (itu akan menjumlahkan nilai KUMULATIF, bukan pertumbuhan).
     */
    private function totalViewsDelta(int $clientId, int $platformId, Carbon $start, Carbon $end): ?int
    {
        $snapshots = ContentMetricSnapshot::where('client_id', $clientId)
            ->where('platform_id', $platformId)
            ->where('snapshot_date', '<=', $end->toDateString())
            ->orderBy('snapshot_date')
            ->get(['content_item_id', 'instagram_media_snapshot_id', 'tiktok_video_snapshot_id', 'snapshot_date', 'views']);

        if ($snapshots->isEmpty()) {
            return null;
        }

        $byContent = $snapshots->groupBy(fn (ContentMetricSnapshot $s) => $s->getDistinctContentKeyAttribute());

        $totalDelta = 0;
        $anyUsable = false;

        foreach ($byContent as $contentSnapshots) {
            $inWindow = $contentSnapshots->filter(fn (ContentMetricSnapshot $s) => Carbon::parse($s->snapshot_date)->gte($start));
            $current = $inWindow->sortByDesc('snapshot_date')->first();
            if (! $current || $current->views === null) {
                continue;
            }

            $baseline = $contentSnapshots
                ->filter(fn (ContentMetricSnapshot $s) => Carbon::parse($s->snapshot_date)->lt($start) && $s->views !== null)
                ->sortByDesc('snapshot_date')
                ->first();

            $delta = $baseline ? ((int) $current->views - (int) $baseline->views) : (int) $current->views;
            if ($delta < 0) {
                continue; // metric reset/correction - dilewati, bukan negative gain palsu
            }

            $totalDelta += $delta;
            $anyUsable = true;
        }

        return $anyUsable ? $totalDelta : null;
    }

    /** @return array<int, float> */
    private function peerGrowthRates(int $platformId, Carbon $periodStart, Carbon $periodEnd, int $days, int $excludeClientId): array
    {
        $previousStart = $periodStart->copy()->subDays($days);
        $previousEnd = $periodStart->copy()->subDay();

        $clientIds = ContentMetricSnapshot::where('platform_id', $platformId)
            ->where('client_id', '!=', $excludeClientId)
            ->whereBetween('snapshot_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->distinct()
            ->pluck('client_id');

        $rates = [];
        foreach ($clientIds as $clientId) {
            $current = $this->totalViewsDelta($clientId, $platformId, $periodStart, $periodEnd);
            $previous = $this->totalViewsDelta($clientId, $platformId, $previousStart, $previousEnd);
            if ($current === null) {
                continue;
            }
            $rates[] = $previous !== null && $previous > 0 ? (($current - $previous) / $previous) * 100 : ($current > 0 ? 100.0 : 0.0);
        }

        return $rates;
    }

    /**
     * Meaningful engagement performance - DIHITUNG dari DELTA RAW METRICS
     * (likes+comments+shares[+saves] delta / reach|views delta), rumus SAMA
     * dengan PeriodPerformanceService/ContentOutcomeScoringService - BUKAN
     * rata-rata kolom `engagement_rate` kumulatif tersimpan per snapshot.
     */
    private function engagementPerformance(int $clientId, int $platformId, Carbon $periodStart, Carbon $periodEnd): ?float
    {
        $rate = $this->engagementRateFromDelta($clientId, $platformId, $periodStart, $periodEnd);
        if ($rate === null) {
            return null;
        }

        $peerRates = $this->peerEngagementRates($platformId, $periodStart, $periodEnd, excludeClientId: $clientId);

        if (count($peerRates) < 1) {
            return null;
        }

        $pool = array_merge($peerRates, [$rate]);

        return RobustStats::percentileRank($rate, $pool);
    }

    /**
     * Delta likes/comments/shares/saves & reach|views client+platform pada
     * [start, end] - dihitung PER CONTENT (sama filosofi totalViewsDelta()),
     * dijumlah lintas content, BARU dibagi jadi satu rate agregat.
     */
    private function engagementRateFromDelta(int $clientId, int $platformId, Carbon $start, Carbon $end): ?float
    {
        $isTiktok = \App\Models\Platform::whereKey($platformId)->value('name') === 'TikTok';

        $snapshots = ContentMetricSnapshot::where('client_id', $clientId)
            ->where('platform_id', $platformId)
            ->where('snapshot_date', '<=', $end->toDateString())
            ->orderBy('snapshot_date')
            ->get(['content_item_id', 'instagram_media_snapshot_id', 'tiktok_video_snapshot_id', 'snapshot_date', 'likes', 'comments', 'shares', 'saves', 'reach', 'views']);

        if ($snapshots->isEmpty()) {
            return null;
        }

        $byContent = $snapshots->groupBy(fn (ContentMetricSnapshot $s) => $s->getDistinctContentKeyAttribute());

        $totalInteractions = 0;
        $totalDenominator = 0;
        $anyUsable = false;

        foreach ($byContent as $contentSnapshots) {
            $inWindow = $contentSnapshots->filter(fn (ContentMetricSnapshot $s) => Carbon::parse($s->snapshot_date)->gte($start));
            $current = $inWindow->sortByDesc('snapshot_date')->first();
            if (! $current) {
                continue;
            }
            $baseline = $contentSnapshots
                ->filter(fn (ContentMetricSnapshot $s) => Carbon::parse($s->snapshot_date)->lt($start))
                ->sortByDesc('snapshot_date')
                ->first();

            $delta = fn (?string $field) => $this->deltaField($baseline, $current, $field);

            $denominator = $isTiktok ? $delta('views') : ($delta('reach') ?? $delta('views'));
            if ($denominator === null || $denominator <= 0) {
                continue;
            }

            $fields = $isTiktok ? ['likes', 'comments', 'shares'] : ['likes', 'comments', 'shares', 'saves'];
            $interactions = 0;
            $allKnown = true;
            foreach ($fields as $field) {
                $value = $delta($field);
                if ($value === null) {
                    $allKnown = false;
                    break;
                }
                $interactions += $value;
            }

            if (! $allKnown) {
                continue;
            }

            $totalInteractions += $interactions;
            $totalDenominator += $denominator;
            $anyUsable = true;
        }

        if (! $anyUsable || $totalDenominator <= 0) {
            return null;
        }

        return round(min($totalInteractions / $totalDenominator * 100, 999.99), 4);
    }

    private function deltaField(?ContentMetricSnapshot $baseline, ContentMetricSnapshot $current, string $field): ?int
    {
        if ($current->{$field} === null) {
            return null;
        }
        if ($baseline === null) {
            return (int) $current->{$field};
        }
        if ($baseline->{$field} === null) {
            return null;
        }

        $delta = (int) $current->{$field} - (int) $baseline->{$field};

        return $delta < 0 ? null : $delta; // metric reset/correction - unknown, bukan negative
    }

    /** @return array<int, float> */
    private function peerEngagementRates(int $platformId, Carbon $periodStart, Carbon $periodEnd, int $excludeClientId): array
    {
        $clientIds = ContentMetricSnapshot::where('platform_id', $platformId)
            ->where('client_id', '!=', $excludeClientId)
            ->whereBetween('snapshot_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->distinct()
            ->pluck('client_id');

        $rates = [];
        foreach ($clientIds as $clientId) {
            $rate = $this->engagementRateFromDelta($clientId, $platformId, $periodStart, $periodEnd);
            if ($rate !== null) {
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /**
     * Follower growth % dari AudienceInsight snapshot terdekat ke
     * period_start/period_end - self-trend, TIDAK PERNAH dibandingkan raw
     * count antarklien.
     */
    private function followerGrowth(int $clientId, int $platformId, Carbon $periodStart, Carbon $periodEnd): ?float
    {
        $startSnapshot = $this->nearestFollowerSnapshot($clientId, $platformId, $periodStart, '<=');
        $endSnapshot = $this->nearestFollowerSnapshot($clientId, $platformId, $periodEnd, '<=');

        if ($startSnapshot === null || $endSnapshot === null || $startSnapshot->follower_count === null || $endSnapshot->follower_count === null) {
            return null;
        }
        if ((int) $startSnapshot->follower_count <= 0) {
            return null;
        }

        $growthRate = (($endSnapshot->follower_count - $startSnapshot->follower_count) / $startSnapshot->follower_count) * 100;

        $peerRates = $this->peerFollowerGrowthRates($platformId, $periodStart, $periodEnd, excludeClientId: $clientId);

        if (count($peerRates) < 1) {
            return null;
        }

        $pool = array_merge($peerRates, [$growthRate]);
        $pool = RobustStats::winsorize($pool);
        $clamped = min(max($growthRate, RobustStats::percentileValue($pool, 5)), RobustStats::percentileValue($pool, 95));

        return RobustStats::percentileRank($clamped, $pool);
    }

    private function nearestFollowerSnapshot(int $clientId, int $platformId, Carbon $onOrBefore, string $operator): ?AudienceInsight
    {
        return AudienceInsight::where('client_id', $clientId)
            ->where('platform_id', $platformId)
            ->whereIn('demographic_type', [AudienceInsight::TYPE_SUMMARY, AudienceInsight::TYPE_GENERIC])
            ->whereNotNull('follower_count')
            ->where('snapshot_date', $operator, $onOrBefore->toDateString())
            ->orderByDesc('snapshot_date')
            ->first();
    }

    /** @return array<int, float> */
    private function peerFollowerGrowthRates(int $platformId, Carbon $periodStart, Carbon $periodEnd, int $excludeClientId): array
    {
        $clientIds = AudienceInsight::where('platform_id', $platformId)
            ->where('client_id', '!=', $excludeClientId)
            ->whereIn('demographic_type', [AudienceInsight::TYPE_SUMMARY, AudienceInsight::TYPE_GENERIC])
            ->distinct()
            ->pluck('client_id');

        $rates = [];
        foreach ($clientIds as $clientId) {
            $start = $this->nearestFollowerSnapshot($clientId, $platformId, $periodStart, '<=');
            $end = $this->nearestFollowerSnapshot($clientId, $platformId, $periodEnd, '<=');
            if (! $start || ! $end || ! $start->follower_count || (int) $start->follower_count <= 0) {
                continue;
            }
            $rates[] = (($end->follower_count - $start->follower_count) / $start->follower_count) * 100;
        }

        return $rates;
    }
}
