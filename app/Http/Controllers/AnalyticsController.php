<?php

namespace App\Http\Controllers;

use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentType;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * KF3xx — Content Analytics (disatukan)
 *
 * PENYEDERHANAAN (atas permintaan pemilik agensi): Overview, Performance
 * Table, dan Audience Dashboard sebelumnya 3 halaman terpisah yang
 * masing-masing minta pilih client sendiri-sendiri. Sekarang digabung jadi
 * 1 route (/analytics) dengan tab, client cuma dipilih SEKALI di atas,
 * berlaku ke semua tab.
 *
 * Content/Client Performance Detail (show()) SENGAJA TETAP TERPISAH,
 * karena itu halaman drill-down per-konten (dinavigasi dari daftar),
 * bukan halaman level-client yang butuh dropdown client di atas.
 */
class AnalyticsController extends Controller
{
    private const VALID_TABS = ['overview', 'table', 'audience'];

    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), self::VALID_TABS) ? $request->input('tab') : 'overview';

        $clientOptions = Client::where('status', 'active')->get();
        $selectedClientId = $request->input('client_id');

        if (! $selectedClientId) {
            return view('analytics.index', [
                'tab' => $tab,
                'noClientSelected' => true,
                'clientOptions' => $clientOptions,
                'selectedClientId' => null,
                'period' => (int) $request->input('period', 30),
            ]);
        }

        $client = Client::findOrFail($selectedClientId);

        $data = match ($tab) {
            'table' => $this->buildTableData($request, $client),
            'audience' => $this->buildAudienceData($request, $client),
            default => $this->buildOverviewData($request, $client),
        };

        return view('analytics.index', array_merge($data, [
            'tab' => $tab,
            'client' => $client,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $selectedClientId,
        ]));
    }

    /**
     * KF3xx — Content / Client Performance Detail (tetap halaman terpisah)
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

        $hasVideoMetrics = $metrics->contains(fn ($m) => $m->watch_time_avg !== null || $m->completion_rate !== null || $m->shares !== null || $m->saves !== null);
        $avgWatchTime = $hasVideoMetrics ? round($metrics->whereNotNull('watch_time_avg')->avg('watch_time_avg')) : null;
        $avgCompletionRate = $hasVideoMetrics ? round($metrics->whereNotNull('completion_rate')->avg('completion_rate'), 2) : null;
        $totalShares = $hasVideoMetrics ? (int) $metrics->sum('shares') : null;
        $totalSaves = $hasVideoMetrics ? (int) $metrics->sum('saves') : null;

        $chronological = $metrics->sortBy('metric_date')->values();
        $trend = $chronological->map(fn ($m) => [
            'label' => Carbon::parse($m->metric_date)->translatedFormat('d M'),
            'value' => (int) $m->views,
        ])->values();

        $syncLogs = $metrics->pluck('syncLog')->filter()->unique('id')->sortByDesc('created_at')->values();

        $peerStart = Carbon::now()->subDays(29)->startOfDay();
        $peerEnd = Carbon::now()->endOfDay();

        $thisContentRecent = $metrics->whereBetween('metric_date', [$peerStart, $peerEnd]);
        $recentViews = (int) $thisContentRecent->sum('views');
        $recentEngagement = $thisContentRecent->count() > 0 ? $thisContentRecent->avg('engagement_rate') : null;

        $peerMetrics = ContentMetric::whereHas('contentItem', function ($q) use ($contentItem) {
                $q->where('client_id', $contentItem->client_id)->where('id', '!=', $contentItem->id);
            })
            ->whereBetween('metric_date', [$peerStart, $peerEnd])
            ->get();

        $peerAvgViews = $peerMetrics->isNotEmpty()
            ? $peerMetrics->groupBy('content_item_id')->map(fn ($rows) => $rows->sum('views'))->avg()
            : null;
        $peerAvgEngagement = $peerMetrics->isNotEmpty() ? $peerMetrics->avg('engagement_rate') : null;

        $viewsVsPeerPct = ($peerAvgViews && $peerAvgViews > 0)
            ? round((($recentViews - $peerAvgViews) / $peerAvgViews) * 100)
            : null;
        $engagementVsPeerPct = ($peerAvgEngagement && $peerAvgEngagement > 0 && $recentEngagement !== null)
            ? round((($recentEngagement - $peerAvgEngagement) / $peerAvgEngagement) * 100)
            : null;
        $hasPeerComparison = $peerMetrics->isNotEmpty() && $thisContentRecent->isNotEmpty();

        return view('analytics.show', compact(
            'contentItem', 'metrics', 'totalViews', 'avgEngagement',
            'daysTracked', 'bestDate', 'trend', 'syncLogs',
            'hasVideoMetrics', 'avgWatchTime', 'avgCompletionRate', 'totalShares', 'totalSaves',
            'hasPeerComparison', 'viewsVsPeerPct', 'engagementVsPeerPct', 'peerAvgViews', 'peerAvgEngagement'
        ));
    }

    public function export(Request $request)
    {
        $selectedClientId = $request->input('client_id');

        if (! $selectedClientId) {
            return back()->with('export_error', 'Pilih client dulu sebelum export.');
        }

        $client = Client::findOrFail($selectedClientId);

        $period = (int) $request->input('period', 30);
        $period = in_array($period, [7, 30, 90]) ? $period : 30;
        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $end = Carbon::now()->endOfDay();

        $metrics = ContentMetric::with(['contentItem', 'platform'])
            ->whereHas('contentItem', fn ($q) => $q->where('client_id', $client->id))
            ->whereBetween('metric_date', [$start, $end])
            ->orderBy('metric_date')
            ->get();

        $filename = 'performance-'.str($client->name)->slug().'-'.now()->format('Ymd-His').'.csv';

        $callback = function () use ($metrics) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['content_title', 'platform', 'metric_date', 'views', 'engagement_rate']);

            foreach ($metrics as $m) {
                fputcsv($handle, [
                    $m->contentItem->title ?? '-',
                    $m->platform->name ?? '-',
                    Carbon::parse($m->metric_date)->toDateString(),
                    $m->views,
                    $m->engagement_rate,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ================================================================
    // Tab: Overview (dulu index())
    // ================================================================
    private function buildOverviewData(Request $request, Client $client): array
    {
        $period = (int) $request->input('period', 30);
        $period = in_array($period, [7, 30, 90]) ? $period : 30;

        $end = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($period - 1)->startOfDay();

        $baseQuery = ContentMetric::whereHas('contentItem', fn ($qq) => $qq->where('client_id', $client->id));

        $currentMetrics = (clone $baseQuery)->whereBetween('metric_date', [$start, $end])->get();
        $previousMetrics = (clone $baseQuery)->whereBetween('metric_date', [$prevStart, $prevEnd])->get();

        $totalViews = (int) $currentMetrics->sum('views');
        $avgEngagement = $currentMetrics->count() > 0 ? round($currentMetrics->avg('engagement_rate'), 2) : 0;
        $contentPublished = $currentMetrics->pluck('content_item_id')->unique()->count();
        $platformsTracked = $currentMetrics->pluck('platform_id')->unique()->count();

        $prevTotalViews = (int) $previousMetrics->sum('views');
        $prevAvgEngagement = $previousMetrics->count() > 0 ? round($previousMetrics->avg('engagement_rate'), 2) : 0;
        $prevContentPublished = $previousMetrics->pluck('content_item_id')->unique()->count();
        $prevPlatformsTracked = $previousMetrics->pluck('platform_id')->unique()->count();

        $stats = [
            ['label' => 'Total Views', 'value' => number_format($totalViews), 'icon' => 'visibility', ...$this->percentChange($prevTotalViews, $totalViews)],
            ['label' => 'Avg. Engagement Rate', 'value' => number_format($avgEngagement, 2).'%', 'icon' => 'favorite', ...$this->percentChange($prevAvgEngagement, $avgEngagement)],
            ['label' => 'Content Published', 'value' => number_format($contentPublished), 'icon' => 'grid_view', ...$this->percentChange($prevContentPublished, $contentPublished)],
            ['label' => 'Platforms Tracked', 'value' => number_format($platformsTracked), 'icon' => 'hub', ...$this->percentChange($prevPlatformsTracked, $platformsTracked)],
        ];

        $trend = $this->buildTrend($currentMetrics, $start, $end, $period);

        $platformBreakdown = $currentMetrics
            ->groupBy('platform_id')
            ->map(fn ($rows, $platformId) => [
                'label' => Platform::find($platformId)->name ?? '-',
                'value' => (int) $rows->sum('views'),
            ])
            ->sortByDesc('value')
            ->values();

        $topContent = $currentMetrics
            ->groupBy('content_item_id')
            ->map(function ($rows, $contentItemId) {
                $item = ContentItem::with(['client', 'contentType', 'platform'])->find($contentItemId);
                if (! $item) return null;

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'client' => $item->client->name ?? '-',
                    'type' => $item->contentType->name ?? '-',
                    'platform' => $item->platform->name ?? '-',
                    'views' => (int) $rows->sum('views'),
                    'engagement_rate' => round($rows->avg('engagement_rate'), 2),
                ];
            })
            ->filter()
            ->sortByDesc('views')
            ->take(5)
            ->values();

        return compact('stats', 'trend', 'platformBreakdown', 'topContent', 'period');
    }

    // ================================================================
    // Tab: Table (dulu table())
    // ================================================================
    private function buildTableData(Request $request, Client $client): array
    {
        $sort = $request->input('sort', 'total_views');
        $dir = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['total_views', 'avg_engagement', 'deadline_at', 'title'];
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'total_views';
        }

        $query = ContentItem::with(['platform', 'contentType', 'workflow'])
            ->where('client_id', $client->id)
            ->withSum('metrics as total_views', 'views')
            ->withAvg('metrics as avg_engagement', 'engagement_rate');

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->input('platform_id'));
        }
        if ($request->filled('content_type_id')) {
            $query->where('content_type_id', $request->input('content_type_id'));
        }
        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->input('search').'%');
        }

        if (in_array($sort, ['total_views', 'avg_engagement'])) {
            $query->orderByRaw("{$sort} IS NULL, {$sort} {$dir}");
        } else {
            $query->orderBy($sort, $dir);
        }

        $items = $query->paginate(15)->withQueryString();

        $platformOptions = Platform::whereHas('contentItems', fn ($q) => $q->where('client_id', $client->id))->get();
        $contentTypeOptions = ContentType::whereHas('contentItems', fn ($q) => $q->where('client_id', $client->id))->get();

        return compact('items', 'platformOptions', 'contentTypeOptions', 'sort', 'dir');
    }

    // ================================================================
    // Tab: Audience (dulu AudienceController@index)
    // ================================================================
    private function buildAudienceData(Request $request, Client $client): array
    {
        $platforms = Platform::whereHas('audienceInsights', fn ($q) => $q->where('client_id', $client->id))->get();

        if ($platforms->isEmpty()) {
            return ['noInsightData' => true];
        }

        $selectedPlatformId = $request->input('platform_id', $platforms->first()->id);
        $platform = $platforms->firstWhere('id', (int) $selectedPlatformId) ?? $platforms->first();

        $period = (int) $request->input('period', 30);
        $period = in_array($period, [7, 30, 90]) ? $period : 30;

        $latestSnapshot = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->latest('snapshot_date')
            ->first();

        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $history = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->where('snapshot_date', '>=', $start)
            ->orderBy('snapshot_date')
            ->get();

        $followerTrend = $history->map(fn ($row) => [
            'label' => Carbon::parse($row->snapshot_date)->translatedFormat('d M'),
            'value' => (int) $row->follower_count,
        ])->values();

        $firstCount = $history->first()->follower_count ?? 0;
        $lastCount = $history->last()->follower_count ?? ($latestSnapshot->follower_count ?? 0);
        $growth = $firstCount > 0 ? round((($lastCount - $firstCount) / $firstCount) * 100, 1) : null;

        $genderBreakdown = $latestSnapshot->gender_breakdown ?? [];
        $ageBreakdown = $latestSnapshot->age_breakdown ?? [];
        $topLocations = collect($latestSnapshot->top_locations ?? [])->sortByDesc('percentage')->values();

        $activeHoursRaw = $latestSnapshot->active_hours ?? [];
        $activeHours = collect(range(0, 23))->map(fn ($hour) => [
            'label' => str_pad($hour, 2, '0', STR_PAD_LEFT).':00',
            'value' => (int) ($activeHoursRaw[$hour] ?? $activeHoursRaw[(string) $hour] ?? 0),
        ]);
        $peakHour = $activeHours->sortByDesc('value')->first();

        return compact(
            'platforms', 'platform', 'selectedPlatformId', 'period',
            'latestSnapshot', 'followerTrend', 'growth', 'lastCount',
            'genderBreakdown', 'ageBreakdown', 'topLocations', 'activeHours', 'peakHour'
        );
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

        if ($percent > 0) return ['change' => "+{$percent}% dari periode sebelumnya", 'trend' => 'up'];
        if ($percent < 0) return ['change' => "{$percent}% dari periode sebelumnya", 'trend' => 'down'];

        return ['change' => 'Sama seperti periode sebelumnya', 'trend' => 'flat'];
    }
}