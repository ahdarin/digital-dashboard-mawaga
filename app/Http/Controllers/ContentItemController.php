<?php

namespace App\Http\Controllers;

use App\Exceptions\PinException;
use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\User;
use App\Services\DelayRiskPredictionService;
use App\Services\PinService;
use App\Services\WorkflowStatusService;
use App\Support\WorkflowTransitions;
use Illuminate\Http\Request;

class ContentItemController extends Controller
{
    private array $doneStatuses = WorkflowTransitions::DONE_STATUSES;

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
        $canApprove = auth()->user()->hasPermissionTo('workflow', 'approve');

        return view('content-items.show', compact('contentItem', 'reassignCandidates', 'canUpdateWorkflow', 'canApprove'));
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

    public function correctStatus(Request $request, ContentItem $contentItem, WorkflowStatusService $workflowStatusService)
    {
        $validated = $request->validate([
            'to_status' => 'required|string',
            'reason' => 'required|string',
        ]);

        try {
            $workflowStatusService->correctStatus($contentItem, $validated['to_status'], $validated['reason'], $request->user());
        } catch (WorkflowTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Status konten berhasil dikoreksi.');
    }

    /**
     * Tandai footage video sudah selesai di-take di lokasi - dipakai buat
     * kasus produksi video dimana syuting baru selesai setelah status sudah
     * pindah ke In Progress (proses edit sudah/lagi berjalan duluan, bukan
     * nunggu Brief Ready). Cuma penanda visual buat tim, bukan bagian dari
     * WorkflowTransitions karena from===to selalu invalid.
     *
     * Tanggal/jam take-nya diisi manual oleh user (bukan otomatis now()) -
     * syuting sering terjadi beberapa hari sebelum baru sempat ditandai di
     * sistem, jadi tanggalnya harus bisa disesuaikan sama kenyataan.
     *
     * Idempoten (klik dobel / sudah ditandai sebelumnya tetap dianggap
     * sukses, bukan error) supaya tombol di kanban board (yang bisa retrigger
     * kalau board belum sempat reload) tidak keliru muncul gagal.
     */
    public function markFootageCaptured(Request $request, ContentItem $contentItem)
    {
        abort_if($contentItem->workflow->current_status !== 'in_progress', 422, 'Cuma bisa ditandai selama status masih Sedang Dikerjakan.');

        $validated = $request->validate([
            'footage_captured_at' => 'nullable|date',
        ]);

        // Selalu update ke tanggal yang diisi user (bukan cuma sekali set) -
        // biar tanggalnya juga bisa dikoreksi belakangan kalau salah isi.
        $contentItem->update(['footage_captured_at' => $validated['footage_captured_at'] ?? now()]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'footage_captured_at' => $contentItem->footage_captured_at->format('d M Y, H:i'),
            ]);
        }

        return back()->with('status', 'Footage video ditandai sudah selesai di-take.');
    }

    /**
     * Pin konten ini buat user yang lagi login - personal, nggak
     * mempengaruhi/kelihatan user lain. Ditolak (422) kalau sudah nyentuh
     * batas maksimal PinService::MAX_PINS.
     */
    public function pin(Request $request, ContentItem $contentItem, PinService $pinService)
    {
        try {
            $pinService->pin($request->user(), $contentItem);
        } catch (PinException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'pinned' => true]);
    }

    /**
     * Lepas pin konten ini buat user yang lagi login. Idempoten, sama
     * seperti unmarkFootageCaptured di bawah.
     */
    public function unpin(Request $request, ContentItem $contentItem, PinService $pinService)
    {
        $pinService->unpin($request->user(), $contentItem);

        return response()->json(['success' => true, 'pinned' => false]);
    }

    /**
     * Simpan/perbarui link file hasil produksi (draft di Google Drive/Canva/
     * dsb) - diisi PIC produksi setelah konten selesai diedit, SEBELUM masuk
     * tahap review/upload, supaya reviewer & client bisa cek hasilnya duluan.
     * Terpisah dari post_url (link postingan LIVE) yang baru diisi di Record
     * Publication saat status Scheduled -> Uploaded.
     */
    public function updateContentLink(Request $request, ContentItem $contentItem)
    {
        $validated = $request->validate([
            'content_file_link' => 'nullable|url|max:2048',
        ]);

        $contentItem->update(['content_file_link' => $validated['content_file_link'] ?? null]);

        return back()->with('status', 'Link konten berhasil disimpan.');
    }

    public function updateCaption(Request $request, ContentItem $contentItem)
    {
        $validated = $request->validate([
            'caption_draft' => 'nullable|string',
        ]);

        $contentItem->update(['caption_draft' => $validated['caption_draft'] ?? null]);

        return back()->with('status', 'Draft caption berhasil disimpan - akan dibaca & disetujui klien di Portal Klien saat konten masuk Menunggu Persetujuan.');
    }

    /**
     * Batalkan penandaan footage sudah di-take - buat kasus salah klik.
     * Idempoten juga, sama seperti markFootageCaptured di atas.
     */
    public function unmarkFootageCaptured(Request $request, ContentItem $contentItem)
    {
        abort_if($contentItem->workflow->current_status !== 'in_progress', 422, 'Cuma bisa dibatalkan selama status masih Sedang Dikerjakan.');

        if ($contentItem->footage_captured_at) {
            $contentItem->update(['footage_captured_at' => null]);
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('status', 'Penandaan footage sudah di-take dibatalkan.');
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

        return back()->with('status', "Penanggung Jawab berhasil dipindahkan ke {$newPic->name} - notifikasi otomatis terkirim ke yang bersangkutan.");
    }
}
