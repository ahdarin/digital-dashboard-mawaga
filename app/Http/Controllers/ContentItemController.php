<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\User;
use App\Services\DelayRiskPredictionService;
use App\Services\WorkflowStatusService;
use Illuminate\Http\Request;

class ContentItemController extends Controller
{
    private array $doneStatuses = ['uploaded', 'cancelled'];

    public function show(ContentItem $contentItem)
    {
        $contentItem->load([
            'client',
            'contentType',
            'platform',
            'workflow.currentPic',
            'assignments.user',
            'statusLogs.changedBy',
            'revisions.requestedBy',
            'publications.platform',
            'publications.publishedBy',
            'delayRiskScores' => fn ($q) => $q->latest()->limit(10),
            'contentBriefDraft.takeByUser',
        ]);

        // Kandidat reassign PIC, diurutkan dari yang paling longgar (task aktif
        // paling sedikit) - biar kelihatan langsung siapa yang punya kapasitas.
        $reassignCandidates = User::whereNull('client_id')
            ->where('status', 'active')
            ->withCount(['assignments as active_task_count' => function ($q) {
                $q->whereHas('contentItem.workflow', fn ($qq) => $qq->whereNotIn('current_status', $this->doneStatuses));
            }])
            ->orderBy('active_task_count')
            ->get();

        $canUpdateWorkflow = auth()->user()->hasPermissionTo('workflow', 'update');

        return view('content-items.show', compact('contentItem', 'reassignCandidates', 'canUpdateWorkflow'));
    }

    /**
     * Endpoint umum buat tombol Status Management (Kerjakan Konten, Konten
     * Telah Selesai, Approve Konten, Jadwalkan Upload, Batalkan Konten).
     * Semua guard & efek samping ditangani WorkflowStatusService - sama
     * persis yang dipakai drag-and-drop di kanban board.
     */
    public function transition(Request $request, ContentItem $contentItem, WorkflowStatusService $workflowStatusService)
    {
        $validated = $request->validate([
            'to_status' => 'required|string',
            'notes' => 'nullable|string',
            'scheduled_upload_at' => 'nullable|date',
        ]);

        $toStatus = $validated['to_status'];
        unset($validated['to_status']);

        try {
            $workflowStatusService->transition($contentItem, $toStatus, $validated, $request->user());
        } catch (WorkflowTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Status konten berhasil diperbarui.');
    }

    /**
     * Pindahkan PIC utama content item ke user lain, lalu langsung hitung ulang
     * skor Delay Risk-nya sinkron - biar penurunan beban kerja PIC baru
     * langsung kereflect di skor tanpa nunggu cron jam-an.
     */
    public function reassign(ContentItem $contentItem, Request $request, DelayRiskPredictionService $delayRiskService)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $newPic = User::whereNull('client_id')->where('status', 'active')->findOrFail($validated['user_id']);

        $workflow = $contentItem->workflow;
        $workflow->update(['current_pic_id' => $newPic->id]);

        ContentItemAssignment::updateOrCreate(
            ['content_item_id' => $contentItem->id, 'assignment_role' => 'primary'],
            ['user_id' => $newPic->id]
        );

        $delayRiskService->predictForItems([$contentItem->id]);

        return back()->with('status', "PIC berhasil dipindahkan ke {$newPic->name}.");
    }
}
