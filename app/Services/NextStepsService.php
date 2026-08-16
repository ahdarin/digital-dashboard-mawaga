<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentWorkflow;
use App\Models\User;

/**
 * Tahap 6.6 - panel "Langkah Berikutnya" di beranda/Tugas Saya: 1-3
 * tindakan paling relevan per role, supaya sistem "memberi tahu apa yang
 * harus dikerjakan" bukan cuma menampilkan data mentah.
 */
class NextStepsService
{
    public function forUser(User $user): array
    {
        $steps = [];

        if ($user->hasPermissionTo('content_plan', 'approve')) {
            $pendingPlans = ContentPlan::where('status', 'pending')
                ->when(
                    ! $user->canSeeAllClients(),
                    fn ($q) => $q->whereIn('client_id', $user->assignedClients()->pluck('clients.id'))
                )
                ->count();
            if ($pendingPlans > 0) {
                $steps[] = [
                    'icon' => 'fact_check',
                    'label' => "{$pendingPlans} rencana konten menunggu persetujuanmu",
                    'route' => route('content-plan.index'),
                ];
            }
        }

        if ($user->hasPermissionTo('workflow', 'approve')) {
            $clientApprovedCount = ContentWorkflow::where('current_status', 'waiting_review')
                ->whereNotNull('client_reviewed_at')
                ->when(
                    ! $user->canSeeAllClients(),
                    fn ($q) => $q->whereHas('contentItem', fn ($ci) => $ci->whereIn(
                        'client_id', $user->assignedClients()->pluck('clients.id')
                    ))
                )
                ->count();
            if ($clientApprovedCount > 0) {
                $steps[] = [
                    'icon' => 'check_circle',
                    'label' => "{$clientApprovedCount} konten sudah disetujui klien, menunggu pengecekanmu",
                    'route' => route('production-workflow.index'),
                ];
            }
        }

        if ($user->role?->name === 'Copywriter') {
            $unfinishedBriefs = ContentItem::whereDoesntHave('contentBriefDraft', fn ($q) => $q->where('status', 'finalized'))
                ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', ['uploaded', 'cancelled']))
                ->when(
                    ! $user->canSeeAllClients(),
                    fn ($q) => $q->whereIn('client_id', $user->assignedClients()->pluck('clients.id'))
                )
                ->count();
            if ($unfinishedBriefs > 0) {
                $steps[] = [
                    'icon' => 'edit_note',
                    'label' => "{$unfinishedBriefs} brief belum diterapkan ke tim produksi",
                    'route' => route('profile.me'),
                ];
            }
        }

        if ($user->hasPermissionTo('workflow', 'update')) {
            $overdueCount = ContentWorkflow::where('current_pic_id', $user->id)
                ->where('is_overdue', true)
                ->count();
            if ($overdueCount > 0) {
                $steps[] = [
                    'icon' => 'schedule',
                    'label' => "{$overdueCount} task kamu sudah lewat deadline",
                    'route' => route('profile.me'),
                ];
            }

            $unresolvedRevisions = ContentWorkflow::where('current_pic_id', $user->id)
                ->where('current_status', 'revision')
                ->count();
            if ($unresolvedRevisions > 0) {
                $steps[] = [
                    'icon' => 'rate_review',
                    'label' => "{$unresolvedRevisions} konten kamu perlu direvisi",
                    'route' => route('production-workflow.index'),
                ];
            }
        }

        return array_slice($steps, 0, 3);
    }
}
