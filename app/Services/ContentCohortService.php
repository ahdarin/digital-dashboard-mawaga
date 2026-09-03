<?php

namespace App\Services;

use App\Models\ContentMetric;
use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - "PUBLISH-DATE COHORT IS
 * PRIMARY". This is the canonical roster+current-metrics engine for the
 * PRIMARY Analytics product (Ringkasan/Konten/Content Detail cohort/Export/
 * Report/AI Strategy) - answers "which content belongs to month X" and
 * "what is its performance RIGHT NOW", NOT "how much did metrics move
 * during month X" (that remains PeriodPerformanceService's job, unchanged
 * and still available as SECONDARY/advanced information - see its own
 * docblock, section C of the product model).
 *
 * THREE SEPARATE CONCEPTS (do not re-merge them):
 * A. CONTENT COHORT - which content belongs to the selected period. Decided
 *    ONLY by genuine provider publication timestamp
 *    (InstagramMediaSnapshot/TikTokVideoSnapshot.published_at), NEVER by
 *    ContentMetric.created_at, ContentMetricSnapshot.snapshot_date, sync
 *    date, database created_at, or last_fetched_at - see computeClientCohort().
 * B. CURRENT PERFORMANCE - latest genuine provider metric for that content
 *    (views/likes/comments/shares/saves/reach/engagement_rate). This is
 *    simply ContentMetric's own columns - that model is ALREADY documented
 *    (see PeriodPerformanceService's docblock) as "current/latest cumulative,
 *    NOT repurposed" - this service reads it directly, no delta math at all.
 * C. HISTORICAL/PERIOD MOVEMENT - PeriodPerformanceService::computeContentDelta()
 *    for the SAME row+period, attached here PURELY as optional secondary
 *    metadata (period_result) - it NEVER decides whether a row is in the
 *    roster or what "current" values are. A content unit with ZERO
 *    observations before/within the requested month (because the app
 *    genuinely did not exist yet, or the rolling sync window never covered
 *    it) still belongs to its publish-month cohort and still shows its
 *    genuine current numbers - only the SECONDARY "gain during this period"
 *    figure is unavailable for it (coverage_status=unavailable,
 *    availability_category=insufficient_history), and that must never hide
 *    the row or zero out its primary metrics.
 *
 * ROOT CAUSE this corrects (traced live): PeriodPerformanceService::
 * computeContentDelta() intentionally bounds its "current" observation to
 * snapshot_date <= periodEnd (a CORRECT constraint for computing a genuine
 * period-gain delta - it must not peek at future observations). Every
 * consumer that reused isUsable() (coverageStatus !== 'unavailable') as a
 * ROSTER GATE inherited that bound as an accidental roster filter too - any
 * content whose ONLY observations happened to land after periodEnd (e.g. a
 * month before the app/sync ever ran) was excluded from the page entirely,
 * even though the content itself, its publish date, and its current metrics
 * are all completely genuine and known. This service never uses
 * coverageStatus/isUsable() for roster membership - only published_at does.
 */
class ContentCohortService
{
    public function __construct(
        private readonly PeriodPerformanceService $periodPerformanceService,
    ) {
    }

    /**
     * Roster = content whose genuine provider publication timestamp falls
     * within [$periodStart 00:00:00, $periodEnd 23:59:59] (CSV/manual rows
     * use metric_date the same way they always have - Langkah 5, "the
     * canonical publish date" section explicitly scopes provider timestamp
     * tracing to API content; CSV never had a captured provider publish
     * date to begin with, metric_date remains its cohort key, unchanged
     * from PeriodPerformanceService's own long-established CSV semantics).
     *
     * @return array{totals: array, platform_breakdown: array, rows: Collection}
     */
    public function computeClientCohort(int|string $clientId, Carbon $periodStart, Carbon $periodEnd, ?int $platformId = null): array
    {
        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->endOfDay();

        $apiMetrics = ContentMetric::where('client_id', $clientId)
            ->when($platformId, fn ($q) => $q->where('platform_id', $platformId))
            ->where(function ($q) use ($periodStart, $periodEnd) {
                $q->whereHas('instagramMediaSnapshot', fn ($q2) => $q2->whereBetween('published_at', [$periodStart, $periodEnd]))
                    ->orWhereHas('tiktokVideoSnapshot', fn ($q2) => $q2->whereBetween('published_at', [$periodStart, $periodEnd]));
            })
            ->with(['contentItem.contentPillar', 'contentItem.contentType', 'contentItem.contentFormat', 'contentItem.workflow', 'platform', 'instagramMediaSnapshot', 'tiktokVideoSnapshot'])
            ->get();

        $csvMetrics = ContentMetric::where('client_id', $clientId)
            ->when($platformId, fn ($q) => $q->where('platform_id', $platformId))
            ->whereNull('instagram_media_snapshot_id')
            ->whereNull('tiktok_video_snapshot_id')
            ->whereBetween('metric_date', [$periodStart, $periodEnd])
            ->with(['contentItem.contentPillar', 'contentItem.contentType', 'contentItem.contentFormat', 'contentItem.workflow', 'platform'])
            ->get();

        return $this->buildCohortRows($apiMetrics, $csvMetrics, $periodStart, $periodEnd);
    }

    /**
     * Core builder - reusable by consumers with a custom roster query (mirrors
     * PeriodPerformanceService::computeAggregate()'s own reuse pattern for
     * Dashboard/Report, which scope to whereHas('contentItem', ...) instead
     * of client_id directly). $apiMetrics MUST already be filtered to the
     * cohort window by the caller (via published_at on the linked snapshot).
     *
     * @return array{totals: array, platform_breakdown: array, rows: Collection}
     */
    public function buildCohortRows(Collection $apiMetrics, Collection $csvMetrics, Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = $apiMetrics->toBase()->map(function (ContentMetric $metric) use ($periodStart, $periodEnd) {
            if ($metric->instagram_media_snapshot_id) {
                $platformType = 'instagram';
                $identityColumn = 'instagram_media_snapshot_id';
                $identityId = $metric->instagram_media_snapshot_id;
                $publishedAt = $metric->instagramMediaSnapshot?->published_at;
            } else {
                $platformType = 'tiktok';
                $identityColumn = 'tiktok_video_snapshot_id';
                $identityId = $metric->tiktok_video_snapshot_id;
                $publishedAt = $metric->tiktokVideoSnapshot?->published_at;
            }

            // SECONDARY ONLY (concept C) - genuine period-movement delta for
            // this SAME row+period, attached for consumers that want to show
            // it (e.g. "Pertumbuhan periode: Riwayat belum cukup"). NEVER
            // consulted to decide whether this row exists in $rows at all -
            // that was already decided by the roster query's published_at
            // filter before this map() even runs.
            $periodResult = $this->periodPerformanceService->computeContentDelta(
                $platformType, $identityColumn, $identityId, $publishedAt, $periodStart, $periodEnd
            );

            return [
                'content_metric' => $metric,
                'published_at' => $publishedAt,
                'source' => 'api',
                'period_result' => $periodResult,
            ];
        });

        // CSV/manual - metric_date IS the cohort date (no separate provider
        // publish timestamp exists for manual rows, unchanged semantics).
        // period_result null - PeriodPerformanceService's own CSV handling
        // already treats metric_date as a per-period value, not a
        // cumulative snapshot chain to diff; there is no separate "movement
        // during the period" concept to compute for it here beyond the
        // value itself.
        $csvRows = $csvMetrics->toBase()->map(fn (ContentMetric $metric) => [
            'content_metric' => $metric,
            'published_at' => Carbon::parse($metric->metric_date)->startOfDay(),
            'source' => 'csv',
            'period_result' => null,
        ]);

        $allRows = $rows->merge($csvRows)->values();

        // CURRENT PERFORMANCE (concept B) - ContentMetric's own columns,
        // APA ADANYA, never derived from a delta. null stays null (a metric
        // genuinely not captured/unsupported by the platform), never
        // silently summed as 0 - same "null != 0" discipline
        // PeriodPerformanceService already established.
        $sumField = fn (string $field) => (int) $allRows
            ->map(fn ($row) => $row['content_metric']->{$field})
            ->filter(fn ($v) => $v !== null)
            ->sum();

        $engagementValues = $allRows->map(fn ($row) => $row['content_metric']->engagement_rate)->filter(fn ($v) => $v !== null);

        $platformBreakdown = $allRows
            ->groupBy(fn ($row) => $row['content_metric']->platform_id)
            ->map(function ($groupRows, $platformId) {
                $platform = Platform::find($platformId);
                $value = $groupRows->map(fn ($row) => $row['content_metric']->views)->filter(fn ($v) => $v !== null)->sum();

                return ['label' => $platform->name ?? '-', 'value' => (int) $value];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'totals' => [
                'content_count' => $allRows->count(),
                'views' => $sumField('views'),
                'likes' => $sumField('likes'),
                'comments' => $sumField('comments'),
                'shares' => $sumField('shares'),
                'saves' => $sumField('saves'),
                'engagement_rate' => $engagementValues->isNotEmpty() ? round($engagementValues->avg(), 2) : null,
                'platforms_tracked' => $allRows->pluck('content_metric.platform_id')->unique()->count(),
            ],
            'platform_breakdown' => $platformBreakdown,
            'rows' => $allRows,
        ];
    }
}
