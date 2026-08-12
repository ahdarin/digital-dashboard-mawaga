<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Models\ContentPublication;
use App\Services\WorkflowStatusService;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Platform;

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

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->input('platform_id'));
        }

        $publications = $query->paginate(15)->withQueryString();

        $clientOptions = $user->canSeeAllClients()
            ? Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        $platformOptions = Platform::orderBy('name')->get();

        return view('publishing-tracker.index', [
            'publications' => $publications,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $request->input('client_id'),
            'platformOptions' => $platformOptions,
            'selectedPlatformId' => $request->input('platform_id'),
        ]);
    }

    /**
     * Catat publikasi & pindahkan status ke uploaded. Dipanggil dari 2 titik
     * masuk: form Record Publication di halaman detail content item, dan
     * modal drag-and-drop kanban (scheduled -> uploaded, lewat fetch JSON).
     */
    public function store(Request $request, ContentItem $contentItem, WorkflowStatusService $workflowStatusService)
    {
        $validated = $request->validate([
            'platform_id' => 'required|exists:platforms,id',
            'published_at' => 'required|date',
            'post_url' => 'nullable|url',
            'caption_final' => 'nullable|string',
        ]);

        try {
            $workflowStatusService->transition($contentItem, 'uploaded', [
                ...$validated,
                'notes' => 'Dipublikasikan dan dicatat via form Publishing Tracker.',
            ], $request->user());
        } catch (WorkflowTransitionException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'status' => 'uploaded']);
        }

        return back()->with('status', 'Publikasi berhasil dicatat.');
    }
}