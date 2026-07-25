<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    /**
     * KF3xx — Content Analytics Dashboard
     * Ringkasan performa konten terpublikasi (views & engagement rate)
     * lintas client/platform untuk periode yang dipilih.
     */
    public function index(Request $request)
    {
        $period = (int) $request->input('period', 30); // 7 / 30 / 90 hari
        $period = in_array($period, [7, 30, 90]) ? $period : 30;

        $selectedClientId = $request->input('client_id');
        $clientOptions = Client::where('status', 'active')->get();

        // Sengaja: kalau belum pilih client, JANGAN agregat semua client
        // sekaligus (biar nggak "ramai" dan lambat) - tampilkan empty
        // state, minta pilih client dulu di dropdown.
        if (! $selectedClientId) {
            return view('analytics.index', [
                'noClientSelected' => true,
                'clientOptions' => $clientOptions,
                'selectedClientId' => null,
                'period' => $period,
            ]);
        }

        $end = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($period - 1)->startOfDay();

        $baseQuery = ContentMetric::query()
            ->when($selectedClientId, fn ($q) => $q->whereHas(
                'contentItem',
                fn ($qq) => $qq->where('client_id', $selectedClientId)
            ));

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

        // Grafik tren views: harian utk 7/30 hari, mingguan utk 90 hari
        $trend = $this->buildTrend($currentMetrics, $start, $end, $period);

        // Breakdown per platform
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

        // Top performing content
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

        return view('analytics.index', compact(
            'stats', 'trend', 'platformBreakdown', 'topContent',
            'clientOptions', 'selectedClientId', 'period'
        ));
    }

    /**
     * KF3xx — Content / Client Performance Detail
     * Detail performa satu content item: histori metrik harian per platform
     * beserta log sinkronisasi/import datanya.
     */
    public function show(ContentItem $contentItem)
    {
        $contentItem->load(['client', 'contentType', 'platform']);

        $metrics = ContentMetric::where('content_item_id', $contentItem->id)
            ->with(['platform', 'syncLog', 'importedBy'])
            ->orderByDesc('metric_date')
            ->get();

        $totalViews = (int) $metrics->sum('views');
        $avgEngagement = $metrics->count() > 0 ? round($metrics->avg('engagement_rate'), 2) : 0;
        $daysTracked = $metrics->pluck('metric_date')->unique()->count();
        $bestDate = $metrics->sortByDesc('views')->first();

        // Data untuk grafik tren (urut tanggal naik)
        $chronological = $metrics->sortBy('metric_date')->values();
        $trend = $chronological->map(fn ($m) => [
            'label' => Carbon::parse($m->metric_date)->translatedFormat('d M'),
            'value' => (int) $m->views,
        ])->values();

        $syncLogs = $metrics
            ->pluck('syncLog')
            ->filter()
            ->unique('id')
            ->sortByDesc('created_at')
            ->values();

        return view('analytics.show', compact(
            'contentItem', 'metrics', 'totalViews', 'avgEngagement',
            'daysTracked', 'bestDate', 'trend', 'syncLogs'
        ));
    }


    private function buildTrend($metrics, Carbon $start, Carbon $end, int $period): array
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