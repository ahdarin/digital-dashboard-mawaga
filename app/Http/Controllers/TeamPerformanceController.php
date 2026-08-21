<?php

namespace App\Http\Controllers;

use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\User;
use App\Models\DelayRiskScore;
use App\Services\AttendanceService;
use App\Services\DelayRiskAccuracyService;
use App\Support\WorkflowTransitions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TeamPerformanceController extends Controller
{
    private array $doneStatuses = WorkflowTransitions::DONE_STATUSES;
    private int $overloadThreshold = 5;

    public function index(Request $request, AttendanceService $attendanceService)
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

        $membersQuery = User::query()
            ->where('status', 'active')
            ->with(['roles', 'assignments.contentItem.workflow']);

        $allUsers = $membersQuery->get();

        // Kumpulkan seluruh content_item_id lintas user dulu, biar revision
        // count dan delay risk score bisa diambil lewat 2 query agregat
        // total (bukan 2 query per user - dulu O(n) query terhadap jumlah
        // staf, sekarang tetap O(1) berapa pun jumlah anggota tim).
        $allContentItemIds = $allUsers
            ->flatMap(fn ($user) => $user->assignments
                ->filter(fn ($a) => $a->contentItem && $a->contentItem->workflow)
                ->pluck('content_item_id'))
            ->unique()
            ->values();

        $revisionCountByItem = ContentRevision::whereIn('content_item_id', $allContentItemIds)
            ->selectRaw('content_item_id, count(*) as cnt')
            ->groupBy('content_item_id')
            ->pluck('cnt', 'content_item_id');

        $riskScoreByItem = DelayRiskScore::whereIn('content_item_id', $allContentItemIds)
            ->whereIn('id', function ($query) use ($allContentItemIds) {
                // ambil skor TERBARU per content item (bukan semua histori)
                $query->selectRaw('MAX(id)')
                    ->from('delay_risk_scores')
                    ->whereIn('content_item_id', $allContentItemIds)
                    ->groupBy('content_item_id');
            })
            ->pluck('risk_score', 'content_item_id');

        $members = $allUsers->map(function ($user) use ($revisionCountByItem, $riskScoreByItem) {
            $assignments = $user->assignments
                ->filter(fn($a) => $a->contentItem && $a->contentItem->workflow);

            $activeCount = $assignments->filter(
                fn($a) => !in_array($a->contentItem->workflow->current_status, $this->doneStatuses)
            )->count();

            $overdueCount = $assignments->filter(
                fn($a) => $a->contentItem->workflow->is_overdue
            )->count();

            $doneCount = $assignments->filter(
                fn($a) => $a->contentItem->workflow->current_status === 'uploaded'
            )->count();

            $revisionCount = $assignments
                ->pluck('content_item_id')
                ->sum(fn ($id) => $revisionCountByItem[$id] ?? 0);

            $activeContentItemIds = $assignments
                ->filter(fn($a) => !in_array($a->contentItem->workflow->current_status, $this->doneStatuses))
                ->pluck('content_item_id');

            $activeRiskScores = $activeContentItemIds
                ->map(fn ($id) => $riskScoreByItem[$id] ?? null)
                ->filter(fn ($score) => $score !== null);

            $avgRiskScore = $activeRiskScores->isNotEmpty() ? $activeRiskScores->avg() : null;

            return [
                'user' => $user,
                'active_count' => $activeCount,
                'overdue_count' => $overdueCount,
                'done_count' => $doneCount,
                'revision_count' => $revisionCount,
                'is_overloaded' => $activeCount > $this->overloadThreshold,
                'avg_risk_score' => $avgRiskScore ? round($avgRiskScore) : null,
            ];
        });

        // Ringkasan atas
        $summary = [
            'personnel_active' => $members->count(),
            'total_active_items' => $members->sum('active_count'),
            'avg_revision' => $members->count() > 0
                ? round($members->sum('revision_count') / $members->count(), 1)
                : 0,
        ];

        $riskAccuracy = app(DelayRiskAccuracyService::class)->calculate();

        return view('team-performance.index', [
            'tab' => $tab,
            'members' => $members,
            'summary' => $summary,
            'riskAccuracy' => $riskAccuracy,
        ]);
    }

    public function show(User $user)
    {
        $assignments = ContentItemAssignment::where('user_id', $user->id)
            ->with(['contentItem.client', 'contentItem.workflow', 'contentItem.contentType'])
            ->get()
            ->filter(fn($a) => $a->contentItem && $a->contentItem->workflow);

        return view('team-performance.show', compact('user', 'assignments'));
    }
}