<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentPublication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Client;

class ContentPublicationController extends Controller
{

    public function index(Request $request)
    {
        $user = $request->user();

        $query = ContentPublication::with(['contentItem.client', 'platform', 'publishedBy'])
            ->latest('published_at');

        if (!$user->canSeeAllClients()) {
            $assignedClientIds = $user->assignedClients()->pluck('clients.id');
            $query->whereHas('contentItem', fn ($q) => $q->whereIn('client_id', $assignedClientIds));
        }

        if ($request->filled('client_id')) {
            $query->whereHas('contentItem', fn ($q) => $q->where('client_id', $request->input('client_id')));
        }

        $publications = $query->paginate(15)->withQueryString();

        $clientOptions = $user->canSeeAllClients()
            ? Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        return view('publishing-tracker.index', [
            'publications' => $publications,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $request->input('client_id'),
        ]);
    }

    public function store(Request $request, ContentItem $contentItem)
    {
        $validated = $request->validate([
            'platform_id' => 'required|exists:platforms,id',
            'published_at' => 'required|date',
            'post_url' => 'nullable|url',
            'caption_final' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated, $contentItem, $request) {
            ContentPublication::create([
                'content_item_id' => $contentItem->id,
                'platform_id' => $validated['platform_id'],
                'published_by' => $request->user()->id,
                'published_at' => $validated['published_at'],
                'post_url' => $validated['post_url'] ?? null,
                'caption_final' => $validated['caption_final'] ?? null,
            ]);

            $contentItem->update(['is_posted' => true]);

            $workflow = $contentItem->workflow;
            $fromStatus = $workflow->current_status;
            $workflow->update(['current_status' => 'uploaded']);

            \App\Models\ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by' => $request->user()->id,
                'from_status' => $fromStatus,
                'to_status' => 'uploaded',
                'notes' => 'Dipublikasikan dan dicatat via form Publishing Tracker.',
                'changed_at' => now(),
            ]);
        });

        return back()->with('status', 'Publikasi berhasil dicatat.');
    }
}