<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\GeneratedReport;
use App\Models\ContentMetric;
use App\Models\Platform;
use App\Rules\AssignedClient;
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

    private function buildPerformanceData(array $filters): array
    {
        $client = Client::find($filters['client_id']);

        $metrics = ContentMetric::with(['contentItem.client', 'contentItem.contentType', 'platform'])
            ->whereHas('contentItem', fn ($q) => $q->where('client_id', $filters['client_id']))
            ->whereBetween('metric_date', [
                Carbon::parse($filters['period_start'])->startOfDay(),
                Carbon::parse($filters['period_end'])->endOfDay(),
            ])
            ->get();

        $totalViews = (int) $metrics->sum('views');
        $avgEngagement = $metrics->count() > 0 ? round($metrics->avg('engagement_rate'), 2) : 0;

        $topContent = $metrics
            ->groupBy('content_item_id')
            ->map(function ($rows) {
                $item = $rows->first()->contentItem;
                return [
                    'title' => $item->title ?? '-',
                    'platform' => $rows->first()->platform->name ?? '-',
                    'type' => $item->contentType->name ?? '-',
                    'views' => (int) $rows->sum('views'),
                    'engagement_rate' => round($rows->avg('engagement_rate'), 2),
                ];
            })
            ->sortByDesc('views')
            ->take(10)
            ->values()
            ->all();

        $platformBreakdown = $metrics
            ->groupBy('platform_id')
            ->map(function ($rows, $platformId) {
                return [
                    'label' => Platform::find($platformId)->name ?? '-',
                    'value' => (int) $rows->sum('views'),
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'total_views' => $totalViews,
            'avg_engagement' => $avgEngagement,
            'content_count' => $metrics->pluck('content_item_id')->unique()->count(),
            'platform_count' => $metrics->pluck('platform_id')->unique()->count(),
            'top_content' => $topContent,
            'platform_breakdown' => $platformBreakdown,
            'client_name' => $client->name ?? '-',
            'period_start' => Carbon::parse($filters['period_start'])->format('d M Y'),
            'period_end' => Carbon::parse($filters['period_end'])->format('d M Y'),
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