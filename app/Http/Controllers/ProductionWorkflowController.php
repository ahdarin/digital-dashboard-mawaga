<?php
namespace App\Http\Controllers;

use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Services\WorkflowStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ProductionWorkflowController extends Controller
{
    // Urutan kolom board, harus konsisten dipakai di view juga
    private array $statuses = [
        'brief_ready',
        'in_progress',
        'waiting_review',
        'revision',
        'approved',
        'scheduled',
        'uploaded',
        'cancelled',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $tab = $request->input('tab', 'board');

        if ($tab === 'revisions') {
            return $this->revisionsTab($request, $user);
        }
        if ($tab === 'published') {
            return $this->publishedTab($request, $user);
        }

        $itemsQuery = ContentItem::with([
            'client',
            'contentType',
            'platform',
            'workflow.currentPic',
            'assignments.user',
            'latestDelayRisk',
        ])
            ->whereHas('workflow');

        // Batasi hanya client yang di-assign, kecuali CEO/Manager
        if (!$user->canSeeAllClients()) {
            $assignedClientIds = $user->assignedClients()->pluck('clients.id');
            $itemsQuery->whereIn('client_id', $assignedClientIds);
        }

        // Filter dropdown by client (opsional, dari query string)
        if ($request->filled('client_id')) {
            $itemsQuery->where('client_id', $request->input('client_id'));
        }

        // Filter bulan berdasarkan deadline (opsional, format YYYY-MM dari query string)
        if ($request->filled('month')) {
            $month = Carbon::parse($request->input('month') . '-01');
            $itemsQuery->whereYear('deadline_at', $month->year)
                ->whereMonth('deadline_at', $month->month);
        }

        $items = $itemsQuery->get()->groupBy(fn($item) => $item->workflow->current_status);

        $board = [];
        foreach ($this->statuses as $status) {
            $columnItems = $items->get($status, collect())->values();
            $columnItems->each(fn ($item, $i) => $item->boardOrder = $i);
            $board[$status] = $columnItems->sortByDesc(
                fn ($item) => optional($item->latestDelayRisk)->risk_score ?? -1
            )->values();
        }

        // Daftar client untuk dropdown filter (hanya yang relevan buat user ini)
        $clientOptions = $user->canSeeAllClients()
            ? \App\Models\Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        return view('production-workflow.index', [
            'tab' => 'board',
            'board' => $board,
            'statuses' => $this->statuses,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $request->input('client_id'),
            'selectedMonth' => $request->input('month'),
            'canUpdateWorkflow' => $user->hasPermissionTo('workflow', 'update'),
            'canCreateContent' => $user->hasPermissionTo('content_plan', 'create'),
            'contentTypeOptions' => \App\Models\ContentType::all(),
            'platformOptions' => \App\Models\Platform::all(),
            'picOptions' => \App\Models\User::whereNull('client_id')->where('status', 'active')->get(),
        ]);
    }

    /**
     * Tab "Revisi" - dulu halaman terpisah (Revision Log), sekarang
     * digabung ke Produksi (Tahap 6.1) supaya tim nggak perlu loncat menu
     * buat lihat status revisi vs papan produksi.
     */
    private function revisionsTab(Request $request, $user)
    {
        $query = \App\Models\ContentRevision::with(['contentItem.client', 'requestedBy'])
            ->latest('created_at');

        if (! $user->canSeeAllClients()) {
            $assignedClientIds = $user->assignedClients()->pluck('clients.id');
            $query->whereHas('contentItem', fn ($q) => $q->whereIn('client_id', $assignedClientIds));
        }

        if ($request->filled('client_id')) {
            $query->whereHas('contentItem', fn ($q) => $q->where('client_id', $request->input('client_id')));
        }

        if ($request->input('status', 'open') !== 'all') {
            $query->where('status', $request->input('status', 'open'));
        }

        $revisions = $query->paginate(15)->withQueryString();

        $clientOptions = $user->canSeeAllClients()
            ? \App\Models\Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        return view('production-workflow.index', [
            'tab' => 'revisions',
            'statuses' => $this->statuses,
            'revisions' => $revisions,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $request->input('client_id'),
            'selectedStatus' => $request->input('status', 'open'),
            'contentTypeOptions' => \App\Models\ContentType::all(),
            'platformOptions' => \App\Models\Platform::all(),
            'picOptions' => \App\Models\User::whereNull('client_id')->where('status', 'active')->get(),
        ]);
    }

    /**
     * Tab "Sudah Tayang" - dulu halaman terpisah (Publishing Tracker),
     * sekarang digabung ke Produksi (Tahap 6.1).
     */
    private function publishedTab(Request $request, $user)
    {
        $query = \App\Models\ContentPublication::with(['contentItem.client', 'platform', 'publishedBy'])
            ->latest('published_at');

        if (! $user->canSeeAllClients()) {
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
            ? \App\Models\Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        $platformOptions = \App\Models\Platform::orderBy('name')->get();

        return view('production-workflow.index', [
            'tab' => 'published',
            'statuses' => $this->statuses,
            'publications' => $publications,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $request->input('client_id'),
            'platformOptions' => $platformOptions,
            'selectedPlatformId' => $request->input('platform_id'),
            'contentTypeOptions' => \App\Models\ContentType::all(),
            'picOptions' => \App\Models\User::whereNull('client_id')->where('status', 'active')->get(),
        ]);
    }

    /**
     * Satu-satunya endpoint drag-and-drop kanban buat semua perpindahan
     * status - transisi simpel (nggak butuh data tambahan) maupun yang
     * butuh payload (revision_note, scheduled_upload_at, data publikasi)
     * SEMUA lewat WorkflowStatusService yang sama persis dipakai tombol
     * Status Management, biar guard & efek sampingnya konsisten di kedua
     * jalur.
     */
    public function updateStatus(Request $request, ContentItem $contentItem, WorkflowStatusService $workflowStatusService)
    {
        $validated = $request->validate([
            'to_status' => 'required|in:' . implode(',', $this->statuses),
            'notes' => 'nullable|string',
            'revision_note' => 'nullable|string',
            'scheduled_upload_at' => 'nullable|date',
            'platform_id' => 'nullable|exists:platforms,id',
            'published_at' => 'nullable|date',
            'post_url' => 'nullable|url',
            'caption_final' => 'nullable|string',
        ]);

        $toStatus = $validated['to_status'];
        unset($validated['to_status']);

        try {
            $workflowStatusService->transition($contentItem, $toStatus, $validated, $request->user());
        } catch (WorkflowTransitionException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'status' => $toStatus]);
    }
}
