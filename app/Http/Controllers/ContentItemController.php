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
     * Tandai footage video sudah selesai di-take di lokasi, TANPA memindahkan
     * status workflow (masih Brief Ready) - dipakai buat kasus produksi video
     * dimana syuting sudah kelar tapi proses edit (baru itu yang bikin
     * pindah ke In Progress) belum mulai. Cuma penanda visual buat tim,
     * bukan bagian dari WorkflowTransitions karena from===to selalu invalid.
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
        abort_if($contentItem->workflow->current_status !== 'brief_ready', 422, 'Cuma bisa ditandai selama status masih Brief Ready.');

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

    /**
     * Batalkan penandaan footage sudah di-take - buat kasus salah klik.
     * Idempoten juga, sama seperti markFootageCaptured di atas.
     */
    public function unmarkFootageCaptured(Request $request, ContentItem $contentItem)
    {
        abort_if($contentItem->workflow->current_status !== 'brief_ready', 422, 'Cuma bisa dibatalkan selama status masih Brief Ready.');

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

        return back()->with('status', "PIC berhasil dipindahkan ke {$newPic->name}.");
    }
}
