<?php

namespace App\Http\Controllers;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncInstagramAudienceJob;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncLog;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\PackageTemplate;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\PicReassignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClientManagementController extends Controller
{
    public function index(Request $request)
    {
        // Halaman ini menampilkan daftar SEMUA client (tidak discope) -
        // beda dari client-management.show yang dibuka ke semua role
        // ber-'client,view' tapi discope ke client assignment-nya
        // (client.scope). Route-nya sendiri cuma gerbang 'client,view'
        // (biar Admin/CEO/Manager lolos), jadi di sini perlu ketat lagi:
        // cuma role yang canSeeAllClients() (CEO/Manager/Admin) yang boleh
        // lihat daftar TANPA scope ini - Content Creator/Graphic
        // Designer/Copywriter/SMO tetap 'client,view' buat detail 1 client,
        // TAPI tidak boleh browse daftar lengkapnya (RoleAccessMatrixTest).
        abort_unless(
            auth()->user()?->hasPermissionTo('client', 'manage')
                || (auth()->user()?->hasPermissionTo('client', 'view') && auth()->user()?->canSeeAllClients()),
            403
        );

        $search = $request->query('search');
        $status = $request->query('status', 'all');

        $clients = Client::query()
            ->with(['category', 'activePackage'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
            })
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('client-management.index', compact('clients', 'search', 'status'));
    }

    public function create()
    {
        $this->authorizeManage();

        $categories = ClientCategory::all();
        $packageTemplates = PackageTemplate::where('is_active', true)->orderBy('name')->get();

        return view('client-management.create', compact('categories', 'packageTemplates'));
    }

    /**
     * Client adalah business entity murni - store() di sini CUMA bikin
     * Client. Tidak ada User/akun/credential yang dibuat sama sekali.
     * portal_token otomatis ke-generate lewat Client::booted() (model event),
     * bukan logic di sini - konsisten dari controller/factory/seeder/test manapun.
     */
    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'client_category_id' => 'required|exists:client_categories,id',
            'logo' => 'nullable|image|max:2048', // max 2MB
            'asset_link' => 'nullable|url|max:255',
            'package_template_id' => 'nullable|exists:package_templates,id',
        ]);

        $client = DB::transaction(function () use ($validated, $request) {
            $logoPath = $request->hasFile('logo')
                ? $request->file('logo')->store('client-logos', 'public')
                : null;

            $client = Client::create([
                'name' => $validated['name'],
                'client_category_id' => $validated['client_category_id'],
                'logo_path' => $logoPath,
                'asset_link' => $validated['asset_link'] ?? null,
                'status' => 'active',
            ]);

            if (filled($validated['package_template_id'] ?? null)) {
                $this->assignPackage($client, PackageTemplate::find($validated['package_template_id']));
            }

            return $client;
        });

        return redirect()->route('client-management.show', $client)
            ->with('status', 'Klien berhasil dibuat. Link Portal Klien telah tersedia.');
    }

    public function show(Client $client, PicReassignmentService $picReassignmentService)
    {
        // Otorisasi dipegang route middleware (permission:client,view +
        // client.scope:client,id) - dibuka ke semua role internal yang
        // di-assign ke client ini, bukan cuma client,manage seperti aksi
        // lain di controller ini. Tombol ubah data di-gate di view lewat
        // hasPermissionTo('client','manage').

        $client->load(['category', 'activePackage', 'packages', 'assignedUsers.roles']);

        $recentContentItems = $client->contentItems()
            ->with(['contentType', 'workflow'])
            ->latest('created_at')
            ->take(10)
            ->get();

        $contentCount = $client->contentItems()->count();
        $planCount = $client->contentPlans()->count();
        $packageTemplates = PackageTemplate::where('is_active', true)->orderBy('name')->get();
        $staffOptions = User::where('status', 'active')->orderBy('name')->get();

        // Berapa konten aktif per PIC yang di-assign ke client ini - dipakai
        // buat memutuskan apakah dia bisa dikeluarkan dari roster langsung
        // atau butuh reassignment dulu (lihat "Keluarkan dari PIC").
        $picActiveCounts = $client->assignedUsers->mapWithKeys(
            fn (User $staff) => [$staff->id => $picReassignmentService->countActive($staff, $client->id)]
        );

        // Integrasi Instagram client ini (hasil OAuth connect) - dipakai
        // buat card "Integrasi Analytics" di halaman ini.
        $instagramIntegration = $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', 'Instagram'))
            ->first();
        $instagramOauthConfigured = filled(config('services.instagram.client_id')) && filled(config('services.instagram.client_secret'));

        // $instagramLastSyncLog buat nampilin HASIL sync terakhir (Synced/
        // Failed + pesan) - AnalyticsSyncLog tetap sumber yang benar buat
        // ini karena selalu ada begitu minimal 1 sync pernah selesai.
        // DIBATASI source_type='api_sync' (bukan latest() polos) - sejak
        // audience sync juga nulis ke tabel yang sama (source_type=
        // 'audience_api_sync'), latest() tanpa scope bisa kejaring log
        // audience dan salah ditampilkan sebagai status Content Analytics.
        //
        // $instagramSyncing ("lagi berjalan SEKARANG") SENGAJA BUKAN dari
        // AnalyticsSyncLog::where(status,pending) - log itu sekarang cuma
        // dibuat DI DALAM Job setelah lock didapat (lihat docblock
        // SyncInstagramAnalyticsJob soal fix stale pending log), jadi ada
        // celah singkat antara dispatch dan job mulai di mana belum ada
        // log 'pending' sama sekali walau syncnya beneran lagi antre/jalan.
        // Sumber yang benar: peek non-invasif ke lock yang sama dipakai
        // WithoutOverlapping - konsisten dengan cek yang sama di controller.
        $instagramLastSyncLog = $instagramIntegration
            ? AnalyticsSyncLog::where('api_integration_id', $instagramIntegration->id)
                ->where('source_type', 'api_sync')->latest()->first()
            : null;
        $instagramSyncing = false;
        if ($instagramIntegration) {
            $lock = Cache::lock(SyncInstagramAnalyticsJob::cacheLockKey($instagramIntegration->id), 10);
            if ($lock->get()) {
                $lock->release();
            } else {
                $instagramSyncing = true;
            }
        }

        // Audience Insights - card & lock TERPISAH dari Content Analytics
        // di atas (job beda, lock key beda - lihat SyncInstagramAudienceJob).
        // $instagramAudienceLastSyncLog = percobaan TERAKHIR (apapun
        // hasilnya) - dipakai buat badge status (Synced/Syncing/Failed).
        // $instagramAudienceLastSuccessAt = kapan TERAKHIR KALI beneran
        // berhasil nulis (dari AudienceInsight->updated_at, bukan log) -
        // dipakai buat teks "Last Audience Sync", sesuai spek: harus dari
        // sync SUKSES terakhir, bukan percobaan terakhir yang mungkin gagal.
        $instagramAudienceLastSyncLog = $instagramIntegration
            ? AnalyticsSyncLog::where('api_integration_id', $instagramIntegration->id)
                ->where('source_type', 'audience_api_sync')->latest()->first()
            : null;
        $instagramAudienceLastSuccessAt = $instagramIntegration
            ? AudienceInsight::where('client_id', $client->id)
                ->where('platform_id', $instagramIntegration->platform_id)
                ->apiSourced()->max('updated_at')
            : null;
        $instagramAudienceSyncing = false;
        if ($instagramIntegration) {
            $lock = Cache::lock(SyncInstagramAudienceJob::cacheLockKey($instagramIntegration->id), 10);
            if ($lock->get()) {
                $lock->release();
            } else {
                $instagramAudienceSyncing = true;
            }
        }

        // Integrasi TikTok client ini - MIRROR blok Instagram di atas persis,
        // TANPA Audience Insights (TikTok Display API standar tidak
        // menyediakan demografis - lihat docs/TIKTOK_INTEGRATION.md).
        $tiktokIntegration = $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', 'TikTok'))
            ->first();
        $tiktokOauthConfigured = filled(config('services.tiktok.client_key')) && filled(config('services.tiktok.client_secret'));

        $tiktokLastSyncLog = $tiktokIntegration
            ? AnalyticsSyncLog::where('api_integration_id', $tiktokIntegration->id)
                ->where('source_type', 'api_sync')->latest()->first()
            : null;
        $tiktokSyncing = false;
        if ($tiktokIntegration) {
            $lock = Cache::lock(SyncTikTokAnalyticsJob::cacheLockKey($tiktokIntegration->id), 10);
            if ($lock->get()) {
                $lock->release();
            } else {
                $tiktokSyncing = true;
            }
        }

        // Data tambahan buat card TikTok Integration - SEMUA dari data yang
        // sudah tersimpan (bukan panggilan API baru). Scope dicek dari
        // api_integrations.scopes (CSV hasil OAuth callback, TIDAK PERNAH
        // NULL != granted dianggap sama - lihat catatan "NULL != 0" di
        // laporan fix TikTok sync).
        $tiktokGrantedScopes = $tiktokIntegration
            ? array_map('trim', explode(',', (string) $tiktokIntegration->scopes))
            : [];
        $tiktokStatsScopeGranted = in_array('user.info.stats', $tiktokGrantedScopes, true);
        $tiktokVideoListScopeGranted = in_array('video.list', $tiktokGrantedScopes, true);

        // follower_count TERBARU dari AudienceInsight (ditulis
        // TikTokAnalyticsSyncService::saveProfileSnapshot() - HANYA ada
        // kalau user.info.stats granted, lihat docblock method itu) - NULL
        // (bukan baris sama sekali) kalau scope tidak granted ATAU belum
        // pernah sync sukses sejak connect.
        $tiktokFollowerInsight = $tiktokIntegration
            ? AudienceInsight::where('client_id', $client->id)
                ->where('platform_id', $tiktokIntegration->platform_id)
                ->apiSourced()->summary()->latest('snapshot_date')->first()
            : null;

        // Total video TikTok yang punya baris tiktok_video_snapshots (all-
        // time, BUKAN cuma sync terakhir - AnalyticsSyncLog TIDAK menyimpan
        // video_count per sync secara terpisah, lihat laporan SYNC_LOG_QUALITY
        // di laporan fix untuk keterbatasan schema ini).
        $tiktokVideoCount = $tiktokIntegration
            ? TikTokVideoSnapshot::where('api_integration_id', $tiktokIntegration->id)->count()
            : 0;

        return view('client-management.show', compact(
            'client', 'recentContentItems', 'contentCount', 'planCount', 'packageTemplates', 'staffOptions', 'picActiveCounts',
            'instagramIntegration', 'instagramOauthConfigured',
            'instagramLastSyncLog', 'instagramSyncing',
            'instagramAudienceLastSyncLog', 'instagramAudienceLastSuccessAt', 'instagramAudienceSyncing',
            'tiktokIntegration', 'tiktokOauthConfigured', 'tiktokLastSyncLog', 'tiktokSyncing',
            'tiktokStatsScopeGranted', 'tiktokVideoListScopeGranted', 'tiktokFollowerInsight', 'tiktokVideoCount'
        ));
    }

    /**
     * Tombol "Sync Audience Insights" di card Instagram Integration -
     * mirror persis pola SettingsController::syncInstagram() (dispatch
     * job lalu redirect langsung, TIDAK menahan request nunggu Instagram
     * API), tapi scoped ke {client} route-model-binding (bukan client_id
     * dari body) - integration SELALU diambil lewat $client->apiIntegrations(),
     * jadi ID integration milik client lain nggak mungkin kepakai walau
     * seseorang coba nebak/kirim ID lain (tidak ada input ID integration
     * sama sekali di flow ini).
     *
     * Reuse SyncInstagramAudienceJob apa adanya (Langkah 2, "jangan buat
     * Job baru") - TIDAK PERNAH backfill 180 hari dari tombol manual ini
     * (Langkah 11: "repeated manual sync -> current daily data saja").
     * Backfill tetap cuma lewat --backfill CLI eksplisit, dipakai sekali
     * pas integration baru connect.
     */
    public function syncInstagramAudience(Client $client)
    {
        $this->authorizeManage();

        $integration = $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', 'Instagram'))
            ->first();

        if (! $integration || ! filled($integration->access_token)) {
            return back()->with('import_error', 'Client ini belum connect Instagram (OAuth). Hubungkan dulu lewat tombol "Connect Instagram".');
        }

        // Peek non-invasif ke lock yang sama dipakai WithoutOverlapping di
        // SyncInstagramAudienceJob - kalau lagi dipegang, JANGAN dispatch
        // job kedua (middleware Job tetap source-of-truth concurrency,
        // ini cuma defense-in-depth + feedback instan ke user).
        $lock = Cache::lock(SyncInstagramAudienceJob::cacheLockKey($integration->id), 10);
        if (! $lock->get()) {
            return back()->with('import_error', 'Sinkronisasi Audience Instagram untuk akun ini sedang berjalan.');
        }
        $lock->release();

        SyncInstagramAudienceJob::dispatch($integration->id, auth()->id());

        return back()->with('import_success', 'Sinkronisasi Audience Instagram dimulai.');
    }

    /**
     * Status sync TikTok "Analitik Konten" client ini, dipoll ringan/read-
     * only dari JS di client-management.show (lihat script di bawah card
     * TikTok) - job sync-nya async, jadi halaman butuh cara tahu kapan job
     * selesai TANPA user refresh manual.
     *
     * Auth + scope SAMA seperti show() (permission:client,view +
     * client.scope:client,id di routes/web.php) - integration SELALU
     * diambil lewat $client->apiIntegrations(), jadi endpoint ini TIDAK
     * PERNAH bisa dipakai buat intip integrasi client lain walau ID
     * integration ditebak manual (tidak ada input ID di sini sama sekali).
     *
     * Response CUMA field yang aman ditampilkan (tidak ada token/raw API
     * response) - lihat StatusResponse di bawah buat kontrak lengkap.
     *
     * Status 'queued' vs 'running': dibedakan dari ADA/TIDAKNYA baris job
     * ini di tabel `jobs` (queue driver 'database') - begitu WithoutOverlapping
     * lock (dipakai bareng syncTiktok()) sudah dipegang TAPI baris job masih
     * ada di `jobs`, berarti worker baru saja mengambilnya (masih dianggap
     * 'running' karena baris `jobs` dihapus SEBELUM handle() selesai, bukan
     * sesudah - jadi overlap sangat singkat, diabaikan). 'queued' murni
     * kondisi: baris job ADA di `jobs` TAPI lock BELUM dipegang (dispatch
     * sudah terjadi, worker belum sempat mengambil).
     */
    public function tiktokSyncStatus(Client $client)
    {
        $integration = $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', 'TikTok'))
            ->first();

        if (! $integration) {
            return response()->json(['status' => 'not_connected', 'message' => 'TikTok belum terhubung untuk client ini.']);
        }

        $lock = Cache::lock(SyncTikTokAnalyticsJob::cacheLockKey($integration->id), 10);
        $running = false;
        if ($lock->get()) {
            $lock->release();
        } else {
            $running = true;
        }

        $queued = ! $running && $this->hasQueuedTiktokJob($integration->id);

        $lastLog = AnalyticsSyncLog::where('api_integration_id', $integration->id)
            ->where('source_type', 'api_sync')->latest()->first();

        $status = match (true) {
            $running => 'running',
            $queued => 'queued',
            default => $lastLog?->status ?? 'idle',
        };

        $messages = [
            'queued' => 'Sinkronisasi TikTok sedang antre...',
            'running' => 'Sedang mengambil profil dan video TikTok...',
            'success' => 'Sinkronisasi selesai.',
            'failed' => 'Sinkronisasi gagal.',
            'pending' => 'Sinkronisasi TikTok sedang antre...',
            'idle' => 'Belum pernah disinkronkan.',
        ];

        return response()->json([
            'status' => $status,
            'message' => $messages[$status] ?? 'Belum pernah disinkronkan.',
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
            // metrics_saved/unmatched_or_failed = synced_count/skipped_count
            // AnalyticsSyncLog apa adanya - lihat catatan SYNC_LOG_QUALITY
            // di laporan akhir soal keterbatasan schema (video_count murni
            // hasil sync TIDAK disimpan terpisah, jadi tidak dikirim di sini
            // supaya tidak fabricating angka).
            'result' => $lastLog && in_array($status, ['success', 'failed'], true) ? [
                'metrics_saved' => $lastLog->synced_count,
                'unmatched_or_failed' => $lastLog->skipped_count,
                'error_message' => $lastLog->error_message,
                'finished_at' => $lastLog->updated_at?->toIso8601String(),
            ] : null,
        ]);
    }

    private function hasQueuedTiktokJob(int $integrationId): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%SyncTikTokAnalyticsJob%')
            ->get(['payload'])
            ->contains(function ($row) use ($integrationId) {
                $payload = json_decode($row->payload, true);
                $serializedCommand = $payload['data']['command'] ?? null;
                if (! is_string($serializedCommand)) {
                    return false;
                }

                $command = @unserialize($serializedCommand);

                return $command instanceof SyncTikTokAnalyticsJob && $command->apiIntegrationId === $integrationId;
            });
    }

    public function edit(Client $client)
    {
        $this->authorizeManage();

        $categories = ClientCategory::all();

        return view('client-management.edit', compact('client', 'categories'));
    }

    public function update(Request $request, Client $client)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'client_category_id' => 'required|exists:client_categories,id',
            'status' => 'required|in:active,past_due,paused',
            'logo' => 'nullable|image|max:2048',
            'remove_logo' => 'nullable|boolean',
            'asset_link' => 'nullable|url|max:255',
        ]);

        DB::transaction(function () use ($validated, $client, $request) {
            $logoPath = $client->logo_path;

            if ($request->hasFile('logo')) {
                if ($client->logo_path) {
                    Storage::disk('public')->delete($client->logo_path);
                }
                $logoPath = $request->file('logo')->store('client-logos', 'public');
            } elseif ($request->boolean('remove_logo') && $client->logo_path) {
                Storage::disk('public')->delete($client->logo_path);
                $logoPath = null;
            }

            $client->update([
                'name' => $validated['name'],
                'client_category_id' => $validated['client_category_id'],
                'status' => $validated['status'],
                'logo_path' => $logoPath,
                'asset_link' => $validated['asset_link'] ?? null,
            ]);
        });

        return redirect()->route('client-management.show', $client)
            ->with('status', 'Data klien berhasil diperbarui.');
    }

    public function updatePackage(Request $request, Client $client)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'package_template_id' => 'required|exists:package_templates,id',
        ]);

        $template = PackageTemplate::findOrFail($validated['package_template_id']);

        DB::transaction(fn () => $this->assignPackage($client, $template));

        return redirect()->route('client-management.show', $client)
            ->with('status', "Paket {$client->name} berhasil diperbarui.");
    }

    /**
     * "Buat link baru? Link lama akan langsung tidak dapat digunakan." -
     * regenerate GANTI token (old URL langsung invalid), portal_access_enabled
     * tidak berubah sama sekali.
     */
    public function regeneratePortalToken(Client $client)
    {
        $this->authorizeManage();

        $client->regeneratePortalToken();

        return redirect()->route('client-management.show', $client)
            ->with('status', 'Link Portal Klien baru berhasil dibuat. Link lama sudah tidak berlaku.');
    }

    public function disablePortal(Client $client)
    {
        $this->authorizeManage();

        $client->update(['portal_access_enabled' => false]);

        return redirect()->route('client-management.show', $client)
            ->with('status', 'Akses Portal Klien dinonaktifkan.');
    }

    public function enablePortal(Client $client)
    {
        $this->authorizeManage();

        $client->update(['portal_access_enabled' => true]);

        return redirect()->route('client-management.show', $client)
            ->with('status', 'Akses Portal Klien diaktifkan kembali.');
    }

    /**
     * Assign paket ke client - kalau sudah ada paket aktif, ditandai
     * 'ended' (bukan dihapus, biar riwayat kuota per periode tetap
     * kelihatan), lalu dibuatkan ClientPackage baru sebagai snapshot
     * (nama & kuota disalin dari template saat itu juga, supaya perubahan
     * template di kemudian hari nggak diam-diam mengubah kuota client yang
     * sudah berjalan).
     */
    private function assignPackage(Client $client, PackageTemplate $template): void
    {
        $client->activePackage?->update(['status' => 'ended', 'end_date' => now()]);

        ClientPackage::create([
            'client_id' => $client->id,
            'package_template_id' => $template->id,
            'package_name_snapshot' => $template->name,
            'monthly_content_quota' => $template->monthly_content_quota,
            'monthly_design_quota' => $template->monthly_design_quota,
            'start_date' => now(),
            'status' => 'active',
        ]);
    }

    public function destroy(Client $client)
    {
        $this->authorizeManage();

        $hasHistory = $client->contentItems()->exists() || $client->contentPlans()->exists();

        if ($hasHistory) {
            $client->update(['status' => 'paused']);

            return redirect()->route('client-management.index')
                ->with('status', "{$client->name} punya riwayat konten, jadi tidak dihapus permanen — status diubah jadi Dijeda.");
        }

        DB::transaction(function () use ($client) {
            if ($client->logo_path) {
                Storage::disk('public')->delete($client->logo_path);
            }
            $client->packages()->delete();
            $client->delete();
        });

        return redirect()->route('client-management.index')
            ->with('status', 'Klien berhasil dihapus.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('client', 'manage'), 403);
    }
}
