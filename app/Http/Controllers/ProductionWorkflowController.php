<?php
namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentStatusLog;
use App\Models\ContentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionWorkflowController extends Controller
{
    // Urutan kolom board, harus konsisten dipakai di view juga
    private array $statuses = [
        'brief_ready', 'in_progress', 'waiting_review', 
        'revision', 'approved', 'scheduled', 
        'uploaded', 'cancelled',
    ];

    public function index()
    {
        $items = ContentItem::with(['client', 'contentType', 'platform', 'workflow.currentPic'])
            ->whereHas('workflow')
            ->get()
            ->groupBy(fn ($item) => $item->workflow->current_status);

        $board = [];
        foreach ($this->statuses as $status) {
            $board[$status] = $items->get($status, collect());
        }

        // dd($board);

        return view('production-workflow.index', [
            'board' => $board,
            'statuses' => $this->statuses,
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

        DB::transaction(function () use ($workflow, $contentItem, $fromStatus, $validated, $request) {
            $workflow->update(['current_status' => $validated['to_status']]);

            ContentStatusLog::create([
                'content_item_id' => $contentItem->id,
                'changed_by' => $request->user()->id,
                'from_status' => $fromStatus,
                'to_status' => $validated['to_status'],
                'notes' => $validated['notes'] ?? null,
                'changed_at' => now(),
            ]);
        });

        return response()->json(['success' => true, 'status' => $validated['to_status']]);
    }
}