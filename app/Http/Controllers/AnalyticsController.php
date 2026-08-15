<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\Platform;
use App\Models\AiStrategyInsight;
use App\Services\AiStrategyService;
use App\Services\AnalyticsSummaryService;
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

        $selectedClientId = $request->input('client_id');
        $clientOptions = Client::where('status', 'active')->get();

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

        $client = Client::findOrFail($selectedClientId);

        $period = (int) $request->input('period', 30);
        $period = in_array($period, [7, 30, 90]) ? $period : 30;
        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $end = Carbon::now()->endOfDay();

        $metrics = ContentMetric::with(['contentItem', 'platform'])
            ->whereHas('contentItem', fn($q) => $q->where('client_id', $client->id))
            ->whereBetween('metric_date', [$start, $end])
            ->orderBy('metric_date')
            ->get();

        $filename = 'performance-' . str($client->name)->slug() . '-' . now()->format('Ymd-His') . '.csv';

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
     * Data buat tab "Performance Table" di halaman Analytics - list semua
     * content item milik 1 client, lengkap dengan agregat metrik-nya
     * (total views, avg engagement), sortable & filterable.
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
            $query->where('title', 'like', '%' . $request->input('search') . '%');
        }

        if (in_array($sort, ['total_views', 'avg_engagement'])) {
            $query->orderByRaw("{$sort} IS NULL, {$sort} {$dir}");
        } else {
            $query->orderBy($sort, $dir);
        }

        $items = $query->paginate(15)->withQueryString();

        $platformOptions = Platform::whereHas('contentItems', fn($q) => $q->where('client_id', $client->id))->get();
        $contentTypeOptions = \App\Models\ContentType::whereHas('contentItems', fn($q) => $q->where('client_id', $client->id))->get();

        return compact('client', 'items', 'platformOptions', 'contentTypeOptions', 'sort', 'dir');
    }

    /**
     * Data buat tab "Audience" di halaman Analytics - dipindah dari
     * AudienceController::index() (sekarang jadi redirect ke sini) biar
     * 1 halaman yang sama kayak Performance Table.
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

        $latestSnapshot = \App\Models\AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->latest('snapshot_date')
            ->first();

        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $history = \App\Models\AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->where('snapshot_date', '>=', $start)
            ->orderBy('snapshot_date')
            ->get();

        $followerTrend = $history->map(fn($row) => [
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
        $activeHours = collect(range(0, 23))->map(function ($hour) use ($activeHoursRaw) {
            return [
                'label' => str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00',
                'value' => (int) ($activeHoursRaw[$hour] ?? $activeHoursRaw[(string) $hour] ?? 0),
            ];
        });
        $peakHour = $activeHours->sortByDesc('value')->first();

        return compact(
            'client',
            'platforms',
            'platform',
            'selectedPlatformId',
            'latestSnapshot',
            'followerTrend',
            'growth',
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
            'client_id' => 'required|exists:clients,id',
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

        // Total draft = kuota konten + kuota desain bulanan client (bukan
        // dibatasi angka tetap) - AI diminta nandain tipe tiap ide (lihat
        // AiStrategyService::contentTypeOptions()) biar draft-nya beneran
        // kehitung sesuai porsi Content vs Design pas dibuka di Content Plan.
        $totalItems = (($activePackage->monthly_content_quota ?: 0) + ($activePackage->monthly_design_quota ?: 0)) ?: 10;
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
                    $contentType = \App\Models\ContentType::whereRaw('LOWER(name) = ?', [strtolower($typeName)])->first()
                        ?? \App\Models\ContentType::firstOrCreate(['name' => $typeName]);
                    $contentTypeId = $contentType->id;
                } else {
                    $contentTypeId = $fallbackTypes->isNotEmpty() ? $fallbackTypes[$created % $fallbackTypes->count()]->id : null;
                }

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

                \App\Models\ContentWorkflow::create([
                    'content_item_id' => $item->id,
                    'current_status' => 'brief_ready',
                    // Deadline yang jatuh sebelum hari ini langsung ditandai
                    // overdue saat dibuat - nggak nunggu cron
                    // `workflow:update-overdue` buat kasih sinyal prioritas.
                    'is_overdue' => $deadline->lt($now),
                ]);

                $created++;
            }
        }

        $aiStrategyInsight->update(['applied_at' => $now, 'applied_by' => auth()->id()]);

        $message = "{$created} draft content item dibuat berdasarkan rekomendasi AI.";
        $message .= $fallbackCount > 0
            ? " {$fallbackCount} di antaranya nggak kebagian ide spesifik dari AI (judul placeholder) - judul, brief, format & platform-nya perlu dilengkapi manual."
            : ' Judul, brief, format & platform udah terisi dari AI - tetap direview dulu sebelum lanjut ke produksi.';

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