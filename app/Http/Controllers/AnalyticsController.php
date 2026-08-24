<?php

namespace App\Http\Controllers;

use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\Platform;
use App\Models\AiStrategyInsight;
use App\Rules\AssignedClient;
use App\Services\AiStrategyService;
use App\Services\AnalyticsSummaryService;
use App\Services\PicAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    /**
     * KF3xx — Content Analytics Dashboard
     * Ringkasan performa konten terpublikasi (views & engagement rate)
     * lintas client/platform untuk periode yang dipilih.
     */
    public function index(Request $request, AiStrategyService $aiStrategyService, AnalyticsSummaryService $analyticsSummaryService)
    {
        $period = (int) $request->input('period', 30); // 7 / 30 / 90 hari
        $period = in_array($period, [7, 30, 90]) ? $period : 30;

        // Analytics, Performance Table, dan Audience sekarang 1 halaman
        // yang sama (tab switch, full reload) - biar client & period yang
        // lagi dipilih nggak ke-reset tiap pindah, dan nggak kerasa
        // "muter-muter" ke halaman lain yang tampilannya beda.
        $activeTab = $request->input('tab', 'overview');
        if (!in_array($activeTab, ['overview', 'table', 'audience'])) {
            $activeTab = 'overview';
        }

        $user = $request->user();
        $selectedClientId = $request->input('client_id');
        $clientOptions = $user->canSeeAllClients()
            ? Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        if ($selectedClientId) {
            $this->assertClientAccessible((int) $selectedClientId);
        }

        // Sengaja: kalau belum pilih client, JANGAN agregat semua client
        // sekaligus (biar nggak "ramai" dan lambat) - tampilkan empty
        // state, minta pilih client dulu di dropdown.
        if (!$selectedClientId) {
            return view('analytics.index', [
                'noClientSelected' => true,
                'clientOptions' => $clientOptions,
                'selectedClientId' => null,
                'period' => $period,
                'activeTab' => $activeTab,
            ]);
        }

        // Tab Performance Table & Audience punya data & query sendiri-
        // sendiri (nggak nyambung ke stats/trend overview) - dihitung
        // terpisah biar tab yang lagi nggak aktif nggak ikut query
        // percuma tiap request.
        if ($activeTab === 'table') {
            return view('analytics.index', array_merge([
                'clientOptions' => $clientOptions,
                'selectedClientId' => $selectedClientId,
                'period' => $period,
                'activeTab' => $activeTab,
            ], $this->buildTableTabData($selectedClientId, $request)));
        }

        if ($activeTab === 'audience') {
            return view('analytics.index', array_merge([
                'clientOptions' => $clientOptions,
                'selectedClientId' => $selectedClientId,
                'period' => $period,
                'activeTab' => $activeTab,
            ], $this->buildAudienceTabData($selectedClientId, $request, $period)));
        }

        ['stats' => $stats, 'trend' => $trend, 'platformBreakdown' => $platformBreakdown, 'topContent' => $topContent]
            = $analyticsSummaryService->buildOverviewData($selectedClientId, $period);

        $latestAiInsight = AiStrategyInsight::where('client_id', $selectedClientId)
            ->latest()
            ->first();

        $aiAnalysisMonth = $aiStrategyService->analysisPeriod()['start']->translatedFormat('F Y');

        return view('analytics.index', compact(
            'stats',
            'trend',
            'platformBreakdown',
            'topContent',
            'clientOptions',
            'selectedClientId',
            'period',
            'latestAiInsight',
            'aiAnalysisMonth',
            'activeTab'
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
        $hasVideoMetrics = $metrics->contains(fn($m) => $m->watch_time_avg !== null || $m->completion_rate !== null || $m->shares !== null || $m->saves !== null);
        $avgWatchTime = $hasVideoMetrics ? round($metrics->whereNotNull('watch_time_avg')->avg('watch_time_avg')) : null;
        $avgCompletionRate = $hasVideoMetrics ? round($metrics->whereNotNull('completion_rate')->avg('completion_rate'), 2) : null;
        $totalShares = $hasVideoMetrics ? (int) $metrics->sum('shares') : null;
        $totalSaves = $hasVideoMetrics ? (int) $metrics->sum('saves') : null;

        // Data untuk grafik tren (urut tanggal naik)
        $chronological = $metrics->sortBy('metric_date')->values();
        $trend = $chronological->map(fn($m) => [
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
            ? $peerMetrics->groupBy('content_item_id')->map(fn($rows) => $rows->sum('views'))->avg()
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
            'contentItem',
            'metrics',
            'totalViews',
            'avgEngagement',
            'daysTracked',
            'bestDate',
            'trend',
            'syncLogs',
            'hasVideoMetrics',
            'avgWatchTime',
            'avgCompletionRate',
            'totalShares',
            'totalSaves',
            'hasPeerComparison',
            'viewsVsPeerPct',
            'engagementVsPeerPct',
            'peerAvgViews',
            'peerAvgEngagement'
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

        if (!$selectedClientId) {
            return back()->with('export_error', 'Pilih client dulu sebelum export.');
        }

        $this->assertClientAccessible((int) $selectedClientId);

        $client = Client::findOrFail($selectedClientId);

        $period = (int) $request->input('period', 30);
        $period = in_array($period, [7, 30, 90]) ? $period : 30;
        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $end = Carbon::now()->endOfDay();

        // client_id langsung (bukan whereHas('contentItem', ...)) - sama
        // seperti dashboard, biar post Instagram real yang belum ke-link
        // ikut ke-export juga, bukan cuma yang sudah punya ContentItem.
        $metrics = ContentMetric::with(['contentItem', 'platform', 'instagramMediaSnapshot'])
            ->where('client_id', $client->id)
            ->whereBetween('metric_date', [$start, $end])
            ->orderBy('metric_date')
            ->get();

        $filename = 'performance-' . str($client->name)->slug() . '-' . now()->format('Ymd-His') . '.csv';

        $callback = function () use ($metrics) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['content_title', 'platform', 'metric_date', 'views', 'engagement_rate']);

            foreach ($metrics as $m) {
                $title = $m->contentItem?->title
                    ?? ($m->instagramMediaSnapshot?->caption ? \Illuminate\Support\Str::limit($m->instagramMediaSnapshot->caption, 60) : null)
                    ?? '-';

                fputcsv($handle, [
                    $title,
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
     * Data buat tab "Performance Table" di halaman Analytics - list SEMUA
     * post yang punya data performa milik 1 client, sortable & filterable.
     *
     * Direstrukturisasi mulai dari ContentMetric (bukan ContentItem lagi) -
     * post Instagram real yang belum ke-link ke ContentItem internal HARUS
     * tetap muncul di sini (Langkah E, audit "Data Source Architecture"),
     * bukan cuma yang sudah punya ContentItem. Agregasi & pagination
     * dilakukan di PHP (bukan SQL) karena baris tabel ini bisa berasal dari
     * 2 sumber metadata berbeda (ContentItem vs InstagramMediaSnapshot)
     * yang nggak bisa di-UNION bersih lewat query builder - volume data
     * client (puluhan-ratusan post) masih aman diproses begini, pola yang
     * sama sudah dipakai AnalyticsSummaryService buat Top Content.
     */
    private function buildTableTabData(int|string $selectedClientId, Request $request): array
    {
        $client = Client::findOrFail($selectedClientId);

        $sort = $request->input('sort', 'total_views');
        $dir = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['total_views', 'avg_engagement', 'deadline_at', 'title'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'total_views';
        }

        $metricsQuery = ContentMetric::where('client_id', $client->id)
            ->with(['contentItem.platform', 'contentItem.contentType', 'contentItem.workflow', 'instagramMediaSnapshot', 'platform']);

        if ($request->filled('platform_id')) {
            $metricsQuery->where('platform_id', $request->input('platform_id'));
        }

        $allMetrics = $metricsQuery->get();

        $rows = $allMetrics
            ->groupBy(fn ($m) => $m->distinct_content_key)
            ->map(function ($group) {
                $first = $group->first();
                $item = $first->contentItem;
                $snapshot = $first->instagramMediaSnapshot;

                return (object) [
                    'id' => $item?->id,
                    'title' => $item?->title ?? \Illuminate\Support\Str::limit($snapshot?->caption ?: 'Instagram Post', 60),
                    'platform' => $first->platform->name ?? '-',
                    'content_type_id' => $item?->content_type_id,
                    // Linked: SELALU ContentType internal (taxonomy produksi) -
                    // walau content_type_id-nya kosong, JANGAN jatuh ke format
                    // Instagram (itu domain beda, jangan campur - tampil '-').
                    // Unmatched: fallback DISPLAY-ONLY dari format Instagram
                    // (Reels/Carousel/Image/Video) - BUKAN ContentType, tidak
                    // pernah ditulis ke content_type_id (lihat docblock
                    // InstagramMediaSnapshot::getDisplayFormatAttribute()).
                    'type' => $item ? $item->contentType?->name : $snapshot?->display_format,
                    'total_views' => (int) $group->sum('views'),
                    'avg_engagement' => round($group->avg('engagement_rate'), 2),
                    // Deadline HANYA dari workflow internal - null kalau
                    // unmatched, TIDAK PERNAH diisi dari published_at/
                    // snapshot_date/created_at (itu bukan deadline).
                    'deadline_at' => $item?->deadline_at,
                    'is_posted' => $item?->is_posted,
                    'is_overdue' => $item?->workflow?->is_overdue ?? false,
                    'linked' => (bool) $item,
                    'permalink' => $snapshot?->permalink,
                    // Dipakai action "Hubungkan Konten" - arahkan ke halaman
                    // unmatched management dgn post ini di-preselect (anchor).
                    'api_integration_id' => $snapshot?->api_integration_id,
                    'external_post_id' => $snapshot?->external_post_id,
                ];
            })
            ->values();

        if ($request->filled('content_type_id')) {
            // Filter tipe konten cuma masuk akal buat post yang sudah
            // ke-link (unmatched nggak punya content_type sama sekali) -
            // otomatis nge-exclude unmatched, itu memang perilaku yang benar.
            $rows = $rows->where('content_type_id', $request->input('content_type_id'));
        }

        if ($request->filled('search')) {
            $needle = strtolower($request->input('search'));
            $rows = $rows->filter(fn ($r) => str_contains(strtolower($r->title), $needle));
        }

        $rows = in_array($sort, ['total_views', 'avg_engagement'])
            ? $rows->sortBy(fn ($r) => $r->{$sort} ?? -INF, SORT_REGULAR, $dir === 'desc')
            : $rows->sortBy($sort, SORT_REGULAR, $dir === 'desc');
        $rows = $rows->values();

        $page = (int) $request->input('page', 1);
        $perPage = 15;
        $items = new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, $perPage),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $platformOptions = Platform::whereIn('id', $allMetrics->pluck('platform_id')->unique())->get();
        $contentTypeOptions = \App\Models\ContentType::whereHas('contentItems', fn($q) => $q->where('client_id', $client->id))->get();

        return compact('client', 'items', 'platformOptions', 'contentTypeOptions', 'sort', 'dir');
    }

    /**
     * Data buat tab "Audience" di halaman Analytics - dipindah dari
     * AudienceController::index() (sekarang jadi redirect ke sini) biar
     * 1 halaman yang sama kayak Performance Table.
     *
     * Precedence (Langkah 17 "Instagram Audience Insights"): kalau
     * client+platform SUDAH PERNAH punya row source=instagram_api, tab ini
     * HANYA baca API (summary + 3 demographic_type terpisah) - CSV/legacy
     * untuk kombinasi itu diabaikan sepenuhnya, tidak digabung (unit beda:
     * CSV manual vs API real, campur jadi angka yang nggak berarti). Kalau
     * belum pernah ada row API sama sekali, fallback ke CSV/legacy persis
     * seperti behavior lama.
     */
    private function buildAudienceTabData(int|string $selectedClientId, Request $request, int $period): array
    {
        $client = Client::findOrFail($selectedClientId);

        $platforms = Platform::whereHas('audienceInsights', fn($q) => $q->where('client_id', $client->id))->get();

        if ($platforms->isEmpty()) {
            return ['noInsightData' => true, 'client' => $client, 'platforms' => $platforms];
        }

        $selectedPlatformId = $request->input('platform_id', $platforms->first()->id);
        $platform = $platforms->firstWhere('id', (int) $selectedPlatformId) ?? $platforms->first();

        $hasApiData = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->apiSourced()
            ->exists();

        $start = Carbon::now()->subDays($period - 1)->startOfDay();

        $data = $hasApiData
            ? $this->buildApiAudienceData($client, $platform, $start, $period)
            : $this->buildCsvAudienceData($client, $platform, $start, $period);

        return array_merge(
            compact('client', 'platforms', 'platform', 'selectedPlatformId'),
            ['audienceSource' => $hasApiData ? AudienceInsight::SOURCE_API : 'csv'],
            $data
        );
    }

    /**
     * Sumber Instagram API real - summary row (followers/reach/active_hours)
     * + 3 demographic_type terpisah (follower/reached/engaged), masing-masing
     * BOLEH null kalau memang belum ada datanya (threshold/belum sync) -
     * TIDAK PERNAH ditebak jadi 0/array kosong (Langkah 4/18).
     */
    private function buildApiAudienceData(Client $client, Platform $platform, Carbon $start, int $period): array
    {
        $baseQuery = fn () => AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->apiSourced();

        $lastSyncAt = (clone $baseQuery())->max('updated_at');

        // Growth follower PAKAI TOTAL follower_count (bukan delta time-series) -
        // dihitung dari 2 snapshot summary TERAKHIR yang benar-benar punya
        // follower_count (banyak baris summary historis hasil backfill reach
        // sengaja follower_count-nya NULL, lihat InstagramAudienceInsightsService::
        // backfillReachHistory()). Kalau baru 1 (atau 0), growth TIDAK dihitung.
        $followerRows = (clone $baseQuery())->summary()->whereNotNull('follower_count')
            ->orderBy('snapshot_date')->get(['snapshot_date', 'follower_count']);

        $lastCount = $followerRows->last()->follower_count ?? null;
        $growth = null;
        $growthMessage = 'Belum cukup data historis untuk menghitung pertumbuhan.';
        if ($followerRows->count() >= 2) {
            $current = $followerRows->last()->follower_count;
            $previous = $followerRows->slice(-2, 1)->first()->follower_count;
            if ($previous > 0) {
                $growth = round((($current - $previous) / $previous) * 100, 1);
                $growthMessage = null;
            }
        }

        $followerTrend = $followerRows
            ->where('snapshot_date', '>=', $start)
            ->map(fn ($row) => ['label' => Carbon::parse($row->snapshot_date)->translatedFormat('d M'), 'value' => $row->follower_count])
            ->values();

        // Reach: kebalikan dari follower_count - historis LENGKAP (backfill
        // s/d 180 hari terbukti tersedia), jadi trend-nya jauh lebih kaya.
        $reachRows = (clone $baseQuery())->summary()->whereNotNull('reach')
            ->where('snapshot_date', '>=', $start)
            ->orderBy('snapshot_date')->get(['snapshot_date', 'reach']);

        $latestReach = $reachRows->last()->reach ?? null;
        $reachTrend = $reachRows
            ->map(fn ($row) => ['label' => Carbon::parse($row->snapshot_date)->translatedFormat('d M'), 'value' => $row->reach])
            ->values();

        $latestActiveHoursRow = (clone $baseQuery())->summary()->whereNotNull('active_hours')
            ->latest('snapshot_date')->first();
        $activeHours = null;
        $peakHour = null;
        if ($latestActiveHoursRow) {
            $activeHours = collect(range(0, 23))->map(fn ($hour) => [
                'label' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00',
                'value' => (int) ($latestActiveHoursRow->active_hours[$hour] ?? $latestActiveHoursRow->active_hours[(string) $hour] ?? 0),
            ]);
            $peakHour = $activeHours->sortByDesc('value')->first();
        }

        $demographics = [];
        foreach ([AudienceInsight::TYPE_FOLLOWER, AudienceInsight::TYPE_REACHED, AudienceInsight::TYPE_ENGAGED] as $type) {
            $row = (clone $baseQuery())->demographics($type)->latest('snapshot_date')->first();
            $demographics[$type] = $row ? [
                'gender_breakdown' => $row->gender_breakdown,
                'age_breakdown' => $row->age_breakdown,
                'top_locations' => $row->top_locations,
                'top_countries' => $row->top_countries,
                'snapshot_date' => $row->snapshot_date,
            ] : null;
        }

        return compact('lastSyncAt', 'lastCount', 'growth', 'growthMessage', 'followerTrend', 'latestReach', 'reachTrend', 'activeHours', 'peakHour', 'demographics');
    }

    /**
     * Sumber CSV/legacy - behavior PERSIS sama seperti sebelum Instagram
     * Audience API ada (1 row/hari, generic, persentase langsung dari CSV).
     * TIDAK diubah sama sekali selain scope query apiSourced() jadi
     * csvSourced() (Langkah 15/21 - CSV tetap compatible).
     */
    private function buildCsvAudienceData(Client $client, Platform $platform, Carbon $start, int $period): array
    {
        $baseQuery = fn () => AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->csvSourced();

        $latestSnapshot = (clone $baseQuery())->latest('snapshot_date')->first();

        $history = (clone $baseQuery())->where('snapshot_date', '>=', $start)->orderBy('snapshot_date')->get();

        $followerTrend = $history->map(fn($row) => [
            'label' => Carbon::parse($row->snapshot_date)->translatedFormat('d M'),
            'value' => (int) $row->follower_count,
        ])->values();

        $firstCount = $history->first()->follower_count ?? 0;
        $lastCount = $history->last()->follower_count ?? ($latestSnapshot->follower_count ?? 0);
        $growth = $firstCount > 0 ? round((($lastCount - $firstCount) / $firstCount) * 100, 1) : null;
        $growthMessage = $firstCount > 0 ? null : 'Belum cukup data historis untuk menghitung pertumbuhan.';

        $genderBreakdown = $latestSnapshot->gender_breakdown ?? [];
        $ageBreakdown = $latestSnapshot->age_breakdown ?? [];
        $topLocations = collect($latestSnapshot->top_locations ?? [])->sortByDesc('percentage')->values();

        $activeHoursRaw = $latestSnapshot->active_hours ?? [];
        $activeHours = collect(range(0, 23))->map(function ($hour) use ($activeHoursRaw) {
            return [
                'label' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00',
                'value' => (int) ($activeHoursRaw[$hour] ?? $activeHoursRaw[(string) $hour] ?? 0),
            ];
        });
        $peakHour = $activeHours->sortByDesc('value')->first();

        return compact(
            'latestSnapshot',
            'followerTrend',
            'growth',
            'growthMessage',
            'lastCount',
            'genderBreakdown',
            'ageBreakdown',
            'topLocations',
            'activeHours',
            'peakHour'
        );
    }

    /**
     * KF3xx — Riwayat AI Strategy Insight per client.
     *
     * Halaman Analytics cuma pernah nampilin insight yang latest() - kalau
     * PIC klik "Generate Ulang" padahal insight SEBELUMNYA masih applied_at
     * (ada draft content item nyantol di Content Plan), insight lama itu
     * jadi nggak kejangkau lagi dari UI manapun (padahal masih ada &
     * mungkin masih perlu di-revert). Halaman ini nampilin SEMUA insight
     * client itu, terlepas dari yang mana yang latest, biar tetap bisa
     * di-lihat/di-apply/di-revert.
     */
    public function aiStrategyHistory(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
        ]);

        $client = Client::findOrFail($validated['client_id']);

        $insights = AiStrategyInsight::where('client_id', $client->id)
            ->with('generatedBy')
            ->latest()
            ->get();

        return view('analytics.ai-strategy-history', compact('client', 'insights'));
    }

    /**
     * KF3xx — AI Strategy Analysis (beneran manggil Gemini API, bukan
     * teks statis). Trigger manual lewat tombol di halaman Analytics.
     */
    public function generateAiStrategy(Request $request, AiStrategyService $aiStrategyService)
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id', new AssignedClient],
        ]);

        $client = Client::findOrFail($validated['client_id']);

        $period = $aiStrategyService->analysisPeriod();
        $periodStart = $period['start'];
        $periodEnd = $period['end'];

        try {
            $summary = $aiStrategyService->buildPerformanceSummary($client);

            if ($summary['content_published_count'] === 0) {
                return redirect()->route('analytics', ['client_id' => $client->id])
                    ->with('ai_error', 'Belum ada data performa konten bulan ' . $periodStart->translatedFormat('F Y') . ' buat client ini - AI butuh data buat dianalisis, bukan nebak.');
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
                'content_ideas' => $aiStrategyService->scoreContentIdeas($result['content_ideas'], $summary),
                'data_completeness_percent' => $dataCompleteness,
                'status' => 'completed',
            ]);

            return redirect()->route('analytics', ['client_id' => $client->id])
                ->with('ai_success', 'Analisis AI berhasil digenerate.');
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

            return redirect()->route('analytics', ['client_id' => $client->id])
                ->with('ai_error', 'Gagal generate analisis AI: ' . $e->getMessage());
        }
    }

    /**
     * KF3xx — Terapkan hasil AI Strategy ke Content Plan.
     *
     * BENERAN ngefek ke sistem (bukan tombol dekoratif): generate draft
     * ContentItem buat bulan berjalan, jumlah & distribusinya ngikutin
     * suggested_split dari AI. User tetap wajib isi judul/brief detail -
     * ini cuma bikinin "kerangka" plan-nya biar nggak mulai dari kosong.
     *
     * Deadline disebar ke SELURUH hari bulan berjalan (tanggal 1 s/d akhir
     * bulan), bukan cuma dari tanggal generate ke akhir bulan - meskipun
     * generate-nya telat (misal tanggal 5/10), draft yang deadline-nya
     * jatuh sebelum hari ini sengaja tetap dibikin dan langsung ditandai
     * overdue. Ini disengaja: draft yang "sudah telat" begitu dibuat
     * berfungsi sebagai sinyal prioritas buat PIC (kerjain yang paling
     * telat duluan), bukan numpuk semua di sisa hari yang ada.
     */
    public function applyAiStrategy(AiStrategyInsight $aiStrategyInsight)
    {
        abort_if($aiStrategyInsight->status !== 'completed', 422, 'Cuma analisis yang berhasil yang bisa diterapkan.');
        abort_if($aiStrategyInsight->applied_at !== null, 422, 'Analisis ini sudah pernah diterapkan sebelumnya.');

        $client = $aiStrategyInsight->client;
        $activePackage = $client->activePackage;

        // client_package_id nullable (Langkah 1-2, audit Agustus 2026) -
        // client tanpa paket aktif TETAP boleh Apply, cuma jumlah draft yang
        // dibuat nggak lagi dari kuota paket (Langkah "Final Fix Batch" E:
        // jangan fabricate quota palsu begitu ClientPackage dummy dibersihkan).
        $ideaCount = collect($aiStrategyInsight->content_ideas)->count();
        abort_if(! $activePackage && $ideaCount === 0, 422, 'Client ini belum punya paket aktif, dan analisis ini tidak menyimpan ide konten apapun untuk diterapkan.');

        $now = Carbon::now();

        $plan = \App\Models\ContentPlan::firstOrCreate(
            ['client_id' => $client->id, 'month' => $now->month, 'year' => $now->year],
            [
                'client_package_id' => $activePackage?->id,
                'created_by' => auth()->id(),
                'status' => 'draft',
            ]
        );

        // Package aktif -> pola lama (kuota bulanan nentuin jumlah draft).
        // Package belum tercatat -> JANGAN klaim kuota palsu, pakai jumlah
        // ide yang memang tersimpan dari hasil AI Strategy ini apa adanya.
        $totalItems = $activePackage
            ? ((($activePackage->monthly_content_quota ?: 0) + ($activePackage->monthly_design_quota ?: 0)) ?: 10)
            : $ideaCount;
        $split = collect($aiStrategyInsight->suggested_split);
        $splitSum = $split->sum('value') ?: 100;
        $daysInMonth = $now->daysInMonth;
        $ideasByPillar = collect($aiStrategyInsight->content_ideas)->groupBy('pillar');

        // Fallback buat slot yang nggak kebagian ide spesifik dari AI
        // (harusnya jarang sejak AiStrategyService diminta generate ide
        // sejumlah target_content_count, tapi tetap dijaga-jaga) - biar
        // nggak dibiarkan kosong total, diputer gilir dari platform yang
        // beneran ke-track buat client ini & tipe konten yang ada di sistem.
        $knownPlatformNames = array_keys($aiStrategyInsight->performance_data['performance_by_platform'] ?? []);
        $fallbackPlatforms = \App\Models\Platform::whereIn('name', $knownPlatformNames)->get();
        $fallbackTypes = \App\Models\ContentType::all();

        $created = 0;
        $fallbackCount = 0;
        $picSummary = [];
        $picAssignmentService = new PicAssignmentService();
        foreach ($split as $row) {
            $count = (int) round(($row['value'] / $splitSum) * $totalItems);
            if ($count < 1) {
                continue;
            }

            $pillar = \App\Models\ContentPillar::firstOrCreate(['name' => $row['label']]);
            $reasoning = collect($aiStrategyInsight->top_pillars)->firstWhere('name', $row['label'])['reasoning'] ?? null;
            $ideasForPillar = $ideasByPillar->get($row['label'], collect());

            for ($i = 0; $i < $count; $i++) {
                $deadline = $now->copy()->startOfMonth()->addDays(rand(0, $daysInMonth - 1));
                $idea = $ideasForPillar->get($i);

                if (!$idea) {
                    $fallbackCount++;
                }

                // Insight lama (digenerate sebelum field "type"/"platform"
                // ada di content_ideas) atau slot yang nggak kebagian ide
                // sama sekali - pakai fallback round-robin, bukan dibiarkan
                // null total.
                $typeName = trim($idea['type'] ?? '');
                if ($typeName !== '') {
                    $contentTypeModel = \App\Models\ContentType::whereRaw('LOWER(name) = ?', [strtolower($typeName)])->first()
                        ?? \App\Models\ContentType::firstOrCreate(['name' => $typeName]);
                } else {
                    $contentTypeModel = $fallbackTypes->isNotEmpty() ? $fallbackTypes[$created % $fallbackTypes->count()] : null;
                }
                $contentTypeId = $contentTypeModel?->id;

                $platformName = trim($idea['platform'] ?? '');
                if ($platformName !== '') {
                    $platform = \App\Models\Platform::whereRaw('LOWER(name) = ?', [strtolower($platformName)])->first()
                        ?? \App\Models\Platform::firstOrCreate(['name' => $platformName]);
                    $platformId = $platform->id;
                } else {
                    $platformId = $fallbackPlatforms->isNotEmpty() ? $fallbackPlatforms[$created % $fallbackPlatforms->count()]->id : null;
                }

                $item = \App\Models\ContentItem::create([
                    'content_plan_id' => $plan->id,
                    'client_id' => $client->id,
                    'content_pillar_id' => $pillar->id,
                    'content_type_id' => $contentTypeId,
                    'platform_id' => $platformId,
                    'ai_strategy_insight_id' => $aiStrategyInsight->id,
                    'title' => $idea['title'] ?? "[Draft AI] {$row['label']} #" . ($i + 1),
                    'brief' => $idea['brief'] ?? ($reasoning ? "Rekomendasi AI: {$reasoning}" : "Digenerate dari AI Strategy Analysis ({$row['value']}% dari komposisi yang disarankan)."),
                    'deadline_at' => $deadline,
                ]);

                $pic = $picAssignmentService->assign($client, $contentTypeModel?->name);

                \App\Models\ContentWorkflow::create([
                    'content_item_id' => $item->id,
                    'current_pic_id' => $pic?->id,
                    'current_status' => 'brief_ready',
                    // Deadline yang jatuh sebelum hari ini langsung ditandai
                    // overdue saat dibuat - nggak nunggu cron
                    // `workflow:update-overdue` buat kasih sinyal prioritas.
                    'is_overdue' => $deadline->lt($now),
                ]);

                if ($pic) {
                    $item->assignments()->create(['user_id' => $pic->id, 'assignment_role' => 'primary']);
                    $picSummary[$pic->name] = ($picSummary[$pic->name] ?? 0) + 1;
                }

                $created++;
            }
        }

        $aiStrategyInsight->update(['applied_at' => $now, 'applied_by' => auth()->id()]);

        $message = "{$created} draft content item dibuat berdasarkan rekomendasi AI.";
        $message .= $fallbackCount > 0
            ? " {$fallbackCount} di antaranya nggak kebagian ide spesifik dari AI (judul placeholder) - judul, brief, format & platform-nya perlu dilengkapi manual."
            : ' Judul, brief, format & platform udah terisi dari AI - tetap direview dulu sebelum lanjut ke produksi.';

        if (!empty($picSummary)) {
            $summaryParts = collect($picSummary)->map(fn ($count, $name) => "{$name} {$count}")->implode(', ');
            $message .= " Pembagian PIC: {$summaryParts}.";
        }
        if ($picAssignmentService->usedFallbackRole()) {
            $message .= ' Sebagian item nggak punya kandidat PIC dengan role yang cocok di antara tim yang sudah di-assign ke klien ini - cek lagi penugasannya, atau tambahkan PIC dengan role yang sesuai lewat "Assign Klien" di Kelola Pengguna.';
        }

        return redirect()->route('content-plan.show', $plan)
            ->with('status', $message);
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
                || ($item->workflow && !in_array($item->workflow->current_status, ['planned', 'brief_ready']));
        });

        if ($hasProgress) {
            return redirect()->route('analytics', ['client_id' => $aiStrategyInsight->client_id])
                ->with('ai_error', 'Nggak bisa di-revert - sebagian draft dari analisis ini udah ada progress (revisi/posting/metrik). Hapus manual satu-satu kalau tetap mau dibatalkan.');
        }

        $deletedCount = $generatedItems->count();

        foreach ($generatedItems as $item) {
            $item->workflow?->delete();
            $item->delete();
        }

        $aiStrategyInsight->update(['applied_at' => null, 'applied_by' => null]);

        return redirect()->route('analytics', ['client_id' => $aiStrategyInsight->client_id])
            ->with('ai_success', "{$deletedCount} draft content item berhasil ditarik kembali. Analisis ini bisa diterapkan ulang kalau perlu.");
    }

    /**
     * KF3xx — Regenerate SATU ide konten spesifik (dari modal detail ide),
     * opsional sekalian ganti pillar-nya. Dipanggil via fetch/AJAX (bukan
     * reload halaman), makanya balikin JSON, bukan redirect.
     *
     * $index itu posisi array di content_ideas (bukan ID kolom terpisah -
     * content_ideas emang cuma JSON array, nggak ada tabel sendiri).
     */
    public function regenerateContentIdea(Request $request, AiStrategyInsight $aiStrategyInsight, int $index, AiStrategyService $aiStrategyService)
    {
        if ($aiStrategyInsight->applied_at !== null) {
            return response()->json(['error' => 'Analisis ini udah diterapkan ke Content Plan - regenerate ide di sini bakal bikin draft yang udah dibuat nggak nyambung lagi. Tarik kembali dulu kalau mau ubah ide.'], 422);
        }

        if (empty($aiStrategyInsight->performance_data)) {
            return response()->json(['error' => 'Analisis ini nggak punya data mentah, generate ulang dulu.'], 422);
        }

        $ideas = $aiStrategyInsight->content_ideas ?? [];

        if (!array_key_exists($index, $ideas)) {
            return response()->json(['error' => 'Ide konten nggak ketemu.'], 404);
        }

        $pillarOptions = collect($aiStrategyInsight->suggested_split)->pluck('label')->all();
        if (empty($pillarOptions)) {
            $pillarOptions = collect($ideas)->pluck('pillar')->filter()->unique()->values()->all();
        }

        $validated = $request->validate([
            'pillar' => ['required', 'string', \Illuminate\Validation\Rule::in($pillarOptions)],
        ]);

        try {
            $otherIdeas = collect($ideas)->except($index)->values()->all();

            $newIdea = $aiStrategyService->regenerateIdea(
                $aiStrategyInsight->performance_data,
                $otherIdeas,
                $validated['pillar']
            );

            $newIdea = $aiStrategyService->scoreContentIdeas([$newIdea], $aiStrategyInsight->performance_data)[0];

            $ideas[$index] = $newIdea;
            $aiStrategyInsight->update(['content_ideas' => array_values($ideas)]);

            return response()->json(['idea' => $newIdea]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Gagal regenerate ide: ' . $e->getMessage()], 500);
        }
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
                ->map(fn($m) => ['role' => $m->role, 'message' => $m->message])
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
            return response()->json(['error' => 'Gagal dapetin balesan AI: ' . $e->getMessage()], 500);
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
        abort_if($aiStrategyInsight->applied_at !== null, 422, 'Analisis ini sudah diterapkan ke Content Plan - draft yang udah dibuat bakal nggak nyambung lagi kalau analisisnya diperbarui sekarang. Tarik kembali (revert) dulu kalau mau update berdasarkan diskusi ini.');

        try {
            $history = $aiStrategyInsight->messages()
                ->get()
                ->map(fn($m) => ['role' => $m->role, 'message' => $m->message])
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
                'content_ideas' => $aiStrategyService->scoreContentIdeas($result['content_ideas'], $aiStrategyInsight->performance_data),
            ]);

            \App\Models\AiStrategyMessage::create([
                'ai_strategy_insight_id' => $aiStrategyInsight->id,
                'user_id' => null,
                'role' => 'system',
                'message' => 'Analisis diperbarui berdasarkan diskusi di atas oleh ' . (auth()->user()->name ?? 'user') . '.',
            ]);

            return redirect()->route('analytics', ['client_id' => $aiStrategyInsight->client_id])
                ->with('ai_success', 'Analisis berhasil diperbarui berdasarkan diskusi.');
        } catch (\Throwable $e) {
            return redirect()->route('analytics', ['client_id' => $aiStrategyInsight->client_id])
                ->with('ai_error', 'Gagal memperbarui analisis: ' . $e->getMessage());
        }
    }
}