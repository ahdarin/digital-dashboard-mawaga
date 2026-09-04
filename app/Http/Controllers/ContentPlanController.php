<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
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
        $selectedStatus = $request->input('status', 'all');

        if ($view === 'calendar') {

            $allowedTypes = ['Video', 'Desain'];

            $calendarItems = ContentItem::with(['client', 'contentType', 'workflow'])
                ->whereMonth('deadline_at', $month)
                ->whereYear('deadline_at', $year)

                ->whereHas('contentType', function ($q) use ($allowedTypes) {
                    $q->whereIn('name', $allowedTypes);
                })

                // Draf belum masuk workflow produksi - jangan muncul di
                // kalender (deadline_at-nya masih placeholder sampai SMO
                // mengisi upload_deadline_at pasca-approve).
                ->whereHas('workflow', fn ($q) => $q->where('current_status', '!=', 'draft'))

                ->when($assignedClientIds !== null, fn ($q) => $q->whereIn('client_id', $assignedClientIds))

                ->when($selectedClientId, function ($q) use ($selectedClientId) {
                    $q->where('client_id', $selectedClientId);
                })

                ->when($selectedType !== 'all', function ($q) use ($selectedType) {
                    $q->whereHas('contentType', function ($query) use ($selectedType) {
                        $query->where('name', $selectedType);
                    });
                })

                // "Sudah Dikerjakan" = current_status uploaded, "Telat Dikerjakan" =
                // is_overdue (dan belum uploaded/cancelled), "Belum Dikerjakan" =
                // sisanya (masih berjalan, belum lewat deadline).
                ->when($selectedStatus === 'done', function ($q) {
                    $q->whereHas('workflow', fn ($query) => $query->where('current_status', 'uploaded'));
                })
                ->when($selectedStatus === 'late', function ($q) {
                    $q->whereHas('workflow', fn ($query) => $query->where('is_overdue', true)
                        ->where('current_status', '!=', 'uploaded'));
                })
                ->when($selectedStatus === 'not_done', function ($q) {
                    $q->whereHas('workflow', fn ($query) => $query->where('is_overdue', false)
                        ->where('current_status', '!=', 'uploaded'));
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
            'selectedStatus',
            'contentTypeOptions',
            'platformOptions'
        ));
    }

    public function store(Request $request, \App\Services\ContentPlanItemGeneratorService $itemGenerator)
    {
        // Bag terpisah ('createContentPlan') - modal "Jobdesk Tambahan" ada
        // di sidebar GLOBAL (semua halaman internal), jadi kalau validasi
        // di sini pakai bag default, urgentOpen (yang juga cek $errors->any())
        // akan ikut kebuka setiap kali form Content Plan ini gagal validasi.
        $validated = $request->validateWithBag('createContentPlan', [
            'client_id' => ['required', 'exists:clients,id', new AssignedClient],
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020',
        ]);

        $client = Client::findOrFail($validated['client_id']);

        // Sejak slot konten digenerate otomatis dari kuota paket (bukan lagi
        // ditambah manual satu-satu), client WAJIB punya paket aktif dulu -
        // tanpa paket tidak ada cara lain buat mengisi Content Plan-nya sama
        // sekali (form "Tambah Konten" sudah dihapus). Balikin sebagai error
        // validasi (bukan abort/halaman 422) supaya modal "Buat Rencana
        // Konten Baru" tetap terbuka dengan pesan errornya, bukan melempar
        // user ke halaman error terpisah.
        $package = $client->activePackage;
        if (! $package) {
            return back()->withErrors([
                'client_id' => "Client \"{$client->name}\" belum punya paket aktif. Atur paket klien dulu di Kelola Klien sebelum membuat Content Plan - slot konten digenerate otomatis dari kuota paket.",
            ], 'createContentPlan')->withInput();
        }

        // Satu client cuma boleh punya SATU rencana per bulan. Dulu rencana
        // ganda relatif tidak berbahaya (isinya diisi manual satu-satu),
        // tapi sejak slot digenerate otomatis dari kuota paket, rencana
        // kedua untuk bulan yang sama langsung menambah satu set penuh slot
        // Draf baru - kuota "Target vs Realisasi" di halaman Rencana Konten
        // jadi berlipat dan tim melihat dua kartu bulan yang sama tanpa tahu
        // mana yang dipakai. Tidak ada unique index di tabelnya, jadi
        // dijaga di sini.
        $existing = ContentPlan::where('client_id', $client->id)
            ->where('month', $validated['month'])
            ->where('year', $validated['year'])
            ->first();

        if ($existing) {
            $monthLabel = Carbon::create($existing->year, $existing->month, 1)->translatedFormat('F Y');

            return back()->withErrors([
                'client_id' => "Rencana konten {$client->name} untuk {$monthLabel} sudah ada - buka rencana yang sudah ada itu, jangan membuat yang kedua.",
            ], 'createContentPlan')->withInput();
        }

        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'client_package_id' => $package->id,
            'created_by' => auth()->id(),
            'month' => $validated['month'],
            'year' => $validated['year'],
            'status' => 'draft',
        ]);

        $items = $itemGenerator->generate($plan, $package);

        return redirect()->route('content-plan.show', $plan)
            ->with('status', "Content plan berhasil dibuat - {$items->count()} slot konten (sesuai paket \"{$package->package_name_snapshot}\") sudah digenerate berstatus Draf. Buka tiap konten untuk mulai isi brief.");
    }

    public function show(ContentPlan $contentPlan, \App\Services\PicResolver $picResolver)
    {
        $contentPlan->load(['client', 'clientPackage', 'creator', 'approver', 'statusLogs.changedByUser']);

        $items = $contentPlan->contentItems()
            ->with(['contentType', 'contentFormat', 'platform', 'workflow.currentPic', 'assignments.user', 'client', 'contentBriefDraft'])
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

        $incomplete = $contentPlan->contentItems()->with('contentBriefDraft')->get()
            ->reject(fn ($item) => $item->hasCompleteBrief());
        abort_if($incomplete->isNotEmpty(), 422, "Masih ada {$incomplete->count()} slot konten yang briefnya belum lengkap ({$incomplete->pluck('provisional_code')->filter()->implode(', ')}) - lengkapi dulu sebelum mengajukan rencana.");

        $fromStatus = $contentPlan->status;
        $contentPlan->update(['status' => 'pending']);
        $this->logPlanStatus($contentPlan, $fromStatus, 'pending');

        NotificationService::notifyPlanApprovers($contentPlan);

        return back()->with('status', 'Rencana berhasil diajukan, menunggu persetujuan.');
    }

    public function approve(ContentPlan $contentPlan)
    {
        abort_unless($contentPlan->status === 'pending', 422, 'Cuma rencana yang sudah diajukan yang bisa disetujui.');

        $contentPlan->update(['status' => 'approved', 'approved_by' => auth()->id()]);
        $this->logPlanStatus($contentPlan, 'pending', 'approved');

        return back()->with('status', 'Content plan disetujui - tim bisa mulai menambahkan content item sesuai rencana ini.');
    }

    public function reject(ContentPlan $contentPlan, Request $request)
    {
        abort_unless($contentPlan->status === 'pending', 422, 'Cuma rencana yang sudah diajukan yang bisa ditolak.');

        // KI-13 - catatan penolakan WAJIB diisi supaya pembuat rencana tahu
        // apa yang harus diperbaiki sebelum mengajukan ulang (lihat reopen()).
        $validated = $request->validate([
            'rejection_note' => 'required|string|max:2000',
        ]);

        $contentPlan->update(['status' => 'rejected', 'approved_by' => auth()->id()]);
        $this->logPlanStatus($contentPlan, 'pending', 'rejected', $validated['rejection_note']);

        return back()->with('status', 'Content plan ditolak, silakan revisi.');
    }

    /**
     * KI-13 - satu-satunya jalur balik dari Ditolak: mengembalikan rencana ke
     * Draf supaya bisa diperbaiki lalu diajukan ulang lewat submit(). Status
     * "rejected" dan catatan penolakannya TETAP tercatat di statusLogs (tidak
     * dihapus/ditimpa) - reopen cuma menambah entri baru, bukan mengedit yang lama.
     */
    public function reopen(ContentPlan $contentPlan)
    {
        abort_unless($contentPlan->status === 'rejected', 422, 'Cuma rencana berstatus Ditolak yang bisa dikembalikan ke Draf.');

        $contentPlan->update(['status' => 'draft']);
        $this->logPlanStatus($contentPlan, 'rejected', 'draft');

        return back()->with('status', 'Rencana dikembalikan ke Draf. Perbaiki lalu ajukan ulang kalau sudah siap.');
    }

    /**
     * Layar SMO isi tanggal upload per item pasca-approve. Deadline produksi
     * (deadline_at) TIDAK diisi manual di sini - dihitung otomatis 2 hari
     * sebelum upload_deadline_at sesuai proses bisnis 523 studio (lihat
     * updateDeadlines()).
     */
    public function deadlines(ContentPlan $contentPlan)
    {
        abort_unless($contentPlan->status === 'approved', 422, 'Cuma rencana yang sudah disetujui yang bisa diatur deadline-nya.');

        $items = $contentPlan->contentItems()
            ->with('contentType')
            ->whereHas('workflow', fn ($q) => $q->where('current_status', 'draft'))
            ->orderBy('provisional_code')
            ->get();

        return view('content-plan.deadlines', compact('contentPlan', 'items'));
    }

    public function updateDeadlines(ContentPlan $contentPlan, Request $request)
    {
        abort_unless($contentPlan->status === 'approved', 422, 'Cuma rencana yang sudah disetujui yang bisa diatur deadline-nya.');

        $validated = $request->validate([
            'upload_deadline_at' => 'required|array',
            'upload_deadline_at.*' => 'required|date',
        ]);

        $items = $contentPlan->contentItems()->whereIn('id', array_keys($validated['upload_deadline_at']))->get();

        foreach ($items as $item) {
            $uploadDeadline = \Illuminate\Support\Carbon::parse($validated['upload_deadline_at'][$item->id]);
            $item->update([
                'upload_deadline_at' => $uploadDeadline,
                // Proses bisnis 523 studio - deadline pengerjaan otomatis 2
                // hari sebelum deadline upload, bukan diisi manual terpisah.
                'deadline_at' => $uploadDeadline->copy()->subDays(2),
            ]);
        }

        return redirect()->route('content-plan.deadlines', $contentPlan)
            ->with('status', 'Deadline upload berhasil disimpan untuk ' . $items->count() . ' item.');
    }

    /**
     * "Kirim ke Produksi" - aksi batch SMO, satu-satunya jalan keluar dari
     * status Draf (lihat WorkflowStatusService::releaseToProduction()).
     */
    public function sendToProduction(ContentPlan $contentPlan, \App\Services\WorkflowStatusService $workflowStatusService)
    {
        abort_unless($contentPlan->status === 'approved', 422, 'Cuma rencana yang sudah disetujui yang bisa dikirim ke produksi.');

        $missingDeadline = $contentPlan->contentItems()
            ->whereHas('workflow', fn ($q) => $q->where('current_status', 'draft'))
            ->whereNull('upload_deadline_at')
            ->count();
        abort_if($missingDeadline > 0, 422, "Masih ada {$missingDeadline} item yang belum diisi deadline upload-nya.");

        try {
            $count = $workflowStatusService->releaseToProduction($contentPlan, auth()->user());
        } catch (\App\Exceptions\WorkflowTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('content-plan.show', $contentPlan)
            ->with('status', "{$count} item berhasil dikirim ke produksi - status berpindah ke Brief Ready dan briefnya sudah dikunci.");
    }

    private function logPlanStatus(ContentPlan $contentPlan, ?string $from, string $to, ?string $notes = null): void
    {
        $contentPlan->statusLogs()->create([
            'changed_by_user_id' => auth()->id(),
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'changed_at' => now(),
        ]);
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
        // Bag terpisah ('urgentContent') - modal ini sendiri ada di sidebar
        // GLOBAL (semua halaman internal), jadi kalau validasi gagal di form
        // lain manapun (mis. Edit Role di Kelola Pengguna) tapi bag-nya
        // default/sama, urgentOpen ikut kebuka juga walau bukan form ini
        // yang gagal - lihat urgentOpen di urgent-content-modal.blade.php.
        $validated = $request->validateWithBag('urgentContent', [
            'client_id' => ['required', 'exists:clients,id', new AssignedClient],
            'title' => 'required|string|max:255',
            'brief' => 'nullable|string',
            'content_type_id' => 'nullable|exists:content_types,id',
            'platform_id' => 'nullable|exists:platforms,id',
            'deadline_at' => 'required|date',
            'pic_id' => ['nullable', Rule::exists('users', 'id')->where('status', 'active')],
        ]);

        $client = Client::findOrFail($validated['client_id']);

        // Sama pola dengan storeItem() - PIC operasional dari User lewat
        // user_client_assignments. Optional di sini (beda dari storeItem
        // yang wajib), tapi kalau diisi WAJIB terbukti terkait client ini -
        // jangan percaya ID dari form saja.
        $picUser = null;
        if (! empty($validated['pic_id'])) {
            $picUser = User::where('id', $validated['pic_id'])
                ->whereHas('assignedClients', fn ($q) => $q->where('clients.id', $client->id))
                ->first();
            abort_unless($picUser, 422, 'Penanggung Jawab yang dipilih tidak terkait dengan client ini.');
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
            'external_pic_name' => $picUser?->name,
            'external_pic_email' => $picUser?->email,
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_pic_id' => $picUser?->id,
            'current_status' => 'brief_ready',
            'is_overdue' => false,
        ]);

        if ($picUser) {
            $item->assignments()->create([
                'user_id' => $picUser->id,
                'assignment_role' => 'primary',
            ]);

            NotificationService::notify(
                $picUser,
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