<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\User;
use App\Services\NextStepsService;

class ProfileController extends Controller
{
    private array $doneStatuses = ['uploaded', 'cancelled'];

    public function show(User $user)
    {
        // Copywriter tidak jadi PIC produksi, jadi "Tugas Saya" mereka bukan
        // papan produksi biasa - melainkan antrean brief yang belum final,
        // karena itu pekerjaan mereka yang sesungguhnya.
        if ($user->role?->name === 'Copywriter') {
            $briefQueueItems = ContentItem::with(['client', 'contentType', 'contentBriefDraft', 'workflow'])
                ->whereDoesntHave('contentBriefDraft', fn ($q) => $q->where('status', 'finalized'))
                ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', $this->doneStatuses))
                ->orderBy('deadline_at')
                ->get();

            $nextSteps = auth()->id() === $user->id ? app(NextStepsService::class)->forUser($user) : [];

            return view('profile.show-copywriter', compact('user', 'briefQueueItems', 'nextSteps'));
        }

        $assignments = ContentItemAssignment::where('user_id', $user->id)
            ->with(['contentItem.client', 'contentItem.workflow', 'contentItem.contentType', 'contentItem.latestDelayRisk'])
            ->get()
            ->filter(fn ($a) => $a->contentItem && $a->contentItem->workflow);

        $activeCount = $assignments->filter(
            fn ($a) => !in_array($a->contentItem->workflow->current_status, $this->doneStatuses)
        )->count();

        $overdueCount = $assignments->filter(
            fn ($a) => $a->contentItem->workflow->is_overdue
        )->count();

        $thisMonthAssignments = $assignments->filter(
            fn ($a) => $a->contentItem->deadline_at->isCurrentMonth()
        );

        $doneThisMonth = $thisMonthAssignments->filter(
            fn ($a) => $a->contentItem->workflow->current_status === 'uploaded'
        )->count();

        $completionRate = $thisMonthAssignments->count() > 0
            ? round(($doneThisMonth / $thisMonthAssignments->count()) * 100)
            : 0;

        $revisionCount = ContentRevision::whereIn(
            'content_item_id',
            $assignments->pluck('content_item_id')
        )->count();

        $assignedClients = $user->assignedClients()->where('status', 'active')->get();

        $nextSteps = auth()->id() === $user->id ? app(NextStepsService::class)->forUser($user) : [];

        return view('profile.show', compact(
            'user', 'assignments', 'activeCount', 'overdueCount',
            'completionRate', 'doneThisMonth', 'revisionCount', 'assignedClients', 'nextSteps'
        ));
    }
}