<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Agregasi "Overview" analytics (stats, trend, platform breakdown, top
 * content) untuk 1 client + periode - dipakai bareng oleh AnalyticsController
 * (internal, tab Overview) dan Client\AnalyticsController (client-side),
 * biar query-nya nggak dobel dan otomatis sinkron kalau logic-nya berubah.
 *
 * Phase 3: angka performa periode (views/engagement/content count) SEKARANG
 * datang dari PeriodPerformanceService (delta cumulative genuine dari
 * content_metric_snapshots buat content API, CSV/manual tetap semantik lama)
 * - BUKAN lagi whereBetween('metric_date', period) di atas ContentMetric
 * (bug lama: metric_date API dikunci ke tanggal PUBLISH, jadi query lama
 * sebenarnya memfilter "diterbitkan dalam periode" bukan "performa
 * diperoleh dalam periode"). Lihat docblock PeriodPerformanceService buat
 * penjelasan lengkap coverage/boundary semantics.
 */
class AnalyticsSummaryService
{
    public function __construct(private readonly PeriodPerformanceService $periodPerformanceService)
    {
    }

    public function buildOverviewData(int|string $clientId, int $period, ?int $platformId = null): array
    {
        $end = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($period - 1)->startOfDay();

        $current = $this->periodPerformanceService->computeClientPeriod($clientId, $start, $end, $platformId);
        $previous = $this->periodPerformanceService->computeClientPeriod($clientId, $prevStart, $prevEnd, $platformId);

        $totalViews = $current['totals']['views'];
        $avgEngagement = $current['totals']['engagement_rate'] ?? 0;
        $contentPublished = $current['totals']['content_count'];
        $platformsTracked = $current['totals']['platforms_tracked'];

        $prevTotalViews = $previous['totals']['views'];
        $prevAvgEngagement = $previous['totals']['engagement_rate'] ?? 0;
        $prevContentPublished = $previous['totals']['content_count'];
        $prevPlatformsTracked = $previous['totals']['platforms_tracked'];

        // Langkah 11 - coverage historis harus jelas ke user, JANGAN
        // tampilkan "30 Hari: X views" tanpa qualifier kalau datanya belum
        // penuh (Langkah 5). Audience coverage TETAP TERPISAH (Langkah 11 -
        // ini cuma untuk performa konten, bukan buildAudienceTabData()).
        $coverageStatus = $current['coverage']['status'];
        $coverageMessage = $this->buildCoverageMessage($current['coverage'], $period);

        $stats = [
            [
                'label' => 'Total Views',
                'value' => number_format($totalViews),
                'icon' => 'visibility',
                ...$this->percentChange($prevTotalViews, $totalViews),
            ],
            [
                'label' => 'Avg. Engagement Rate',
                'value' => number_format($avgEngagement, 2) . '%',
                'icon' => 'favorite',
                ...$this->percentChange($prevAvgEngagement, $avgEngagement),
            ],
            [
                'label' => 'Content Published',
                'value' => number_format($contentPublished),
                'icon' => 'grid_view',
                ...$this->percentChange($prevContentPublished, $contentPublished),
            ],
            [
                'label' => 'Platforms Tracked',
                'value' => number_format($platformsTracked),
                'icon' => 'hub',
                ...$this->percentChange($prevPlatformsTracked, $platformsTracked),
            ],
        ];

        $dailySeries = $this->periodPerformanceService->computeDailyGainSeries($clientId, $start, $end, $platformId);
        $trend = $this->buildTrend($dailySeries, $period);

        $platformBreakdown = collect($current['platform_breakdown']);

        $topContent = collect($current['rows'])
            ->filter(fn ($row) => $row['result']->isUsable())
            ->map(fn ($row) => $this->presentTopContentRow($row))
            ->filter()
            ->sortByDesc('views')
            ->take(5)
            ->values();

        return compact('stats', 'trend', 'platformBreakdown', 'topContent', 'coverageStatus', 'coverageMessage');
    }

    /**
     * Bangun 1 baris Top Content dari hasil PeriodPerformanceService - views/
     * engagement_rate SEKARANG delta periode (Phase 3), BUKAN lagi sum
     * mentah content_metrics.views. coverage_status diikutkan biar UI bisa
     * kasih badge "partial"/qualifier kalau bukan gain periode penuh
     * (Langkah 12 - jangan ranking lifetime metric sementara header bilang
     * 7/30/90 hari tanpa qualifier apapun).
     */
    private function presentTopContentRow(array $row): ?array
    {
        $metric = $row['content_metric'];
        $result = $row['result'];

        if ($metric->content_item_id) {
            $item = ContentItem::with(['client', 'contentType', 'platform'])->find($metric->content_item_id);
            if (! $item) {
                return null;
            }

            $linkedSnapshot = $metric->instagram_media_snapshot_id
                ? InstagramMediaSnapshot::find($metric->instagram_media_snapshot_id)
                : ($metric->tiktok_video_snapshot_id ? \App\Models\TikTokVideoSnapshot::find($metric->tiktok_video_snapshot_id) : null);

            return [
                'id' => $item->id,
                'title' => $item->title,
                'client' => $item->client->name ?? '-',
                'type' => $item->contentType->name ?? '-',
                'platform' => $item->platform->name ?? '-',
                'views' => $result->views() ?? 0,
                'engagement_rate' => $result->engagementRate,
                'coverage_status' => $result->coverageStatus,
                'linked' => true,
                'permalink' => $linkedSnapshot?->permalink,
            ];
        }

        // Post API real TAPI belum ke-link - metadata dari
        // InstagramMediaSnapshot/TikTokVideoSnapshot (caption/permalink),
        // bukan ContentItem. TIDAK di-skip (tetap dihitung dalam Analytics),
        // cuma nggak ada link "Detail" internal.
        if ($metric->instagram_media_snapshot_id) {
            $snapshot = InstagramMediaSnapshot::find($metric->instagram_media_snapshot_id);
            $title = $snapshot?->caption ? Str::limit($snapshot->caption, 60) : 'Instagram Post (belum terhubung)';
            $type = $snapshot?->display_format ?? '-';
        } else {
            $snapshot = \App\Models\TikTokVideoSnapshot::find($metric->tiktok_video_snapshot_id);
            $title = $snapshot?->video_description ? Str::limit($snapshot->video_description, 60) : 'TikTok Video (belum terhubung)';
            $type = $snapshot?->display_format ?? '-';
        }

        return [
            'id' => null,
            'title' => $title,
            'client' => '-',
            'type' => $type,
            'platform' => $metric->platform->name ?? Platform::find($metric->platform_id)?->name ?? '-',
            'views' => $result->views() ?? 0,
            'engagement_rate' => $result->engagementRate,
            'coverage_status' => $result->coverageStatus,
            'linked' => false,
            'permalink' => $snapshot->permalink ?? $snapshot->share_url ?? null,
            'api_integration_id' => $snapshot?->api_integration_id,
            'external_post_id' => $snapshot?->external_post_id,
        ];
    }

    /**
     * @param  array<int, array{date: string, label: string, value: ?int, has_gap: bool}>  $dailySeries
     */
    public function buildTrend(array $dailySeries, int $period): array
    {
        return $period <= 30 ? $dailySeries : $this->periodPerformanceService->aggregateWeekly($dailySeries);
    }

    private function buildCoverageMessage(array $coverage, int $period): ?string
    {
        return $this->periodPerformanceService->coverageMessage($coverage, $period);
    }

    private function percentChange(int|float $previous, int|float $current): array
    {
        if ($previous == 0) {
            return $current > 0
                ? ['change' => 'Naik dari 0 di periode sebelumnya', 'trend' => 'up']
                : ['change' => 'Belum ada data', 'trend' => 'flat'];
        }

        $percent = round((($current - $previous) / $previous) * 100, 1);

        if ($percent > 0) {
            return ['change' => "+{$percent}% dari periode sebelumnya", 'trend' => 'up'];
        }

        if ($percent < 0) {
            return ['change' => "{$percent}% dari periode sebelumnya", 'trend' => 'down'];
        }

        return ['change' => 'Sama seperti periode sebelumnya', 'trend' => 'flat'];
    }
}
