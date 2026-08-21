<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Platform;
use App\Models\TeamMember;
use App\Rules\AssignedClient;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
            'platformOptions'
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

        // client_package_id nullable (Langkah 1-2, audit Agustus 2026) -
        // NULL berarti "data paket belum tercatat", BUKAN "client tidak
        // boleh punya Content Plan". Client real (mis. hasil import Content
        // Planner) yang belum ada catatan paketnya TETAP boleh dibuatkan plan.
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'client_package_id' => $client->activePackage?->id,
            'created_by' => auth()->id(),
            'month' => $validated['month'],
            'year' => $validated['year'],
            'status' => 'draft',
        ]);

        return redirect()->route('content-plan.show', $plan)
            ->with('status', 'Content plan berhasil dibuat. Silakan tambahkan content item-nya.');
    }

    public function show(ContentPlan $contentPlan, \App\Services\PicResolver $picResolver)
    {
        $contentPlan->load(['client', 'clientPackage', 'creator', 'approver']);

        $items = $contentPlan->contentItems()
            ->with(['contentType', 'platform', 'workflow.currentPic', 'assignments.user', 'client'])
            ->orderBy('deadline_at')
            ->get();

        return view('content-plan.show', compact('contentPlan', 'items', 'picResolver'));
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
        // PIC operasional sekarang dari TeamMember (Langkah "Final Fix Batch"
        // A1) lewat team_member_client, BUKAN lagi User/assignedClients -
        // audit membuktikan hampir semua client real 0 assignedUsers padahal
        // punya 2-4 TeamMember real yang menanganinya, jadi dropdown lama
        // selalu kosong dan form ini nggak bisa disubmit sama sekali.
        $teamMemberOptions = $contentPlan->client->teamMembers()->orderBy('name')->get();

        return view('content-plan.create-item', compact('contentPlan', 'pillars', 'types', 'platforms', 'teamMemberOptions'));
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
            'team_member_id' => ['required', 'exists:team_members,id'],
            'estimated_duration_seconds' => 'nullable|integer',
            'estimated_slide_count' => 'nullable|integer',
        ]);

        // Jangan percaya ID dari form saja - TeamMember harus beneran
        // terkait client rencana ini (team_member_client), bukan cuma exists
        // di tabel team_members manapun.
        $teamMember = TeamMember::where('id', $validated['team_member_id'])
            ->whereHas('clients', fn ($q) => $q->where('clients.id', $contentPlan->client_id))
            ->first();
        abort_unless($teamMember, 422, 'PIC operasional yang dipilih tidak terkait dengan client ini.');

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
            // Selalu simpan identitas PIC operasional apa adanya, terlepas
            // dari apakah dia punya User/login atau tidak - PicResolver
            // pakai kolom ini buat display begitu current_pic_id kosong.
            'external_pic_name' => $teamMember->name,
            'external_pic_email' => $teamMember->email,
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_pic_id' => $teamMember->user_id,
            'current_status' => 'brief_ready',
            'is_overdue' => false,
        ]);

        // TeamMember dengan akun sistem -> ikutkan alur assignment existing
        // (dipakai kapasitas/reassign/notifikasi User-based). TeamMember
        // tanpa akun -> JANGAN bikin User/ContentItemAssignment palsu,
        // external_pic_* di atas sudah cukup buat PicResolver menampilkannya.
        if ($teamMember->user_id) {
            $item->assignments()->create([
                'user_id' => $teamMember->user_id,
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
            'team_member_id' => ['nullable', 'exists:team_members,id'],
        ]);

        $client = Client::findOrFail($validated['client_id']);

        // Sama pola dengan storeItem() - PIC operasional dari TeamMember,
        // bukan User/assignedClients (Langkah "Final QA - QuickCreateUrgent
        // fix"). Optional di sini (beda dari storeItem yang wajib), tapi
        // kalau diisi WAJIB terbukti terkait client ini - jangan percaya ID
        // dari form saja.
        $teamMember = null;
        if (! empty($validated['team_member_id'])) {
            $teamMember = TeamMember::where('id', $validated['team_member_id'])
                ->whereHas('clients', fn ($q) => $q->where('clients.id', $client->id))
                ->first();
            abort_unless($teamMember, 422, 'PIC operasional yang dipilih tidak terkait dengan client ini.');
        }

        // client_package_id nullable (Langkah 1-2) - sama seperti store(),
        // paket belum tercatat tidak lagi memblokir penambahan konten.
        $plan = ContentPlan::firstOrCreate(
            ['client_id' => $client->id, 'month' => now()->month, 'year' => now()->year],
            ['client_package_id' => $client->activePackage?->id, 'created_by' => auth()->id(), 'status' => 'draft']
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
            'external_pic_name' => $teamMember?->name,
            'external_pic_email' => $teamMember?->email,
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_pic_id' => $teamMember?->user_id,
            'current_status' => 'brief_ready',
            'is_overdue' => false,
        ]);

        if ($teamMember?->user_id) {
            $item->assignments()->create([
                'user_id' => $teamMember->user_id,
                'assignment_role' => 'primary',
            ]);

            NotificationService::notify(
                $teamMember->user,
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