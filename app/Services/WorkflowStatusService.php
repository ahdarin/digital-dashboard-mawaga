<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\User;
use App\Support\WorkflowTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu buat memindahkan status ContentWorkflow - dipakai baik
 * dari tombol Status Management (content-items/show) maupun drag-and-drop
 * di kanban board (production-workflow), biar guard & efek samping otomatis
 * (auto-resolve revisi, dst) selalu konsisten di kedua jalur, bukan ditulis
 * dua kali dengan resiko beda perilaku.
 *
 * Catatan: nambah catatan revisi SAAT status sudah 'revision' (bukan
 * transisi waiting_review -> revision) BUKAN tanggung jawab service ini -
 * itu bukan perpindahan status (from === to selalu invalid di
 * WorkflowTransitions), jadi ditangani langsung oleh ContentRevisionController.
 */
class WorkflowStatusService
{
    public function transition(ContentItem $contentItem, string $toStatus, array $payload, User $actor): void
    {
        $workflow = $contentItem->workflow;
        $fromStatus = $workflow->current_status;

        if (! WorkflowTransitions::isValid($fromStatus, $toStatus)) {
            $fromLabel = WorkflowTransitions::label($fromStatus);
            $toLabel = WorkflowTransitions::label($toStatus);
            throw new WorkflowTransitionException("Tidak bisa memindahkan dari '{$fromLabel}' langsung ke '{$toLabel}'.");
        }

        if ($toStatus === 'approved' && $this->hasUnresolvedRevisions($contentItem)) {
            throw new WorkflowTransitionException('Masih ada revisi yang belum diselesaikan - selesaikan dulu sebelum approve.');
        }

        if ($toStatus === 'approved' && ! $actor->hasPermissionTo('workflow', 'approve')) {
            throw new WorkflowTransitionException('Anda tidak punya izin untuk menyetujui (approve) konten ini.');
        }

        if ($toStatus === 'revision' && trim($payload['revision_note'] ?? '') === '') {
            throw new WorkflowTransitionException('Catatan revisi wajib diisi untuk memindahkan konten ke status Revision.');
        }

        if ($toStatus === 'scheduled' && empty($payload['scheduled_upload_at'])) {
            throw new WorkflowTransitionException('Tanggal & jam rencana upload wajib diisi untuk menjadwalkan konten.');
        }

        if ($toStatus === 'uploaded' && (empty($payload['platform_id']) || empty($payload['published_at']))) {
            throw new WorkflowTransitionException('Platform dan tanggal publish wajib diisi untuk menandai konten sebagai uploaded.');
        }

        DB::transaction(function () use ($contentItem, $workflow, $fromStatus, $toStatus, $payload, $actor) {
            if ($toStatus === 'revision') {
                $lastRound = ContentRevision::where('content_item_id', $contentItem->id)->max('revision_round') ?? 0;
                ContentRevision::create([
                    'content_item_id' => $contentItem->id,
                    'requested_by' => $actor->id,
                    'revision_round' => $lastRound + 1,
                    'revision_note' => $payload['revision_note'],
                    'status' => 'open',
                ]);
            }

            // "Kerjakan Revisi" - semua revisi yang lagi open digarap bareng
            // sekaligus (statusnya 1 field di level content item, jadi nggak
            // bisa sebagian open sebagian in_progress).
            if ($toStatus === 'in_progress' && $fromStatus === 'revision') {
                ContentRevision::where('content_item_id', $contentItem->id)
                    ->where('status', 'open')
                    ->update(['status' => 'in_progress']);
            }

            // Balik ke waiting_review dari in_progress = revisi yang lagi
            // dikerjakan otomatis dianggap selesai digarap.
            if ($toStatus === 'waiting_review' && $fromStatus === 'in_progress') {
                ContentRevision::where('content_item_id', $contentItem->id)
                    ->where('status', 'in_progress')
                    ->update(['status' => 'resolved']);
            }

            if ($toStatus === 'scheduled') {
                $contentItem->update(['scheduled_upload_at' => $payload['scheduled_upload_at']]);
            }

            if ($toStatus === 'uploaded') {
                ContentPublication::create([
                    'content_item_id' => $contentItem->id,
                    'platform_id' => $payload['platform_id'],
                    'published_by' => $actor->id,
                    'published_at' => $payload['published_at'],
                    'post_url' => $payload['post_url'] ?? null,
                    'caption_final' => $payload['caption_final'] ?? null,
                ]);
                $contentItem->update(['is_posted' => true]);
            }

            $workflow->update(['current_status' => $toStatus]);

            ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by' => $actor->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $payload['notes'] ?? null,
                'changed_at' => now(),
            ]);
        });
    }

    public function hasUnresolvedRevisions(ContentItem $contentItem): bool
    {
        return ContentRevision::where('content_item_id', $contentItem->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->exists();
    }
}
