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
    public function show(Request $request, ContentItem $contentItem)
    {
        abort_unless($contentItem->client_id === $request->user()->client_id, 403);

        $contentItem->load(['contentType', 'platform', 'workflow', 'publications.platform']);

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

        return redirect(route('client.dashboard') . '#persetujuan')
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

        NotificationService::notifyAssignedUsers(
            $contentItem,
            'Klien Meminta Revisi',
            'client_revision_requested',
            "\"{$contentItem->title}\" diminta revisi oleh klien: \"{$validated['revision_note']}\""
        );

        return redirect(route('client.dashboard') . '#persetujuan')
            ->with('status', 'Catatan revisi berhasil dikirim.');
    }
}