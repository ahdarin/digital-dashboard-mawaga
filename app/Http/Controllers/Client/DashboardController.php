<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Services\AnalyticsPeriodResolver;
use App\Services\AnalyticsSummaryService;
use App\Support\WorkflowTransitions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * Dashboard ringkasan client - cuma metrik milik client ini sendiri,
     * TIDAK ada perbandingan/ranking lintas client atau metrik operasional
     * internal (overdue rate, jumlah tim, skor risiko AI) - itu semua cuma
     * relevan buat tim internal, bukan buat client.
     *
     * Client SELALU diambil dari portal context (token permanen di URL),
     * TIDAK PERNAH dari $request->user() - Client Portal tidak pakai Auth
     * sama sekali, lihat ResolveClientPortal middleware.
     */
    public function index(Request $request, AnalyticsSummaryService $analyticsSummaryService, AnalyticsPeriodResolver $periodResolver)
    {
        $client = $request->attributes->get('portalClient')->loadMissing(['category', 'activePackage']);
        $clientId = $client->id;
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

        // PASS 2 - SATU-SATUNYA jalur resmi (AnalyticsPeriodResolver),
        // TETAP rolling 30 hari (widget ringkasan portal, bukan halaman
        // Analytics penuh - di luar scope month/custom pass ini).
        ['trend' => $trend] = $analyticsSummaryService->buildOverviewData($clientId, $periodResolver->buildLegacyDays(30));
        $totalViewsThisMonth = (int) collect($trend)->sum('value');

        $stats = [
            ['label' => 'Konten Bulan Ini', 'value' => number_format($contentThisMonth), 'icon' => 'draft', 'link' => route('client.portal.calendar', $client->portal_token)],
            ['label' => 'Menunggu Persetujuan Anda', 'value' => number_format($pendingApproval), 'icon' => 'pending_actions', 'link' => route('client.portal.dashboard', $client->portal_token) . '#persetujuan'],
            ['label' => 'Konten Tayang Bulan Ini', 'value' => number_format($publishedThisMonth), 'icon' => 'cloud_done', 'link' => route('client.portal.history', $client->portal_token)],
            ['label' => 'Total Views (30 Hari)', 'value' => number_format($totalViewsThisMonth), 'icon' => 'visibility', 'link' => route('client.portal.analytics', $client->portal_token)],
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

        // Approval Queue digabung jadi satu bagian di dalam Dashboard
        // (bukan tab/halaman terpisah).
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
