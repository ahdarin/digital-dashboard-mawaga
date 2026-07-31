<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\Client;
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