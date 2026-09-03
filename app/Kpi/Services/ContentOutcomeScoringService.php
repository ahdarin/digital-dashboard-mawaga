<?php

namespace App\Kpi\Services;

use App\Enums\ContentFormatGroup;
use App\Enums\CoverageStatus;
use App\Enums\MeasurementWindow;
use App\Kpi\Dto\ContentOutcomeScore;
use App\Kpi\Dto\PublicationDelta;
use App\Kpi\Formula\KpiFormulaConfig;
use App\Kpi\Support\RobustStats;
use App\Models\ContentItem;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPublication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Scoring outcome performa konten (docs/kpi/ANALYTICS_NORMALIZATION.md) -
 * TIDAK PERNAH memakai raw views/likes/reach/follower langsung sebagai poin,
 * SELALU dinormalisasi terhadap peer group yang sebanding (client+platform+
 * format+usia publication+window historis yang sama).
 *
 * Alur: computePublicationDelta() (per publication, reuse filosofi coverage
 * PeriodPerformanceService TAPI di-scope per (content_item_id, platform_id)
 * eksplisit - PeriodPerformanceService::computeContentDelta() TIDAK dipakai
 * langsung di sini karena identityColumn='content_item_id' miliknya ambigu
 * untuk content multi-platform, lihat docs/kpi/IMPLEMENTATION_PLAN.md) ->
 * buildPeerPool() (satu query batch, bukan N+1) -> scoreContentItem()
 * (gabung multi-platform + normalisasi peer + composite skor).
 */
class ContentOutcomeScoringService
{
    /**
     * Delta metric SATU publication pada window [published_at, published_at
     * + windowDays] - null kalau publication belum cukup umur (provisional)
     * atau tidak ada data sama sekali (unavailable).
     */
    public function computePublicationDelta(ContentPublication $publication, int $windowDays): PublicationDelta
    {
        $platformType = strtolower($publication->platform?->name ?? '');
        $platformType = str_contains($platformType, 'tiktok') ? 'tiktok' : 'instagram';

        $publishedAt = Carbon::parse($publication->published_at)->startOfDay();
        $targetEnd = $publishedAt->copy()->addDays($windowDays);

        if (Carbon::now()->lt($targetEnd)) {
            return PublicationDelta::provisional($platformType);
        }

        $snapshots = ContentMetricSnapshot::where('content_item_id', $publication->content_item_id)
            ->where('platform_id', $publication->platform_id)
            ->whereDate('snapshot_date', '<=', $targetEnd->toDateString())
            ->orderBy('snapshot_date')
            ->get();

        if ($snapshots->isEmpty()) {
            return PublicationDelta::unavailable($platformType);
        }

        $current = $snapshots->last();
        $isExact = Carbon::parse($current->snapshot_date)->equalTo($targetEnd);

        return new PublicationDelta(
            coverageStatus: $isExact ? CoverageStatus::Full : CoverageStatus::Partial,
            views: $current->views !== null ? (int) $current->views : null,
            reach: $current->reach !== null ? (int) $current->reach : null,
            likes: $current->likes !== null ? (int) $current->likes : null,
            comments: $current->comments !== null ? (int) $current->comments : null,
            shares: $current->shares !== null ? (int) $current->shares : null,
            saves: $current->saves !== null ? (int) $current->saves : null,
            watchTimeAvg: $current->watch_time_avg !== null ? (float) $current->watch_time_avg : null,
            completionRate: $current->completion_rate !== null ? (float) $current->completion_rate : null,
            platformType: $platformType,
        );
    }

    /**
     * Peer pool untuk baseline - client+platform+format yang sama dalam
     * lookback_days, minimal N publication (KpiFormulaConfig). Fallback ke
     * platform+format lintas klien kalau tidak cukup (tanpa penyesuaian
     * ukuran akun - simplifikasi v1, lihat docs/kpi/ANALYTICS_NORMALIZATION.md
     * "Known limitations"). SATU query batch snapshot (bukan N+1 per peer).
     *
     * Koreksi 2026-09-02:
     * - #9 minimum peer sample BENAR-BENAR diberlakukan - kalau SETELAH
     *   fallback lintas klien pool tetap di bawah minimum, `sample_size`
     *   dikembalikan APA ADANYA (bisa < minimum) supaya caller (percentileScoreFor)
     *   bisa menolak menghasilkan skor dari situ - TIDAK diam-diam dianggap cukup.
     * - #10 publication TARGET dikecualikan dari peer pool-nya sendiri
     *   (query sebelumnya tidak exclude id publication yang sedang dinilai).
     * - #11 peer pool HANYA berisi publication coverage FULL - partial TIDAK
     *   dipakai sebagai baseline (data partial-nya sendiri belum lengkap,
     *   tidak layak jadi pembanding "normal").
     *
     * @return array{pool: Collection<int, PublicationDelta>, peer_group_key: string, sample_size: int, min_required: int}
     */
    public function buildPeerPool(
        ContentItem $referenceItem,
        int $platformId,
        ContentFormatGroup $formatGroup,
        MeasurementWindow $window,
        KpiFormulaConfig $config,
        ?int $excludePublicationId = null,
    ): array {
        $lookbackStart = Carbon::now()->subDays($config->baseline['lookback_days']);
        $minRequired = $config->baseline['min_publications_for_client_platform_format'];

        $candidates = $this->candidatePublications(
            clientId: $referenceItem->client_id,
            platformId: $platformId,
            formatGroup: $formatGroup,
            publishedAfter: $lookbackStart,
            sameClientOnly: true,
            excludePublicationId: $excludePublicationId,
        );

        $peerGroupKey = "client:{$referenceItem->client_id}|platform:{$platformId}|format:{$formatGroup->value}";
        $usable = $this->fullCoverageDeltas($candidates, $window);

        if ($usable->count() < $minRequired) {
            $candidates = $this->candidatePublications(
                clientId: null,
                platformId: $platformId,
                formatGroup: $formatGroup,
                publishedAfter: $lookbackStart,
                sameClientOnly: false,
                excludePublicationId: $excludePublicationId,
            );
            $peerGroupKey = "platform:{$platformId}|format:{$formatGroup->value}|cross_client";
            $usable = $this->fullCoverageDeltas($candidates, $window);
        }

        return [
            'pool' => $usable->values(),
            'peer_group_key' => $peerGroupKey,
            'sample_size' => $usable->count(),
            'min_required' => $minRequired,
        ];
    }

    /**
     * @return Collection<int, PublicationDelta>
     */
    private function fullCoverageDeltas(Collection $candidates, MeasurementWindow $window): Collection
    {
        return $candidates
            ->map(fn (ContentPublication $pub) => $this->computePublicationDelta($pub, $window->days()))
            ->filter(fn (PublicationDelta $d) => $d->coverageStatus === CoverageStatus::Full)
            ->values();
    }

    /**
     * @return Collection<int, ContentPublication>
     */
    private function candidatePublications(?int $clientId, int $platformId, ContentFormatGroup $formatGroup, Carbon $publishedAfter, bool $sameClientOnly, ?int $excludePublicationId = null): Collection
    {
        return ContentPublication::query()
            ->where('platform_id', $platformId)
            ->where('is_paid', false)
            ->where('published_at', '>=', $publishedAfter)
            ->when($excludePublicationId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->whereHas('contentItem', function ($q) use ($clientId, $formatGroup, $sameClientOnly) {
                $q->when($sameClientOnly && $clientId, fn ($qq) => $qq->where('client_id', $clientId))
                    ->with(['contentType'])
                    ->whereHas('contentType', fn ($qq) => $qq->where(
                        'name',
                        $formatGroup === ContentFormatGroup::Video ? 'Video' : 'Desain'
                    ));
            })
            ->with(['contentItem.contentType', 'platform'])
            ->get()
            ->filter(fn (ContentPublication $pub) => ContentFormatGroup::resolve(
                $pub->contentItem?->contentType?->name,
                $pub->contentItem?->content_format
            ) === $formatGroup)
            ->values();
    }

    /**
     * Skor komponen video (visibility/engagement/retention) - retention
     * yang unavailable TIDAK dianggap 0, bobotnya diredistribusi proporsional
     * ke visibility+engagement (§ FORMULAS.md "redistribusi bobot").
     *
     * @param  Collection<int, PublicationDelta>  $peerPool
     * @return array{score: ?float, components: array<string, mixed>}
     */
    public function scoreVideoFormat(PublicationDelta $target, Collection $peerPool, KpiFormulaConfig $config, int $minRequired = 0): array
    {
        $weights = $config->contentOutcome['video'];
        $interactionWeights = $config->contentOutcome['engagement_component_weights'];

        $visibilityScore = $this->percentileScoreFor(
            $target->views,
            $peerPool->map(fn (PublicationDelta $d) => $d->views)->filter(fn ($v) => $v !== null)->values()->all(),
            $minRequired,
        );

        $targetEngagementRate = $this->weightedEngagementRate($target, $interactionWeights);
        $peerEngagementRates = $peerPool
            ->map(fn (PublicationDelta $d) => $this->weightedEngagementRate($d, $interactionWeights))
            ->filter(fn ($v) => $v !== null)
            ->values()
            ->all();
        $engagementScore = $this->percentileScoreFor($targetEngagementRate, $peerEngagementRates, $minRequired);

        $retentionValue = $target->watchTimeAvg !== null && $target->completionRate !== null
            ? ($target->completionRate)
            : null;
        $peerRetention = $peerPool
            ->map(fn (PublicationDelta $d) => $d->completionRate)
            ->filter(fn ($v) => $v !== null)
            ->values()
            ->all();
        $retentionScore = $retentionValue !== null ? $this->percentileScoreFor($retentionValue, $peerRetention, $minRequired) : null;

        $components = [
            'visibility' => ['status' => $visibilityScore !== null ? 'available' : 'unavailable', 'weight' => $weights['visibility'], 'raw' => $target->views, 'normalized' => $visibilityScore],
            'engagement' => ['status' => $engagementScore !== null ? 'available' : 'unavailable', 'weight' => $weights['engagement'], 'raw' => $targetEngagementRate, 'normalized' => $engagementScore],
            'retention' => ['status' => $retentionScore !== null ? 'available' : 'unavailable', 'weight' => $weights['retention'], 'raw' => $retentionValue, 'normalized' => $retentionScore],
        ];

        $score = $this->composeWeighted($components);

        return ['score' => $score, 'components' => $components];
    }

    /**
     * Skor komponen desain (reach/saves/shares/comments/likes percentile) -
     * Carousel HANYA dibandingkan dengan Carousel, Single Feed dengan Single
     * Feed (dijamin oleh peer pool yang sudah difilter format_group SAMA
     * sebelum method ini dipanggil).
     *
     * @param  Collection<int, PublicationDelta>  $peerPool
     * @return array{score: ?float, components: array<string, mixed>}
     */
    public function scoreDesignFormat(PublicationDelta $target, Collection $peerPool, KpiFormulaConfig $config, int $minRequired = 0): array
    {
        $weights = $config->contentOutcome['design'];

        $reachScore = $this->percentileScoreFor(
            $target->reach,
            $peerPool->map(fn (PublicationDelta $d) => $d->reach)->filter(fn ($v) => $v !== null)->values()->all(),
            $minRequired,
        );

        $savesRate = $this->rateAgainstReach($target->saves, $target->reach);
        $sharesRate = $this->rateAgainstReach($target->shares, $target->reach);
        $commentsRate = $this->rateAgainstReach($target->comments, $target->reach);
        $likesRate = $this->rateAgainstReach($target->likes, $target->reach);

        $peerSavesRates = $peerPool->map(fn (PublicationDelta $d) => $this->rateAgainstReach($d->saves, $d->reach))->filter(fn ($v) => $v !== null)->values()->all();
        $peerSharesRates = $peerPool->map(fn (PublicationDelta $d) => $this->rateAgainstReach($d->shares, $d->reach))->filter(fn ($v) => $v !== null)->values()->all();
        $peerCommentsRates = $peerPool->map(fn (PublicationDelta $d) => $this->rateAgainstReach($d->comments, $d->reach))->filter(fn ($v) => $v !== null)->values()->all();
        $peerLikesRates = $peerPool->map(fn (PublicationDelta $d) => $this->rateAgainstReach($d->likes, $d->reach))->filter(fn ($v) => $v !== null)->values()->all();

        $components = [
            'reach' => ['status' => $reachScore !== null ? 'available' : 'unavailable', 'weight' => $weights['reach'], 'raw' => $target->reach, 'normalized' => $reachScore],
            'saves' => ['status' => $savesRate !== null ? 'available' : 'unavailable', 'weight' => $weights['saves'], 'raw' => $savesRate, 'normalized' => $savesRate !== null ? $this->percentileScoreFor($savesRate, $peerSavesRates, $minRequired) : null],
            'shares' => ['status' => $sharesRate !== null ? 'available' : 'unavailable', 'weight' => $weights['shares'], 'raw' => $sharesRate, 'normalized' => $sharesRate !== null ? $this->percentileScoreFor($sharesRate, $peerSharesRates, $minRequired) : null],
            'comments' => ['status' => $commentsRate !== null ? 'available' : 'unavailable', 'weight' => $weights['comments'], 'raw' => $commentsRate, 'normalized' => $commentsRate !== null ? $this->percentileScoreFor($commentsRate, $peerCommentsRates, $minRequired) : null],
            'likes' => ['status' => $likesRate !== null ? 'available' : 'unavailable', 'weight' => $weights['likes'], 'raw' => $likesRate, 'normalized' => $likesRate !== null ? $this->percentileScoreFor($likesRate, $peerLikesRates, $minRequired) : null],
        ];

        $score = $this->composeWeighted($components);

        return ['score' => $score, 'components' => $components];
    }

    private function rateAgainstReach(?int $numerator, ?int $reach): ?float
    {
        if ($numerator === null || $reach === null || $reach <= 0) {
            return null;
        }

        return $numerator / $reach;
    }

    private function weightedEngagementRate(PublicationDelta $delta, array $interactionWeights): ?float
    {
        $denominator = $delta->platformType === 'tiktok' ? $delta->views : ($delta->reach ?? $delta->views);
        if ($denominator === null || $denominator <= 0) {
            return null;
        }

        $components = $delta->platformType === 'tiktok'
            ? ['likes' => $delta->likes, 'comments' => $delta->comments, 'shares' => $delta->shares]
            : ['likes' => $delta->likes, 'comments' => $delta->comments, 'shares' => $delta->shares, 'saves' => $delta->saves];

        $weightedSum = 0.0;
        foreach ($components as $key => $value) {
            if ($value === null) {
                return null; // komponen wajib formula tidak diketahui -> seluruh rate NULL
            }
            $weightedSum += $value * ($interactionWeights[$key] ?? 1.0);
        }

        return round(min($weightedSum / $denominator * 100, 999.99), 4);
    }

    /**
     * Koreksi #12: kalau peer pool (TIDAK termasuk target sendiri) di bawah
     * $minRequired, kembalikan NULL (unavailable) - TIDAK PERNAH menghasilkan
     * skor netral 50 dari sample yang tidak cukup. $minRequired=0 (default)
     * mempertahankan perilaku lama HANYA untuk pemanggil yang sudah
     * memvalidasi sample size di tempat lain.
     *
     * @param  array<int, float>  $peerValues  TIDAK termasuk target
     */
    private function percentileScoreFor(int|float|null $value, array $peerValues, int $minRequired = 0): ?float
    {
        if ($value === null) {
            return null;
        }

        if (count($peerValues) < $minRequired) {
            return null;
        }

        $pool = array_map(fn ($v) => RobustStats::log1p((float) $v), array_merge($peerValues, [(float) $value]));
        $pool = RobustStats::winsorize($pool);
        $transformedValue = RobustStats::log1p((float) $value);
        $winsorizedValue = min(max($transformedValue, RobustStats::percentileValue($pool, 5)), RobustStats::percentileValue($pool, 95));

        return RobustStats::percentileRank($winsorizedValue, $pool);
    }

    /**
     * Gabungkan component score jadi satu skor - bobot komponen yang
     * unavailable diredistribusi PROPORSIONAL ke komponen yang tersedia
     * (bukan diperlakukan sebagai 0). Kalau SEMUA komponen unavailable,
     * return null (bukan 0).
     *
     * @param  array<string, array{status: string, weight: float, raw: mixed, normalized: ?float}>  $components
     */
    private function composeWeighted(array $components): ?float
    {
        $available = array_filter($components, fn ($c) => $c['normalized'] !== null);
        if (empty($available)) {
            return null;
        }

        $totalWeight = array_sum(array_column($available, 'weight'));
        if ($totalWeight <= 0) {
            return null;
        }

        $sum = 0.0;
        foreach ($available as $c) {
            $sum += $c['normalized'] * ($c['weight'] / $totalWeight);
        }

        return RobustStats::clampScore($sum);
    }

    /**
     * Skor content-level (gabung multi-platform) untuk SATU measurement
     * window. Publication is_paid dikecualikan total (tidak masuk skor
     * organic maupun peer pool).
     */
    public function scoreContentItem(ContentItem $item, MeasurementWindow $window, KpiFormulaConfig $config): ContentOutcomeScore
    {
        $formatGroup = ContentFormatGroup::resolve($item->contentType?->name, $item->content_format);

        $publications = $item->publications()->where('is_paid', false)->with('platform')->get();

        if ($publications->isEmpty()) {
            return ContentOutcomeScore::unavailable($item->id, $formatGroup, $window, 'no_organic_publication');
        }

        $platformResults = [];
        $coverageStatuses = [];
        $totalSample = 0;
        $peerGroupKeys = [];

        foreach ($publications as $publication) {
            $delta = $this->computePublicationDelta($publication, $window->days());

            if ($delta->coverageStatus === CoverageStatus::Provisional) {
                $coverageStatuses[] = CoverageStatus::Provisional;
                $platformResults[$publication->platform_id] = ['score' => null, 'components' => [], 'status' => 'provisional'];

                continue;
            }

            if ($delta->coverageStatus === CoverageStatus::Unavailable) {
                $coverageStatuses[] = CoverageStatus::Unavailable;
                $platformResults[$publication->platform_id] = ['score' => null, 'components' => [], 'status' => 'unavailable'];

                continue;
            }

            $peer = $this->buildPeerPool($item, $publication->platform_id, $formatGroup, $window, $config, excludePublicationId: $publication->id);
            $totalSample += $peer['sample_size'];
            $peerGroupKeys[] = $peer['peer_group_key'];

            // #9 minimum peer sample benar-benar diberlakukan - kalau pool
            // (setelah fallback lintas klien) tetap di bawah minimum, publication
            // ini ditandai unavailable (insufficient_peer_sample), TIDAK
            // dipaksa menghasilkan skor dari sample yang tidak cukup.
            if ($peer['sample_size'] < $peer['min_required']) {
                $coverageStatuses[] = CoverageStatus::Unavailable;
                $platformResults[$publication->platform_id] = [
                    'score' => null, 'components' => [], 'status' => 'unavailable',
                    'reason' => 'insufficient_peer_sample',
                ];

                continue;
            }

            $result = $formatGroup->isVideoFamily()
                ? $this->scoreVideoFormat($delta, $peer['pool'], $config, $peer['min_required'])
                : $this->scoreDesignFormat($delta, $peer['pool'], $config, $peer['min_required']);

            $platformResults[$publication->platform_id] = [
                'score' => $result['score'],
                'components' => $result['components'],
                'raw' => $delta->toRawArray(),
                'status' => 'scored',
            ];
            $coverageStatuses[] = $delta->coverageStatus;
        }

        $usableScores = array_filter(array_column($platformResults, 'score'), fn ($s) => $s !== null);

        if (empty($usableScores)) {
            $overallCoverage = in_array(CoverageStatus::Provisional, $coverageStatuses, true) && ! in_array(CoverageStatus::Unavailable, $coverageStatuses, true)
                ? CoverageStatus::Provisional
                : CoverageStatus::Unavailable;

            return new ContentOutcomeScore(
                contentItemId: $item->id,
                formatGroup: $formatGroup,
                window: $window,
                coverageStatus: $overallCoverage,
                peerSampleSize: 0,
                peerGroupKey: null,
                normalizedScore: null,
                componentScores: $platformResults,
                rawMetrics: [],
                exclusionReason: $overallCoverage === CoverageStatus::Provisional ? 'not_aged_enough' : 'no_usable_publication_data',
            );
        }

        // Multi-platform: bobot setara antar platform yang punya skor usable
        // (strategic platform weight per-client belum ada sumber datanya di
        // domain saat ini - lihat FORMULAS.md "Known limitations").
        $combinedScore = RobustStats::clampScore(array_sum($usableScores) / count($usableScores));
        $overallCoverage = CoverageStatus::weakest(...$coverageStatuses);

        return new ContentOutcomeScore(
            contentItemId: $item->id,
            formatGroup: $formatGroup,
            window: $window,
            coverageStatus: $overallCoverage,
            peerSampleSize: $totalSample,
            peerGroupKey: implode(';', array_unique($peerGroupKeys)),
            normalizedScore: $combinedScore,
            componentScores: $platformResults,
            rawMetrics: array_map(fn ($r) => $r['raw'] ?? null, $platformResults),
        );
    }
}
