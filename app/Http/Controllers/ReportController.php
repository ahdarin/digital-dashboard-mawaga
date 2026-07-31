<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\GeneratedReport;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ContentReportExport;
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
        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
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

        return redirect()->route('report.index')
            ->with('status', 'Laporan Excel berhasil dibuat, silakan unduh dari daftar di bawah.');
    }
}