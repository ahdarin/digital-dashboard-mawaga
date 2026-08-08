<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentWorkflow;
use App\Models\User;
use App\Support\WorkflowTransitions;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    private array $doneStatuses = ['uploaded', 'cancelled'];

    public function index()
    {
        $now = Carbon::now();
        $startOfThisMonth = $now->copy()->startOfMonth();
        $endOfThisMonth = $now->copy()->endOfMonth();
        $startOfLastMonth = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $contentThisMonth = ContentItem::whereBetween('deadline_at', [$startOfThisMonth, $endOfThisMonth])->count();
        $contentLastMonth = ContentItem::whereBetween('deadline_at', [$startOfLastMonth, $endOfLastMonth])->count();
        $contentChange = $this->percentChange($contentLastMonth, $contentThisMonth);

        $overdueCount = ContentWorkflow::where('is_overdue', true)->count();
        $totalWorkflow = ContentWorkflow::count();
        $overdueRate = $totalWorkflow > 0 ? round(($overdueCount / $totalWorkflow) * 100, 1) : 0;

        $activeClients = Client::where('status', 'active')->count();
        $newClientsThisMonth = Client::where('created_at', '>=', $startOfThisMonth)->count();

        $activeTeam = User::whereNull('client_id')->where('status', 'active')->count();

        // --- Tambahan: performa/reach (domain PIC 3, PRD 7.3.3 Executive Dashboard) ---
        $viewsThisMonth = (int) ContentMetric::whereBetween('metric_date', [$startOfThisMonth, $endOfThisMonth])->sum('views');
        $viewsLastMonth = (int) ContentMetric::whereBetween('metric_date', [$startOfLastMonth, $endOfLastMonth])->sum('views');
        $viewsChange = $this->percentChange($viewsLastMonth, $viewsThisMonth);

        $uploadedThisMonth = ContentWorkflow::where('current_status', 'uploaded')
            ->whereBetween('updated_at', [$startOfThisMonth, $endOfThisMonth])
            ->count();

        $stats = [
            [
                'label' => 'Content This Month',
                'value' => number_format($contentThisMonth),
                'change' => $contentChange['label'],
                'trend' => $contentChange['trend'],
                'icon' => 'draft',
            ],
            [
                'label' => 'Active Clients',
                'value' => number_format($activeClients),
                'change' => $newClientsThisMonth > 0
                    ? "+{$newClientsThisMonth} klien baru bulan ini"
                    : 'Tidak ada klien baru bulan ini',
                'trend' => $newClientsThisMonth > 0 ? 'up' : 'flat',
                'icon' => 'group',
            ],
            [
                'label' => 'Active Team',
                'value' => number_format($activeTeam),
                'change' => 'Anggota internal berstatus aktif',
                'trend' => 'flat',
                'icon' => 'badge',
            ],
            [
                'label' => 'Overdue Items',
                'value' => number_format($overdueCount),
                'change' => "{$overdueRate}% dari total workflow berjalan",
                'trend' => $overdueCount > 0 ? 'down' : 'up',
                'icon' => 'schedule',
            ],
            [
                'label' => 'Total Views This Month',
                'value' => number_format($viewsThisMonth),
                'change' => $viewsChange['label'],
                'trend' => $viewsChange['trend'],
                'icon' => 'visibility',
            ],
            [
                'label' => 'Uploaded Content',
                'value' => number_format($uploadedThisMonth),
                'change' => 'Bulan berjalan',
                'trend' => 'flat',
                'icon' => 'cloud_done',
            ],
        ];

        $performance = collect(range(6, 0))->map(function ($monthsAgo) {
            $month = Carbon::now()->subMonths($monthsAgo);

            $count = ContentItem::whereYear('deadline_at', $month->year)
                ->whereMonth('deadline_at', $month->month)
                ->count();

            return [
                'label' => $month->translatedFormat('M'),
                'value' => $count,
            ];
        })->toArray();

        // --- Tambahan: trend views 8 minggu terakhir (domain PIC 3) ---
        $viewsTrend = collect(range(7, 0))->map(function ($weeksAgo) {
            $weekStart = Carbon::now()->subWeeks($weeksAgo)->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();

            $sum = (int) ContentMetric::whereBetween('metric_date', [$weekStart, $weekEnd])->sum('views');

            return [
                'label' => $weekStart->translatedFormat('d M'),
                'value' => $sum,
            ];
        })->toArray();

        $attentionItems = ContentWorkflow::with(['contentItem.client', 'currentPic'])
            ->where('is_overdue', true)
            ->oldest('updated_at')
            ->take(4)
            ->get()
            ->map(function ($workflow) {
                return [
                    'title' => $workflow->contentItem->title ?? 'Tanpa judul',
                    'client' => $workflow->contentItem->client->name ?? '-',
                    'pic' => $workflow->currentPic->name ?? 'Belum ditugaskan',
                    'status' => $this->statusLabel($workflow->current_status),
                ];
            });

        // Panel prediktif (beda dari "Perlu Perhatian" di atas yang reaktif/is_overdue):
        // item aktif yang BELUM overdue tapi skor AI Delay Risk-nya lagi tinggi - biar
        // tim bisa cegah keterlambatan sebelum kejadian, bukan cuma tahu setelah telat.
        $highRiskItems = ContentItem::with(['client', 'workflow.currentPic', 'latestDelayRisk'])
            ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', $this->doneStatuses)->where('is_overdue', false))
            ->whereHas('latestDelayRisk', fn ($q) => $q->where('risk_level', 'high'))
            ->get()
            ->sortByDesc(fn ($item) => $item->latestDelayRisk->risk_score)
            ->take(4)
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'client' => $item->client->name ?? '-',
                    'pic' => $item->workflow->currentPic->name ?? 'Belum ditugaskan',
                    'risk_score' => $item->latestDelayRisk->risk_score,
                    'top_factor' => $item->latestDelayRisk->top_factor,
                ];
            });

        $recentItems = ContentItem::with(['client', 'contentType', 'workflow'])
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
            'stats', 'performance', 'viewsTrend', 'attentionItems', 'highRiskItems', 'recentItems', 'insights'
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