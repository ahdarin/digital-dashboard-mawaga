<?php

namespace App\Services;

use App\Models\ContentItemAssignment;
use App\Models\ContentWorkflow;
use App\Models\User;
use App\Support\WorkflowTransitions;
use Illuminate\Database\Eloquent\Collection;

/**
 * Satu sumber kebenaran buat "berapa banyak konten aktif yang PIC-nya orang
 * ini" dan "pindahkan semua ke orang lain" - dipakai bareng oleh
 * UserManagementController::destroy() (nonaktifkan user) dan
 * UserClientAssignmentController (keluarkan dari roster PIC klien), supaya
 * dua alur itu nggak diam-diam bikin content item nyangkut ke PIC yang
 * sudah tidak relevan (nonaktif, atau bukan tim client itu lagi).
 *
 * "Aktif" = current_status BUKAN salah satu DONE_STATUSES (uploaded/
 * cancelled) - status yang masih dianggap kerjaan berjalan.
 */
class PicReassignmentService
{
    public function activeWorkflows(User $user, ?int $clientId = null): Collection
    {
        return ContentWorkflow::where('current_pic_id', $user->id)
            ->whereNotIn('current_status', WorkflowTransitions::DONE_STATUSES)
            ->when($clientId, fn ($q) => $q->whereHas('contentItem', fn ($qq) => $qq->where('client_id', $clientId)))
            ->get();
    }

    public function countActive(User $user, ?int $clientId = null): int
    {
        return $this->activeWorkflows($user, $clientId)->count();
    }

    /**
     * Pindahkan semua workflow aktif $from ke $to - pola yang sama persis
     * dengan ContentItemController::reassign() (current_pic_id +
     * ContentItemAssignment 'primary'), cuma dijalankan buat banyak item
     * sekaligus.
     */
    public function transferAll(User $from, User $to, ?int $clientId = null): int
    {
        $workflows = $this->activeWorkflows($from, $clientId);

        foreach ($workflows as $workflow) {
            $workflow->update(['current_pic_id' => $to->id]);

            ContentItemAssignment::updateOrCreate(
                ['content_item_id' => $workflow->content_item_id, 'assignment_role' => 'primary'],
                ['user_id' => $to->id]
            );
        }

        return $workflows->count();
    }
}
