<?php

namespace App\Services;

use App\Enums\UserRole;
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
                    'priority' => 80,
                ];
            }
        }

        if ($user->hasPermissionTo('workflow', 'approve')) {
            $clientApprovedCount = ContentWorkflow::whereHas('contentItem')
                ->where('current_status', 'waiting_review')
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
                    'priority' => 80,
                ];
            }
        }

        if ($user->hasAnyRole([UserRole::Copywriter])) {
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
                    'priority' => 50,
                ];
            }
        }

        if ($user->hasPermissionTo('workflow', 'update')) {
            $overdueCount = ContentWorkflow::whereHas('contentItem')
                ->where('current_pic_id', $user->id)
                ->where('is_overdue', true)
                ->count();
            if ($overdueCount > 0) {
                $steps[] = [
                    'icon' => 'schedule',
                    'label' => "{$overdueCount} task kamu sudah lewat deadline",
                    'route' => route('profile.me'),
                    'priority' => 100,
                ];
            }

            $unresolvedRevisions = ContentWorkflow::whereHas('contentItem')
                ->where('current_pic_id', $user->id)
                ->where('current_status', 'revision')
                ->count();
            if ($unresolvedRevisions > 0) {
                $steps[] = [
                    'icon' => 'rate_review',
                    'label' => "{$unresolvedRevisions} konten kamu perlu direvisi",
                    'route' => route('production-workflow.index'),
                    'priority' => 90,
                ];
            }
        }

        if (empty($steps)) {
            return [[
                'icon' => 'task_alt',
                'label' => 'Tidak ada langkah berikutnya saat ini',
                'route' => route('profile.me'),
                'priority' => 0,
            ]];
        }

        usort($steps, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_slice($steps, 0, 3);
    }
}
