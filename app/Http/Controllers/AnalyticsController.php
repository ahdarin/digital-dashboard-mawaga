<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\Platform;
use App\Models\AiStrategyInsight;
use App\Rules\AssignedClient;
use App\Services\AiStrategyService;
use App\Services\AnalyticsPeriod;
use App\Services\AnalyticsPeriodResolver;
use App\Services\AnalyticsSummaryService;
use App\Services\AnalyticsSyncOrchestrator;
use App\Services\ContentCohortService;
use App\Services\PeriodPerformanceService;
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
    public function index(Request $request, AiStrategyService $aiStrategyService, AnalyticsSummaryService $analyticsSummaryService, AnalyticsPeriodResolver $periodResolver)
    {
        // PASS 2 - "PERIOD ENGINE V2". SATU-SATUNYA tempat period
        // di-resolve buat halaman ini (Overview/Table/Audience/Export
        // SEMUA menerima object AnalyticsPeriod yang SAMA, bukan hitung
        // date math sendiri-sendiri lagi - lihat AnalyticsPeriodResolver
        // docblock). Default SEKARANG bulan kalender BERJALAN (bukan lagi
        // period=30) - "PRIMARY PRODUCT CHANGE". $periodError (kalau ada)
        // dari input mentah tidak valid (rentang kebalik/masa depan/lebih
        // dari MAX_CUSTOM_RANGE_DAYS) - resolver TETAP fallback tenang ke
        // bulan berjalan (Langkah 2 "never hard error for GET display
        // params"), pesan ini murni informational buat user.
        ['period' => $period, 'error' => $periodError] = $periodResolver->resolveWithError($request);

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
                'periodError' => $periodError,
                'activeTab' => $activeTab,
                'platformOptions' => collect(),
                'selectedPlatformId' => null,
            ]);
        }

        // Platform - GLOBAL filter (Client/Period/Platform), dipakai SAMA
        // persis di ketiga tab (Overview/Table/Audience), bukan lagi filter
        // lokal punya tab masing-masing (Langkah 3, bug lama: Table punya
        // dropdown platform sendiri, Audience beda lagi, Overview nggak
        // punya sama sekali - filter nggak konsisten & nggak kebawa pindah
        // tab). Opsinya = union platform yang:
        // - PUNYA ApiIntegration buat client ini (connected, TERLEPAS ada
        //   data metric/audience atau belum - client yang baru connect
        //   TikTok tapi belum pernah sync harus TETAP bisa milih TikTok di
        //   sini, terutama buat tombol "Perbarui Data" global nanti), ATAU
        // - PUNYA ContentMetric (data performa), ATAU
        // - PUNYA AudienceInsight (data audiens, API maupun CSV)
        // buat client ini - biar dropdown nggak pernah kosong di satu tab
        // tapi keisi di tab lain, dan nggak "menghilangkan" platform yang
        // baru connect/belum sync sama sekali (pre-Phase-2-check correction).
        $platformOptions = Platform::where(function ($q) use ($selectedClientId) {
            $q->whereHas('apiIntegrations', fn ($q2) => $q2->where('client_id', $selectedClientId))
                ->orWhereHas('contentMetrics', fn ($q2) => $q2->where('client_id', $selectedClientId))
                ->orWhereHas('audienceInsights', fn ($q2) => $q2->where('client_id', $selectedClientId));
        })->orderBy('name')->get();

        $selectedPlatformId = $request->input('platform_id');
        $selectedPlatformId = $selectedPlatformId !== null && $selectedPlatformId !== ''
            ? (int) $selectedPlatformId
            : null;
        // Platform yang tidak relevan/accessible buat client ini (mis. sisa
        // query string dari client lain yang kebetulan punya platform_id
        // sama persis, atau ID yang ditebak manual) diperlakukan sebagai
        // "Semua Platform", BUKAN error keras - biar switch client di
        // dropdown yang sama nggak pernah nyangkut di state platform lama.
        if ($selectedPlatformId !== null && ! $platformOptions->contains('id', $selectedPlatformId)) {
            $selectedPlatformId = null;
        }

        // PASS 3 (Langkah N, "SYNC HISTORY") - riwayat SINGKAT (5 run
        // terakhir yang sudah selesai), server-rendered (bukan JS-polled -
        // ini histori, bukan operasi aktif), SECONDARY (disclosure kolaps,
        // lihat blade) - BUKAN admin logging interface. Ikut GLOBAL filter
        // (client) SAJA, dipakai identik di ketiga tab sama seperti
        // freshness/sync panel.
        $syncHistory = $request->user()->hasPermissionTo('settings', 'manage')
            ? $this->buildSyncHistory((int) $selectedClientId)
            : collect();

        $filterState = [
            'clientOptions' => $clientOptions,
            'selectedClientId' => $selectedClientId,
            'period' => $period,
            'periodError' => $periodError,
            'activeTab' => $activeTab,
            'platformOptions' => $platformOptions,
            'selectedPlatformId' => $selectedPlatformId,
            'syncHistory' => $syncHistory,
        ];

        // Tab Performance Table & Audience punya data & query sendiri-
        // sendiri (nggak nyambung ke stats/trend overview) - dihitung
        // terpisah biar tab yang lagi nggak aktif nggak ikut query
        // percuma tiap request.
        if ($activeTab === 'table') {
            return view('analytics.index', array_merge(
                $filterState,
                $this->buildTableTabData($selectedClientId, $request, $period, $selectedPlatformId)
            ));
        }

        if ($activeTab === 'audience') {
            return view('analytics.index', array_merge(
                $filterState,
                $this->buildAudienceTabData($selectedClientId, $period, $selectedPlatformId)
            ));
        }

        [
            'stats' => $stats,
            'trend' => $trend,
            'platformBreakdown' => $platformBreakdown,
            'topContent' => $topContent,
            'coverageStatus' => $coverageStatus,
            'coverageMessage' => $coverageMessage,
            'cohortContextMessage' => $cohortContextMessage,
        ] = $analyticsSummaryService->buildOverviewData($selectedClientId, $period, $selectedPlatformId);

        // Phase 4.1 (v2, "AI Strategy Month Selection") - insight yang
        // ditampilkan HARUS match EXACT context yang lagi aktif (client +
        // platform global + BULAN yang dipilih via <input type="month">
        // khusus AI Strategy, TERPISAH dari filter period 7/30/90
        // Overview/Table/Audience) - insight Agustus-Instagram TIDAK
        // BOLEH nongol pas user lagi lihat September-TikTok. NULL
        // semantics eksplisit - whereNull('platform_id') buat All
        // Platforms, where('platform_id', X) buat platform spesifik,
        // BUKAN where('platform_id', $selectedPlatformId) polos (NULL
        // never equals NULL secara SQL).
        $analysisMonth = $this->resolveAnalysisMonth($request);
        $aiWindow = $aiStrategyService->resolveMonthWindow($analysisMonth);
        $latestAiInsight = AiStrategyInsight::where('client_id', $selectedClientId)
            ->when(
                $selectedPlatformId === null,
                fn ($q) => $q->whereNull('platform_id'),
                fn ($q) => $q->where('platform_id', $selectedPlatformId)
            )
            ->where('period_start', $aiWindow['start']->toDateString())
            ->where('period_end', $aiWindow['end']->toDateString())
            // Phase 4.2 audit (Langkah 6, "context history behavior") -
            // orderByDesc('id') dipakai, BUKAN latest()/created_at, karena
            // 2 Generate Ulang buat context SAMA bisa kejadian dalam detik
            // yang sama (created_at timestamp identik) - MySQL ORDER BY
            // created_at DESC dengan tie TIDAK dijamin urutannya. id
            // auto-increment SELALU monoton sesuai urutan insert, jadi
            // satu-satunya cara benar2 deterministic buat "yang paling baru".
            ->orderByDesc('id')
            ->first();

        $aiAnalysisPeriodLabel = $this->analysisMonthLabel($analysisMonth).' · '.(
            $selectedPlatformId
                ? ($platformOptions->firstWhere('id', $selectedPlatformId)?->name ?? 'Platform')
                : 'Semua Platform'
        );

        // Slot kosong (draft, brief belum lengkap) client ini - dipakai
        // picker "Terapkan ke Slot Ini" per-ide, karena sejak Content Plan
        // auto-generate slot dari kuota paket, menerapkan ide AI berarti
        // mengisi slot yang sudah ada, bukan bikin content item baru.
        // (Fitur dari origin/main, digabung di sini saat merge - lihat
        // fitur period/coverage/platform dari stabilization/pre-user-manual
        // di array di bawah, dua-duanya dipertahankan.)
        $emptySlots = ContentItem::where('client_id', $selectedClientId)
            ->whereHas('workflow', fn ($q) => $q->where('current_status', 'draft'))
            ->orderBy('provisional_code')
            ->get(['id', 'title', 'provisional_code'])
            ->filter(fn ($item) => ! $item->hasCompleteBrief())
            ->values();

        return view('analytics.index', array_merge($filterState, compact(
            'stats',
            'trend',
            'platformBreakdown',
            'topContent',
            'latestAiInsight',
            'aiAnalysisPeriodLabel',
            'analysisMonth',
            'coverageStatus',
            'coverageMessage',
            'cohortContextMessage',
            'emptySlots'
        )));
    }

    /**
     * KF3xx — Content / Client Performance Detail
     * Detail performa satu content item: histori metrik harian per platform
     * beserta log sinkronisasi/import datanya.
     *
     * Phase 3 (Langkah 9D): totalViews/avgEngagement/metrik video TETAP dari
     * content_metrics APA ADANYA (current/latest cumulative, TIDAK direpurpose
     * - Langkah 10) - itu representasi KONDISI SEKARANG, bukan gain periode.
     * Yang diganti ke PeriodPerformanceService HANYA 3 hal yang secara
     * eksplisit "period"/"trend": trend chart harian, daysTracked (buat
     * content API, itu jumlah snapshot_date genuine, BUKAN 1 metric_date
     * yang dikunci ke publish), dan peer comparison 30 hari.
     */
    public function show(ContentItem $contentItem)
    {
        $contentItem->load(['client', 'contentType', 'contentFormat', 'platform']);

        $metrics = ContentMetric::where('content_item_id', $contentItem->id)
            ->with(['platform', 'syncLog', 'importedBy', 'instagramMediaSnapshot', 'tiktokVideoSnapshot'])
            ->orderByDesc('metric_date')
            ->get();

        $totalViews = (int) $metrics->sum('views');
        $avgEngagement = $metrics->count() > 0 ? round($metrics->avg('engagement_rate'), 2) : 0;
        $bestDate = $metrics->sortByDesc('views')->first();

        // Metrik video (Reels/TikTok) - null semua kalau konten ini nggak
        // pernah punya data ini sama sekali (misal konten Feed/foto)
        $hasVideoMetrics = $metrics->contains(fn($m) => $m->watch_time_avg !== null || $m->completion_rate !== null || $m->shares !== null || $m->saves !== null);
        $avgWatchTime = $hasVideoMetrics ? round($metrics->whereNotNull('watch_time_avg')->avg('watch_time_avg')) : null;
        $avgCompletionRate = $hasVideoMetrics ? round($metrics->whereNotNull('completion_rate')->avg('completion_rate'), 2) : null;
        $totalShares = $hasVideoMetrics ? (int) $metrics->sum('shares') : null;
        $totalSaves = $hasVideoMetrics ? (int) $metrics->sum('saves') : null;

        $syncLogs = $metrics
            ->pluck('syncLog')
            ->filter()
            ->unique('id')
            ->sortByDesc('created_at')
            ->values();

        $periodPerformanceService = app(PeriodPerformanceService::class);
        $apiMetric = $metrics->first(fn ($m) => $m->instagram_media_snapshot_id || $m->tiktok_video_snapshot_id);

        $peerStart = Carbon::now()->subDays(29)->startOfDay();
        $peerEnd = Carbon::now()->endOfDay();

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 5/18) -
        // canonical PUBLISH date genuine dari provider (InstagramMediaSnapshot/
        // TikTokVideoSnapshot.published_at) - TIDAK PERNAH ContentMetric.
        // created_at/metric_date/last_fetched_at. null buat CSV/manual
        // (tidak ada timestamp publish provider genuine buat baris manual).
        $publishedAt = $apiMetric
            ? ($apiMetric->instagramMediaSnapshot?->published_at ?? $apiMetric->tiktokVideoSnapshot?->published_at)
            : null;

        if ($apiMetric) {
            $identityColumn = $apiMetric->instagram_media_snapshot_id ? 'instagram_media_snapshot_id' : 'tiktok_video_snapshot_id';
            $identityId = $apiMetric->instagram_media_snapshot_id ?? $apiMetric->tiktok_video_snapshot_id;
            $platformType = $apiMetric->instagram_media_snapshot_id ? 'instagram' : 'tiktok';

            // Trend chart harian (90 hari) - GAIN harian genuine dari
            // content_metric_snapshots, BUKAN lifetime cumulative
            // content_metrics.views (yang cuma 1 baris, dikunci ke tanggal
            // publish - chart lama praktis 1 titik doang buat content API).
            $trendStart = Carbon::now()->subDays(89)->startOfDay();
            $snapshotsForTrend = ContentMetricSnapshot::where($identityColumn, $identityId)
                ->whereBetween('snapshot_date', [$trendStart->copy()->subDay()->toDateString(), $peerEnd->toDateString()])
                ->orderBy('snapshot_date')
                ->get(['snapshot_date', 'views', 'instagram_media_snapshot_id', 'tiktok_video_snapshot_id']);

            $dailySeries = $periodPerformanceService->computeDailyGainSeriesFromSnapshots($snapshotsForTrend, $trendStart, $peerEnd);
            $trend = collect($dailySeries)->map(fn ($p) => [
                'label' => Carbon::parse($p['date'])->translatedFormat('d M'),
                'value' => $p['value'],
                'has_gap' => $p['has_gap'],
            ])->values();

            $daysTracked = ContentMetricSnapshot::where($identityColumn, $identityId)->distinct()->count('snapshot_date');

            $thisResult = $periodPerformanceService->computeContentDelta($platformType, $identityColumn, $identityId, $publishedAt, $peerStart, $peerEnd);
            $recentViews = $thisResult->views() ?? 0;
            $recentEngagement = $thisResult->engagementRate;
            $hasRecentData = $thisResult->isUsable();
            // SYSTEM CONSISTENCY PASS (Part AD) - breakdown gain periode
            // (views/likes/comments/shares/saves), TERPISAH dari "TOTAL
            // SAAT INI" di bawah - delta ini SUDAH dihitung
            // computeContentDelta() di atas (dulu HANYA dipakai internal
            // buat persentase peer comparison, tidak pernah ditampilkan
            // sebagai angka sendiri). Null TETAP null (metric yang genuinely
            // tidak bisa dihitung/reset), TIDAK di-default 0 di sini.
            $periodDelta = $thisResult->delta;
            $periodDeltaAvailable = $thisResult->isUsable();
            // Total SAAT INI (content_metrics.views dkk, sudah benar
            // menyimpan raw provider terkini tiap sync) - freshness dari
            // last_fetched_at snapshot sumbernya sendiri, BUKAN
            // updated_at ContentMetric (kolom itu ikut ter-update tiap
            // upsert row yang sama, tapi last_fetched_at snapshot adalah
            // sinyal "kapan angka INI terakhir genuinely disegarkan dari
            // provider" yang sudah dipakai konsisten di tempat lain
            // - Settings/Analytics sync panel).
            $currentObservedAt = $apiMetric->instagramMediaSnapshot?->last_fetched_at
                ?? $apiMetric->tiktokVideoSnapshot?->last_fetched_at;
        } else {
            // CSV/manual - semantik lama dipertahankan APA ADANYA
            // (metric_date CSV = nilai per-periode ASLI dari user, bukan
            // cumulative snapshot - Langkah 8/10, TIDAK dipaksa lewat delta
            // engine).
            $chronological = $metrics->sortBy('metric_date')->values();
            $trend = $chronological->map(fn($m) => [
                'label' => Carbon::parse($m->metric_date)->translatedFormat('d M'),
                'value' => (int) $m->views,
                'has_gap' => false,
            ])->values();
            $daysTracked = $metrics->pluck('metric_date')->unique()->count();

            $thisContentRecent = $metrics->whereBetween('metric_date', [$peerStart, $peerEnd]);
            $recentViews = (int) $thisContentRecent->sum('views');
            $recentEngagement = $thisContentRecent->count() > 0 ? (float) $thisContentRecent->avg('engagement_rate') : null;
            $hasRecentData = $thisContentRecent->isNotEmpty();
            // CSV/manual TIDAK punya konsep "total saat ini" terpisah dari
            // periode (Part AC, "do not fabricate current-total semantics
            // where they do not exist") - periodDelta di sini murni sum
            // baris dalam window, TIDAK ADA current_observed_at (tidak ada
            // sync provider sama sekali).
            $periodDelta = [
                'views' => $recentViews,
                'likes' => $thisContentRecent->isNotEmpty() ? (int) $thisContentRecent->sum('likes') : null,
                'comments' => $thisContentRecent->isNotEmpty() ? (int) $thisContentRecent->sum('comments') : null,
                'shares' => $thisContentRecent->isNotEmpty() ? (int) $thisContentRecent->sum('shares') : null,
                'saves' => $thisContentRecent->isNotEmpty() ? (int) $thisContentRecent->sum('saves') : null,
            ];
            $periodDeltaAvailable = $hasRecentData;
            $currentObservedAt = null;
        }

        // --- Perbandingan vs rata-rata konten lain milik client yang sama ---
        // Dibatasi 30 hari terakhir biar adil dibandingin (konten yang lebih
        // lama ditrack otomatis lebih unggul kalau dibandingin all-time).
        // Roster peer = STANDAR computeClientPeriod() (client_id langsung,
        // API+CSV tergabung, sama seperti Overview/Table) - hanya baris
        // usable (full/partial) yang dipakai, "unavailable" tidak boleh
        // ikut menyeret rata-rata jadi 0 palsu.
        $peerAggregate = $periodPerformanceService->computeClientPeriod($contentItem->client_id, $peerStart, $peerEnd, null);
        $peerRows = collect($peerAggregate['rows'])
            ->filter(fn ($row) => $row['result']->isUsable())
            ->filter(fn ($row) => ($row['content_metric']->content_item_id ?? null) !== $contentItem->id);

        $peerAvgViews = $peerRows->isNotEmpty() ? $peerRows->avg(fn ($row) => $row['result']->views() ?? 0) : null;
        $peerEngagementValues = $peerRows->map(fn ($row) => $row['result']->engagementRate)->filter(fn ($v) => $v !== null);
        $peerAvgEngagement = $peerEngagementValues->isNotEmpty() ? $peerEngagementValues->avg() : null;

        $viewsVsPeerPct = ($peerAvgViews && $peerAvgViews > 0)
            ? round((($recentViews - $peerAvgViews) / $peerAvgViews) * 100)
            : null;
        $engagementVsPeerPct = ($peerAvgEngagement && $peerAvgEngagement > 0 && $recentEngagement !== null)
            ? round((($recentEngagement - $peerAvgEngagement) / $peerAvgEngagement) * 100)
            : null;
        $hasPeerComparison = $peerRows->isNotEmpty() && $hasRecentData;

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
            'peerAvgEngagement',
            'periodDelta',
            'periodDeltaAvailable',
            'currentObservedAt',
            'publishedAt'
        ));
    }


    /**
     * KF3xx — Export Performance Data
     * Download CSV performa konten client terpilih pada periode terpilih.
     *
     * Phase 3: KOLOM DIUBAH (Langkah 9C, "jangan lagi export 'content
     * published in period' seolah period performance; label/columns harus
     * jujur") - dulu format kolomnya SENGAJA sama persis dengan Import CSV
     * (content_title,platform,metric_date,views,engagement_rate) biar bisa
     * di-import ulang, TAPI itu keliru buat data API: metric_date API
     * dikunci ke tanggal PUBLISH, jadi "views" di baris itu sebenarnya
     * lifetime cumulative sampai publish, BUKAN performa periode terpilih.
     * Sekarang views/engagement_rate = DELTA periode (PeriodPerformanceService),
     * kolom period_start/period_end/coverage_status ditambahkan biar angka
     * ini jelas maksudnya "gain periode X" dan TIDAK bisa disalahartikan
     * sebagai baris harian - file ini TIDAK dimaksudkan buat di-import balik
     * lewat importPerformance() (beda semantik metric_date CSV yang
     * per-hari asli vs kolom period_end di sini).
     */
    public function export(Request $request, AnalyticsPeriodResolver $periodResolver)
    {
        $selectedClientId = $request->input('client_id');

        if (!$selectedClientId) {
            return back()->with('export_error', 'Pilih client dulu sebelum export.');
        }

        $this->assertClientAccessible((int) $selectedClientId);

        $client = Client::findOrFail($selectedClientId);

        // PASS 2 - export HARUS resolve range yang SAMA PERSIS dengan
        // Overview/Table buat client+platform yang sama (Langkah 12,
        // "Export/Report consistency") - SATU-SATUNYA jalur resmi.
        //
        // PASS 2.1 (Langkah 2, "INVALID EXPORT PERIOD") - beda dari halaman
        // utama (boleh fallback tenang + banner), export TIDAK BOLEH diam-
        // diam ganti periode kalau input period_mode/month/date_from/
        // date_to yang DIKIRIM EKSPLISIT ternyata tidak valid (rentang
        // kebalik/masa depan/lebih dari MAX_CUSTOM_RANGE_DAYS/format salah)
        // - user yang minta "export September" TIDAK BOLEH diam-diam
        // menerima file bulan berjalan yang lain, itu salah tafsir data
        // tanpa disadari. $error TETAP null (jadi TIDAK diblokir) buat
        // kasus "tidak ada input period sama sekali" - itu default yang
        // sah, bukan input tidak valid.
        ['period' => $period, 'error' => $periodError] = $periodResolver->resolveWithError($request);
        if ($periodError) {
            return back()->with('export_error', $periodError);
        }

        $start = $period->dateFrom;
        $end = $period->effectiveDateTo;

        // Phase 4.1 (Langkah 6) - platform_id GLOBAL filter sekarang ikut
        // dibawa ke export (dulu selalu null/"semua platform" walau user
        // lagi pilih 1 platform di halaman Analytics - export.blade link
        // juga sudah dikirim platform_id-nya, cuma sebelumnya dibuang di
        // sini).
        $platformId = $this->resolvePlatformId($request);

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 16) -
        // roster = cohort publikasi (published_at), metrics utama = performa
        // TERKINI genuine (content_metrics apa adanya). Kolom period_gain_*
        // TETAP ada (opsional/sekunder, dari period_result yang di-attach
        // ContentCohortService) TAPI TIDAK PERNAH lagi jadi alasan 1 baris
        // dikecualikan dari export.
        $aggregate = app(ContentCohortService::class)->computeClientCohort($client->id, $start, $end, $platformId);
        $rows = collect($aggregate['rows']);

        $filename = 'performance-' . str($client->name)->slug() . '-' . now()->format('Ymd-His') . '.csv';

        $callback = function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['content_title', 'platform', 'published_at', 'current_views', 'current_likes', 'current_comments', 'current_shares', 'current_engagement_rate', 'period_views_gain', 'period_coverage_status']);

            foreach ($rows as $row) {
                $metric = $row['content_metric'];
                $periodResult = $row['period_result'];
                $isCsv = $row['source'] === 'csv';

                $snapshot = ! $isCsv ? ($metric->instagramMediaSnapshot ?? $metric->tiktokVideoSnapshot) : null;
                $title = $metric->contentItem?->title
                    ?? ($snapshot?->caption ? \Illuminate\Support\Str::limit($snapshot->caption, 60) : null)
                    ?? ($snapshot?->video_description ? \Illuminate\Support\Str::limit($snapshot->video_description, 60) : null)
                    ?? '-';

                fputcsv($handle, [
                    $title,
                    $metric->platform->name ?? '-',
                    $row['published_at']?->toDateString(),
                    $isCsv ? '' : $metric->views,
                    $isCsv ? '' : $metric->likes,
                    $isCsv ? '' : $metric->comments,
                    $isCsv ? '' : $metric->shares,
                    $metric->engagement_rate,
                    // CSV/manual tidak punya konsep "current total" terpisah
                    // dari periode - nilainya sendiri (genuine, apa adanya
                    // dari input user) ditaruh di sini (bukan hilang tanpa
                    // muncul di kolom manapun).
                    $isCsv ? $metric->views : $periodResult?->views(),
                    $isCsv ? \App\Services\ContentPeriodResult::PARTIAL : ($periodResult?->coverageStatus ?? \App\Services\ContentPeriodResult::UNAVAILABLE),
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
     * Phase 4 - dispatch tombol global "Perbarui Data". SELALU
     * client-scoped (Langkah 2 - TIDAK PERNAH dispatch global/all-client),
     * platform_id (kalau ada) menentukan subjob mana yang relevan (Langkah
     * 3). Payload cuma client_id + platform_id - TIDAK PERNAH menerima
     * access_token/credential apapun dari browser (Langkah 9). CSRF
     * terlindungi lewat middleware web stack standar (route ini ada di
     * dalam grup `web` seperti semua route lain, VerifyCsrfToken otomatis
     * aktif).
     */
    public function syncDispatch(Request $request, AnalyticsSyncOrchestrator $orchestrator)
    {
        $clientId = $request->input('client_id');

        if (! $clientId) {
            return response()->json(['message' => 'Pilih client untuk menyinkronkan data.'], 422);
        }

        // Manual scope check (client_id lewat body, bukan route-model-
        // binding) - pola SAMA PERSIS dengan SettingsController::
        // syncInstagram()/syncTiktok() (assertClientAccessible() di
        // Controller dasar, dipakai bareng export() di atas).
        $this->assertClientAccessible((int) $clientId);
        $client = Client::findOrFail($clientId);

        $platformId = $this->resolvePlatformId($request);

        if ($platformId !== null && ! Platform::whereKey($platformId)->whereIn('name', ['Instagram', 'TikTok'])->exists()) {
            return response()->json(['message' => 'Platform tidak valid untuk sinkronisasi.'], 422);
        }

        $result = $orchestrator->dispatch($client, $platformId, auth()->id());

        return response()->json($result);
    }

    /**
     * Phase 4 - status polling read-only buat tombol "Perbarui Data" -
     * dipoll JS tiap ~2-3 detik (Langkah 10). Client-scoped SAMA PERSIS
     * dengan syncDispatch() di atas. Response HANYA data UX yang aman
     * ditampilkan - lihat AnalyticsSyncOrchestrator::statusPayload(), tidak
     * ada token/secret/raw exception yang pernah keluar dari sana.
     */
    public function syncStatus(Request $request, AnalyticsSyncOrchestrator $orchestrator)
    {
        $clientId = $request->input('client_id');

        if (! $clientId) {
            return response()->json(['overall_status' => 'idle', 'message' => 'Pilih client untuk menyinkronkan data.', 'subjobs' => []]);
        }

        $this->assertClientAccessible((int) $clientId);
        $client = Client::findOrFail($clientId);

        $platformId = $this->resolvePlatformId($request);

        // Analytics V2 Phase B - 'progress' TAMBAHAN, additive (key baru,
        // seluruh key existing di statusForClient() TIDAK berubah sama
        // sekali - konsumen lama tetap jalan identik). null kalau belum
        // pernah ada AnalyticsSyncRun buat client ini (integration belum
        // pernah sync lewat orchestrator, mis. instalasi baru) - itu state
        // VALID, bukan error.
        //
        // SYNC UI STALE TERMINAL STATE BUG FIX - No-Cache eksplisit. Poll()
        // JS memanggil URL yang PERSIS SAMA (client_id/platform_id statis)
        // berulang tiap 2.5 detik selama satu run - tanpa header ini,
        // response GET ini genuinely rentan di-cache oleh browser ATAU
        // reverse proxy/CDN di depan deployment production (heuristic
        // freshness caching, RFC 7234) begitu server TIDAK secara eksplisit
        // bilang "jangan di-cache", membuat client bisa menerima payload
        // status BASI walau backend sudah genuinely maju - defense-in-depth
        // di atas fix single-source-of-truth di AnalyticsSyncOrchestrator.
        return response()->json([
            ...$orchestrator->statusForClient($client, $platformId),
            'progress' => $orchestrator->latestRunProgress($client, $platformId),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * PASS 3 (Langkah H, "TARGETED RETRY UX") - retry SATU subjob penuh
     * (mis. "Coba lagi TikTok" / "Coba lagi data Audiens") - dispatch ulang
     * dari awal lewat AnalyticsSyncOrchestrator::retryTask(), TIDAK PERNAH
     * dispatch complete sync lain. task_id lewat body (bukan route-model-
     * binding) - authorization diverifikasi manual lewat client milik
     * integration task ini (pola sama syncDispatch/export di atas).
     */
    public function syncRetryTask(Request $request, AnalyticsSyncOrchestrator $orchestrator)
    {
        $task = AnalyticsSyncTask::with('integration')->find($request->input('task_id'));

        if (! $task || ! $task->integration) {
            return response()->json(['retried' => false, 'reason' => 'not_found'], 404);
        }

        $this->assertClientAccessible((int) $task->integration->client_id);

        return response()->json($orchestrator->retryTask($task, auth()->id()));
    }

    /**
     * PASS 3 (Langkah H) - retry HANYA item/failure yang masih gagal+
     * retryable milik 1 task (mis. "Coba lagi 1 video"), TIDAK dispatch job
     * baru dari awal - langsung lewat AnalyticsSyncOrchestrator::
     * retryFailedItemsForTask() (Pass 1B, synchronous, cuma menyasar
     * AnalyticsSyncFailure unresolved+retryable milik task ini).
     */
    public function syncRetryFailedItems(Request $request, AnalyticsSyncOrchestrator $orchestrator)
    {
        $task = AnalyticsSyncTask::with('integration')->find($request->input('task_id'));

        if (! $task || ! $task->integration) {
            return response()->json(['retried' => false, 'reason' => 'not_found'], 404);
        }

        $this->assertClientAccessible((int) $task->integration->client_id);

        return response()->json($orchestrator->retryFailedItemsForTask($task, auth()->id()));
    }

    /**
     * PASS 3 (Langkah N, "SYNC HISTORY") - 5 AnalyticsSyncRun TERAKHIR
     * milik client ini yang SUDAH selesai (finished_at terisi - run yang
     * masih queued/running ditangani panel progress aktif, bukan histori).
     * Murni presentation query, TIDAK menyentuh AnalyticsSyncOrchestrator
     * (itu tetap satu-satunya sumber status LIVE).
     *
     * @return \Illuminate\Support\Collection<int, array{started_at: \Illuminate\Support\Carbon, platforms_label: string, status_label: string, counts_label: ?string, duration_label: ?string}>
     */
    private function buildSyncHistory(int $clientId): \Illuminate\Support\Collection
    {
        return AnalyticsSyncRun::where('client_id', $clientId)
            ->whereNotNull('finished_at')
            ->with('tasks')
            ->latest('started_at')
            ->take(5)
            ->get()
            ->map(function (\App\Models\AnalyticsSyncRun $run) {
                $contentTasks = $run->tasks->whereIn('subjob', ['instagram_content', 'tiktok_content']);

                $platformsLabel = $contentTasks->map(fn ($t) => $t->subjob === 'tiktok_content' ? 'TikTok' : 'Instagram')
                    ->unique()->implode(' + ');

                $statusLabel = match ($run->status) {
                    'success' => 'Selesai',
                    'partial' => 'Selesai sebagian',
                    'failed' => 'Gagal',
                    'needs_reconnect' => 'Butuh dihubungkan ulang',
                    default => 'Selesai sebagian',
                };

                $countsLabel = $contentTasks
                    ->filter(fn ($t) => $t->discovered_count > 0)
                    ->map(function ($t) {
                        $short = $t->subjob === 'tiktok_content' ? 'TikTok' : 'IG';

                        return $t->success_count === $t->discovered_count
                            ? "{$t->success_count} {$short}"
                            : "{$t->success_count}/{$t->discovered_count} {$short}";
                    })
                    ->implode(' · ') ?: null;

                $durationLabel = null;
                if ($run->started_at && $run->finished_at) {
                    $seconds = $run->started_at->diffInSeconds($run->finished_at);
                    $durationLabel = $seconds >= 60
                        ? sprintf('%dm %dd', intdiv($seconds, 60), $seconds % 60)
                        : "{$seconds}d";
                }

                return [
                    'started_at' => $run->started_at,
                    'platforms_label' => $platformsLabel ?: 'Audiens',
                    'status_label' => $statusLabel,
                    'counts_label' => $countsLabel,
                    'duration_label' => $durationLabel,
                ];
            });
    }

    private function resolvePlatformId(Request $request): ?int
    {
        $platformId = $request->input('platform_id');

        return $platformId !== null && $platformId !== '' ? (int) $platformId : null;
    }

    /**
     * Bulan analisis AI Strategy (YYYY-MM) - READ/display context, pola
     * tolerant-fallback SAMA seperti $period lain di controller ini
     * (bukan hard-reject; field ini muncul di URL/GET, bukan mutating).
     * Default bulan berjalan kalau kosong/invalid/di masa depan.
     */
    private function resolveAnalysisMonth(Request $request): string
    {
        $raw = (string) $request->input('analysis_month', '');
        $currentMonth = Carbon::now()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) {
            return $currentMonth;
        }

        // Bulan di masa depan tidak masuk akal buat retrospective
        // performance analysis - treat sebagai bulan berjalan.
        return $raw > $currentMonth ? $currentMonth : $raw;
    }

    /**
     * "Agustus 2026" buat bulan yang sudah lewat, atau "September 2026
     * hingga 2 September 2026" buat bulan berjalan (Langkah 5/8 - jangan
     * klaim performa bulan penuh kalau bulannya belum selesai).
     */
    private function analysisMonthLabel(string $month): string
    {
        $monthCarbon = Carbon::createFromFormat('Y-m-d', $month.'-01');
        $label = $monthCarbon->translatedFormat('F Y');

        if ($month === Carbon::now()->format('Y-m')) {
            $label .= ' hingga '.Carbon::now()->translatedFormat('d F Y');
        }

        return $label;
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
    private function buildTableTabData(int|string $selectedClientId, Request $request, AnalyticsPeriod $period, ?int $platformId): array
    {
        $client = Client::findOrFail($selectedClientId);

        $sort = $request->input('sort', 'total_views');
        $dir = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['total_views', 'avg_engagement', 'deadline_at', 'title'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'total_views';
        }

        // PASS 2 - date math SEKARANG SATU-SATUNYA sumber (AnalyticsPeriod
        // dari resolver di index()), BUKAN subDays($period) lokal lagi.
        // effectiveDateTo (bukan dateTo mentah) yang dipakai - bulan
        // berjalan TIDAK dievaluasi sampai tanggal yang belum terjadi.
        $start = $period->dateFrom;
        $end = $period->effectiveDateTo;

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - roster SEKARANG
        // cohort publikasi (ContentCohortService, published_at), BUKAN lagi
        // isUsable()-filtered computeClientPeriod() rows. Konten yang
        // dipublikasikan periode ini TETAP tampil walau riwayat observasi
        // period-gain-nya belum cukup (mis. sync baru mulai setelah periode
        // itu berlalu) - lihat docblock ContentCohortService buat root
        // cause lengkap ("empty August" bug).
        $cohortContextMessage = 'Menampilkan performa terkini konten yang dipublikasikan pada '.$period->label().'.';
        $aggregate = app(ContentCohortService::class)->computeClientCohort($client->id, $start, $end, $platformId);

        $formatResolver = app(\App\Services\ContentFormatResolver::class);

        $rows = collect($aggregate['rows'])
            ->map(function ($row) use ($formatResolver) {
                $metric = $row['content_metric'];
                $periodResult = $row['period_result']; // null utk CSV - lihat ContentCohortService
                $isCsv = $row['source'] === 'csv';
                $item = $metric->contentItem;
                $igSnapshot = ! $isCsv ? $metric->instagramMediaSnapshot : null;
                $ttSnapshot = ! $isCsv ? $metric->tiktokVideoSnapshot : null;
                $snapshot = $igSnapshot ?? $ttSnapshot;

                return (object) [
                    'id' => $item?->id,
                    'title' => $item?->title ?? \Illuminate\Support\Str::limit(($snapshot?->caption ?? $snapshot?->video_description) ?: 'Post', 60),
                    'platform' => $metric->platform->name ?? '-',
                    'content_type_id' => $item?->content_type_id,
                    // SYSTEM CONSISTENCY PASS (Part B/C/H) - "type" tunggal
                    // yang dulu bergantian isi ContentType ATAU format
                    // provider (2 dimensi beda dicampur 1 field) DIPECAH
                    // jadi 2 field terpisah, konsisten di kedua kondisi
                    // link:
                    // - production_type: SELALU ContentType internal
                    //   (Desain/Video) - null kalau unmatched (belum ada
                    //   ContentItem sama sekali, TIDAK PERNAH ditebak dari
                    //   format provider).
                    // - content_format: kanonis (Single Post/Carousel/
                    //   Video) lewat ContentFormatResolver - prioritas
                    //   master ContentItem->contentFormat kalau sudah
                    //   ke-link & diisi, fallback normalisasi provider utk
                    //   yang belum (linked TANPA content_format_id maupun
                    //   unmatched sama-sama boleh fallback).
                    'production_type' => $item?->contentType?->name,
                    'content_format' => $item
                        ? $formatResolver->labelForContentItem($item, $igSnapshot, $ttSnapshot)
                        : $formatResolver->labelForSnapshot($igSnapshot, $ttSnapshot),
                    // PASS 3 (Data Health, "never turn missing into zero") -
                    // BUG DITEMUKAN & DIPERBAIKI: versi lama "?? 0" di sini
                    // diam-diam mengubah views/engagement yang genuinely NULL
                    // (mis. metric_reset_or_correction - row TETAP 'usable'/
                    // partial, tapi delta-nya sendiri belum bisa dipercaya)
                    // jadi "0" SEBELUM sempat sampai ke blade - padahal blade
                    // SUDAH benar cek `!== null`, cuma nilainya sudah keburu
                    // ditimpa di sini. Null TETAP null sekarang.
                    //
                    // SYSTEM CONSISTENCY PASS (Part AA/AB) / FINAL ANALYTICS
                    // PRODUCT SEMANTICS CORRECTION - 'current_views' = total
                    // provider TERKINI (content_metrics.views apa adanya,
                    // concept B, PRIMARY) - HANYA diisi buat konten yang
                    // genuinely API-linked ($snapshot ada), TIDAK PERNAH
                    // difabrikasi buat CSV/manual (tidak ada konsep "current
                    // total" terpisah dari periode di sana). 'total_views'
                    // SEKARANG murni gain periode SEKUNDER (concept C, dari
                    // period_result yang di-attach ContentCohortService) -
                    // nullable kalau riwayat observasi belum cukup (mis.
                    // sync baru mulai setelah periode ini berlalu) - TIDAK
                    // PERNAH lagi menentukan apakah baris ini muncul sama
                    // sekali (itu sudah diputuskan roster cohort di atas,
                    // murni published_at).
                    'current_views' => $isCsv ? null : ($snapshot ? $metric->views : null),
                    'current_observed_at' => $snapshot?->last_fetched_at,
                    'total_views' => $isCsv ? (int) $metric->views : $periodResult?->views(),
                    'avg_engagement' => $isCsv
                        ? ($metric->engagement_rate !== null ? (float) $metric->engagement_rate : null)
                        : ($metric->engagement_rate !== null ? (float) $metric->engagement_rate : null),
                    'coverage_status' => $isCsv ? \App\Services\ContentPeriodResult::PARTIAL : ($periodResult?->coverageStatus ?? \App\Services\ContentPeriodResult::UNAVAILABLE),
                    'availability_category' => $isCsv ? 'available' : ($periodResult?->availabilityCategory() ?? 'insufficient_history'),
                    // Deadline HANYA dari workflow internal - null kalau
                    // unmatched, TIDAK PERNAH diisi dari published_at/
                    // snapshot_date/created_at (itu bukan deadline).
                    'deadline_at' => $item?->deadline_at,
                    'is_posted' => $item?->is_posted,
                    'is_overdue' => $item?->workflow?->is_overdue ?? false,
                    'linked' => (bool) $item,
                    'permalink' => $snapshot?->permalink ?? $snapshot?->share_url ?? null,
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

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 7, 13) -
        // sort key/URL param "total_views" DIPERTAHANKAN (backward
        // compatible link/bookmark), TAPI nilai yang dibandingkan SEKARANG
        // current_views dulu (performa TERKINI, primary) dengan total_views
        // (gain periode, secondary) sebagai fallback - "top-performing
        // content dalam cohort" berarti diranking dari performa sekarang,
        // bukan gain periode yang bisa jadi kosong semata karena riwayat
        // observasi baru mulai.
        $rows = match ($sort) {
            'total_views' => $rows->sortBy(fn ($r) => $r->current_views ?? $r->total_views ?? -INF, SORT_REGULAR, $dir === 'desc'),
            'avg_engagement' => $rows->sortBy(fn ($r) => $r->{$sort} ?? -INF, SORT_REGULAR, $dir === 'desc'),
            default => $rows->sortBy($sort, SORT_REGULAR, $dir === 'desc'),
        };
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

        // platformOptions TIDAK dihitung ulang di sini - dropdown platform
        // sekarang GLOBAL (dihitung sekali di index(), dipakai identik di
        // ketiga tab), jangan return key yang sama biar tidak menimpa punya
        // global saat di-array_merge() controller.
        $contentTypeOptions = \App\Models\ContentType::whereHas('contentItems', fn($q) => $q->where('client_id', $client->id))->get();

        return compact('client', 'items', 'contentTypeOptions', 'sort', 'dir', 'cohortContextMessage');
    }

    /**
     * Data buat tab "Audience" di halaman Analytics - dipindah dari
     * AudienceController::index() (sekarang jadi redirect ke sini) biar
     * 1 halaman yang sama kayak Performance Table.
     *
     * $platformId - GLOBAL filter (dihitung sekali di index(), sama persis
     * yang dipakai Overview/Table), BUKAN lagi dropdown lokal punya tab ini
     * (Phase 1 item 2/3). null berarti "Semua Platform" dipilih di filter
     * global - Audience TIDAK PERNAH agregat demografi lintas platform
     * (unit beda per platform, gabungan jadi angka yang nggak berarti),
     * jadi minta user pilih 1 platform dulu (Phase 1 item 5).
     *
     * Precedence (Langkah 17 "Instagram Audience Insights"): kalau
     * client+platform SUDAH PERNAH punya row API (instagram_api ATAU
     * tiktok_api), tab ini HANYA baca API (summary + demographic_type
     * terpisah kalau ada) - CSV/legacy untuk kombinasi itu diabaikan
     * sepenuhnya, tidak digabung (unit beda: CSV manual vs API real, campur
     * jadi angka yang nggak berarti). Kalau belum pernah ada row API sama
     * sekali, fallback ke CSV/legacy persis seperti behavior lama.
     */
    private function buildAudienceTabData(int|string $selectedClientId, AnalyticsPeriod $period, ?int $platformId): array
    {
        $client = Client::findOrFail($selectedClientId);

        if (! $platformId) {
            return ['noPlatformSelected' => true, 'client' => $client];
        }

        $platform = Platform::find($platformId);
        if (! $platform) {
            return ['noPlatformSelected' => true, 'client' => $client];
        }

        // Resolve source EXACT per platform - JANGAN pakai boolean
        // hasApiData lalu hardcode SOURCE_API (bug lama: TikTok API data
        // ke-render sebagai "Instagram API" karena SOURCE_API === 'instagram_api'
        // dan apiSourced() scope sendiri sudah generic mencakup instagram_api
        // MAUPUN tiktok_api). 1 platform_id cuma pernah py 1 source API
        // (Instagram nulis instagram_api, TikTok nulis tiktok_api - tidak
        // pernah dua-duanya buat platform yang sama), jadi baris apiSourced()
        // PERTAMA sudah cukup buat tahu source sebenarnya.
        $apiRow = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->apiSourced()
            ->first();
        $hasApiData = (bool) $apiRow;

        $hasCsvData = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->csvSourced()
            ->exists();

        if (! $hasApiData && ! $hasCsvData) {
            return ['noInsightData' => true, 'client' => $client, 'platform' => $platform];
        }

        $data = $hasApiData
            ? $this->buildApiAudienceData($client, $platform, $period)
            : $this->buildCsvAudienceData($client, $platform, $period);

        return array_merge(
            compact('client', 'platform'),
            ['audienceSource' => $hasApiData ? $apiRow->source : 'csv', 'periodLabel' => $period->label()],
            $data
        );
    }

    /**
     * Sumber Instagram API real - summary row (followers/reach/active_hours)
     * + 3 demographic_type terpisah (follower/reached/engaged), masing-masing
     * BOLEH null kalau memang belum ada datanya (threshold/belum sync) -
     * TIDAK PERNAH ditebak jadi 0/array kosong (Langkah 4/18).
     */
    private function buildApiAudienceData(Client $client, Platform $platform, AnalyticsPeriod $period): array
    {
        $start = $period->dateFrom;
        $end = $period->effectiveDateTo;

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
            ->where('snapshot_date', '<=', $end)
            ->map(fn ($row) => ['label' => Carbon::parse($row->snapshot_date)->translatedFormat('d M'), 'value' => $row->follower_count])
            ->values();

        // Reach: kebalikan dari follower_count - historis LENGKAP (backfill
        // s/d 180 hari terbukti tersedia), jadi trend-nya jauh lebih kaya.
        // PASS 2 - dibatasi <= effectiveDateTo juga (bukan cuma >= start),
        // supaya month/custom range yang genuinely di masa lalu TIDAK
        // bocor data setelah date_to (dulu aman diam-diam krn "start" only
        // filter cukup buat rolling-days, karena upper bound selalu "now").
        $reachRows = (clone $baseQuery())->summary()->whereNotNull('reach')
            ->where('snapshot_date', '>=', $start)
            ->where('snapshot_date', '<=', $end)
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

        // PASS 3 (Langkah J, "DATA HEALTH UX") - ringkasan ringkas metrik
        // yang genuinely null buat platform ini, dipakai disclosure "Lihat
        // kondisi data" (BUKAN kalkulasi baru - murni membaca ulang null-
        // check yang SUDAH ADA di atas, satu tempat biar view tidak perlu
        // ulang logic yang sama). insufficient_history dipakai sebagai
        // kategori JUJUR default (kita genuinely tidak tahu di sini apakah
        // penyebabnya threshold Meta atau memang belum pernah sync - lihat
        // AvailabilityPresenter, TIDAK menebak kategori yang lebih spesifik
        // dari yang bisa dibuktikan).
        $dataHealthItems = [];
        if ($platform->name === 'Instagram') {
            if ($latestReach === null) {
                $dataHealthItems[] = ['label' => 'Reach Akun', 'category' => \App\Services\AvailabilityPresenter::INSUFFICIENT_HISTORY];
            }
            if ($activeHours === null) {
                $dataHealthItems[] = ['label' => 'Jam Aktif Audiens', 'category' => \App\Services\AvailabilityPresenter::INSUFFICIENT_HISTORY];
            }

            // PASS 4 (Langkah 7) - integration id dibutuhkan buat cek signal
            // provider-availability TERBUKTI (code 3006, lihat
            // InstagramAudienceInsightsService::isKnownProviderUnavailable())
            // - lookup ringan, 1 baris, cuma dipakai kalau ada demographic
            // yang null (baris di atas TIDAK selalu butuh ini).
            $integrationId = $client->apiIntegrations()
                ->whereHas('platform', fn ($q) => $q->where('id', $platform->id))
                ->value('id');

            foreach (['follower' => 'Follower Demographics', 'reached' => 'Reached Audience', 'engaged' => 'Engaged Audience'] as $type => $demoLabel) {
                if (($demographics[$type] ?? null) === null) {
                    // PASS 4 (Langkah 7) - PROVIDER_UNAVAILABLE HANYA kalau
                    // sync terakhir genuinely membuktikannya (code 3006
                    // Meta) - selain itu TETAP insufficient_history yang
                    // jujur (Langkah 7, "do NOT guess when no evidence").
                    $category = ($integrationId && \App\Services\InstagramAudienceInsightsService::isKnownProviderUnavailable($integrationId, $type))
                        ? \App\Services\AvailabilityPresenter::PROVIDER_UNAVAILABLE
                        : \App\Services\AvailabilityPresenter::INSUFFICIENT_HISTORY;
                    $dataHealthItems[] = ['label' => $demoLabel, 'category' => $category];
                }
            }
        }

        return compact('lastSyncAt', 'lastCount', 'growth', 'growthMessage', 'followerTrend', 'latestReach', 'reachTrend', 'activeHours', 'peakHour', 'demographics', 'dataHealthItems');
    }

    /**
     * Sumber CSV/legacy - behavior PERSIS sama seperti sebelum Instagram
     * Audience API ada (1 row/hari, generic, persentase langsung dari CSV).
     * TIDAK diubah sama sekali selain scope query apiSourced() jadi
     * csvSourced() (Langkah 15/21 - CSV tetap compatible).
     */
    private function buildCsvAudienceData(Client $client, Platform $platform, AnalyticsPeriod $period): array
    {
        $start = $period->dateFrom;
        $end = $period->effectiveDateTo;

        $baseQuery = fn () => AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->csvSourced();

        $latestSnapshot = (clone $baseQuery())->latest('snapshot_date')->first();

        $history = (clone $baseQuery())->where('snapshot_date', '>=', $start)->where('snapshot_date', '<=', $end)->orderBy('snapshot_date')->get();

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
        // Phase L (re-audit) - client_id lewat query string, bukan
        // route-model-binding, jadi client.scope middleware tidak bisa
        // dipasang di route ini - tanpa AssignedClient, role ter-scope bisa
        // baca riwayat AI Strategy client manapun cuma dengan ganti query
        // string, sama kelas bug dengan KI-09.
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id', new AssignedClient],
        ]);

        $client = Client::findOrFail($validated['client_id']);

        $insights = AiStrategyInsight::where('client_id', $client->id)
            ->with(['generatedBy', 'platform'])
            // orderByDesc('id') - lihat catatan di index()'s $latestAiInsight,
            // same-second created_at ties tidak dijamin urut oleh latest().
            ->orderByDesc('id')
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

        // Phase 4.1 (v2, "strict validation for AI generation", tetap
        // berlaku setelah beralih dari period 7/30/90 ke calendar month) -
        // endpoint ini MUTATING + BERBAYAR (setiap generate = 1 panggilan
        // Gemini API sungguhan) - BEDA dari index()/export() yang read-
        // only display filter (tolerant fallback masih wajar di sana). Di
        // sini analysis_month/platform_id TIDAK BOLEH silently fallback -
        // input invalid harus DITOLAK KERAS SEBELUM buildPerformanceSummary()/
        // generateStrategy() (jadi Gemini) pernah dipanggil sama sekali,
        // dan SEBELUM AiStrategyInsight dibuat. redirect()->with('ai_error', ...)
        // dipakai (BUKAN default Laravel validate() error bag) karena
        // halaman ini cuma render session('ai_error'), tidak pernah render
        // $errors->first() di manapun - validate() gagal di sini akan
        // silently invisible ke user. Format YYYY-MM divalidasi ketat
        // (regex), TIDAK percaya raw date string dari request begitu saja
        // (Langkah 10) - dan bulan di masa depan ditolak (retrospective
        // analysis, bukan proyeksi).
        $rawMonth = (string) $request->input('analysis_month', '');
        $currentMonth = Carbon::now()->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawMonth) || $rawMonth > $currentMonth) {
            return redirect()->route('analytics', ['client_id' => $client->id])
                ->with('ai_error', 'Bulan analisis tidak valid - pilih bulan yang valid (tidak boleh di masa depan).');
        }
        $month = $rawMonth;

        $platformId = $this->resolvePlatformId($request);
        $redirectParams = array_filter(['client_id' => $client->id, 'analysis_month' => $month, 'platform_id' => $platformId]);

        // "Jangan percaya arbitrary platform ID" - validasi SAMA PERSIS
        // dengan syncDispatch() (satu-satunya platform yang valid buat
        // performa konten sistem ini).
        if ($platformId !== null && ! Platform::whereKey($platformId)->whereIn('name', ['Instagram', 'TikTok'])->exists()) {
            return redirect()->route('analytics', ['client_id' => $client->id, 'analysis_month' => $month])
                ->with('ai_error', 'Platform tidak valid untuk analisis.');
        }

        $window = $aiStrategyService->resolveMonthWindow($month);
        $periodStart = $window['start'];
        $periodEnd = $window['end'];

        try {
            $summary = $aiStrategyService->buildPerformanceSummary($client, $month, $platformId);

            if ($summary['content_published_count'] === 0) {
                $platformNote = $platformId ? ' untuk '.$summary['platform_label'] : '';
                $monthLabel = $this->analysisMonthLabel($month);
                return redirect()->route('analytics', $redirectParams)
                    ->with('ai_error', "Belum ada data performa konten {$monthLabel}{$platformNote} buat client ini - AI butuh data buat dianalisis, bukan nebak.");
            }

            $result = $aiStrategyService->generateStrategy($summary);

            $dataCompleteness = min(100, round(($summary['tracked_days'] / $summary['period_days']) * 100));

            AiStrategyInsight::create([
                'client_id' => $client->id,
                'platform_id' => $platformId,
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

            return redirect()->route('analytics', $redirectParams)
                ->with('ai_success', 'Analisis AI berhasil digenerate.');
        } catch (\Throwable $e) {
            AiStrategyInsight::create([
                'client_id' => $client->id,
                'platform_id' => $platformId,
                'generated_by' => auth()->id(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary' => '-',
                'action_items' => [],
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return redirect()->route('analytics', $redirectParams)
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
     * Terapkan SATU ide AI ke SATU slot content item yang masih kosong -
     * bukan bikin item baru seperti applyAiStrategy() lama. Sejak Content
     * Plan auto-generate slot sejumlah kuota paket, "menerapkan ide" berarti
     * menimpa data slot yang sudah ada (title/brief/pilar/platform), satu
     * per satu, biar user yang menentukan ide mana masuk slot mana - bukan
     * sistem yang mendistribusikan otomatis ke semua slot sekaligus.
     */
    public function applyAiStrategyIdea(Request $request, AiStrategyInsight $aiStrategyInsight, int $index)
    {
        abort_if($aiStrategyInsight->status !== 'completed', 422, 'Cuma analisis yang berhasil yang bisa diterapkan.');

        $ideas = collect($aiStrategyInsight->content_ideas);
        abort_unless($ideas->has($index), 404, 'Ide tidak ditemukan.');
        $idea = $ideas->get($index);

        $appliedIndexes = collect($aiStrategyInsight->applied_idea_indexes ?? []);
        abort_if($appliedIndexes->contains($index), 422, 'Ide ini sudah diterapkan sebelumnya.');

        $validated = $request->validate([
            'content_item_id' => 'required|exists:content_items,id',
        ]);

        $targetItem = ContentItem::where('id', $validated['content_item_id'])
            ->where('client_id', $aiStrategyInsight->client_id)
            ->firstOrFail();

        abort_unless($targetItem->workflow?->current_status === 'draft', 422, 'Slot ini sudah tidak berstatus Draf - pilih slot lain.');

        $pillar = ! empty($idea['pillar']) ? \App\Models\ContentPillar::firstOrCreate(['name' => $idea['pillar']]) : null;

        $typeName = trim($idea['type'] ?? '');
        $contentType = $typeName !== ''
            ? (\App\Models\ContentType::whereRaw('LOWER(name) = ?', [strtolower($typeName)])->first() ?? \App\Models\ContentType::firstOrCreate(['name' => $typeName]))
            : null;

        $platformName = trim($idea['platform'] ?? '');
        $platform = $platformName !== ''
            ? (Platform::whereRaw('LOWER(name) = ?', [strtolower($platformName)])->first() ?? Platform::firstOrCreate(['name' => $platformName]))
            : null;

        $targetItem->update([
            'title' => $idea['title'] ?? $targetItem->title,
            'brief' => $idea['brief'] ?? $targetItem->brief,
            'content_pillar_id' => $pillar?->id ?? $targetItem->content_pillar_id,
            'content_type_id' => $contentType?->id ?? $targetItem->content_type_id,
            'platform_id' => $platform?->id ?? $targetItem->platform_id,
            'ai_strategy_insight_id' => $aiStrategyInsight->id,
        ]);

        if ($platform) {
            $targetItem->platforms()->syncWithoutDetaching([$platform->id]);
        }

        $aiStrategyInsight->update([
            'applied_idea_indexes' => $appliedIndexes->push($index)->unique()->values()->all(),
        ]);

        return back()->with('status', "Ide \"{$idea['title']}\" berhasil diterapkan ke slot \"{$targetItem->provisional_code}\" - lengkapi brief produksinya di halaman konten.");
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