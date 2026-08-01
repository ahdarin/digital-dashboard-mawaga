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
    public function index()
    {
        $user = auth()->user();

        $platforms = Platform::all();

        $integrations = $platforms->map(function ($platform) {
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

        $clientOptions = Client::where('status', 'active')->get();

        return view('settings.index', compact('user', 'integrations', 'clientOptions'));
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
            'source_type' => 'csv_import',
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

            $platform = Platform::firstOrCreate(['name' => trim($data['platform'] ?? '')]);
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
}