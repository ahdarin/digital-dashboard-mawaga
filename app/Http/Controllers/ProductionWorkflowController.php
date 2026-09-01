<?php
namespace App\Http\Controllers;

use App\Exceptions\WorkflowTransitionException;
use App\Models\ContentItem;
use App\Services\PinService;
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

    public function index(Request $request, PinService $pinService, \App\Services\PicResolver $picResolver)
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
            // Item Draf belum masuk workflow produksi sama sekali - belum
            // dikirim SMO ke produksi (lihat WorkflowStatusService::
            // releaseToProduction()), jadi tidak boleh muncul di board
            // maupun tampilan List sekalipun tidak ada di $statuses.
            ->whereHas('workflow', fn ($q) => $q->where('current_status', '!=', 'draft'));

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

        $allItems = $itemsQuery->get();
        $items = $allItems->groupBy(fn($item) => $item->workflow->current_status);

        // Pin personal (lihat PinService) - dihitung duluan di sini karena
        // dipakai buat ngapungin kartu ke atas di tiap kolom Kanban, selain
        // buat sort tampilan List di bawah.
        $pinnedIds = $pinService->pinnedContentItemIds($user);

        $board = [];
        foreach ($this->statuses as $status) {
            // Default: terbaru dipindahkan/diupdate di atas, biar kartu yang
            // baru saja di-drag kelihatan langsung tanpa harus cari-cari.
            $columnItems = $items->get($status, collect())
                ->sortByDesc(fn ($item) => $item->workflow->updated_at)
                ->values();
            // Kartu yang di-pin diapungkan ke atas kolom, di atas urutan
            // default-nya - sama seperti tampilan List, "fokus saya" harus
            // tetap kelihatan duluan di kolom manapun dia berada. sortBy
            // stabil (PHP 8+) jadi urutan terbaru-diupdate di atas tetap
            // terjaga di dalam masing-masing grup pinned/tidak.
            if ($pinnedIds->isNotEmpty()) {
                $columnItems = $columnItems->sortBy(fn ($item) => $pinnedIds->contains($item->id) ? 0 : 1)->values();
            }
            $columnItems->each(fn ($item, $i) => $item->boardOrder = $i);
            $board[$status] = $columnItems;
        }

        // Tampilan List - alternatif Kanban buat scan cepat lintas status.
        // Default: deadline paling dekat dulu, biar yang paling mendesak
        // ketemu duluan tanpa loncat-loncat kolom. Bisa di-sort ulang per
        // kolom lewat query string sort/dir.
        $sortColumn = $request->input('sort', 'deadline');
        $sortDir = $request->input('dir', 'asc') === 'desc' ? 'desc' : 'asc';

        $sortKeys = [
            'title' => fn ($item) => strtolower($item->title),
            'client' => fn ($item) => strtolower($item->client->name ?? ''),
            'type' => fn ($item) => strtolower($item->contentType->name ?? ''),
            // Bukan alfabet - urut sesuai tahapan workflow yang sudah
            // disusun (brief_ready -> ... -> cancelled), sesuai statuses.
            // array_search() balikin `false` (bukan -1/null) kalau status-nya tidak
            // dikenal - di sort comparator `false` ke-cast jadi 0, jadi status asing
            // malah nongol di paling atas (posisi brief_ready). Pakai `!== false` di
            // sini, BUKAN `?: PHP_INT_MAX`, karena brief_ready sendiri index-nya 0
            // (falsy) - short-circuit `?:` bakal salah nganggep itu "tidak ketemu".
            'status' => function ($item) {
                $index = array_search($item->workflow->current_status, $this->statuses);
                return $index !== false ? $index : PHP_INT_MAX;
            },
            // Resolver sama yang dipakai buat display (Langkah "TeamMember <->
            // Legacy PIC") - sort by PIC ikut nangkep legacy PIC (Uun dkk),
            // bukan cuma yang punya User beneran.
            'pic' => fn ($item) => strtolower($picResolver->resolve($item)['name'] ?? ''),
            'deadline' => fn ($item) => $item->deadline_at,
            'risk' => fn ($item) => $item->latestDelayRisk->risk_score ?? -1,
        ];

        $sortKey = $sortKeys[$sortColumn] ?? $sortKeys['deadline'];
        $listItems = ($sortDir === 'desc' ? $allItems->sortByDesc($sortKey) : $allItems->sortBy($sortKey))->values();

        // Filter status cuma buat tampilan List - Kanban sudah tersegmentasi
        // per kolom status jadi filter ini nggak relevan di sana.
        $selectedStatus = $request->input('status');
        if ($selectedStatus) {
            $listItems = $listItems->filter(fn ($item) => $item->workflow->current_status === $selectedStatus)->values();
        }

        // Pin personal (lihat PinService, $pinnedIds sudah dihitung di atas)
        // selalu diapungkan ke atas terlepas dari kolom sort yang aktif -
        // "fokus saya" harus tetap kelihatan duluan walau lagi sort by
        // risiko/deadline dsb. sortBy stabil (PHP 8+) jadi urutan dari
        // $sortKey di atas tetap terjaga di dalam masing-masing grup
        // pinned/tidak.
        if ($pinnedIds->isNotEmpty()) {
            $listItems = $listItems->sortBy(fn ($item) => $pinnedIds->contains($item->id) ? 0 : 1)->values();
        }

        // Sort/filter di atas jalan di memori (bukan query builder, karena sort
        // "status" ngikutin urutan tahapan workflow custom, bukan alfabet) - jadi
        // paginate juga manual pakai LengthAwarePaginator, bukan ->paginate() di
        // query. withPath/withQueryString biar link halaman ikut bawa sort/dir/
        // filter yang lagi aktif.
        $listPage = (int) $request->input('page', 1);
        $listPerPage = 20;
        $listItems = new \Illuminate\Pagination\LengthAwarePaginator(
            $listItems->forPage($listPage, $listPerPage)->values(),
            $listItems->count(),
            $listPerPage,
            $listPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Daftar client untuk dropdown filter (hanya yang relevan buat user ini)
        $clientOptions = $user->canSeeAllClients()
            ? \App\Models\Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        return view('production-workflow.index', [
            'tab' => 'board',
            'view' => $request->input('view', 'board') === 'list' ? 'list' : 'board',
            // Dipakai buat bedain "belum milih view apa-apa" dari "eksplisit
            // minta board" - view-nya cuma jalanin script redirect ke List
            // di mobile kalau belum ada pilihan eksplisit (lihat script di
            // awal production-workflow/index.blade.php).
            'viewExplicit' => $request->has('view'),
            'board' => $board,
            'listItems' => $listItems,
            'pinnedIds' => $pinnedIds,
            'sortColumn' => $sortColumn,
            'sortDir' => $sortDir,
            'statuses' => $this->statuses,
            'clientOptions' => $clientOptions,
            'selectedClientId' => $request->input('client_id'),
            'selectedMonth' => $request->input('month'),
            'selectedStatus' => $selectedStatus,
            'canUpdateWorkflow' => $user->hasPermissionTo('workflow', 'update'),
            'canCreateContent' => $user->hasPermissionTo('content_plan', 'create'),
            'picResolver' => $picResolver,
            'contentTypeOptions' => \App\Models\ContentType::all(),
            'platformOptions' => \App\Models\Platform::all(),
            'picOptions' => \App\Models\User::query()->where('status', 'active')->with('assignedClients:id')->get(),
        ]);
    }

    /**
     * Tab "Revisi" - dulu halaman terpisah (Revision Log), sekarang
     * digabung ke Produksi (Tahap 6.1) supaya tim nggak perlu loncat menu
     * buat lihat status revisi vs papan produksi.
     */
    private function revisionsTab(Request $request, $user)
    {
        $query = \App\Models\ContentRevision::with(['contentItem.client', 'requestedByUser', 'requestedByClient'])
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
            'picOptions' => \App\Models\User::query()->where('status', 'active')->with('assignedClients:id')->get(),
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
            'picOptions' => \App\Models\User::query()->where('status', 'active')->with('assignedClients:id')->get(),
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
            'content_file_link' => 'nullable|url|max:2048',
            'platform_id' => 'nullable|exists:platforms,id',
            'published_at' => 'nullable|date',
            'post_url' => 'nullable|url',
            'caption_final' => 'nullable|string',
            'publications' => 'nullable|array',
            'publications.*.platform_id' => 'required_with:publications|exists:platforms,id',
            'publications.*.published_at' => 'required_with:publications|date',
            'publications.*.post_url' => 'nullable|url',
            'publications.*.caption_final' => 'nullable|string',
        ]);

        $toStatus = $validated['to_status'];
        unset($validated['to_status']);

        try {
            $workflowStatusService->transition($contentItem, $toStatus, $validated, $request->user());
        } catch (WorkflowTransitionException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'status' => $toStatus,
            'scheduled_upload_at' => $toStatus === 'scheduled' ? $contentItem->scheduled_upload_at?->format('d M Y, H:i') : null,
        ]);
    }
}
