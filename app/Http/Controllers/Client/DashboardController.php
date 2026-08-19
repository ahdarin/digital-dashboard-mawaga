<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Services\AnalyticsSummaryService;
use App\Support\WorkflowTransitions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * Dashboard ringkasan client - dulu halaman ini ALIAS ke
     * ApprovalController@index (dashboard = approval queue). Sekarang
     * dipisah jadi ringkasan sungguhan: cuma metrik milik client ini
     * sendiri, TIDAK ada perbandingan/ranking lintas client atau metrik
     * operasional internal (overdue rate, jumlah tim, skor risiko AI) -
     * itu semua cuma relevan buat tim internal, bukan buat client.
     */
    public function index(Request $request, AnalyticsSummaryService $analyticsSummaryService)
    {
        $clientId = $request->user()->client_id;
        $client = $request->user()->client()->with(['category', 'activePackage'])->first();
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        $contentThisMonth = ContentItem::where('client_id', $clientId)
            ->whereBetween('deadline_at', [$startOfMonth, $endOfMonth])
            ->count();

        $pendingApproval = ContentItem::where('client_id', $clientId)
            ->whereHas('workflow', fn ($q) => $q->where('current_status', 'waiting_review')->whereNull('client_reviewed_at'))
            ->count();

        $publishedThisMonth = ContentItem::where('client_id', $clientId)
            ->whereHas('workflow', fn ($q) => $q->where('current_status', 'uploaded'))
            ->whereBetween('updated_at', [$startOfMonth, $endOfMonth])
            ->count();

        ['trend' => $trend] = $analyticsSummaryService->buildOverviewData($clientId, 30);
        $totalViewsThisMonth = (int) collect($trend)->sum('value');

        $stats = [
            ['label' => 'Konten Bulan Ini', 'value' => number_format($contentThisMonth), 'icon' => 'draft', 'link' => route('client.calendar')],
            ['label' => 'Menunggu Persetujuan Anda', 'value' => number_format($pendingApproval), 'icon' => 'pending_actions', 'link' => route('client.dashboard') . '#persetujuan'],
            ['label' => 'Konten Tayang Bulan Ini', 'value' => number_format($publishedThisMonth), 'icon' => 'cloud_done', 'link' => route('client.history')],
            ['label' => 'Total Views (30 Hari)', 'value' => number_format($totalViewsThisMonth), 'icon' => 'visibility', 'link' => route('client.analytics')],
        ];

        $recentItems = ContentItem::where('client_id', $clientId)
            ->with(['contentType', 'workflow'])
            ->latest('created_at')
            ->take(6)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->contentType->name ?? '-',
                'deadline' => $item->deadline_at,
                'status' => WorkflowTransitions::label($item->workflow->current_status ?? ''),
            ]);

        // Approval Queue dulunya halaman tersendiri, sekarang digabung jadi
        // satu bagian di dalam Dashboard (bukan tab terpisah) - lihat
        // ApprovalController lama sebelum dipangkas.
        $approvalItems = ContentItem::with(['contentType', 'platform', 'workflow'])
            ->where('client_id', $clientId)
            ->whereHas('workflow', fn ($q) => $q->where('current_status', 'waiting_review'))
            ->orderBy('deadline_at')
            ->get();

        $pendingApprovalItems = $approvalItems->whereNull('workflow.client_reviewed_at')->values();
        $reviewedApprovalItems = $approvalItems->whereNotNull('workflow.client_reviewed_at')->values();

        return view('client.dashboard.index', compact(
            'client', 'stats', 'trend', 'recentItems', 'pendingApprovalItems', 'reviewedApprovalItems'
        ));
    }
}
