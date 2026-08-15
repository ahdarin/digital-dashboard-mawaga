<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ringkasan pekerjaan satu user - dipakai bareng oleh halaman Beranda
 * (ringkasan diri sendiri) dan halaman Profil (lihat ringkasan siapa pun),
 * biar logikanya nggak dobel dan nggak bisa kebablasan beda.
 */
class UserWorkSummaryService
{
    private array $doneStatuses = ['uploaded', 'cancelled'];

    public function isCopywriter(User $user): bool
    {
        return $user->role?->name === 'Copywriter';
    }

    /**
     * Copywriter tidak jadi PIC produksi, jadi ringkasannya bukan papan
     * produksi biasa - melainkan antrean brief yang belum final.
     */
    public function copywriterQueue(User $user): Collection
    {
        return ContentItem::with(['client', 'contentType', 'contentBriefDraft', 'workflow'])
            ->whereDoesntHave('contentBriefDraft', fn ($q) => $q->where('status', 'finalized'))
            ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', $this->doneStatuses))
            ->orderBy('deadline_at')
            ->get();
    }

    public function productionSummary(User $user): array
    {
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

        return compact(
            'assignments', 'activeCount', 'overdueCount',
            'completionRate', 'doneThisMonth', 'revisionCount', 'assignedClients'
        );
    }
}
