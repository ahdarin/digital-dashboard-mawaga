<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ContentPlanController extends Controller
{
    public function index(Request $request)
    {
        $selectedClientId = $request->input('client_id');
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $plans = ContentPlan::with(['client', 'clientPackage'])
            ->withCount('contentItems')
            ->when($selectedClientId, fn ($q) => $q->where('client_id', $selectedClientId))
            ->where('month', $month)
            ->where('year', $year)
            ->latest()
            ->paginate(10)
            ->withQueryString();

        // Ringkasan target vs realisasi, digabung dari semua plan yang tampil
        $targetContent = $plans->sum(fn ($p) => $p->clientPackage->monthly_content_quota ?? 0);
        $targetDesign = $plans->sum(fn ($p) => $p->clientPackage->monthly_design_quota ?? 0);
        $realizedContent = $plans->sum('content_items_count');
        $realizedDesign = ContentItem::whereIn('content_plan_id', $plans->pluck('id'))
            ->whereHas('contentType', fn ($q) => $q->where('name', 'Design'))
            ->count();

        $clientOptions = Client::where('status', 'active')->get();

        return view('content-plan.index', compact(
            'plans', 'clientOptions', 'selectedClientId', 'month', 'year',
            'targetContent', 'targetDesign', 'realizedContent', 'realizedDesign'
        ));
    }

    public function create(Request $request)
    {
        $clientOptions = Client::where('status', 'active')->with('activePackage')->get();

        return view('content-plan.create', compact('clientOptions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $activePackage = $client->activePackage;

        abort_unless($activePackage, 422, 'Client ini belum punya paket aktif, tidak bisa dibuatkan content plan.');

        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'client_package_id' => $activePackage->id,
            'created_by' => auth()->id(),
            'month' => $validated['month'],
            'year' => $validated['year'],
            'status' => 'draft',
        ]);

        return redirect()->route('content-plan.show', $plan)
            ->with('status', 'Content plan berhasil dibuat. Silakan tambahkan content item-nya.');
    }

    public function show(ContentPlan $contentPlan, Request $request)
    {
        $contentPlan->load(['client', 'clientPackage', 'creator', 'approver']);

        $items = $contentPlan->contentItems()
            ->with(['contentType', 'platform', 'workflow', 'assignments.user'])
            ->orderBy('deadline_at')
            ->get();

        $view = $request->input('view', 'table'); // table | calendar

        return view('content-plan.show', compact('contentPlan', 'items', 'view'));
    }

    public function approve(ContentPlan $contentPlan)
    {
        $contentPlan->update(['status' => 'approved', 'approved_by' => auth()->id()]);

        return back()->with('status', 'Content plan disetujui.');
    }

    public function reject(ContentPlan $contentPlan)
    {
        $contentPlan->update(['status' => 'rejected', 'approved_by' => auth()->id()]);

        return back()->with('status', 'Content plan ditolak, silakan revisi.');
    }

    public function createItem(ContentPlan $contentPlan)
    {
        $pillars = ContentPillar::all();
        $types = ContentType::all();
        $platforms = Platform::all();
        $picOptions = User::whereNull('client_id')->where('status', 'active')->get();

        return view('content-plan.create-item', compact('contentPlan', 'pillars', 'types', 'platforms', 'picOptions'));
    }

    public function storeItem(ContentPlan $contentPlan, Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'brief' => 'nullable|string',
            'content_pillar_id' => 'nullable|exists:content_pillars,id',
            'content_type_id' => 'nullable|exists:content_types,id',
            'platform_id' => 'nullable|exists:platforms,id',
            'deadline_at' => 'required|date',
            'pic_id' => 'nullable|exists:users,id',
        ]);

        $item = ContentItem::create([
            'content_plan_id' => $contentPlan->id,
            'client_id' => $contentPlan->client_id,
            'content_pillar_id' => $validated['content_pillar_id'] ?? null,
            'content_type_id' => $validated['content_type_id'] ?? null,
            'platform_id' => $validated['platform_id'] ?? null,
            'title' => $validated['title'],
            'brief' => $validated['brief'] ?? null,
            'deadline_at' => Carbon::parse($validated['deadline_at']),
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_pic_id' => $validated['pic_id'] ?? null,
            'current_status' => 'planned',
            'is_overdue' => false,
        ]);

        if (! empty($validated['pic_id'])) {
            $item->assignments()->create([
                'user_id' => $validated['pic_id'],
                'assignment_role' => 'primary',
            ]);
        }

        return redirect()->route('content-plan.show', $contentPlan)
            ->with('status', 'Content item berhasil ditambahkan.');
    }
}