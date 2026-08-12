<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\Platform;
use Illuminate\Support\Carbon;

/**
 * Agregasi "Overview" analytics (stats, trend, platform breakdown, top
 * content) untuk 1 client + periode - dipakai bareng oleh AnalyticsController
 * (internal, tab Overview) dan Client\AnalyticsController (client-side),
 * biar query-nya nggak dobel dan otomatis sinkron kalau logic-nya berubah.
 */
class AnalyticsSummaryService
{
    public function buildOverviewData(int|string $clientId, int $period): array
    {
        $end = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($period - 1)->startOfDay();

        $baseQuery = ContentMetric::query()
            ->whereHas('contentItem', fn ($q) => $q->where('client_id', $clientId));

        $currentMetrics = (clone $baseQuery)->whereBetween('metric_date', [$start, $end])->get();
        $previousMetrics = (clone $baseQuery)->whereBetween('metric_date', [$prevStart, $prevEnd])->get();

        $totalViews = (int) $currentMetrics->sum('views');
        $avgEngagement = $currentMetrics->count() > 0
            ? round($currentMetrics->avg('engagement_rate'), 2)
            : 0;
        $contentPublished = $currentMetrics->pluck('content_item_id')->unique()->count();
        $platformsTracked = $currentMetrics->pluck('platform_id')->unique()->count();

        $prevTotalViews = (int) $previousMetrics->sum('views');
        $prevAvgEngagement = $previousMetrics->count() > 0
            ? round($previousMetrics->avg('engagement_rate'), 2)
            : 0;
        $prevContentPublished = $previousMetrics->pluck('content_item_id')->unique()->count();
        $prevPlatformsTracked = $previousMetrics->pluck('platform_id')->unique()->count();

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

        $trend = $this->buildTrend($currentMetrics, $start, $end, $period);

        $platformBreakdown = $currentMetrics
            ->groupBy('platform_id')
            ->map(function ($rows, $platformId) {
                $platform = Platform::find($platformId);

                return [
                    'label' => $platform->name ?? '-',
                    'value' => (int) $rows->sum('views'),
                ];
            })
            ->sortByDesc('value')
            ->values();

        $topContent = $currentMetrics
            ->groupBy('content_item_id')
            ->map(function ($rows, $contentItemId) {
                $item = ContentItem::with(['client', 'contentType', 'platform'])->find($contentItemId);
                if (! $item) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'client' => $item->client->name ?? '-',
                    'type' => $item->contentType->name ?? '-',
                    'platform' => $item->platform->name ?? '-',
                    'views' => (int) $rows->sum('views'),
                    'engagement_rate' => round($rows->avg('engagement_rate'), 2),
                    'last_metric_date' => $rows->max('metric_date'),
                ];
            })
            ->filter()
            ->sortByDesc('views')
            ->take(5)
            ->values();

        return compact('stats', 'trend', 'platformBreakdown', 'topContent');
    }

    public function buildTrend($metrics, Carbon $start, Carbon $end, int $period): array
    {
        if ($period <= 30) {
            $byDate = $metrics->groupBy(fn ($m) => Carbon::parse($m->metric_date)->format('Y-m-d'));

            $points = collect();
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $key = $cursor->format('Y-m-d');
                $points->push([
                    'label' => $cursor->translatedFormat('d/m'),
                    'value' => (int) ($byDate->get($key)?->sum('views') ?? 0),
                ]);
                $cursor->addDay();
            }

            return $points->toArray();
        }

        // 90 hari -> kelompokkan per minggu
        $byWeek = $metrics->groupBy(fn ($m) => Carbon::parse($m->metric_date)->startOfWeek()->format('Y-m-d'));

        $points = collect();
        $cursor = $start->copy()->startOfWeek();
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m-d');
            $points->push([
                'label' => $cursor->translatedFormat('d M'),
                'value' => (int) ($byWeek->get($key)?->sum('views') ?? 0),
            ]);
            $cursor->addWeek();
        }

        return $points->toArray();
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
