<?php

namespace App\Http\Controllers;

use App\Kpi\Services\KpiRecalculationTrigger;
use App\Kpi\Services\TeamPerformanceDashboardService;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DelayRiskAccuracyService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Controller TIPIS: seluruh query/formula KPI hidup di App\Kpi\Services\*
 * (TeamPerformanceDashboardService cs). Tidak ada leaderboard/ranking lintas
 * role di sini (dilarang spesifikasi) - tiap baris `UserKpiResult` berdiri
 * sendiri per (user, role).
 *
 * Koreksi produk 2026-09-02 (Fase 4): TIDAK PERNAH mensyaratkan
 * user/administrator menjalankan kalkulasi manual. Kalau hasil untuk
 * periode ini belum ada/stale, kalkulasi di-dispatch OTOMATIS di latar
 * belakang (debounced, lihat KpiRecalculationTrigger) - halaman tetap
 * menampilkan snapshot run TERAKHIR yang ada (periode apa pun) sambil
 * pembaruan berjalan, atau "Data KPI sedang disiapkan otomatis" kalau
 * belum pernah ada sama sekali. TIDAK ADA instruksi command developer yang
 * ditampilkan ke pengguna.
 */
class TeamPerformanceController extends Controller
{
    public function index(
        Request $request,
        AttendanceService $attendanceService,
        TeamPerformanceDashboardService $dashboard,
    ) {
        $tab = $request->input('tab', 'ringkasan');

        if ($tab === 'kehadiran') {
            return $this->kehadiranTab($request, $attendanceService);
        }

        [$periodStart, $periodEnd] = $this->resolvePeriod($request);
        [$run, $isCalculating, $usingFallbackPeriod] = $this->resolveRunWithAutoDispatch($dashboard, $periodStart, $periodEnd);

        $filters = $request->only(['client_id', 'role_id', 'coverage_status']);
        $memberRows = $run ? $dashboard->memberRows($run, $filters) : collect();

        $filterOptions = [
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'roles' => $run ? $dashboard->rolesWithResults($run) : Role::orderBy('name')->get(),
        ];

        $shared = [
            'run' => $run,
            'isCalculating' => $isCalculating,
            'usingFallbackPeriod' => $usingFallbackPeriod,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'memberRows' => $memberRows,
            'filters' => $filters,
            'filterOptions' => $filterOptions,
        ];

        if ($tab === 'anggota') {
            return view('team-performance.index', ['tab' => $tab, ...$shared]);
        }

        $summary = $run ? $dashboard->teamSummary($run, $memberRows) : null;
        $riskAccuracy = app(DelayRiskAccuracyService::class)->calculate();

        return view('team-performance.index', [
            'tab' => 'ringkasan',
            'summary' => $summary,
            'riskAccuracy' => $riskAccuracy,
            ...$shared,
        ]);
    }

    /**
     * Detail KPI satu anggota - mendukung satu user beberapa role & beberapa
     * klien sekaligus (tidak pernah satu overall score lintas role).
     */
    public function show(Request $request, User $user, TeamPerformanceDashboardService $dashboard)
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod($request);
        [$run, $isCalculating, $usingFallbackPeriod] = $this->resolveRunWithAutoDispatch($dashboard, $periodStart, $periodEnd);

        $results = $run ? $dashboard->resultsForUser($run, $user) : collect();

        $selectedRoleId = (int) $request->input('role_id', $results->first()?->role_id);
        $selectedClientId = $request->input('client_id') ? (int) $request->input('client_id') : $results->first()?->client_id;
        $selectedResult = $results->first(fn ($r) => $r->role_id === $selectedRoleId && $r->client_id === $selectedClientId);
        $contentOutcomes = $selectedResult ? $dashboard->contentOutcomesForResult($selectedResult) : collect();

        return view('team-performance.show', [
            'member' => $user,
            'run' => $run,
            'isCalculating' => $isCalculating,
            'usingFallbackPeriod' => $usingFallbackPeriod,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'results' => $results,
            'selectedRoleId' => $selectedRoleId,
            'contentOutcomes' => $contentOutcomes,
        ]);
    }

    /**
     * Resolve run untuk ditampilkan + auto-dispatch kalkulasi kalau
     * stale/belum ada - TIDAK PERNAH meminta user melakukan apa pun.
     *
     * Koreksi lanjutan 2026-09-02 (#3): dispatch memakai PERIODE YANG
     * DIPILIH pengguna ($periodStart/$periodEnd), BUKAN selalu bulan
     * berjalan - membuka periode historis (mis. Juni lewat filter bulan)
     * harus menjadwalkan kalkulasi ULANG Juni, bukan diam-diam menghitung
     * bulan sekarang.
     *
     * @return array{0: ?\App\Models\KpiCalculationRun, 1: bool, 2: bool}
     */
    private function resolveRunWithAutoDispatch(TeamPerformanceDashboardService $dashboard, Carbon $periodStart, Carbon $periodEnd): array
    {
        $run = $dashboard->latestCompletedRun($periodStart, $periodEnd);
        $isStale = $dashboard->isStale($run);

        if ($isStale) {
            KpiRecalculationTrigger::schedule($periodStart, $periodEnd);
        }

        $usingFallbackPeriod = false;

        if ($run === null) {
            $run = $dashboard->latestCompletedRunAnyPeriod();
            $usingFallbackPeriod = $run !== null;
        }

        return [$run, $isStale, $usingFallbackPeriod];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(Request $request): array
    {
        $periodStart = $request->filled('period_start')
            ? Carbon::parse($request->input('period_start'))->startOfMonth()
            : Carbon::now('Asia/Jakarta')->startOfMonth();

        $periodEnd = $periodStart->copy()->endOfMonth();

        return [$periodStart, $periodEnd];
    }

    private function kehadiranTab(Request $request, AttendanceService $attendanceService)
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::now();
        $month = $request->filled('month')
            ? Carbon::parse($request->input('month').'-01')
            : Carbon::now()->startOfMonth();

        $attendanceRecords = $attendanceService->dailyRecords($date);
        $monthlySummary = $attendanceService->monthlySummary($month);

        // "Hari Kerja" nilainya sama buat semua orang (cuma tergantung
        // bulan, bukan per-user) - ambil sekali dari baris pertama
        // sebelum di-filter/paginate, biar tidak perlu jadi kolom
        // berulang di tabel.
        $totalWorkdays = $monthlySummary->first()['total_workdays'] ?? 0;

        $search = $request->input('search');
        if ($search) {
            $monthlySummary = $monthlySummary
                ->filter(fn ($s) => str_contains(strtolower($s['user']->name), strtolower($search)))
                ->values();
        }

        // monthlySummary dihitung manual per user (bukan query builder - lihat
        // AttendanceService::monthlySummary), jadi paginate juga manual pakai
        // LengthAwarePaginator - sama pola dengan list Production Workflow.
        $summaryPage = (int) $request->input('page', 1);
        $summaryPerPage = 10;
        $monthlySummary = new LengthAwarePaginator(
            $monthlySummary->forPage($summaryPage, $summaryPerPage)->values(),
            $monthlySummary->count(),
            $summaryPerPage,
            $summaryPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('team-performance.index', [
            'tab' => 'kehadiran',
            'date' => $date,
            'month' => $month,
            'search' => $search,
            'totalWorkdays' => $totalWorkdays,
            'attendanceRecords' => $attendanceRecords,
            'monthlySummary' => $monthlySummary,
        ]);
    }
}
