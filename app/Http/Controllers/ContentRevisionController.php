<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\Client;
use App\Services\WorkflowStatusService;
use Illuminate\Http\Request;

class ContentRevisionController extends Controller
{

    public function index(Request $request)
    {
        $user = $request->user();

        $query = ContentRevision::with(['contentItem.client', 'requestedBy'])
            ->latest('created_at');

        if (!$user->canSeeAllClients()) {
            $assignedClientIds = $user->assignedClients()->pluck('clients.id');
            $query->whereHas('contentItem', fn ($q) => $q->whereIn('client_id', $assignedClientIds));
        }

        if ($request->filled('client_id')) {
            $query->whereHas('contentItem', fn ($q) => $q->where('client_id', $request->input('client_id')));
        }

        // Default: tampilkan yang open saja, kecuali diminta lihat semua
        if ($request->input('status', 'open') !== 'all') {
            $query->where('status', $request->input('status', 'open'));
        }

        $revisions = $query->paginate(15)->withQueryString();

        $clientOptions = $user->canSeeAllClients()
            ? Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        return view('revision-log.index', [
            'revisions' => $revisions,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $request->input('client_id'),
            'selectedStatus' => $request->input('status', 'open'),
        ]);
    }
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
                    'requested_by' => $request->user()->id,
                    'revision_round' => $lastRound + 1,
                    'revision_note' => $validated['revision_note'],
                    'status' => 'open',
                ]);
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