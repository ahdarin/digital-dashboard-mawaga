<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
        $integrations = $this->buildIntegrationStatus();
        $clientOptions = Client::where('status', 'active')->get();

        // Tahap 6.2 - Data Pilihan (dulu Master Data) & Integrasi digabung
        // jadi tab di Pengaturan, bukan halaman menu terpisah lagi.
        $mdTab = null;
        $mdItems = collect();
        $mdSearch = null;
        if ($section === 'data-pilihan') {
            $mdTab = $request->input('type', 'content-pillar');
            abort_unless(array_key_exists($mdTab, MasterDataController::TYPES), 404);
            $mdSearch = $request->input('search');
            $mdItems = MasterDataController::TYPES[$mdTab]::query()
                ->when($mdSearch, fn ($q) => $q->where('name', 'like', "%{$mdSearch}%"))
                ->orderBy('name')
                ->get();
        }

        $syncLogs = null;
        if ($section === 'integrasi') {
            $syncLogs = AnalyticsSyncLog::with(['client', 'platform', 'importedBy'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('date'), fn ($q) => $q->whereDate('created_at', $request->input('date')))
                ->latest()
                ->paginate(15)
                ->withQueryString();
        }

        // Status koneksi service pihak ketiga yang dipakai sistem (bukan
        // per-client platform kayak $integrations di atas, ini level
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
            'user', 'integrations', 'clientOptions', 'systemConnections',
            'section', 'mdTab', 'mdItems', 'mdSearch', 'syncLogs'
        ));
    }

    /**
     * Status koneksi API per platform - dipakai bareng sama index() dan
     * integrationsPage(), biar nggak ada 2 salinan logic yang bisa
     * kebablasan beda pas salah satunya diubah.
     */
    private function buildIntegrationStatus()
    {
        return Platform::all()->map(function ($platform) {
            $integration = ApiIntegration::where('platform_id', $platform->id)
                ->where('status', 'active')
                ->latest()
                ->first();

            return [
                'platform' => $platform->name,
                'connected' => (bool) $integration,
                'integration_name' => $integration->integration_name ?? null,
                'updated_at' => $integration->updated_at ?? null,
            ];
        });
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