<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowTransitionException;
use App\Models\ApiIntegration;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentPublication;
use App\Models\InstagramMediaSnapshot;
use App\Models\TikTokVideoSnapshot;
use App\Services\HistoricalContentMatcher;
use App\Services\WorkflowStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        return back()->with('status', 'Publikasi berhasil dicatat - status konten dipindahkan ke Sudah Tayang.');
    }

    /**
     * Daftar media Instagram akun ini yang BELUM ke-link ke content_item
     * manapun - query LOCAL (instagram_media_snapshots), BUKAN live-fetch ke
     * API tiap halaman dibuka (lihat diskusi optimasi sync). Snapshot ini
     * diisi oleh SyncInstagramAnalytics setiap kali sync jalan (default 2
     * bulan atau historical per bulan) - buka halaman ini cuma query DB
     * biasa, cepat berapa pun besar akunnya.
     *
     * API cuma kepakai lewat 3 jalur: sync (default/historical), tombol
     * Sync Now, dan connect/reconnect OAuth - bukan di sini.
     */
    public function unmatchedInstagram(Request $request, ApiIntegration $apiIntegration, HistoricalContentMatcher $historicalMatcher)
    {
        abort_unless($apiIntegration->platform->name === 'Instagram', 404);

        $snapshots = InstagramMediaSnapshot::where('api_integration_id', $apiIntegration->id)
            ->whereIn('match_status', ['unmatched', 'ambiguous'])
            ->orderByDesc('published_at')
            ->get();

        $suggestions = $this->buildHistoricalSuggestions($apiIntegration, $snapshots, $historicalMatcher);

        $unmatched = $snapshots->map(fn ($s) => [
            'id' => $s->external_post_id,
            'caption' => Str::limit($s->caption ?? '(tanpa caption)', 120),
            'timestamp' => $s->published_at,
            'permalink' => $s->permalink,
            'media_type' => $s->media_type,
            'thumbnail_url' => $s->thumbnail_url,
            'ambiguous_reason' => $s->match_status === 'ambiguous' ? "Ada beberapa kandidat content item, perlu dipilih manual." : null,
            'suggestion' => $suggestions[$s->id] ?? null,
        ])->all();

        $lastFetchedAt = InstagramMediaSnapshot::where('api_integration_id', $apiIntegration->id)
            ->max('last_fetched_at');
        $totalSnapshotted = InstagramMediaSnapshot::where('api_integration_id', $apiIntegration->id)->count();

        $contentItemOptions = ContentItem::where('client_id', $apiIntegration->client_id)
            ->orderByDesc('id')
            ->get(['id', 'title']);

        return view('publishing-tracker.instagram-unmatched', [
            'apiIntegration' => $apiIntegration->load('client'),
            'unmatched' => $unmatched,
            'totalSnapshotted' => $totalSnapshotted,
            'lastFetchedAt' => $lastFetchedAt ? Carbon::parse($lastFetchedAt) : null,
            'contentItemOptions' => $contentItemOptions,
        ]);
    }

    /**
     * Saran (BUKAN auto-link) dari HistoricalContentMatcher - read-only,
     * cuma dipakai buat TAMPILAN di halaman ini. Scope SENGAJA dipersempit ke
     * ContentItem hasil "Content Planner Import" (import_source=
     * content_planner_xlsx) yang belum punya ContentPublication Instagram -
     * TIDAK menyentuh ContentPublicationMatcher/tolerance ±120 menit
     * operasional sama sekali, dan TIDAK pernah membuat/mengubah baris
     * apapun di sini. Staff tetap wajib klik "Simpan" manual buat link
     * beneran, lewat form existing (route publishing-tracker.instagram.link)
     * yang sudah ada jauh sebelum ini.
     */
    private function buildHistoricalSuggestions(ApiIntegration $apiIntegration, $snapshots, HistoricalContentMatcher $historicalMatcher): array
    {
        $plannerItems = ContentItem::where('client_id', $apiIntegration->client_id)
            ->where('import_source', 'content_planner_xlsx')
            ->whereDoesntHave('publications', fn ($q) => $q->where('platform_id', $apiIntegration->platform_id))
            ->with('contentType')
            ->get();

        if ($plannerItems->isEmpty()) {
            return [];
        }

        $candidatesBySnapshot = [];
        foreach ($snapshots as $snapshot) {
            $candidatesBySnapshot[$snapshot->id] = $historicalMatcher->candidatesForSnapshot($snapshot, $plannerItems);
        }
        $candidatesBySnapshot = $historicalMatcher->applyUniqueDateBonus($candidatesBySnapshot, $plannerItems, $snapshots);

        $suggestions = [];
        foreach ($snapshots as $snapshot) {
            $candidates = $candidatesBySnapshot[$snapshot->id];
            if (empty($candidates)) {
                continue;
            }

            $classification = $historicalMatcher->classify($candidates);
            if ($classification['status'] === 'NO_MATCH') {
                continue;
            }

            $suggestions[$snapshot->id] = [
                'classification' => $classification['status'],
                'reason' => $classification['reason'],
                'candidates' => array_map(fn ($c) => [
                    'content_item_id' => $c['item']->id,
                    'content_item_title' => $c['item']->title,
                    'diff_days' => $c['diff_days'],
                    'similarity' => $c['similarity'],
                    'format_compatible' => $c['format_score'] > 0,
                    'score' => $c['score'],
                ], array_slice($candidates, 0, 3)),
            ];
        }

        return $suggestions;
    }

    /**
     * Manual link (Langkah 16/17 diskusi Instagram matching): hubungkan 1
     * media Instagram ke 1 content_item pilihan staff. Validasi client
     * WAJIB dobel - route model binding {apiIntegration} sudah dijaga
     * client.scope di route, di sini ditambah pengecekan eksplisit content
     * item beneran milik client yang sama dengan integration-nya (bukan
     * cuma client yang staff itu punya akses), biar nggak ada celah link
     * lintas client biarpun staff-nya kebetulan pegang akses ke dua-duanya.
     */
    public function linkInstagramMedia(Request $request, ApiIntegration $apiIntegration)
    {
        abort_unless($apiIntegration->platform->name === 'Instagram', 404);

        $validated = $request->validate([
            'content_item_id' => ['required', 'integer'],
            'external_post_id' => ['required', 'string'],
            'permalink' => ['nullable', 'url'],
            'caption' => ['nullable', 'string'],
            'timestamp' => ['nullable', 'date'],
        ]);

        $contentItem = ContentItem::where('id', $validated['content_item_id'])
            ->where('client_id', $apiIntegration->client_id)
            ->first();

        if (! $contentItem) {
            return back()->with('import_error', 'Content item tidak ditemukan atau bukan milik client yang sama dengan integration ini.');
        }

        $alreadyLinkedElsewhere = ContentPublication::where('platform_id', $apiIntegration->platform_id)
            ->where('external_post_id', $validated['external_post_id'])
            ->where('content_item_id', '!=', $contentItem->id)
            ->exists();

        if ($alreadyLinkedElsewhere) {
            return back()->with('import_error', 'Media Instagram ini sudah terhubung ke content item lain.');
        }

        try {
            DB::transaction(function () use ($validated, $contentItem, $apiIntegration) {
                $publication = ContentPublication::updateOrCreate(
                    ['content_item_id' => $contentItem->id, 'platform_id' => $apiIntegration->platform_id],
                    [
                        'external_post_id' => $validated['external_post_id'],
                        'api_integration_id' => $apiIntegration->id,
                        'published_by' => auth()->id(),
                        'published_at' => $validated['timestamp'] ?? now(),
                        'post_url' => $validated['permalink'] ?? null,
                        'caption_final' => $validated['caption'] ?? null,
                    ]
                );

                // Update snapshot-nya juga (kalau ada) biar langsung hilang
                // dari daftar unmatched tanpa perlu nunggu sync berikutnya.
                $snapshot = InstagramMediaSnapshot::where('api_integration_id', $apiIntegration->id)
                    ->where('external_post_id', $validated['external_post_id'])
                    ->first();

                if ($snapshot) {
                    $snapshot->update(['match_status' => 'matched', 'content_publication_id' => $publication->id]);

                    // content_metrics baris ini SUDAH ADA dari sync sebelumnya
                    // (dibuat via instagram_media_snapshot_id, lihat
                    // InstagramAnalyticsSyncService::saveMetric()) -
                    // UPDATE content_item_id-nya, JANGAN bikin baris baru.
                    ContentMetric::where('instagram_media_snapshot_id', $snapshot->id)
                        ->update(['content_item_id' => $contentItem->id, 'client_id' => $apiIntegration->client_id]);
                }
            });
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Manual link Instagram gagal (kemungkinan race condition unique constraint)', ['error' => $e->getMessage()]);

            return back()->with('import_error', 'Gagal menghubungkan - media ini kemungkinan baru saja terhubung dari proses lain.');
        }

        return back()->with('import_success', "Media berhasil dihubungkan ke \"{$contentItem->title}\".");
    }

    /**
     * MIRROR unmatchedInstagram() - TAPI TANPA "suggestion" (HistoricalContentMatcher
     * cuma buat rekonsiliasi data Content Planner Excel LAMA yang memang
     * Instagram-only, tidak ada padanan histori TikTok - lihat audit
     * arsitektur di docs/TIKTOK_INTEGRATION.md Section "Content Matching").
     * Selebihnya pola identik: daftar video unmatched/ambiguous, manual link
     * lewat linkTiktokMedia() di bawah.
     */
    public function unmatchedTiktok(Request $request, ApiIntegration $apiIntegration)
    {
        abort_unless($apiIntegration->platform->name === 'TikTok', 404);

        $snapshots = TikTokVideoSnapshot::where('api_integration_id', $apiIntegration->id)
            ->whereIn('match_status', ['unmatched', 'ambiguous'])
            ->orderByDesc('published_at')
            ->get();

        $unmatched = $snapshots->map(fn ($s) => [
            'id' => $s->external_post_id,
            'caption' => Str::limit($s->video_description ?? $s->title ?? '(tanpa deskripsi)', 120),
            'timestamp' => $s->published_at,
            'permalink' => $s->share_url,
            'media_type' => 'Video',
            'thumbnail_url' => $s->cover_image_url,
            'ambiguous_reason' => $s->match_status === 'ambiguous' ? 'Ada beberapa kandidat content item, perlu dipilih manual.' : null,
        ])->all();

        $lastFetchedAt = TikTokVideoSnapshot::where('api_integration_id', $apiIntegration->id)
            ->max('last_fetched_at');
        $totalSnapshotted = TikTokVideoSnapshot::where('api_integration_id', $apiIntegration->id)->count();

        $contentItemOptions = ContentItem::where('client_id', $apiIntegration->client_id)
            ->orderByDesc('id')
            ->get(['id', 'title']);

        return view('publishing-tracker.tiktok-unmatched', [
            'apiIntegration' => $apiIntegration->load('client'),
            'unmatched' => $unmatched,
            'totalSnapshotted' => $totalSnapshotted,
            'lastFetchedAt' => $lastFetchedAt ? Carbon::parse($lastFetchedAt) : null,
            'contentItemOptions' => $contentItemOptions,
        ]);
    }

    /**
     * MIRROR linkInstagramMedia() - identik strukturnya, cuma menunjuk
     * TikTokVideoSnapshot + tiktok_video_snapshot_id, bukan
     * InstagramMediaSnapshot + instagram_media_snapshot_id.
     */
    public function linkTiktokMedia(Request $request, ApiIntegration $apiIntegration)
    {
        abort_unless($apiIntegration->platform->name === 'TikTok', 404);

        $validated = $request->validate([
            'content_item_id' => ['required', 'integer'],
            'external_post_id' => ['required', 'string'],
            'permalink' => ['nullable', 'url'],
            'caption' => ['nullable', 'string'],
            'timestamp' => ['nullable', 'date'],
        ]);

        $contentItem = ContentItem::where('id', $validated['content_item_id'])
            ->where('client_id', $apiIntegration->client_id)
            ->first();

        if (! $contentItem) {
            return back()->with('import_error', 'Content item tidak ditemukan atau bukan milik client yang sama dengan integration ini.');
        }

        $alreadyLinkedElsewhere = ContentPublication::where('platform_id', $apiIntegration->platform_id)
            ->where('external_post_id', $validated['external_post_id'])
            ->where('content_item_id', '!=', $contentItem->id)
            ->exists();

        if ($alreadyLinkedElsewhere) {
            return back()->with('import_error', 'Video TikTok ini sudah terhubung ke content item lain.');
        }

        try {
            DB::transaction(function () use ($validated, $contentItem, $apiIntegration) {
                $publication = ContentPublication::updateOrCreate(
                    ['content_item_id' => $contentItem->id, 'platform_id' => $apiIntegration->platform_id],
                    [
                        'external_post_id' => $validated['external_post_id'],
                        'api_integration_id' => $apiIntegration->id,
                        'published_by' => auth()->id(),
                        'published_at' => $validated['timestamp'] ?? now(),
                        'post_url' => $validated['permalink'] ?? null,
                        'caption_final' => $validated['caption'] ?? null,
                    ]
                );

                $snapshot = TikTokVideoSnapshot::where('api_integration_id', $apiIntegration->id)
                    ->where('external_post_id', $validated['external_post_id'])
                    ->first();

                if ($snapshot) {
                    $snapshot->update(['match_status' => 'matched', 'content_publication_id' => $publication->id]);

                    ContentMetric::where('tiktok_video_snapshot_id', $snapshot->id)
                        ->update(['content_item_id' => $contentItem->id, 'client_id' => $apiIntegration->client_id]);
                }
            });
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Manual link TikTok gagal (kemungkinan race condition unique constraint)', ['error' => $e->getMessage()]);

            return back()->with('import_error', 'Gagal menghubungkan - video ini kemungkinan baru saja terhubung dari proses lain.');
        }

        return back()->with('import_success', "Video berhasil dihubungkan ke \"{$contentItem->title}\".");
    }
}