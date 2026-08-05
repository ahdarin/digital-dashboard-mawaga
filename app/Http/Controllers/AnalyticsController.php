<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\Platform;
use App\Models\AiStrategyInsight;
use App\Services\AiStrategyService;
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

        $latestAiInsight = AiStrategyInsight::where('client_id', $selectedClientId)
            ->latest()
            ->first();

        return view('analytics.index', compact(
            'stats', 'trend', 'platformBreakdown', 'topContent',
            'clientOptions', 'selectedClientId', 'period', 'latestAiInsight'
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

        // Metrik video (Reels/TikTok) - null semua kalau konten ini nggak
        // pernah punya data ini sama sekali (misal konten Feed/foto)
        $hasVideoMetrics = $metrics->contains(fn ($m) => $m->watch_time_avg !== null || $m->completion_rate !== null || $m->shares !== null || $m->saves !== null);
        $avgWatchTime = $hasVideoMetrics ? round($metrics->whereNotNull('watch_time_avg')->avg('watch_time_avg')) : null;
        $avgCompletionRate = $hasVideoMetrics ? round($metrics->whereNotNull('completion_rate')->avg('completion_rate'), 2) : null;
        $totalShares = $hasVideoMetrics ? (int) $metrics->sum('shares') : null;
        $totalSaves = $hasVideoMetrics ? (int) $metrics->sum('saves') : null;

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

        // --- Perbandingan vs rata-rata konten lain milik client yang sama ---
        // Dibatasi 30 hari terakhir biar adil dibandingin (konten yang lebih
        // lama ditrack otomatis lebih unggul kalau dibandingin all-time).
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


    /**
     * KF3xx — Export Performance Data
     * Download CSV performa konten client terpilih pada periode terpilih.
     * Sengaja pakai format kolom yang SAMA persis dengan Import CSV
     * (content_title,platform,metric_date,views,engagement_rate) - biar
     * hasil export bisa langsung di-import ulang (misal buat backup, atau
     * dipindah ke client lain).
     */
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

    /**
     * KF3xx — Performance Table
     * List semua content item milik 1 client, lengkap dengan agregat
     * metrik-nya (total views, avg engagement) - sortable & filterable.
     */
    public function table(Request $request)
    {
        $clientOptions = Client::where('status', 'active')->get();
        $selectedClientId = $request->input('client_id');

        if (! $selectedClientId) {
            return view('analytics.table', [
                'noClientSelected' => true,
                'clientOptions' => $clientOptions,
                'selectedClientId' => null,
            ]);
        }

        $client = Client::findOrFail($selectedClientId);

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
        $contentTypeOptions = \App\Models\ContentType::whereHas('contentItems', fn ($q) => $q->where('client_id', $client->id))->get();

        return view('analytics.table', compact(
            'client', 'clientOptions', 'selectedClientId', 'items',
            'platformOptions', 'contentTypeOptions', 'sort', 'dir'
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

    /**
     * KF3xx — AI Strategy Analysis (beneran manggil Gemini API, bukan
     * teks statis). Trigger manual lewat tombol di halaman Analytics.
     */
    public function generateAiStrategy(Request $request, AiStrategyService $aiStrategyService)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
        ]);

        $client = Client::findOrFail($validated['client_id']);

        $periodStart = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $periodEnd = Carbon::now()->subMonthNoOverflow()->endOfMonth();

        try {
            $summary = $aiStrategyService->buildPerformanceSummary($client);

            if ($summary['content_published_count'] === 0) {
                return back()->with('ai_error', 'Belum ada data performa konten bulan '.$periodStart->translatedFormat('F Y').' buat client ini - AI butuh data buat dianalisis, bukan nebak.');
            }

            $result = $aiStrategyService->generateStrategy($summary);

            $dataCompleteness = min(100, round(($summary['tracked_days'] / $summary['period_days']) * 100));

            AiStrategyInsight::create([
                'client_id' => $client->id,
                'generated_by' => auth()->id(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'performance_data' => $summary,
                'summary' => $result['summary'],
                'action_items' => $result['action_items'],
                'suggested_split' => $result['suggested_split'],
                'top_pillars' => $result['top_pillars'],
                'content_ideas' => $result['content_ideas'],
                'data_completeness_percent' => $dataCompleteness,
                'status' => 'completed',
            ]);

            return back()->with('ai_success', 'Analisis AI berhasil digenerate.');
        } catch (\Throwable $e) {
            AiStrategyInsight::create([
                'client_id' => $client->id,
                'generated_by' => auth()->id(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary' => '-',
                'action_items' => [],
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return back()->with('ai_error', 'Gagal generate analisis AI: '.$e->getMessage());
        }
    }

    /**
     * KF3xx — Terapkan hasil AI Strategy ke Content Plan.
     *
     * BENERAN ngefek ke sistem (bukan tombol dekoratif): generate draft
     * ContentItem buat bulan berjalan, jumlah & distribusinya ngikutin
     * suggested_split dari AI. User tetap wajib isi judul/brief detail -
     * ini cuma bikinin "kerangka" plan-nya biar nggak mulai dari kosong.
     */
    public function applyAiStrategy(AiStrategyInsight $aiStrategyInsight)
    {
        abort_if($aiStrategyInsight->status !== 'completed', 422, 'Cuma analisis yang berhasil yang bisa diterapkan.');
        abort_if($aiStrategyInsight->applied_at !== null, 422, 'Analisis ini sudah pernah diterapkan sebelumnya.');

        $client = $aiStrategyInsight->client;
        $activePackage = $client->activePackage;

        abort_unless($activePackage, 422, 'Client ini belum punya paket aktif, tidak bisa dibuatkan content plan.');

        $now = Carbon::now();

        $plan = \App\Models\ContentPlan::firstOrCreate(
            ['client_id' => $client->id, 'month' => $now->month, 'year' => $now->year],
            [
                'client_package_id' => $activePackage->id,
                'created_by' => auth()->id(),
                'status' => 'draft',
            ]
        );

        $totalItems = min($activePackage->monthly_content_quota ?: 10, 10);
        $split = collect($aiStrategyInsight->suggested_split);
        $splitSum = $split->sum('value') ?: 100;
        $daysRemaining = max($now->daysInMonth - $now->day, 1);
        $ideasByPillar = collect($aiStrategyInsight->content_ideas)->groupBy('pillar');

        $created = 0;
        foreach ($split as $row) {
            $count = (int) round(($row['value'] / $splitSum) * $totalItems);
            if ($count < 1) {
                continue;
            }

            $pillar = \App\Models\ContentPillar::firstOrCreate(['name' => $row['label']]);
            $reasoning = collect($aiStrategyInsight->top_pillars)->firstWhere('name', $row['label'])['reasoning'] ?? null;
            $ideasForPillar = $ideasByPillar->get($row['label'], collect());

            for ($i = 0; $i < $count; $i++) {
                $deadline = $now->copy()->addDays(rand(1, $daysRemaining));
                $idea = $ideasForPillar->get($i);

                $item = \App\Models\ContentItem::create([
                    'content_plan_id' => $plan->id,
                    'client_id' => $client->id,
                    'content_pillar_id' => $pillar->id,
                    'ai_strategy_insight_id' => $aiStrategyInsight->id,
                    'title' => $idea['title'] ?? "[Draft AI] {$row['label']} #".($i + 1),
                    'brief' => $idea['brief'] ?? ($reasoning ? "Rekomendasi AI: {$reasoning}" : "Digenerate dari AI Strategy Analysis ({$row['value']}% dari komposisi yang disarankan)."),
                    'deadline_at' => $deadline,
                ]);

                \App\Models\ContentWorkflow::create([
                    'content_item_id' => $item->id,
                    'current_status' => 'planned',
                    'is_overdue' => false,
                ]);

                $created++;
            }
        }

        $aiStrategyInsight->update(['applied_at' => $now, 'applied_by' => auth()->id()]);

        return redirect()->route('content-plan.show', $plan)
            ->with('status', "{$created} draft content item dibuat berdasarkan rekomendasi AI. Judul & brief masih perlu dilengkapi manual.");
    }

    /**
     * KF3xx — Revert (tarik kembali) hasil AI Strategy yang udah diterapkan.
     *
     * Hapus SEMUA content item yang dibuat dari insight ini (ditandai lewat
     * ai_strategy_insight_id), asal belum ada progress beneran di item
     * itu (belum posting, belum ada revisi/metrik) - biar nggak ngilangin
     * kerjaan tim yang udah kadung jalan.
     */
    public function revertAiStrategy(AiStrategyInsight $aiStrategyInsight)
    {
        abort_if($aiStrategyInsight->applied_at === null, 422, 'Analisis ini belum pernah diterapkan.');

        $generatedItems = \App\Models\ContentItem::where('ai_strategy_insight_id', $aiStrategyInsight->id)
            ->with(['workflow', 'revisions', 'metrics'])
            ->get();

        $hasProgress = $generatedItems->contains(function ($item) {
            return $item->is_posted
                || $item->revisions->isNotEmpty()
                || $item->metrics->isNotEmpty()
                || ($item->workflow && ! in_array($item->workflow->current_status, ['planned', 'brief_ready']));
        });

        if ($hasProgress) {
            return back()->with('ai_error', 'Nggak bisa di-revert - sebagian draft dari analisis ini udah ada progress (revisi/posting/metrik). Hapus manual satu-satu kalau tetap mau dibatalkan.');
        }

        $deletedCount = $generatedItems->count();

        foreach ($generatedItems as $item) {
            $item->workflow?->delete();
            $item->delete();
        }

        $aiStrategyInsight->update(['applied_at' => null, 'applied_by' => null]);

        return back()->with('ai_success', "{$deletedCount} draft content item berhasil ditarik kembali. Analisis ini bisa diterapkan ulang kalau perlu.");
    }

    /**
     * KF3xx — Kirim pesan diskusi ke AI soal 1 hasil analisis.
     * Dipanggil via fetch/AJAX (bukan reload halaman), makanya balikin
     * JSON, bukan redirect.
     */
    public function sendChatMessage(Request $request, AiStrategyInsight $aiStrategyInsight, AiStrategyService $aiStrategyService)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        if (empty($aiStrategyInsight->performance_data)) {
            return response()->json(['error' => 'Analisis ini dibuat sebelum fitur diskusi ada, jadi nggak punya data mentah buat dirujuk. Generate ulang analisisnya dulu.'], 422);
        }

        \App\Models\AiStrategyMessage::create([
            'ai_strategy_insight_id' => $aiStrategyInsight->id,
            'user_id' => auth()->id(),
            'role' => 'user',
            'message' => $validated['message'],
        ]);

        try {
            $history = $aiStrategyInsight->messages()
                ->get()
                ->map(fn ($m) => ['role' => $m->role, 'message' => $m->message])
                ->all();

            $previousResult = [
                'summary' => $aiStrategyInsight->summary,
                'action_items' => $aiStrategyInsight->action_items,
                'suggested_split' => $aiStrategyInsight->suggested_split,
            ];

            $reply = $aiStrategyService->chat(
                $aiStrategyInsight->performance_data,
                $previousResult,
                $history,
                $validated['message']
            );

            $assistantMessage = \App\Models\AiStrategyMessage::create([
                'ai_strategy_insight_id' => $aiStrategyInsight->id,
                'user_id' => null,
                'role' => 'assistant',
                'message' => $reply,
            ]);

            return response()->json([
                'message' => $assistantMessage->message,
                'created_at' => $assistantMessage->created_at->format('H:i'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Gagal dapetin balesan AI: '.$e->getMessage()], 500);
        }
    }

    /**
     * KF3xx — Perbarui analisis terstruktur berdasarkan seluruh diskusi
     * yang udah terjadi. Nge-update insight yang SAMA (bukan bikin baru),
     * biar histori chat-nya tetep nyambung ke 1 insight yang sama.
     */
    public function refineFromDiscussion(AiStrategyInsight $aiStrategyInsight, AiStrategyService $aiStrategyService)
    {
        abort_if(empty($aiStrategyInsight->performance_data), 422, 'Analisis ini nggak punya data mentah, generate ulang dulu.');
        abort_if($aiStrategyInsight->messages->isEmpty(), 422, 'Belum ada diskusi buat dijadiin dasar pembaruan.');

        try {
            $history = $aiStrategyInsight->messages()
                ->get()
                ->map(fn ($m) => ['role' => $m->role, 'message' => $m->message])
                ->all();

            $previousResult = [
                'summary' => $aiStrategyInsight->summary,
                'action_items' => $aiStrategyInsight->action_items,
                'suggested_split' => $aiStrategyInsight->suggested_split,
            ];

            $result = $aiStrategyService->refineFromDiscussion(
                $aiStrategyInsight->performance_data,
                $previousResult,
                $history
            );

            $aiStrategyInsight->update([
                'summary' => $result['summary'],
                'action_items' => $result['action_items'],
                'suggested_split' => $result['suggested_split'],
                'top_pillars' => $result['top_pillars'],
                'content_ideas' => $result['content_ideas'],
            ]);

            \App\Models\AiStrategyMessage::create([
                'ai_strategy_insight_id' => $aiStrategyInsight->id,
                'user_id' => null,
                'role' => 'system',
                'message' => 'Analisis diperbarui berdasarkan diskusi di atas oleh '.(auth()->user()->name ?? 'user').'.',
            ]);

            return back()->with('ai_success', 'Analisis berhasil diperbarui berdasarkan diskusi.');
        } catch (\Throwable $e) {
            return back()->with('ai_error', 'Gagal memperbarui analisis: '.$e->getMessage());
        }
    }
}