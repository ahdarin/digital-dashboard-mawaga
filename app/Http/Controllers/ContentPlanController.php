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
use App\Rules\AssignedClient;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ContentPlanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $selectedClientId = $request->input('client_id');
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $view = $request->input('view', 'table'); // table | calendar

        // Batasi hanya client yang di-assign, kecuali CEO/Manager - samakan
        // dengan pola scoping di ProductionWorkflowController.
        $assignedClientIds = $user->canSeeAllClients() ? null : $user->assignedClients()->pluck('clients.id');

        // ---- Data untuk Table View (logic lama, tidak berubah) ----
        $plans = ContentPlan::with(['client', 'clientPackage'])
            ->withCount('contentItems')
            ->when($selectedClientId, fn ($q) => $q->where('client_id', $selectedClientId))
            ->when($assignedClientIds !== null, fn ($q) => $q->whereIn('client_id', $assignedClientIds))
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

        $clientOptions = $user->canSeeAllClients()
            ? Client::where('status', 'active')->with('activePackage')->get()
            : $user->assignedClients()->where('status', 'active')->with('activePackage')->get();

        // ---- Data untuk Calendar View ----
        $itemsByDateClient = collect();
        $typeOptions = collect();
        $selectedType = $request->input('type', 'all');

        if ($view === 'calendar') {

            $allowedTypes = ['Video', 'Desain'];

            $calendarItems = ContentItem::with(['client', 'contentType'])
                ->whereMonth('deadline_at', $month)
                ->whereYear('deadline_at', $year)

                ->whereHas('contentType', function ($q) use ($allowedTypes) {
                    $q->whereIn('name', $allowedTypes);
                })

                ->when($assignedClientIds !== null, fn ($q) => $q->whereIn('client_id', $assignedClientIds))

                ->when($selectedClientId, function ($q) use ($selectedClientId) {
                    $q->where('client_id', $selectedClientId);
                })

                ->when($selectedType !== 'all', function ($q) use ($selectedType) {
                    $q->whereHas('contentType', function ($query) use ($selectedType) {
                        $query->where('name', $selectedType);
                    });
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
            'contentTypeOptions',
            'platformOptions',
            'picOptions'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id', new AssignedClient],
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

    public function show(ContentPlan $contentPlan)
    {
        $contentPlan->load(['client', 'clientPackage', 'creator', 'approver']);

        $items = $contentPlan->contentItems()
            ->with(['contentType', 'platform', 'workflow', 'assignments.user', 'client'])
            ->orderBy('deadline_at')
            ->get();

        return view('content-plan.show', compact('contentPlan', 'items'));
    }

    /**
     * "Ajukan Rencana" - pindahkan draft ke antrean persetujuan (pending).
     * Sebelum ini ditambahkan, draft bisa langsung di-approve tanpa pernah
     * diajukan, jadi langkah pengajuan ini tidak pernah benar-benar dilewati.
     */
    public function submit(ContentPlan $contentPlan)
    {
        abort_unless($contentPlan->status === 'draft', 422, 'Cuma rencana berstatus Draf yang bisa diajukan.');

        $contentPlan->update(['status' => 'pending']);

        NotificationService::notifyPlanApprovers($contentPlan);

        return back()->with('status', 'Rencana berhasil diajukan, menunggu persetujuan.');
    }

    public function approve(ContentPlan $contentPlan)
    {
        abort_unless($contentPlan->status === 'pending', 422, 'Cuma rencana yang sudah diajukan yang bisa disetujui.');

        $contentPlan->update(['status' => 'approved', 'approved_by' => auth()->id()]);

        return back()->with('status', 'Content plan disetujui - tim bisa mulai menambahkan content item sesuai rencana ini.');
    }

    public function reject(ContentPlan $contentPlan)
    {
        abort_unless($contentPlan->status === 'pending', 422, 'Cuma rencana yang sudah diajukan yang bisa ditolak.');

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
            'pic_id' => ['required', Rule::exists('users', 'id')->whereNull('client_id')->where('status', 'active')],
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
            ->with('status', 'Content item berhasil ditambahkan - muncul di papan Produksi dengan status Siap Dikerjakan.');
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
            'client_id' => ['required', 'exists:clients,id', new AssignedClient],
            'title' => 'required|string|max:255',
            'brief' => 'nullable|string',
            'content_type_id' => 'nullable|exists:content_types,id',
            'platform_id' => 'nullable|exists:platforms,id',
            'deadline_at' => 'required|date',
            'pic_id' => ['nullable', Rule::exists('users', 'id')->whereNull('client_id')->where('status', 'active')],
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

}