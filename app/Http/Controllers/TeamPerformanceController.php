<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserMonthlyKpiResult;
use App\Services\AttendanceService;
use App\Services\DelayRiskAccuracyService;
use App\Services\TeamPerformanceKpiCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TeamPerformanceController extends Controller
{
    private const TREND_MONTHS = 6;

    public function index(Request $request, AttendanceService $attendanceService, TeamPerformanceKpiCalculator $calculator)
    {
        $tab = $request->input('tab', 'performa');

        if ($tab === 'kehadiran') {
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
            $monthlySummary = new \Illuminate\Pagination\LengthAwarePaginator(
                $monthlySummary->forPage($summaryPage, $summaryPerPage)->values(),
                $monthlySummary->count(),
                $summaryPerPage,
                $summaryPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return view('team-performance.index', [
                'tab' => $tab,
                'date' => $date,
                'month' => $month,
                'search' => $search,
                'totalWorkdays' => $totalWorkdays,
                'attendanceRecords' => $attendanceRecords,
                'monthlySummary' => $monthlySummary,
            ]);
        }

        $periodStart = $this->resolvePeriod($request->input('month'));
        $calculator->ensureCalculated($periodStart);

        // Admin bukan bagian dari tim produksi (view-only by design, tidak
        // pernah muncul di assignment/brief/status log) - dikecualikan dari
        // daftar supaya tidak menumpuk sebagai "Belum ada data" terus-menerus.
        $users = User::query()
            ->where('status', 'active')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', UserRole::Admin->value))
            ->with('roles')
            ->orderBy('name')
            ->get();

        $results = UserMonthlyKpiResult::where('period_start', $periodStart->toDateString())
            ->get()
            ->keyBy('user_id');

        $members = $users->map(fn (User $user) => [
            'user' => $user,
            'result' => $results->get($user->id),
        ]);

        // Perbandingan Nilai KPI antar anggota - diurutkan dari skor
        // tertinggi (beda dari Daftar Anggota di bawah yang tetap alfabetis)
        // supaya chart perbandingan enak dibaca. Anggota tanpa hasil bulan
        // ini dilewati (bukan digambar sebagai 0) - konsisten dengan
        // x-trend-chart yang membedakan "tidak ada data" dari nilai nol asli.
        $comparisonChart = $members
            ->filter(fn ($m) => $m['result'] !== null)
            ->sortByDesc(fn ($m) => $m['result']->final_score)
            ->map(fn ($m) => [
                'label' => $m['user']->name,
                'value' => round($m['result']->final_score),
            ])
            ->values();

        $teamTrend = $this->teamTrend($periodStart);

        $riskAccuracy = app(DelayRiskAccuracyService::class)->calculate();

        return view('team-performance.index', [
            'tab' => $tab,
            'periodStart' => $periodStart,
            'members' => $members,
            'comparisonChart' => $comparisonChart,
            'teamTrend' => $teamTrend,
            'riskAccuracy' => $riskAccuracy,
        ]);
    }

    private function resolvePeriod(?string $month): Carbon
    {
        return $month
            ? Carbon::parse($month.'-01')->startOfMonth()
            : Carbon::now()->startOfMonth();
    }

    /**
     * Tren rata-rata tim 6 bulan terakhir (termasuk bulan berjalan) untuk
     * tiga metrik - dipakai Ringkasan Tim sebagai line/bar chart, bukan
     * kartu besar. Bulan tanpa satupun hasil (belum dihitung/tidak ada
     * content) direpresentasikan sebagai null (gap), bukan 0.
     */
    private function teamTrend(Carbon $periodStart): array
    {
        $months = collect(range(self::TREND_MONTHS - 1, 0))
            ->map(fn ($i) => $periodStart->copy()->subMonths($i));

        $resultsByMonth = UserMonthlyKpiResult::whereIn(
            'period_start',
            $months->map(fn (Carbon $m) => $m->toDateString())
        )->get()->groupBy(fn (UserMonthlyKpiResult $r) => $r->period_start->toDateString());

        $kpi = [];
        $timeliness = [];
        $quality = [];

        foreach ($months as $month) {
            $label = $month->translatedFormat('M y');
            $rows = $resultsByMonth->get($month->toDateString(), collect());

            $kpi[] = ['label' => $label, 'value' => $rows->isNotEmpty() ? round($rows->avg('final_score')) : null];

            $timelinessRows = $rows->filter(fn ($r) => $r->timeliness_score !== null);
            $timeliness[] = ['label' => $label, 'value' => $timelinessRows->isNotEmpty() ? round($timelinessRows->avg('timeliness_score')) : null];

            $quality[] = ['label' => $label, 'value' => $rows->isNotEmpty() ? round($rows->avg('quality_score')) : null];
        }

        return ['kpi' => $kpi, 'timeliness' => $timeliness, 'quality' => $quality];
    }
}
