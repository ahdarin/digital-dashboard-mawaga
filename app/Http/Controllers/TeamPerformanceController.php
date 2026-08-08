<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\User;
use App\Models\DelayRiskScore;
use App\Services\DelayRiskAccuracyService;
use Illuminate\Http\Request;

class TeamPerformanceController extends Controller
{
    private array $doneStatuses = ['uploaded', 'cancelled'];
    private int $overloadThreshold = 5;

    public function index(Request $request)
    {
        $selectedClientId = $request->input('client_id');

        $membersQuery = User::whereNull('client_id')
            ->where('status', 'active')
            ->with(['role', 'assignments.contentItem.workflow']);

        $members = $membersQuery->get()->map(function ($user) use ($selectedClientId) {
            $assignments = $user->assignments
                ->filter(fn($a) => $a->contentItem && $a->contentItem->workflow)
                ->when($selectedClientId, fn($items) => $items->filter(
                    fn($a) => $a->contentItem->client_id == $selectedClientId
                ));

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

        $overloadedMembers = $members->filter(fn($m) => $m['is_overloaded']);
        $overdueMembers = $members->filter(fn($m) => $m['overdue_count'] > 0);

        $clientOptions = Client::where('status', 'active')->get();

        $riskAccuracy = app(DelayRiskAccuracyService::class)->calculate();

        return view('team-performance.index', compact(
            'members',
            'summary',
            'overloadedMembers',
            'overdueMembers',
            'clientOptions',
            'selectedClientId',
            'riskAccuracy'
        ));
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