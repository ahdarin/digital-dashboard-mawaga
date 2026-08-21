<?php

namespace App\Http\Controllers;

use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\DelayRiskScore;
use App\Services\AttendanceService;
use App\Services\DelayRiskAccuracyService;
use App\Services\TeamMemberContentResolver;
use App\Support\WorkflowTransitions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TeamPerformanceController extends Controller
{
    private array $doneStatuses = WorkflowTransitions::DONE_STATUSES;
    private int $overloadThreshold = 5;

    public function index(Request $request, AttendanceService $attendanceService, TeamMemberContentResolver $contentResolver)
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

        // TeamMember = primary entity (Langkah "Performa Tim - migrasi
        // domain User ke TeamMember"), BUKAN User lagi - roster 13 orang
        // real dari GUIDE, User cuma relasi opsional lewat team_members.user_id.
        // Content attribution (assignment User + external_pic_email) di-union
        // lewat TeamMemberContentResolver, SATU sumber kebenaran yang sama
        // dipakai ContentItemController::show() buat kandidat reassign.
        $teamMembers = TeamMember::with('user')->orderBy('name')->get();
        $contentItemsByMember = $contentResolver->resolveContentItems($teamMembers);

        // Kumpulkan seluruh content_item_id lintas TeamMember dulu, biar
        // revision count dan delay risk score bisa diambil lewat 2 query
        // agregat total (bukan per-anggota) - flat terhadap jumlah TeamMember.
        $allContentItemIds = $contentItemsByMember
            ->flatten(1)
            ->filter(fn ($item) => $item->workflow)
            ->pluck('id')
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

        $members = $teamMembers->map(function (TeamMember $tm) use ($contentItemsByMember, $revisionCountByItem, $riskScoreByItem) {
            $allItems = $contentItemsByMember->get($tm->id) ?? collect();
            $items = $allItems->filter(fn ($item) => $item->workflow);

            $activeCount = $items->filter(
                fn ($item) => ! in_array($item->workflow->current_status, $this->doneStatuses)
            )->count();

            $overdueCount = $items->filter(
                fn ($item) => $item->workflow->is_overdue
            )->count();

            $doneCount = $items->filter(
                fn ($item) => $item->workflow->current_status === 'uploaded'
            )->count();

            $revisionCount = $items
                ->pluck('id')
                ->sum(fn ($id) => $revisionCountByItem[$id] ?? 0);

            $activeContentItemIds = $items
                ->filter(fn ($item) => ! in_array($item->workflow->current_status, $this->doneStatuses))
                ->pluck('id');

            $activeRiskScores = $activeContentItemIds
                ->map(fn ($id) => $riskScoreByItem[$id] ?? null)
                ->filter(fn ($score) => $score !== null);

            $avgRiskScore = $activeRiskScores->isNotEmpty() ? $activeRiskScores->avg() : null;

            return [
                'team_member' => $tm,
                'content_count' => $allItems->count(),
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