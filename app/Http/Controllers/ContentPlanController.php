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
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ContentPlanController extends Controller
{
    public function index(Request $request)
    {
        $selectedClientId = $request->input('client_id');
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $view = $request->input('view', 'table'); // table | calendar

        // ---- Data untuk Table View (logic lama, tidak berubah) ----
        $plans = ContentPlan::with(['client', 'clientPackage'])
            ->withCount('contentItems')
            ->when($selectedClientId, fn ($q) => $q->where('client_id', $selectedClientId))
            ->where('month', $month)
            ->where('year', $year)
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $targetContent = $plans->sum(fn ($p) => $p->clientPackage->monthly_content_quota ?? 0);
        $targetDesign = $plans->sum(fn ($p) => $p->clientPackage->monthly_design_quota ?? 0);
        $realizedContent = $plans->sum('content_items_count');
        $realizedDesign = ContentItem::whereIn('content_plan_id', $plans->pluck('id'))
            ->whereHas('contentType', fn ($q) => $q->where('name', 'Desain'))
            ->count();

        $clientOptions = Client::where('status', 'active')->with('activePackage')->get();

        // ---- Data untuk Calendar View ----
        $itemsByDateClient = collect();
        $typeOptions = collect();
        $selectedType = $request->input('type', 'all');
        $selectedDate = $request->input('date');

        if ($view === 'calendar') {

            $allowedTypes = ['Video', 'Desain'];

            $calendarItems = ContentItem::with(['client', 'contentType'])
                ->whereMonth('deadline_at', $month)
                ->whereYear('deadline_at', $year)

                ->whereHas('contentType', function ($q) use ($allowedTypes) {
                    $q->whereIn('name', $allowedTypes);
                })

                ->when($selectedClientId, function ($q) use ($selectedClientId) {
                    $q->where('client_id', $selectedClientId);
                })

                ->when($selectedType !== 'all', function ($q) use ($selectedType) {
                    $q->whereHas('contentType', function ($query) use ($selectedType) {
                        $query->where('name', $selectedType);
                    });
                })

                ->when($selectedDate, function ($q) use ($selectedDate) {
                    $q->whereDate('deadline_at', $selectedDate);
                })

                ->orderBy('deadline_at')
                ->get();

            $itemsByDateClient = $calendarItems
                ->groupBy(fn ($item) => $item->deadline_at->format('Y-m-d'))
                ->map(fn ($dayItems) => $dayItems->groupBy('client_id'));

            $typeOptions = ContentType::whereIn(
                'name',
                $allowedTypes
            )->get();
        }

        $contentTypeOptions = ContentType::all();
        $platformOptions = Platform::all();
        $picOptions = User::whereNull('client_id')->where('status', 'active')->get();

        return view('content-plan.index', compact(
            'plans',
            'clientOptions',
            'selectedClientId',
            'month',
            'year',
            'view',
            'targetContent',
            'targetDesign',
            'realizedContent',
            'realizedDesign',
            'itemsByDateClient',
            'typeOptions',
            'selectedType',
            'selectedDate',
            'contentTypeOptions',
            'platformOptions',
            'picOptions'
        ));
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
            ->with(['contentType', 'platform', 'workflow', 'assignments.user', 'client'])
            ->orderBy('deadline_at')
            ->get();

        $view = $request->input('view', 'table'); // table | calendar

        // Sama desainnya kayak kalender utama (content-plan.index) - pakai
        // partial calendar-grid yang sama biar tidak dobel maintenance.
        $month = (int) $request->input('month', $contentPlan->month);
        $year = (int) $request->input('year', $contentPlan->year);
        $selectedType = $request->input('type', 'all');
        $selectedDate = $request->input('date');
        $selectedClientId = $contentPlan->client_id;
        $clientOptions = collect([$contentPlan->client]);
        $itemsByDateClient = collect();

        if ($view === 'calendar') {
            $itemsByDateClient = $items
                ->when($selectedType !== 'all', fn ($collection) => $collection->filter(
                    fn ($item) => ($item->contentType->name ?? null) === $selectedType
                ))
                ->when($selectedDate, fn ($collection) => $collection->filter(
                    fn ($item) => $item->deadline_at->format('Y-m-d') === $selectedDate
                ))
                ->groupBy(fn ($item) => $item->deadline_at->format('Y-m-d'))
                ->map(fn ($dayItems) => $dayItems->groupBy('client_id'));
        }

        $contentTypeOptions = ContentType::all();
        $platformOptions = Platform::all();
        $picOptions = User::whereNull('client_id')->where('status', 'active')->get();

        return view('content-plan.show', compact(
            'contentPlan', 'items', 'view', 'month', 'year',
            'itemsByDateClient', 'clientOptions', 'selectedType', 'selectedDate', 'selectedClientId',
            'contentTypeOptions', 'platformOptions', 'picOptions'
        ));
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
            'estimated_duration_seconds' => 'nullable|integer',
            'estimated_slide_count' => 'nullable|integer',
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
            'estimated_duration_seconds' => $validated['estimated_duration_seconds'] ?? null,
            'estimated_slide_count' => $validated['estimated_slide_count'] ?? null,
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_pic_id' => $validated['pic_id'] ?? null,
            'current_status' => 'brief_ready',
            'is_overdue' => false,
        ]);

        if (!empty($validated['pic_id'])) {
            $item->assignments()->create([
                'user_id' => $validated['pic_id'],
                'assignment_role' => 'primary',
            ]);
        }

        return redirect()->route('content-plan.show', $contentPlan)
            ->with('status', 'Content item berhasil ditambahkan.');
    }

    /**
     * Input cepat buat "jobdesk tambahan" - permintaan mendadak dari client
     * (dokumentasi event, liputan kelas, dsb) yang tidak lewat proses
     * perencanaan bulanan biasa. Otomatis cari/buatkan ContentPlan bulan
     * berjalan buat client itu (item tetap butuh content_plan_id), langsung
     * masuk papan Production Workflow dengan flag is_urgent supaya menonjol,
     * dan PIC yang ditugaskan langsung dapat notifikasi karena sifatnya
     * mendesak.
     */
    public function quickCreateUrgent(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'title' => 'required|string|max:255',
            'brief' => 'nullable|string',
            'content_type_id' => 'nullable|exists:content_types,id',
            'platform_id' => 'nullable|exists:platforms,id',
            'deadline_at' => 'required|date',
            'pic_id' => 'nullable|exists:users,id',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $activePackage = $client->activePackage;

        abort_unless($activePackage, 422, 'Client ini belum punya paket aktif, tidak bisa ditambahkan konten.');

        $plan = ContentPlan::firstOrCreate(
            ['client_id' => $client->id, 'month' => now()->month, 'year' => now()->year],
            ['client_package_id' => $activePackage->id, 'created_by' => auth()->id(), 'status' => 'draft']
        );

        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'is_urgent' => true,
            'content_type_id' => $validated['content_type_id'] ?? null,
            'platform_id' => $validated['platform_id'] ?? null,
            'title' => $validated['title'],
            'brief' => $validated['brief'] ?? null,
            'deadline_at' => Carbon::parse($validated['deadline_at']),
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_pic_id' => $validated['pic_id'] ?? null,
            'current_status' => 'brief_ready',
            'is_overdue' => false,
        ]);

        if (!empty($validated['pic_id'])) {
            $item->assignments()->create([
                'user_id' => $validated['pic_id'],
                'assignment_role' => 'primary',
            ]);

            NotificationService::notify(
                User::findOrFail($validated['pic_id']),
                'Jobdesk tambahan baru buat kamu',
                'task',
                "\"{$item->title}\" ({$client->name}) - permintaan mendadak, deadline {$item->deadline_at->format('d M Y, H:i')}.",
                $item
            );
        }

        return redirect()->route('content-items.show', $item)
            ->with('status', 'Jobdesk tambahan berhasil ditambahkan ke Production Workflow.');
    }

    public function calendar(Request $request)
    {
        $selectedClientId = $request->input('client_id');
        $selectedTypeId = $request->input('content_type_id');
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $items = ContentItem::with(['client', 'contentType'])
            ->whereMonth('deadline_at', $month)
            ->whereYear('deadline_at', $year)
            ->when($selectedClientId, fn ($q) => $q->where('client_id', $selectedClientId))
            ->whereHas('contentType', fn ($q) => $q->whereIn('name', ['Video', 'Desain']))
            ->when($selectedTypeId, fn ($q) => $q->where('content_type_id', $selectedTypeId))
            ->orderBy('deadline_at')
            ->get();

        // date (Y-m-d) -> client_id -> Collection<ContentItem>
        $itemsByDateClient = $items
            ->groupBy(fn ($i) => $i->deadline_at->format('Y-m-d'))
            ->map(fn ($dayItems) => $dayItems->groupBy('client_id'));

        $clientOptions = Client::where('status', 'active')->get();
        $typeOptions = ContentType::whereIn('name', ['Video', 'Desain'])->get();

        return view('content-plan.calendar', compact(
            'itemsByDateClient', 'month', 'year',
            'clientOptions', 'typeOptions', 'selectedClientId', 'selectedTypeId'
        ));
    }
}