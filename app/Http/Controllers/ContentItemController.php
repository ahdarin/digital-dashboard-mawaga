<?php

namespace App\Http\Controllers;

use App\Exceptions\PinException;
use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\User;
use App\Services\DelayRiskPredictionService;
use App\Services\PinService;
use App\Services\UserContentResolver;
use App\Services\WorkflowStatusService;
use App\Support\WorkflowTransitions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentItemController extends Controller
{
    private array $doneStatuses = WorkflowTransitions::DONE_STATUSES;

    public function show(ContentItem $contentItem, \App\Services\PicResolver $picResolver, UserContentResolver $contentResolver)
    {
        $contentItem->load([
            'client',
            'contentType',
            'platform',
            'platforms',
            'workflow.currentPic',
            'assignments.user',
            'statusLogs.changedByUser',
            'statusLogs.changedByClient',
            'revisions.requestedByUser',
            'revisions.requestedByClient',
            'publications.platform',
            'publications.publishedBy',
            'delayRiskScores' => fn ($q) => $q->latest()->limit(10),
            'contentBriefDraft.takeByUser',
        ]);

        // Kandidat reassign PIC dibatasi ke tim yang SUDAH di-assign ke client
        // ini (lewat "Assign Klien" di Kelola Pengguna) - bukan semua staff
        // internal. Diurutkan dari yang paling longgar (task aktif paling
        // sedikit) biar kelihatan langsung siapa yang punya kapasitas.
        $reassignCandidates = User::query()
            ->where('status', 'active')
            ->whereHas('assignedClients', fn ($q) => $q->where('clients.id', $contentItem->client_id))
            ->with('roles')
            ->withCount(['assignments as active_task_count' => function ($q) {
                $q->whereHas('contentItem.workflow', fn ($qq) => $qq->whereNotIn('current_status', \App\Support\WorkflowTransitions::INACTIVE_STATUSES));
            }])
            ->orderBy('active_task_count')
            ->get();

        $canUpdateWorkflow = auth()->user()->hasPermissionTo('workflow', 'update');
        $canApprove = auth()->user()->hasPermissionTo('workflow', 'approve');

        // Opsi buat form "Info Dasar" - cuma dipakai selama status Draf
        // (lihat content-items/show.blade.php), tapi disiapkan selalu biar
        // tidak perlu query kondisional.
        $pillarOptions = \App\Models\ContentPillar::all();
        $platformOptions = \App\Models\Platform::all();

        return view('content-items.show', compact(
            'contentItem', 'reassignCandidates', 'canUpdateWorkflow', 'canApprove', 'picResolver',
            'pillarOptions', 'platformOptions'
        ));
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
     * "Info Dasar" - judul, brief singkat, pilar, platform, PIC. Dulu diisi
     * sekaligus lewat form "Tambah Konten" saat item dibuat; sekarang item
     * sudah ada duluan (slot auto-generate dari kuota paket, lihat
     * ContentPlanItemGeneratorService), jadi copywriter melengkapi field
     * ini belakangan dari halaman detail - cuma selama masih status Draf.
     */
    public function updateInfo(Request $request, ContentItem $contentItem)
    {
        abort_unless($contentItem->workflow->current_status === 'draft', 422, 'Info dasar cuma bisa diubah selama status masih Draf.');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'brief' => 'nullable|string',
            'content_pillar_id' => 'nullable|exists:content_pillars,id',
            'platform_ids' => 'nullable|array',
            'platform_ids.*' => 'exists:platforms,id',
            'pic_user_id' => ['nullable', Rule::exists('users', 'id')->where('status', 'active')],
        ]);

        $picUser = null;
        if (! empty($validated['pic_user_id'])) {
            // Sama pola dengan reassign()/storeItem() lama - PIC harus
            // beneran terkait client item ini, bukan cuma exists di users.
            $picUser = User::where('id', $validated['pic_user_id'])
                ->whereHas('assignedClients', fn ($q) => $q->where('clients.id', $contentItem->client_id))
                ->first();
            abort_unless($picUser, 422, 'Penanggung Jawab yang dipilih tidak terkait dengan client ini.');
        }

        $platformIds = array_values(array_unique($validated['platform_ids'] ?? []));

        $contentItem->update([
            'title' => $validated['title'],
            'brief' => $validated['brief'] ?? null,
            'content_pillar_id' => $validated['content_pillar_id'] ?? null,
            // platform_id (scalar lama) tetap disinkronkan ke platform pertama
            // yang dipilih - dibaca banyak titik lama (laporan, analytics,
            // import, publish 1-platform) yang belum pindah ke pivot.
            'platform_id' => $platformIds[0] ?? null,
            'external_pic_name' => $picUser?->name ?? $contentItem->external_pic_name,
            'external_pic_email' => $picUser?->email ?? $contentItem->external_pic_email,
        ]);
        $contentItem->platforms()->sync($platformIds);

        if ($picUser) {
            $contentItem->workflow->update(['current_pic_id' => $picUser->id]);
            ContentItemAssignment::updateOrCreate(
                ['content_item_id' => $contentItem->id, 'assignment_role' => 'primary'],
                ['user_id' => $picUser->id]
            );
        }

        return back()->with('status', 'Info dasar berhasil disimpan.');
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
            'pic_user_id' => 'required|exists:users,id',
        ]);

        // Sama seperti storeItem()/quickCreateUrgent() - PIC baru harus
        // benar-benar terkait client konten ini lewat assignedClients, bukan
        // cuma exists di tabel users manapun.
        $newPic = User::query()
            ->where('status', 'active')
            ->whereHas('assignedClients', fn ($q) => $q->where('clients.id', $contentItem->client_id))
            ->find($validated['pic_user_id']);
        abort_unless($newPic, 422, 'Penanggung Jawab yang dipilih tidak terkait dengan client ini.');

        $workflow = $contentItem->workflow;
        $workflow->update(['current_pic_id' => $newPic->id]);

        $contentItem->update([
            'external_pic_name' => $newPic->name,
            'external_pic_email' => $newPic->email,
        ]);

        ContentItemAssignment::updateOrCreate(
            ['content_item_id' => $contentItem->id, 'assignment_role' => 'primary'],
            ['user_id' => $newPic->id]
        );

        $delayRiskService->predictForItems([$contentItem->id]);

        \App\Services\NotificationService::notify(
            $newPic,
            'Kamu jadi Penanggung Jawab konten ini',
            'task',
            "\"{$contentItem->title}\" ({$contentItem->client->name}) dipindahkan ke kamu.",
            $contentItem
        );

        return back()->with('status', "Penanggung Jawab berhasil dipindahkan ke {$newPic->name}.");
    }
}
