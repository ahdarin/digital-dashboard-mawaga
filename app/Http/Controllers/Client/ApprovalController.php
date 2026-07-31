<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    public function index(Request $request)
    {
        $clientId = $request->user()->client_id;

        $items = ContentItem::with(['contentType', 'platform', 'workflow'])
            ->where('client_id', $clientId)
            ->whereHas('workflow', fn ($q) => $q->where('current_status', 'waiting_review'))
            ->orderBy('deadline_at')
            ->get();

        return view('client.approval.index', compact('items'));
    }

    public function show(Request $request, ContentItem $contentItem)
    {
        abort_unless($contentItem->client_id === $request->user()->client_id, 403);

        $contentItem->load(['contentType', 'platform', 'workflow']);

        return view('client.approval.show', compact('contentItem'));
    }

    public function approve(Request $request, ContentItem $contentItem)
    {
        abort_unless($contentItem->client_id === $request->user()->client_id, 403);

        $workflow = $contentItem->workflow;
        abort_unless($workflow->current_status === 'waiting_review', 400);

        DB::transaction(function () use ($workflow, $contentItem, $request) {
            $fromStatus = $workflow->current_status;
            $workflow->update(['current_status' => 'approved']);

            ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by' => $request->user()->id,
                'from_status' => $fromStatus,
                'to_status' => 'approved',
                'approval_type' => 'client_approval',
                'notes' => 'Disetujui oleh klien melalui Portal Klien.',
                'changed_at' => now(),
            ]);
        });

        return redirect()->route('client.approval.index')
            ->with('status', 'Konten berhasil disetujui.');
    }

    public function requestRevision(Request $request, ContentItem $contentItem)
    {
        abort_unless($contentItem->client_id === $request->user()->client_id, 403);

        $validated = $request->validate([
            'revision_note' => 'required|string',
        ]);

        $workflow = $contentItem->workflow;
        abort_unless($workflow->current_status === 'waiting_review', 400);

        DB::transaction(function () use ($workflow, $contentItem, $validated, $request) {
            $lastRound = ContentRevision::where('content_item_id', $contentItem->id)->max('revision_round') ?? 0;

            ContentRevision::create([
                'content_item_id' => $contentItem->id,
                'requested_by' => $request->user()->id,
                'revision_round' => $lastRound + 1,
                'revision_note' => $validated['revision_note'],
                'status' => 'open',
            ]);

            $fromStatus = $workflow->current_status;
            $workflow->update(['current_status' => 'revision']);

            ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by' => $request->user()->id,
                'from_status' => $fromStatus,
                'to_status' => 'revision',
                'notes' => 'Revisi diminta oleh klien melalui Portal Klien.',
                'changed_at' => now(),
            ]);
        });

        return redirect()->route('client.approval.index')
            ->with('status', 'Catatan revisi berhasil dikirim.');
    }
}