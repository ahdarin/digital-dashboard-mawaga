<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentRevision;
use Illuminate\Http\Request;

class ContentRevisionController extends Controller
{
    public function store(Request $request, ContentItem $contentItem)
    {
        $validated = $request->validate([
            'revision_note' => 'required|string',
        ]);

        $lastRound = ContentRevision::where('content_item_id', $contentItem->id)->max('revision_round') ?? 0;

        ContentRevision::create([
            'content_item_id' => $contentItem->id,
            'requested_by' => $request->user()->id,
            'revision_round' => $lastRound + 1,
            'revision_note' => $validated['revision_note'],
            'status' => 'open',
        ]);

        return back()->with('status', 'Catatan revisi berhasil ditambahkan.');
    }

    public function resolve(ContentItem $contentItem, ContentRevision $revision)
    {
        $revision->update(['status' => 'resolved']);

        return back()->with('status', 'Revisi ditandai selesai.');
    }
}