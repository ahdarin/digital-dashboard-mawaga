<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\User;
use App\Support\WorkflowTransitions;
use Illuminate\Support\Collection;

/**
 * Ringkasan pekerjaan satu user - dipakai bareng oleh halaman Beranda
 * (ringkasan diri sendiri) dan halaman Profil (lihat ringkasan siapa pun),
 * biar logikanya nggak dobel dan nggak bisa kebablasan beda.
 */
class UserWorkSummaryService
{
    private array $doneStatuses = WorkflowTransitions::DONE_STATUSES;

    public function isCopywriter(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Copywriter]);
    }

    /**
     * Copywriter tidak jadi PIC produksi, jadi ringkasannya bukan papan
     * produksi biasa - melainkan antrean brief yang belum final. Dibatasi
     * ke client yang ada di roster $user (bukan global) - konsisten sama
     * scoping di Produksi/Content Plan dkk.
     */
    /**
     * $pinnedIds selalu punya pin milik user yang lagi login (bukan $user
     * yang lagi dilihat ringkasannya) - profil siapa pun bisa ditinjau,
     * tapi "fokus saya" tetap relatif ke penonton, bukan pemilik profil.
     */
    public function copywriterQueue(User $user, ?Collection $pinnedIds = null): Collection
    {
        $items = ContentItem::with(['client', 'contentType', 'contentBriefDraft', 'workflow'])
            ->whereDoesntHave('contentBriefDraft', fn ($q) => $q->where('status', 'finalized'))
            ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', $this->doneStatuses))
            ->when(
                ! $user->canSeeAllClients(),
                fn ($q) => $q->whereIn('client_id', $user->assignedClients()->pluck('clients.id'))
            )
            ->orderBy('deadline_at')
            ->get();

        if ($pinnedIds && $pinnedIds->isNotEmpty()) {
            $items = $items->sortBy(fn ($item) => $pinnedIds->contains($item->id) ? 0 : 1)->values();
        }

        return $items;
    }

    public function productionSummary(User $user, ?Collection $pinnedIds = null): array
    {
        $assignments = ContentItemAssignment::where('user_id', $user->id)
            ->with(['contentItem.client', 'contentItem.workflow', 'contentItem.contentType', 'contentItem.latestDelayRisk'])
            ->get()
            ->filter(fn ($a) => $a->contentItem && $a->contentItem->workflow);

        if ($pinnedIds && $pinnedIds->isNotEmpty()) {
            $assignments = $assignments->sortBy(
                fn ($a) => $pinnedIds->contains($a->content_item_id) ? 0 : 1
            )->values();
        }

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

        // Client yang ditangani DISPLAY dari TeamMember->clients() (team_member_client)
        // - bukan user_client_assignments (itu domain scoping akses dashboard,
        // sekarang kosong buat hampir semua akun real karena tanggung jawab
        // operasional sudah pindah ke TeamMember, lihat audit "Kelola Tim").
        // User tanpa TeamMember terkait -> tetap collection kosong, bukan error.
        $assignedClients = $user->teamMember?->clients()->where('status', 'active')->get() ?? collect();

        return compact(
            'assignments', 'activeCount', 'overdueCount',
            'completionRate', 'doneThisMonth', 'revisionCount', 'assignedClients'
        );
    }
}
