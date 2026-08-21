<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsSummaryService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * Versi read-only & disederhanakan dari AnalyticsController (internal)
     * untuk client - client SELALU dari portal context (token permanen),
     * bukan dari parameter/query, biar 1 client nggak pernah bisa lihat data
     * client lain. Query-nya sendiri direuse dari AnalyticsSummaryService
     * yang sama persis dipakai versi internal.
     */
    public function index(Request $request, AnalyticsSummaryService $analyticsSummaryService)
    {
        $period = (int) $request->input('period', 30); // 7 / 30 / 90 hari
        $period = in_array($period, [7, 30, 90]) ? $period : 30;

        $client = $request->attributes->get('portalClient');

        ['stats' => $stats, 'trend' => $trend, 'platformBreakdown' => $platformBreakdown, 'topContent' => $topContent]
            = $analyticsSummaryService->buildOverviewData($client->id, $period);

        return view('client.analytics.index', compact('client', 'stats', 'trend', 'platformBreakdown', 'topContent', 'period'));
    }
}
