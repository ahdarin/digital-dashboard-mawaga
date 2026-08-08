<?php
namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentStatusLog;
use App\Models\ContentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\WorkflowTransitions;

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
            'board' => $board,
            'statuses' => $this->statuses,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $request->input('client_id'),
        ]);
    }

    public function updateStatus(Request $request, ContentItem $contentItem)
    {
        $validated = $request->validate([
            'to_status' => 'required|in:' . implode(',', $this->statuses),
            'notes' => 'nullable|string',
        ]);

        $workflow = $contentItem->workflow;
        $fromStatus = $workflow->current_status;
        $toStatus = $validated['to_status'];

        if (!WorkflowTransitions::isValid($fromStatus, $toStatus)) {
            $fromLabel = WorkflowTransitions::label($fromStatus);
            $toLabel = WorkflowTransitions::label($toStatus);

            return response()->json([
                'success' => false,
                'message' => "Tidak bisa memindahkan dari '{$fromLabel}' langsung ke '{$toLabel}'.",
            ], 422);
        }

        DB::transaction(function () use ($workflow, $contentItem, $fromStatus, $toStatus, $validated, $request) {
            $workflow->update(['current_status' => $toStatus]);

            ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by' => $request->user()->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $validated['notes'] ?? null,
                'changed_at' => now(),
            ]);
        });

        return response()->json(['success' => true, 'status' => $toStatus]);
    }
}