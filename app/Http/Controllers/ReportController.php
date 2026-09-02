<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\GeneratedReport;
use App\Models\ContentMetric;
use App\Models\Platform;
use App\Rules\AssignedClient;
use App\Services\PeriodPerformanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ContentReportExport;
use App\Exports\PerformanceReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $clientOptions = $user->canSeeAllClients()
            ? Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        $reports = GeneratedReport::with('client')
            ->where('generated_by', $user->id)
            ->latest()
            ->get();

        return view('report.index', compact('clientOptions', 'reports'));
    }

    public function generate(Request $request)
    {
        // client_id boleh kosong HANYA buat CEO/Manager (ekspor lintas
        // semua client yang mereka pegang) - role lain wajib pilih salah
        // satu client, dan harus client yang memang ada di roster-nya.
        $user = $request->user();

        $validated = $request->validate([
            'client_id' => [
                Rule::requiredIf(! $user->canSeeAllClients()),
                'nullable',
                'exists:clients,id',
                new AssignedClient,
            ],
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'format' => 'required|in:pdf,excel',
        ]);

        $reportData = $this->buildReportData($validated);

        if ($validated['format'] === 'pdf') {
            return $this->generatePdf($reportData, $validated, $request->user());
        }

        return $this->generateExcel($reportData, $validated, $request->user());
    }

    private function buildReportData(array $filters): array
    {
        $query = ContentItem::with(['client', 'workflow', 'revisions'])
            ->whereBetween('deadline_at', [
                Carbon::parse($filters['period_start'])->startOfDay(),
                Carbon::parse($filters['period_end'])->endOfDay(),
            ]);

        if (!empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        $items = $query->get();

        return [
            'items' => $items,
            'total' => $items->count(),
            'done' => $items->filter(fn ($i) => $i->workflow?->current_status === 'uploaded')->count(),
            'overdue' => $items->filter(fn ($i) => $i->workflow?->is_overdue)->count(),
            'in_revision' => $items->filter(fn ($i) => $i->workflow?->current_status === 'revision')->count(),
            'total_revisions' => $items->sum(fn ($i) => $i->revisions->count()),
            'client_name' => !empty($filters['client_id'])
                ? Client::find($filters['client_id'])?->name
                : 'Semua Client',
            'period_start' => Carbon::parse($filters['period_start'])->format('d M Y'),
            'period_end' => Carbon::parse($filters['period_end'])->format('d M Y'),
        ];
    }

    private function generatePdf(array $data, array $filters, $user)
    {
        $pdf = Pdf::loadView('report.pdf', $data);
        $filename = 'laporan-progres-' . now()->format('Ymd-His') . '.pdf';
        $path = 'reports/' . $filename;

        \Storage::disk('public')->put($path, $pdf->output());

        GeneratedReport::create([
            'client_id' => $filters['client_id'] ?? null,
            'generated_by' => $user->id,
            'report_type' => 'monthly_summary',
            'period_start' => $filters['period_start'],
            'period_end' => $filters['period_end'],
            'file_path' => $path,
        ]);

        return $pdf->download($filename);
    }

    private function generateExcel(array $data, array $filters, $user)
    {
        $filename = 'laporan-progres-' . now()->format('Ymd-His') . '.xlsx';
        $path = 'reports/' . $filename;

        Excel::store(new ContentReportExport($data), $path, 'public');

        GeneratedReport::create([
            'client_id' => $filters['client_id'] ?? null,
            'generated_by' => $user->id,
            'report_type' => 'monthly_summary',
            'period_start' => $filters['period_start'],
            'period_end' => $filters['period_end'],
            'file_path' => $path,
        ]);

        return \Storage::disk('public')->download($path, $filename);
    }

    // ================================================================
    // TAMBAHAN DOMAIN PIC 3 - Performance Report
    // (views, engagement, top content, breakdown platform)
    // Method di atas (index, generate, dst) murni punya PIC 2, tidak
    // diubah sama sekali.
    // ================================================================

    /**
     * KF3xx — Performance Report (domain PIC 3)
     * Laporan performa konten (views, engagement, top content, breakdown
     * platform) - beda dari generate() di atas (itu laporan progres
     * produksi: selesai/overdue/revisi, punya PIC 2).
     *
     * Sengaja pakai model & infra yang SAMA (GeneratedReport, dompdf,
     * maatwebsite/excel) - jadi nggak perlu migration/package baru sama
     * sekali, cukup nambah report_type baru: 'performance_summary'.
     */
    public function generatePerformance(Request $request)
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id', new AssignedClient],
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'format' => 'required|in:pdf,excel',
        ]);

        $reportData = $this->buildPerformanceData($validated);

        if ($validated['format'] === 'pdf') {
            return $this->generatePerformancePdf($reportData, $validated, $request->user());
        }

        return $this->generatePerformanceExcel($reportData, $validated, $request->user());
    }

    /**
     * Phase 3 (Langkah 9F): views/engagement_rate SEKARANG delta periode
     * genuine (PeriodPerformanceService), BUKAN lagi sum(views) whereBetween
     * (metric_date) - metric_date API dikunci ke tanggal publish. Roster
     * TETAP whereHas('contentItem', ...) (cuma content yang SUDAH ke-link -
     * preserved dari behavior lama Report, bukan scope Phase 3).
     */
    private function buildPerformanceData(array $filters): array
    {
        $client = Client::find($filters['client_id']);

        $periodStart = Carbon::parse($filters['period_start'])->startOfDay();
        $periodEnd = Carbon::parse($filters['period_end'])->endOfDay();

        $apiMetrics = ContentMetric::with(['contentItem.client', 'contentItem.contentType', 'platform', 'instagramMediaSnapshot', 'tiktokVideoSnapshot'])
            ->whereHas('contentItem', fn ($q) => $q->where('client_id', $filters['client_id']))
            ->where(fn ($q) => $q->whereNotNull('instagram_media_snapshot_id')->orWhereNotNull('tiktok_video_snapshot_id'))
            ->get();

        $csvMetrics = ContentMetric::with(['contentItem.client', 'contentItem.contentType', 'platform'])
            ->whereHas('contentItem', fn ($q) => $q->where('client_id', $filters['client_id']))
            ->whereNull('instagram_media_snapshot_id')
            ->whereNull('tiktok_video_snapshot_id')
            ->whereBetween('metric_date', [$periodStart, $periodEnd])
            ->get();

        $periodPerformanceService = app(PeriodPerformanceService::class);
        $aggregate = $periodPerformanceService->computeAggregate($apiMetrics, $csvMetrics, $periodStart, $periodEnd);
        $usableRows = collect($aggregate['rows'])->filter(fn ($row) => $row['result']->isUsable());

        $topContent = $usableRows
            ->map(function ($row) {
                $item = $row['content_metric']->contentItem;
                if (! $item) {
                    return null;
                }

                return [
                    'title' => $item->title ?? '-',
                    'platform' => $row['content_metric']->platform->name ?? '-',
                    'type' => $item->contentType->name ?? '-',
                    'views' => $row['result']->views() ?? 0,
                    'engagement_rate' => $row['result']->engagementRate ?? 0,
                ];
            })
            ->filter()
            ->sortByDesc('views')
            ->take(10)
            ->values()
            ->all();

        $periodDays = $periodStart->diffInDays($periodEnd) + 1;

        return [
            'total_views' => $aggregate['totals']['views'],
            'avg_engagement' => $aggregate['totals']['engagement_rate'] ?? 0,
            'content_count' => $aggregate['totals']['content_count'],
            'platform_count' => $aggregate['totals']['platforms_tracked'],
            'top_content' => $topContent,
            'platform_breakdown' => $aggregate['platform_breakdown'],
            'client_name' => $client->name ?? '-',
            'period_start' => $periodStart->format('d M Y'),
            'period_end' => $periodEnd->format('d M Y'),
            'coverage_status' => $aggregate['coverage']['status'],
            'coverage_message' => $periodPerformanceService->coverageMessage($aggregate['coverage'], (int) $periodDays),
        ];
    }

    private function generatePerformancePdf(array $data, array $filters, $user)
    {
        $pdf = Pdf::loadView('report.performance-pdf', $data);
        $filename = 'laporan-performa-'.now()->format('Ymd-His').'.pdf';
        $path = 'reports/'.$filename;

        \Storage::disk('public')->put($path, $pdf->output());

        GeneratedReport::create([
            'client_id' => $filters['client_id'],
            'generated_by' => $user->id,
            'report_type' => 'performance_summary',
            'period_start' => $filters['period_start'],
            'period_end' => $filters['period_end'],
            'file_path' => $path,
        ]);

        return $pdf->download($filename);
    }

    private function generatePerformanceExcel(array $data, array $filters, $user)
    {
        $filename = 'laporan-performa-'.now()->format('Ymd-His').'.xlsx';
        $path = 'reports/'.$filename;

        Excel::store(new PerformanceReportExport($data), $path, 'public');

        GeneratedReport::create([
            'client_id' => $filters['client_id'],
            'generated_by' => $user->id,
            'report_type' => 'performance_summary',
            'period_start' => $filters['period_start'],
            'period_end' => $filters['period_end'],
            'file_path' => $path,
        ]);

        return \Storage::disk('public')->download($path, $filename);
    }
}