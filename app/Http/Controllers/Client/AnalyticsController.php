<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsPeriodResolver;
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
     *
     * PASS 2 - portal TETAP pakai UI/URL period=7/30/90 lama (di luar
     * scope month/custom pass ini, halaman terpisah & lebih sederhana),
     * TAPI sekarang resolve lewat AnalyticsPeriodResolver (SATU-SATUNYA
     * jalur resmi), bukan hitung int mentah lokal lagi.
     */
    public function index(Request $request, AnalyticsSummaryService $analyticsSummaryService, AnalyticsPeriodResolver $periodResolver)
    {
        $days = (int) $request->input('period', 30); // 7 / 30 / 90 hari
        $days = in_array($days, [7, 30, 90]) ? $days : 30;

        $client = $request->attributes->get('portalClient');

        ['stats' => $stats, 'trend' => $trend, 'platformBreakdown' => $platformBreakdown, 'topContent' => $topContent]
            = $analyticsSummaryService->buildOverviewData($client->id, $periodResolver->buildLegacyDays($days));

        // View ini pakai $period sebagai INT mentah (7/30/90) buat
        // highlight tombol pilihan - TIDAK diganti AnalyticsPeriod object
        // (itu murni detail internal buildOverviewData() di atas).
        $period = $days;

        return view('client.analytics.index', compact('client', 'stats', 'trend', 'platformBreakdown', 'topContent', 'period'));
    }
}
