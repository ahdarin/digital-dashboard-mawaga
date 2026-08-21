<?php

namespace App\Http\Controllers;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncInstagramAudienceJob;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\PackageTemplate;
use App\Models\Platform;
use App\Services\InstagramAnalyticsSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\MasterDataController;

/**
 * NOTE UNTUK TIM:
 * Tidak ada desain UI resmi untuk halaman ini, jadi disusun mengikuti pola
 * visual halaman lain (card putih rounded-2xl, warna aksen #044b46).
 *
 * Isinya digabung dari dua hal yang masuk akal ada di Settings:
 * 1. Account - data akun user yang login (read-only, edit lewat halaman Profile).
 * 2. Analytics Integration Settings - sesuai PRD 7.3.4 (domain PIC 3):
 *    status koneksi API per platform + jalur import performa via CSV/Excel.
 *
 * Bagian connect/disconnect integration MASIH UI SAJA (belum ada action/route
 * submit-nya) - itu butuh App Review Meta/TikTok dulu sebelum bisa
 * diimplementasi beneran (lihat diskusi soal ini di chat).
 *
 * Import CSV SUDAH FUNGSIONAL (lihat importPerformance() di bawah).
 */
class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $section = $request->input('tab', 'umum');

        $user = auth()->user();

        // Scoped sama seperti Content Plan/Analytics/Production Workflow -
        // 'settings,manage' juga digenggam SMO (bukan cuma CEO/Manager yang
        // canSeeAllClients()), jadi tanpa scoping ini SMO bisa lihat DAN
        // trigger sync buat client yang bukan assignment-nya (audit
        // "Settings Integrasi client-centric" Langkah 15/16).
        $clientOptions = $user->canSeeAllClients()
            ? Client::where('status', 'active')->get()
            : $user->assignedClients()->where('status', 'active')->get();

        // Tahap 6.2 - Data Pilihan (dulu Master Data) & Integrasi digabung
        // jadi tab di Pengaturan, bukan halaman menu terpisah lagi.
        $mdTab = null;
        $mdItems = collect();
        $mdSearch = null;
        if ($section === 'data-pilihan') {
            $mdTab = $request->input('type', 'content-pillar');
            abort_unless(array_key_exists($mdTab, MasterDataController::TYPES) || $mdTab === 'package-template', 404);
            $mdSearch = $request->input('search');
            $mdItems = $mdTab === 'package-template'
                ? PackageTemplate::query()
                    ->when($mdSearch, fn ($q) => $q->where('name', 'like', "%{$mdSearch}%"))
                    ->orderBy('name')
                    ->get()
                : MasterDataController::TYPES[$mdTab]::query()
                    ->when($mdSearch, fn ($q) => $q->where('name', 'like', "%{$mdSearch}%"))
                    ->orderBy('name')
                    ->get();
        }

        // Integrasi SEKARANG client-centric (dulu: 1 kartu Instagram global
        // ambil integration TERAKHIR di-update lintas SEMUA client, salah
        // total begitu ada >1 client connect - lihat audit "Settings
        // Integrasi client-centric"). User pilih client dulu, baru kartu
        // integration MILIK client itu yang ditampilkan.
        $selectedClientId = null;
        $selectedClient = null;
        $instagramCard = null;
        $instagramOauthConfigured = false;
        $syncLogs = null;
        $logsAllClients = false;
        if ($section === 'integrasi') {
            $requestedClientId = $request->input('client_id') ?: $clientOptions->first()?->id;
            // $clientOptions SUDAH scoped di atas - cari di situ (bukan
            // Client::find() langsung) biar ?client_id=X hasil tebak/ketik
            // manual tidak bisa nembus scope (lihat EnsureClientScope, pola
            // yang sama diterapkan manual di sini karena client_id datang
            // dari query string, bukan route-model-binding).
            $selectedClient = $requestedClientId ? $clientOptions->firstWhere('id', (int) $requestedClientId) : null;
            $selectedClientId = $selectedClient?->id;
            $instagramCard = $selectedClient ? $this->buildInstagramCard($selectedClient) : null;
            $instagramOauthConfigured = filled(config('services.instagram.client_id')) && filled(config('services.instagram.client_secret'));

            // "All Clients" cuma valid buat yang canSeeAllClients() - user
            // ter-assign spesifik tidak boleh lihat log client lain lewat
            // toggle ini walau query string-nya dipaksa manual.
            $logsAllClients = $user->canSeeAllClients() && $request->boolean('all_clients');
            $assignedClientIds = $user->canSeeAllClients() ? null : $clientOptions->pluck('id');

            $syncLogs = AnalyticsSyncLog::with(['client', 'platform', 'importedBy'])
                ->when($assignedClientIds !== null, fn ($q) => $q->whereIn('client_id', $assignedClientIds))
                ->when(! $logsAllClients && $selectedClientId, fn ($q) => $q->where('client_id', $selectedClientId))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('date'), fn ($q) => $q->whereDate('created_at', $request->input('date')))
                ->latest()
                ->paginate(15)
                ->withQueryString();
        }

        // Status koneksi service pihak ketiga yang dipakai sistem (bukan
        // per-client platform kayak $instagramCard di atas, ini level
        // aplikasi) - sebelumnya nggak kelihatan sama sekali di Settings,
        // padahal AI Strategy Analysis (Gemini), login client (Fonnte
        // WhatsApp), dan login tim internal (Google) semuanya bergantung
        // ke sini. Dicek dari config, bukan nge-tes koneksi beneran (biar
        // nggak ngirim request tiap kali halaman Settings dibuka).
        $systemConnections = [
            [
                'label' => 'Google Sign-In',
                'description' => 'Login tim internal',
                'icon' => 'admin_panel_settings',
                'connected' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            ],
            [
                'label' => 'WhatsApp (Fonnte)',
                'description' => 'Kirim link login client',
                'icon' => 'chat',
                'connected' => filled(config('services.fonnte.token')),
            ],
            [
                'label' => 'Gemini AI',
                'description' => 'AI Strategy Analysis',
                'icon' => 'auto_awesome',
                'connected' => filled(config('services.gemini.api_key')),
            ],
        ];

        return view('settings.index', compact(
            'user', 'clientOptions', 'systemConnections',
            'section', 'mdTab', 'mdItems', 'mdSearch', 'syncLogs',
            'selectedClientId', 'selectedClient', 'instagramCard', 'instagramOauthConfigured', 'logsAllClients'
        ));
    }

    /**
     * Kartu Instagram di Settings > Integrasi - SELALU scoped ke 1 client
     * (dulu: query ambil integration TERAKHIR di-update lintas SEMUA
     * client, salah kalau ada >1 client connect - lihat audit). Integration
     * "connected" WAJIB `whereNotNull('access_token')`, BUKAN cuma
     * `status='active'` - ditemukan row DemoSeeder placeholder
     * (ApiIntegration status=active TAPI access_token kosong) yang bakal
     * kejaring salah kalau cuma cek status.
     *
     * Log content vs audience DIPISAH lewat source_type ('api_sync' vs
     * 'audience_api_sync') - pola sama persis dengan ClientManagementController
     * ::show() (sengaja tidak diekstrak jadi 1 helper bersama - 2 controller
     * beda halaman, duplikasi ~20 baris ini lebih murah daripada bikin
     * abstraksi baru buat sesuatu sekecil ini).
     */
    private function buildInstagramCard(Client $client): array
    {
        $platform = Platform::where('name', 'Instagram')->first();

        $integration = $client->apiIntegrations()
            ->where('platform_id', $platform?->id)
            ->whereNotNull('access_token')
            ->first();

        if (! $integration) {
            return ['connected' => false];
        }

        $contentLastSyncLog = AnalyticsSyncLog::where('api_integration_id', $integration->id)
            ->where('source_type', 'api_sync')->latest()->first();

        $audienceLastSyncLog = AnalyticsSyncLog::where('api_integration_id', $integration->id)
            ->where('source_type', 'audience_api_sync')->latest()->first();
        $audienceLastSuccessAt = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $integration->platform_id)
            ->apiSourced()->max('updated_at');

        return [
            'connected' => true,
            'integration' => $integration,
            'content_last_sync_log' => $contentLastSyncLog,
            'content_syncing' => $this->isLocked(SyncInstagramAnalyticsJob::cacheLockKey($integration->id)),
            'audience_last_sync_log' => $audienceLastSyncLog,
            'audience_last_success_at' => $audienceLastSuccessAt,
            'audience_syncing' => $this->isLocked(SyncInstagramAudienceJob::cacheLockKey($integration->id)),
        ];
    }

    /**
     * Peek non-invasif ke Cache::lock() - ambil, langsung lepas kalau
     * berhasil (cuma ngecek "lagi dipegang atau nggak"), pola sama persis
     * yang sudah dipakai ClientManagementController::show() &
     * SettingsController::syncInstagram().
     */
    private function isLocked(string $cacheLockKey): bool
    {
        $lock = Cache::lock($cacheLockKey, 10);
        if ($lock->get()) {
            $lock->release();
            return false;
        }

        return true;
    }

    /**
     * KF3xx — Import Performance Data (PRD 7.3.4)
     * Format CSV yang diharapkan (baris pertama = header):
     *   content_title,platform,metric_date,views,engagement_rate
     *   Post Promo Ramadan,Instagram,2026-07-01,1200,4.5
     *
     * - content_title dicocokkan ke content_items.title MILIK CLIENT YANG
     *   DIPILIH di form (jadi title cukup unik per client, nggak perlu
     *   persis sama di seluruh sistem).
     * - platform dicocokkan ke platforms.name (case-insensitive). Kalau
     *   belum ada di master data, otomatis dibuatkan.
     * - metric_date format bebas asal bisa di-parse Carbon (disarankan
     *   YYYY-MM-DD).
     * - Baris yang content_title-nya nggak ketemu content item manapun
     *   milik client itu akan DI-SKIP (dicatat di ringkasan hasil import,
     *   nggak bikin proses gagal total).
     * - Kolom opsional (PRD 7.3.1): reach, impressions, likes, comments,
     *   profile_visit, plus watch_time_avg/completion_rate/shares/saves
     *   khusus konten video. Kalau kolomnya nggak ada di CSV atau
     *   kosong, disimpan null - nggak dipaksa jadi 0.
     */
    public function importPerformance(Request $request)
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $client = Client::findOrFail($validated['client_id']);

        $syncLog = AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'imported_by' => auth()->id(),
            'source_type' => 'performance_csv_import',
            'status' => 'pending',
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            $syncLog->update(['status' => 'failed']);
            return back()->with('import_error', 'File CSV kosong atau formatnya nggak kebaca.');
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), $header);
        $required = ['content_title', 'platform', 'metric_date', 'views', 'engagement_rate'];
        $missingColumns = array_diff($required, $header);

        if (! empty($missingColumns)) {
            fclose($handle);
            $syncLog->update(['status' => 'failed']);
            return back()->with('import_error', 'Kolom CSV tidak lengkap. Wajib ada: '.implode(', ', $required).'. Yang hilang: '.implode(', ', $missingColumns));
        }

        $successCount = 0;
        $skippedRows = [];
        $rowNumber = 1; // baris 1 = header
        $platformIdsUsed = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // baris kosong, skip diam-diam
            }

            $data = array_combine($header, array_pad($row, count($header), null));

            $platform = Platform::where('name', trim($data['platform'] ?? ''))->first();

            if (! $platform) {
                $skippedRows[] = "Baris {$rowNumber}: platform '".trim($data['platform'] ?? '-')."' tidak dikenali";
                continue;
            }

            $platformIdsUsed[$platform->id] = true;

            $contentItem = ContentItem::where('client_id', $client->id)
                ->whereRaw('LOWER(TRIM(title)) = ?', [strtolower(trim(preg_replace('/\s+/', ' ', $data['content_title'] ?? '')))])
                ->first();

            if (! $contentItem) {
                $skippedRows[] = "Baris {$rowNumber}: konten '".trim($data['content_title'] ?? '-')."' tidak ditemukan untuk client {$client->name}";
                continue;
            }

            $metricDate = null;
            try {
                $metricDate = Carbon::parse($data['metric_date']);
            } catch (\Exception $e) {
                $skippedRows[] = "Baris {$rowNumber}: format tanggal '{$data['metric_date']}' tidak valid";
                continue;
            }

            ContentMetric::updateOrCreate(
                [
                    'content_item_id' => $contentItem->id,
                    'platform_id' => $platform->id,
                    'metric_date' => $metricDate->toDateString(),
                ],
                [
                    'imported_by' => auth()->id(),
                    'sync_log_id' => $syncLog->id,
                    'views' => (int) ($data['views'] ?? 0),
                    'engagement_rate' => (float) ($data['engagement_rate'] ?? 0),
                    // Kolom video (Reels/TikTok) - opsional, dibiarin null
                    // kalau kolomnya nggak ada di CSV atau kosong (bukan 0),
                    // karena konten Feed/foto memang nggak punya nilai ini.
                    'watch_time_avg' => isset($data['watch_time_avg']) && $data['watch_time_avg'] !== ''
                        ? (int) $data['watch_time_avg'] : null,
                    'completion_rate' => isset($data['completion_rate']) && $data['completion_rate'] !== ''
                        ? (float) $data['completion_rate'] : null,
                    'shares' => isset($data['shares']) && $data['shares'] !== ''
                        ? (int) $data['shares'] : null,
                    'saves' => isset($data['saves']) && $data['saves'] !== ''
                        ? (int) $data['saves'] : null,
                    // Kolom engagement tambahan (PRD 7.3.1) - sama-sama
                    // opsional, null kalau nggak ada di CSV atau kosong.
                    'reach' => isset($data['reach']) && $data['reach'] !== ''
                        ? (int) $data['reach'] : null,
                    'impressions' => isset($data['impressions']) && $data['impressions'] !== ''
                        ? (int) $data['impressions'] : null,
                    'likes' => isset($data['likes']) && $data['likes'] !== ''
                        ? (int) $data['likes'] : null,
                    'comments' => isset($data['comments']) && $data['comments'] !== ''
                        ? (int) $data['comments'] : null,
                    'profile_visit' => isset($data['profile_visit']) && $data['profile_visit'] !== ''
                        ? (int) $data['profile_visit'] : null,
                ]
            );

            $successCount++;
        }

        fclose($handle);

        $syncLog->update([
            'status' => empty($skippedRows) || $successCount > 0 ? 'success' : 'failed',
            'platform_id' => count($platformIdsUsed) === 1 ? array_key_first($platformIdsUsed) : null,
        ]);

        $message = "{$successCount} baris berhasil diimport.";
        if (! empty($skippedRows)) {
            $message .= ' '.count($skippedRows).' baris dilewati.';
        }

        return back()
            ->with('import_success', $message)
            ->with('import_skipped', $skippedRows);
    }

    /**
     * Trigger manual buat command analytics:detect-anomalies - berguna
     * pas development/testing tanpa perlu nunggu scheduler jalan.
     * Di production, command ini jalan otomatis tiap jam lewat
     * routes/console.php.
     */
    public function runAnomalyDetection()
    {
        Artisan::call('analytics:detect-anomalies');
        $output = trim(Artisan::output());

        return back()->with('import_success', 'Deteksi anomali selesai dijalankan. '.$output);
    }

    /**
     * Tombol "Sync Last 2 Months" / "Sync Selected Month" di Client Detail.
     * Dispatch SyncInstagramAnalyticsJob ke queue lalu redirect LANGSUNG -
     * tidak menahan request user menunggu Instagram API. Sync sesungguhnya
     * jalan di background worker (php artisan queue:work).
     *
     * AnalyticsSyncLog SENGAJA TIDAK dibuat di sini lagi (lihat docblock
     * SyncInstagramAnalyticsJob) - kalau dibuat di controller lalu job-nya
     * dibuang WithoutOverlapping (integration lagi sibuk), log itu nyangkut
     * 'pending' selamanya. Job sendiri yang bikin log-nya, SETELAH lock
     * berhasil didapat.
     *
     * Buat feedback instan "sedang berjalan" tanpa gantung ke log: peek
     * non-invasif ke Cache::lock() pakai key yang sama persis dengan
     * WithoutOverlapping (SyncInstagramAnalyticsJob::cacheLockKey()) -
     * coba ambil, langsung lepas lagi kalau berhasil (cuma ngecek).
     *
     * PENTING buat operasional: QUEUE_CONNECTION=database sudah ada tapi
     * butuh `php artisan queue:work` (atau setara) jalan terus-menerus -
     * lihat docs/RUNTIME.md.
     */
    public function syncInstagram(Request $request)
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            // Format YYYY-MM divalidasi di sini (feedback cepat) DAN di
            // InstagramAnalyticsSyncService::resolveSyncWindow() (defense
            // kalau dispatch dipicu dari jalur lain) - regex sama persis.
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        // client_id lewat form field, BUKAN route-model-binding, jadi
        // EnsureClientScope middleware tidak bisa dipasang di route - cek
        // manual di sini (pola sama: canSeeAllClients() atau memang
        // ter-assign ke client ini). Tanpa ini, siapapun yang punya
        // 'settings,manage' (termasuk SMO yang di-scope ke client tertentu
        // di seluruh halaman lain) bisa submit client_id client manapun.
        $user = $request->user();
        abort_unless(
            $user->canSeeAllClients() || $user->assignedClients()->whereKey($validated['client_id'])->exists(),
            403,
            'Anda tidak punya akses ke client ini.'
        );

        $platform = Platform::where('name', 'Instagram')->first();
        $integration = ApiIntegration::where('client_id', $validated['client_id'])->where('platform_id', $platform->id)->first();

        if (! $integration || ! filled($integration->access_token)) {
            return back()->with('import_error', 'Client ini belum connect Instagram (OAuth). Hubungkan dulu lewat tombol "Connect Instagram".');
        }

        // Cegah 2 sync bersamaan buat integration yang sama (defense-in-depth,
        // WithoutOverlapping di Job tetap source of truth concurrency).
        // Lock diambil lalu LANGSUNG dilepas - ini cuma ngecek "sedang
        // dipegang atau nggak", bukan benar-benar mau pegang dari sini.
        $lock = Cache::lock(SyncInstagramAnalyticsJob::cacheLockKey($integration->id), 10);
        if (! $lock->get()) {
            return back()->with('import_error', 'Sinkronisasi Instagram untuk akun ini sedang berjalan.');
        }
        $lock->release();

        try {
            [$syncMode, $since, $until] = app(InstagramAnalyticsSyncService::class)
                ->resolveSyncWindow($validated['month'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('import_error', $e->getMessage());
        }

        SyncInstagramAnalyticsJob::dispatch(
            $integration->id, $syncMode,
            $since->toDateString(), $until->toDateString(), auth()->id()
        );

        return back()->with('import_success', 'Sinkronisasi Instagram dimulai.');
    }

    /**
     * PRD 7.3.4 — "Import Performance" sebagai halaman tersendiri
     * (bukan cuma section kecil di Settings). Form submit-nya tetap ke
     * importPerformance() yang sudah ada, cuma tampilannya dipisah.
     */
    public function importPage()
    {
        $clientOptions = Client::where('status', 'active')->get();

        return view('settings.import', compact('clientOptions'));
    }
}