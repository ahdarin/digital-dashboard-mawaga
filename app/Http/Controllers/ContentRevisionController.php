<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Services\WorkflowStatusService;
use Illuminate\Http\Request;

class ContentRevisionController extends Controller
{
    /**
     * Tambah catatan revisi. Dipanggil dari 2 titik masuk:
     * - Form di halaman detail content item (status waiting_review ATAU
     *   revision - boleh nambah beberapa catatan sebelum tim mulai
     *   "Kerjakan Revisi", statusnya tetap 'revision' kalau sudah di situ).
     * - Modal drag-and-drop kanban (cuma dari waiting_review, lewat fetch
     *   JSON) - trigger transisi ke status 'revision'.
     */
    public function store(Request $request, ContentItem $contentItem, WorkflowStatusService $workflowStatusService)
    {
        $validated = $request->validate([
            'revision_note' => 'required|string',
        ]);

        $currentStatus = $contentItem->workflow->current_status;

        if (! in_array($currentStatus, ['waiting_review', 'revision'])) {
            $message = 'Revisi cuma bisa ditambahkan saat status Menunggu Persetujuan atau Perlu Revisi.';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }
            return back()->with('error', $message);
        }

        try {
            if ($currentStatus === 'waiting_review') {
                // Nambah revisi pertama kali - ini yang men-trigger pindah
                // status ke 'revision' (dibuatkan barisnya oleh service).
                $workflowStatusService->transition($contentItem, 'revision', [
                    'revision_note' => $validated['revision_note'],
                ], $request->user());
            } else {
                // Status sudah 'revision' - nambah catatan tambahan doang,
                // BUKAN transisi (from === to selalu invalid), jadi dibuat
                // langsung di sini tanpa lewat service.
                $lastRound = ContentRevision::where('content_item_id', $contentItem->id)->max('revision_round') ?? 0;
                ContentRevision::create([
                    'content_item_id' => $contentItem->id,
                    'requested_by_user_id' => $request->user()->id,
                    'revision_round' => $lastRound + 1,
                    'revision_note' => $validated['revision_note'],
                    'status' => 'open',
                ]);

                // KPI Fase 4 - revisi dibuat (jalur ini TIDAK lewat
                // WorkflowStatusService::transition(), jadi trigger-nya
                // ditaruh eksplisit di sini juga).
                \App\Kpi\Services\KpiRecalculationTrigger::scheduleCurrentPeriod();
            }
        } catch (WorkflowTransitionException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'status' => $contentItem->workflow->fresh()->current_status]);
        }

        return back()->with('status', 'Catatan revisi berhasil ditambahkan - klik "Kerjakan Revisi" saat siap mulai menggarapnya.');
    }

    /**
     * "Kerjakan Revisi" - mulai garap semua revisi yang lagi open sekaligus
     * (bukan cuma $revision yang diklik - status ada di level content item,
     * jadi nggak bisa sebagian open sebagian in_progress), konten pindah ke
     * in_progress. Nama method sengaja bukan "resolve" lagi (perilakunya
     * bukan menandai selesai, tapi mulai kerja). $revision tetap jadi bagian
     * URL biar tombolnya natural ditaruh di tiap kartu revisi.
     */
    public function startWork(Request $request, ContentItem $contentItem, ContentRevision $revision, WorkflowStatusService $workflowStatusService)
    {
        abort_unless($revision->content_item_id === $contentItem->id, 404);

        try {
            $workflowStatusService->transition($contentItem, 'in_progress', [], $request->user());
        } catch (WorkflowTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Revisi mulai dikerjakan, status konten dipindahkan ke Sedang Dikerjakan.');
    }
}