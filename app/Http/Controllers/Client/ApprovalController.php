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
    /**
     * Ambil ContentItem TERSCOPE ke client dari portal context - dipakai
     * ketiga method di bawah, BUKAN implicit route-model-binding global
     * (route param {contentItem} sengaja diterima sebagai ID mentah, bukan
     * type-hint ContentItem) - whereKey()->firstOrFail() di sini SATU-SATUNYA
     * jalur resolusi model, jadi tidak mungkin ada content item milik client
     * lain yang lolos. 404 (bukan 403) kalau content item itu bukan milik
     * client token ini (termasuk kalau memang tidak ada) - jangan bocorkan
     * bahwa ID itu valid tapi punya client lain.
     */
    private function scopedContentItem(Request $request, string $contentItemId): ContentItem
    {
        $client = $request->attributes->get('portalClient');

        return $client->contentItems()->whereKey($contentItemId)->firstOrFail();
    }

    /**
     * $token DIDEKLARASIKAN walau tidak dipakai langsung di sini (client
     * sudah tersedia lewat $request->attributes->get('portalClient')) -
     * WAJIB ada karena Laravel me-resolve parameter scalar (non class-hint)
     * SECARA POSISI, bukan berdasarkan nama, begitu ada parameter route yang
     * tidak diwakili di signature method (di sini {token} datang sebelum
     * {contentItem} di URL) - tanpa ini, $contentItem diam-diam menerima
     * nilai token, bukan ID content item. Jangan hapus parameter ini.
     */
    public function show(Request $request, string $token, string $contentItem)
    {
        $contentItem = $this->scopedContentItem($request, $contentItem);

        $contentItem->load(['contentType', 'platform', 'workflow', 'publications.platform']);

        $alreadyReviewed = ! is_null($contentItem->workflow->client_reviewed_at);

        return view('client.approval.show', compact('contentItem', 'alreadyReviewed'));
    }

    public function approve(Request $request, string $token, string $contentItem)
    {
        $client = $request->attributes->get('portalClient');
        $contentItem = $this->scopedContentItem($request, $contentItem);

        $workflow = $contentItem->workflow;
        abort_unless($workflow->current_status === 'waiting_review', 400);
        abort_unless(is_null($workflow->client_reviewed_at), 400);

        $workflow->update([
            'client_reviewed_at' => now(),
            'client_reviewed_by_client_id' => $client->id,
            'client_review_result' => 'approved',
        ]);

        NotificationService::notifyInternalCheckers($contentItem);

        return redirect(route('client.portal.dashboard', $client->portal_token) . '#persetujuan')
            ->with('status', 'Terima kasih! Persetujuan kamu sudah dicatat dan akan dicek oleh tim internal sebelum konten resmi disetujui.');
    }

    public function requestRevision(Request $request, string $token, string $contentItem)
    {
        $client = $request->attributes->get('portalClient');
        $contentItem = $this->scopedContentItem($request, $contentItem);

        $validated = $request->validate([
            'revision_note' => 'required|string',
        ]);

        $workflow = $contentItem->workflow;
        abort_unless($workflow->current_status === 'waiting_review', 400);

        DB::transaction(function () use ($workflow, $contentItem, $validated, $client) {
            $lastRound = ContentRevision::where('content_item_id', $contentItem->id)->max('revision_round') ?? 0;

            ContentRevision::create([
                'content_item_id' => $contentItem->id,
                'requested_by_client_id' => $client->id,
                'revision_round' => $lastRound + 1,
                'revision_note' => $validated['revision_note'],
                'status' => 'open',
            ]);

            $fromStatus = $workflow->current_status;
            $workflow->update(['current_status' => 'revision']);

            ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by_client_id' => $client->id,
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

        return redirect(route('client.portal.dashboard', $client->portal_token) . '#persetujuan')
            ->with('status', 'Catatan revisi berhasil dikirim.');
    }
}
