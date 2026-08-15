<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Services\NotificationService;
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

        $pendingItems = $items->whereNull('workflow.client_reviewed_at')->values();
        $reviewedItems = $items->whereNotNull('workflow.client_reviewed_at')->values();

        return view('client.approval.index', compact('pendingItems', 'reviewedItems'));
    }

    public function show(Request $request, ContentItem $contentItem)
    {
        abort_unless($contentItem->client_id === $request->user()->client_id, 403);

        $contentItem->load(['contentType', 'platform', 'workflow']);

        $alreadyReviewed = ! is_null($contentItem->workflow->client_reviewed_at);

        return view('client.approval.show', compact('contentItem', 'alreadyReviewed'));
    }

    public function approve(Request $request, ContentItem $contentItem)
    {
        abort_unless($contentItem->client_id === $request->user()->client_id, 403);

        $workflow = $contentItem->workflow;
        abort_unless($workflow->current_status === 'waiting_review', 400);
        abort_unless(is_null($workflow->client_reviewed_at), 400);

        $workflow->update([
            'client_reviewed_at' => now(),
            'client_reviewed_by' => $request->user()->id,
            'client_review_result' => 'approved',
        ]);

        NotificationService::notifyInternalCheckers($contentItem);

        return redirect()->route('client.approval.index')
            ->with('status', 'Terima kasih! Persetujuan kamu sudah dicatat dan akan dicek oleh tim internal sebelum konten resmi disetujui.');
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