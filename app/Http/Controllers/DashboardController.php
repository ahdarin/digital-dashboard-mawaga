<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentWorkflow;
use App\Models\User;
use App\Services\AnalyticsPeriodResolver;
use App\Services\AnalyticsSummaryService;
use App\Services\DelayRiskAccuracyService;
use App\Services\PeriodPerformanceService;
use App\Services\PicResolver;
use App\Support\WorkflowTransitions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    private array $doneStatuses = WorkflowTransitions::INACTIVE_STATUSES;

    public function index(Request $request, AnalyticsSummaryService $analyticsSummaryService, PeriodPerformanceService $periodPerformanceService, PicResolver $picResolver, AnalyticsPeriodResolver $periodResolver)
    {
        $now = Carbon::now();
        $startOfThisMonth = $now->copy()->startOfMonth();
        $endOfThisMonth = $now->copy()->endOfMonth();
        $startOfLastMonth = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonthNoOverflow()->endOfMonth();

        // KI-10 - Dashboard sebelumnya sama sekali tidak dibatasi per client,
        // jadi SMO (yang di semua halaman lain cuma lihat roster-nya) bisa
        // lihat KPI/ranking/overdue/risk seluruh client di sini. Pola sama
        // persis dengan ContentPlanController::index() - null berarti
        // CEO/Manager (semua client), array berarti dibatasi roster.
        $user = $request->user();
        $assignedClientIds = $user->canSeeAllClients() ? null : $user->assignedClients()->pluck('clients.id');
        $scopeClient = fn ($q, string $column = 'client_id') => $q->when(
            $assignedClientIds !== null, fn ($qq) => $qq->whereIn($column, $assignedClientIds)
        );
        $scopeViaContentItem = fn ($q) => $q->whereHas('contentItem', fn ($qq) => $scopeClient($qq));

        // Phase 3 - roster performa API/CSV di-scope SAMA PERSIS dengan
        // konvensi Dashboard yang sudah ada di atas (whereHas('contentItem',
        // ...) - HANYA content yang SUDAH ke-link ke ContentItem internal,
        // BEDA dari Overview/Table yang ikut post API belum ke-link -
        // preserved apa adanya, bukan scope Phase 3). Delta/coverage/
        // engagement MATH-nya tetap PeriodPerformanceService yang sama
        // (Langkah 9 - "jangan bikin 8 formula terpisah").
        $buildScopedAggregate = function (Carbon $start, Carbon $end) use ($scopeViaContentItem, $periodPerformanceService) {
            $apiMetrics = $scopeViaContentItem(
                ContentMetric::query()->where(fn ($q) => $q->whereNotNull('instagram_media_snapshot_id')->orWhereNotNull('tiktok_video_snapshot_id'))
            )->with(['contentItem.client', 'contentItem.platform', 'platform', 'instagramMediaSnapshot', 'tiktokVideoSnapshot'])->get();

            $csvMetrics = $scopeViaContentItem(
                ContentMetric::query()->whereNull('instagram_media_snapshot_id')->whereNull('tiktok_video_snapshot_id')
                    ->whereBetween('metric_date', [$start, $end])
            )->with(['contentItem.client', 'contentItem.platform', 'platform'])->get();

            return $periodPerformanceService->computeAggregate($apiMetrics, $csvMetrics, $start, $end);
        };

        $contentThisMonth = $scopeClient(ContentItem::whereBetween('deadline_at', [$startOfThisMonth, $endOfThisMonth]))->count();
        $contentLastMonth = $scopeClient(ContentItem::whereBetween('deadline_at', [$startOfLastMonth, $endOfLastMonth]))->count();
        $contentChange = $this->percentChange($contentLastMonth, $contentThisMonth);

        $overdueCount = $scopeViaContentItem(ContentWorkflow::query())
            ->whereNotIn('current_status', WorkflowTransitions::INACTIVE_STATUSES)
            ->where('is_overdue', true)
            ->count();
        $totalWorkflow = $scopeViaContentItem(ContentWorkflow::query())
            ->whereNotIn('current_status', WorkflowTransitions::INACTIVE_STATUSES)
            ->count();
        $overdueRate = $totalWorkflow > 0 ? round(($overdueCount / $totalWorkflow) * 100, 1) : 0;

        $activeClients = $scopeClient(Client::where('status', 'active'), 'id')->count();
        $newClientsThisMonth = $scopeClient(Client::where('created_at', '>=', $startOfThisMonth), 'id')->count();

        $activeTeam = User::query()->where('status', 'active')->count();

        // --- Tambahan: performa/reach (domain PIC 3, PRD 7.3.3 Executive Dashboard) ---
        // Phase 3: pakai PeriodPerformanceService (delta cumulative genuine),
        // BUKAN lagi sum(views) whereBetween(metric_date) - metric_date API
        // dikunci ke tanggal publish, bukan tanggal sync.
        $thisMonthAgg = $buildScopedAggregate($startOfThisMonth, $endOfThisMonth);
        $lastMonthAgg = $buildScopedAggregate($startOfLastMonth, $endOfLastMonth);

        $viewsThisMonth = $thisMonthAgg['totals']['views'];
        $viewsLastMonth = $lastMonthAgg['totals']['views'];
        $viewsChange = $this->percentChange($viewsLastMonth, $viewsThisMonth);

        $uploadedThisMonth = $scopeViaContentItem(ContentWorkflow::query())
            ->where('current_status', 'uploaded')
            ->whereBetween('updated_at', [$startOfThisMonth, $endOfThisMonth])
            ->count();

        $stats = [
            [
                'label' => 'Konten Bulan Ini',
                'value' => number_format($contentThisMonth),
                'change' => $contentChange['label'],
                'trend' => $contentChange['trend'],
                'icon' => 'draft',
                'link' => route('content-plan.index', ['view' => 'calendar']),
            ],
            [
                'label' => 'Klien Aktif',
                'value' => number_format($activeClients),
                'change' => $newClientsThisMonth > 0
                    ? "+{$newClientsThisMonth} klien baru bulan ini"
                    : 'Tidak ada klien baru bulan ini',
                'trend' => $newClientsThisMonth > 0 ? 'up' : 'flat',
                'icon' => 'group',
                'link' => route('client-management.index'),
            ],
            [
                'label' => 'Tim Aktif',
                'value' => number_format($activeTeam),
                'change' => 'Total staf internal (dengan/tanpa akses login)',
                'trend' => 'flat',
                'icon' => 'badge',
                'link' => route('team-performance.index'),
            ],
            [
                'label' => 'Item Overdue',
                'value' => number_format($overdueCount),
                'change' => "{$overdueRate}% dari total workflow berjalan",
                'trend' => $overdueCount > 0 ? 'down' : 'up',
                'icon' => 'schedule',
                'link' => route('production-workflow.index'),
            ],
            [
                'label' => 'Total Views Bulan Ini',
                'value' => number_format($viewsThisMonth),
                'change' => $viewsChange['label'],
                'trend' => $viewsChange['trend'],
                'icon' => 'visibility',
                'link' => '#tren-views',
            ],
            [
                'label' => 'Konten Tayang',
                'value' => number_format($uploadedThisMonth),
                'change' => 'Bulan berjalan',
                'trend' => 'flat',
                'icon' => 'cloud_done',
                'link' => route('production-workflow.index', ['tab' => 'published']),
            ],
        ];

        $performance = collect(range(6, 0))->map(function ($monthsAgo) use ($scopeClient) {
            $month = Carbon::now()->subMonths($monthsAgo);

            $count = $scopeClient(
                ContentItem::whereYear('deadline_at', $month->year)->whereMonth('deadline_at', $month->month)
            )->count();

            return [
                'label' => $month->translatedFormat('M'),
                'value' => $count,
            ];
        })->toArray();

        // --- Tambahan: trend views dengan selector periode 7/30/90 hari (domain PIC 3, PRD 7.3.3) ---
        // PASS 2 - UI/URL widget ini TETAP int 7/30/90 sederhana (di luar
        // scope month/custom pass ini, lihat Langkah 16), TAPI date math-nya
        // sekarang lewat AnalyticsPeriodResolver (SATU-SATUNYA jalur resmi),
        // bukan subDays() lokal lagi.
        $period = (int) $request->input('period', 30);
        $period = in_array($period, [7, 30, 90]) ? $period : 30;

        $trendPeriod = $periodResolver->buildLegacyDays($period);
        $trendStart = $trendPeriod->dateFrom;
        $trendEnd = $trendPeriod->effectiveDateTo;

        $trendSnapshots = ContentMetricSnapshot::query()
            ->whereNotNull('content_item_id')
            ->when($assignedClientIds !== null, fn ($q) => $q->whereIn('client_id', $assignedClientIds))
            ->whereBetween('snapshot_date', [$trendStart->copy()->subDay()->toDateString(), $trendEnd->toDateString()])
            ->get(['instagram_media_snapshot_id', 'tiktok_video_snapshot_id', 'snapshot_date', 'views']);
        $trendCsvMetrics = $scopeViaContentItem(
            ContentMetric::query()->whereNull('instagram_media_snapshot_id')->whereNull('tiktok_video_snapshot_id')
                ->whereBetween('metric_date', [$trendStart, $trendEnd])
        )->get(['metric_date', 'views']);

        $trendDailySeries = $periodPerformanceService->computeDailyGainSeriesFromSnapshots($trendSnapshots, $trendStart, $trendEnd, $trendCsvMetrics);
        $viewsTrend = $analyticsSummaryService->buildTrend($trendDailySeries, $period);

        $attentionItems = $scopeViaContentItem(
            ContentWorkflow::with(['contentItem.client', 'contentItem.workflow.currentPic', 'currentPic'])
        )
            ->where('is_overdue', true)
            ->oldest('updated_at')
            ->take(4)
            ->get()
            ->map(function ($workflow) use ($picResolver) {
                return [
                    'title' => $workflow->contentItem->title ?? 'Tanpa judul',
                    'client' => $workflow->contentItem->client->name ?? '-',
                    'pic' => $workflow->contentItem ? ($picResolver->resolve($workflow->contentItem)['name'] ?? 'Belum ditugaskan') : 'Belum ditugaskan',
                    'status' => $this->statusLabel($workflow->current_status),
                ];
            });

        // Panel prediktif (beda dari "Perlu Perhatian" di atas yang reaktif/is_overdue):
        // item aktif yang BELUM overdue tapi skor AI Delay Risk-nya lagi tinggi - biar
        // tim bisa cegah keterlambatan sebelum kejadian, bukan cuma tahu setelah telat.
        $highRiskItems = $scopeClient(
            ContentItem::with(['client', 'workflow.currentPic', 'latestDelayRisk'])
                ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', $this->doneStatuses)->where('is_overdue', false))
                ->whereHas('latestDelayRisk', fn ($q) => $q->where('risk_level', 'high'))
        )
            ->get()
            ->sortByDesc(fn ($item) => $item->latestDelayRisk->risk_score)
            ->take(4)
            ->map(function ($item) use ($picResolver) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'client' => $item->client->name ?? '-',
                    'pic' => $picResolver->resolve($item)['name'] ?? 'Belum ditugaskan',
                    'risk_score' => $item->latestDelayRisk->risk_score,
                    'top_factor' => $item->latestDelayRisk->top_factor,
                ];
            });

        // --- Tambahan: teaser Analytics (bulan berjalan, ikut scope client) ---
        // Phase 3: pakai $thisMonthAgg['rows'] (hasil PeriodPerformanceService,
        // sudah dihitung di atas buat KPI Total Views) - 1 row = 1 delta
        // periode per content, BUKAN lagi sum(views) mentah per metric_date.
        $usableMonthRows = collect($thisMonthAgg['rows'])->filter(fn ($row) => $row['result']->isUsable());

        $topContent = $usableMonthRows
            ->map(function ($row) {
                $item = $row['content_metric']->contentItem;
                if (! $item) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'client' => $item->client->name ?? '-',
                    'platform' => $item->platform->name ?? '-',
                    'views' => $row['result']->views() ?? 0,
                    'engagement_rate' => $row['result']->engagementRate ?? 0,
                ];
            })
            ->filter()
            ->sortByDesc('views')
            ->take(5)
            ->values();

        // --- Tambahan: Top Client ranking (PRD 7.3.3 Executive Dashboard) ---
        $topClients = $usableMonthRows
            ->groupBy(fn ($row) => $row['content_metric']->contentItem->client_id ?? 0)
            ->map(function ($rows) {
                $client = $rows->first()['content_metric']->contentItem->client ?? null;
                if (! $client) {
                    return null;
                }

                $engagementValues = $rows->pluck('result.engagementRate')->filter(fn ($v) => $v !== null);

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'views' => (int) $rows->sum(fn ($row) => $row['result']->views() ?? 0),
                    'engagement_rate' => $engagementValues->isNotEmpty() ? round($engagementValues->avg(), 2) : 0,
                    'content_count' => $rows->count(),
                ];
            })
            ->filter()
            ->sortByDesc('views')
            ->take(5)
            ->values();

        // --- Tambahan: teaser akurasi prediksi AI Delay Risk (feedback loop) ---
        $riskAccuracy = app(DelayRiskAccuracyService::class)->calculate();

        $recentItems = $scopeClient(ContentItem::with(['client', 'contentType', 'workflow']))
            ->latest('created_at')
            ->take(6)
            ->get()
            ->map(function ($item) {
                return [
                    'title' => $item->title,
                    'client' => $item->client->name ?? '-',
                    'type' => $item->contentType->name ?? '-',
                    'deadline' => $item->deadline_at,
                    'status' => $this->statusLabel($item->workflow->current_status ?? null),
                    'is_overdue' => (bool) ($item->workflow->is_overdue ?? false),
                ];
            });

        $insights = $this->generateInsights(
            contentThisMonth: $contentThisMonth,
            contentLastMonth: $contentLastMonth,
            overdueCount: $overdueCount,
            overdueRate: $overdueRate,
            newClientsThisMonth: $newClientsThisMonth,
            activeClients: $activeClients
        );

        return view('dashboard.index', compact(
            'stats', 'performance', 'viewsTrend', 'attentionItems', 'highRiskItems', 'recentItems', 'insights',
            'topContent', 'topClients', 'riskAccuracy', 'period'
        ));
    }

    private function percentChange(int $previous, int $current): array
    {
        if ($previous === 0) {
            return $current > 0
                ? ['label' => 'Baru mulai tercatat bulan ini', 'trend' => 'up']
                : ['label' => 'Belum ada data', 'trend' => 'flat'];
        }

        $percent = round((($current - $previous) / $previous) * 100, 1);

        if ($percent > 0) {
            return ['label' => "+{$percent}% dari bulan lalu", 'trend' => 'up'];
        }

        if ($percent < 0) {
            return ['label' => "{$percent}% dari bulan lalu", 'trend' => 'down'];
        }

        return ['label' => 'Sama seperti bulan lalu', 'trend' => 'flat'];
    }

    private function statusLabel(?string $status): string
    {
        if (! $status) {
            return '-';
        }

        return WorkflowTransitions::label($status);
    }

    private function generateInsights(
        int $contentThisMonth,
        int $contentLastMonth,
        int $overdueCount,
        float $overdueRate,
        int $newClientsThisMonth,
        int $activeClients
    ): array {
        $insights = [];

        if ($contentLastMonth > 0) {
            $diff = $contentThisMonth - $contentLastMonth;

            if ($diff > 0) {
                $insights[] = [
                    'title' => "Output konten naik {$diff} item bulan ini",
                    'description' => "Total {$contentThisMonth} konten dijadwalkan, dibanding {$contentLastMonth} bulan lalu.",
                ];
            } elseif ($diff < 0) {
                $insights[] = [
                    'title' => 'Output konten menurun dibanding bulan lalu',
                    'description' => "Turun dari {$contentLastMonth} menjadi {$contentThisMonth} konten. Perlu dicek apakah ada bottleneck di tim.",
                ];
            }
        }

        if ($overdueCount > 0) {
            $insights[] = [
                'title' => "{$overdueRate}% workflow berjalan berstatus overdue",
                'description' => "Ada {$overdueCount} konten yang melewati deadline. Cek panel 'Perlu Perhatian' di bawah untuk detail PIC-nya.",
            ];
        } else {
            $insights[] = [
                'title' => 'Tidak ada workflow overdue',
                'description' => 'Semua konten yang sedang berjalan masih on schedule.',
            ];
        }

        if ($newClientsThisMonth > 0) {
            $insights[] = [
                'title' => "{$newClientsThisMonth} klien baru onboard bulan ini",
                'description' => "Total klien aktif sekarang {$activeClients}. Pastikan tim produksi sudah dapat kapasitas.",
            ];
        }

        return $insights;
    }
}