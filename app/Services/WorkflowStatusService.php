<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Models\ContentPlan;
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

        // Draf cuma bisa keluar lewat aksi batch SMO (releaseToProduction),
        // bukan tombol Status Management/drag kanban per item - walau
        // draft->brief_ready valid di WorkflowTransitions (dipakai batch itu
        // sendiri & koreksi status Manager/CEO).
        if ($fromStatus === WorkflowTransitions::DRAFT_STATUS) {
            throw new WorkflowTransitionException('Konten ini masih berstatus Draf - belum masuk workflow produksi. Kirim ke produksi lewat halaman Content Plan (SMO) dulu.');
        }

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

        // Multi-platform (Info Dasar sekarang bisa pilih >1 platform): payload
        // bisa berupa array publications[] (satu entri per platform), atau
        // bentuk lama satu platform_id/published_at scalar - item lama/
        // quickCreateUrgent yang belum pakai pivot platform tetap jalan lewat
        // fallback ini.
        $publications = $payload['publications'] ?? (empty($payload['platform_id']) ? [] : [[
            'platform_id' => $payload['platform_id'],
            'published_at' => $payload['published_at'] ?? null,
            'post_url' => $payload['post_url'] ?? null,
            'caption_final' => $payload['caption_final'] ?? null,
        ]]);

        if ($toStatus === 'uploaded' && (
            empty($publications)
            || collect($publications)->contains(fn ($p) => empty($p['platform_id']) || empty($p['published_at']))
        )) {
            throw new WorkflowTransitionException('Platform dan tanggal publish wajib diisi untuk menandai konten sebagai uploaded.');
        }

        // Unfurl thumbnail dari post_url SEBELUM transaction dibuka - ini
        // panggilan HTTP keluar (bisa lambat/timeout), jangan sampai
        // menahan koneksi/lock database selama itu berlangsung.
        if ($toStatus === 'uploaded') {
            $thumbnailService = app(LinkThumbnailService::class);
            foreach ($publications as $i => $pub) {
                $publications[$i]['thumbnail_url'] = ! empty($pub['post_url']) ? $thumbnailService->fetch($pub['post_url']) : null;
            }
        }

        DB::transaction(function () use ($contentItem, $workflow, $fromStatus, $toStatus, $payload, $publications, $actor) {
            if ($toStatus === 'revision') {
                $lastRound = ContentRevision::where('content_item_id', $contentItem->id)->max('revision_round') ?? 0;
                ContentRevision::create([
                    'content_item_id' => $contentItem->id,
                    'requested_by_user_id' => $actor->id,
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

            if ($toStatus === 'waiting_review' && ! empty($payload['content_file_link'])) {
                $contentItem->update(['content_file_link' => $payload['content_file_link']]);
            }

            if ($toStatus === 'scheduled') {
                $contentItem->update(['scheduled_upload_at' => $payload['scheduled_upload_at']]);
            }

            if ($toStatus === 'uploaded') {
                foreach ($publications as $pub) {
                    ContentPublication::create([
                        'content_item_id' => $contentItem->id,
                        'platform_id' => $pub['platform_id'],
                        'published_by' => $actor->id,
                        'published_at' => $pub['published_at'],
                        'post_url' => $pub['post_url'] ?? null,
                        'thumbnail_url' => $pub['thumbnail_url'] ?? null,
                        'caption_final' => $pub['caption_final'] ?? null,
                    ]);
                }
                $contentItem->update(['is_posted' => true]);

                // Konten sudah tayang - lepas dari daftar pin semua orang
                // biar daftar pin nggak numpuk konten yang udah kelar.
                app(PinService::class)->unpinForAllUsers($contentItem);
            }

            $workflow->update(['current_status' => $toStatus]);

            ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by_user_id' => $actor->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $payload['notes'] ?? null,
                'changed_at' => now(),
            ]);
        });
    }

    /**
     * "Koreksi Status" - khusus Manager & CEO, buat kasus status terlanjur
     * salah dipindahkan. Beda dari transition() biasa: TIDAK terikat aturan
     * WorkflowTransitions (bisa lompat/mundur ke status manapun), dan
     * dicatat dengan approval_type='correction' supaya jelas ini bukan
     * revisi biasa - jadi Team Performance bisa mengecualikannya dari
     * hitungan revisi tim.
     */
    public function correctStatus(ContentItem $contentItem, string $toStatus, string $reason, User $actor): void
    {
        if (! $actor->hasAnyRole([UserRole::Manager, UserRole::CEO])) {
            throw new WorkflowTransitionException('Cuma Manager atau CEO yang bisa melakukan koreksi status.');
        }

        if (! array_key_exists($toStatus, WorkflowTransitions::labels())) {
            throw new WorkflowTransitionException('Status tujuan tidak dikenali.');
        }

        $workflow = $contentItem->workflow;
        $fromStatus = $workflow->current_status;

        if ($fromStatus === $toStatus) {
            throw new WorkflowTransitionException('Status tujuan sama dengan status saat ini.');
        }

        DB::transaction(function () use ($contentItem, $workflow, $fromStatus, $toStatus, $reason, $actor) {
            $workflow->update(['current_status' => $toStatus]);

            ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by_user_id' => $actor->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'approval_type' => 'correction',
                'notes' => $reason,
                'changed_at' => now(),
            ]);
        });
    }

    /**
     * Aksi batch SMO "Kirim ke Produksi" - satu-satunya jalan keluar dari
     * status Draf. Semua item plan ini pindah draft->brief_ready bersamaan,
     * dan briefnya langsung dikunci (finalize) di transaction yang sama -
     * begitu masuk produksi, brief tidak bisa diedit lagi sama sekali
     * (sesuai gate isLocked() yang sudah dipakai semua endpoint brief).
     */
    public function releaseToProduction(ContentPlan $contentPlan, User $actor): int
    {
        $items = $contentPlan->contentItems()->with(['workflow', 'contentBriefDraft'])->get();

        $draftItems = $items->filter(fn ($item) => $item->workflow?->current_status === WorkflowTransitions::DRAFT_STATUS);

        if ($draftItems->isEmpty()) {
            throw new WorkflowTransitionException('Tidak ada item berstatus Draf di rencana ini untuk dikirim ke produksi.');
        }

        DB::transaction(function () use ($draftItems, $actor) {
            foreach ($draftItems as $item) {
                $item->workflow->update(['current_status' => 'brief_ready']);

                ContentStatusLog::create([
                    'content_item_id' => $item->id,
                    'changed_by_user_id' => $actor->id,
                    'from_status' => WorkflowTransitions::DRAFT_STATUS,
                    'to_status' => 'brief_ready',
                    'notes' => null,
                    'changed_at' => now(),
                ]);

                if ($item->contentBriefDraft && ! $item->contentBriefDraft->isLocked()) {
                    $item->contentBriefDraft->update(['status' => 'finalized', 'finalized_at' => now()]);

                    \App\Services\NotificationService::notifyAssignedUsers(
                        $item,
                        'Brief produksi siap dikerjakan',
                        'task',
                        "Brief \"{$item->contentBriefDraft->hook_title}\" untuk \"{$item->title}\" sudah diterapkan dan siap dieksekusi tim produksi."
                    );
                }
            }
        });

        return $draftItems->count();
    }

    public function hasUnresolvedRevisions(ContentItem $contentItem): bool
    {
        return ContentRevision::where('content_item_id', $contentItem->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->exists();
    }
}
