<?php

namespace App\Http\Controllers;

use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\User;
use App\Models\DelayRiskScore;
use App\Services\AttendanceService;
use App\Services\DelayRiskAccuracyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TeamPerformanceController extends Controller
{
    private array $doneStatuses = ['uploaded', 'cancelled'];
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

            return view('team-performance.index', [
                'tab' => $tab,
                'date' => $date,
                'month' => $month,
                'attendanceRecords' => $attendanceRecords,
                'monthlySummary' => $monthlySummary,
            ]);
        }

        $membersQuery = User::whereNull('client_id')
            ->where('status', 'active')
            ->with(['role', 'assignments.contentItem.workflow']);

        $members = $membersQuery->get()->map(function ($user) {
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

            $revisionCount = ContentRevision::whereIn(
                'content_item_id',
                $assignments->pluck('content_item_id')
            )->count();

            $activeContentItemIds = $assignments
                ->filter(fn($a) => !in_array($a->contentItem->workflow->current_status, $this->doneStatuses))
                ->pluck('content_item_id');

            $avgRiskScore = DelayRiskScore::whereIn('content_item_id', $activeContentItemIds)
                ->whereIn('id', function ($query) use ($activeContentItemIds) {
                    // ambil skor TERBARU per content item (bukan semua histori)
                    $query->selectRaw('MAX(id)')
                        ->from('delay_risk_scores')
                        ->whereIn('content_item_id', $activeContentItemIds)
                        ->groupBy('content_item_id');
                })
                ->avg('risk_score');

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