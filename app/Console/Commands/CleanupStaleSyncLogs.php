<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSyncLog;
use Illuminate\Console\Command;

/**
 * Maintenance command - tandai AnalyticsSyncLog yang nyangkut 'pending'
 * kelamaan sebagai gagal. Sejak fix stale-pending-log (lihat docblock
 * SyncInstagramAnalyticsJob), log 'pending' cuma bisa dibuat SETELAH job
 * beneran mulai (lolos lock), jadi harusnya nggak ada lagi yang nyangkut
 * dari skenario overlap - tapi tetap bisa kejadian kalau worker process
 * mati mendadak (crash/kill) di TENGAH job jalan, sebelum sempat update
 * status akhir.
 *
 * CHECK constraint DB cuma izinkan ['success','failed','pending'] - TIDAK
 * ada 'cancelled', jadi log basi ditandai 'failed' (bukan bikin state
 * baru), dengan error_message yang jelas beda dari kegagalan API asli.
 *
 * Jalankan manual: php artisan analytics:cleanup-stale-sync-logs
 * (opsional --minutes=N buat ubah threshold, default 30 menit - dipilih
 * di atas worst-case retry job yang masih legitimate: 3x percobaan dengan
 * backoff 30s+120s+300s = 7.5 menit, plus durasi sync itu sendiri per
 * percobaan, beri margin besar biar nggak nandain sync yang masih sah
 * jalan sebagai stale).
 */
class CleanupStaleSyncLogs extends Command
{
    protected $signature = 'analytics:cleanup-stale-sync-logs {--minutes=30 : Umur (menit) pending dianggap basi}';
    protected $description = 'Tandai AnalyticsSyncLog yang nyangkut status pending kelamaan sebagai failed';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $stale = AnalyticsSyncLog::where('status', 'pending')
            ->where('created_at', '<', $threshold)
            ->get();

        if ($stale->isEmpty()) {
            $this->info("Tidak ada sync log pending yang lebih tua dari {$minutes} menit.");
            return self::SUCCESS;
        }

        foreach ($stale as $log) {
            $log->update([
                'status' => 'failed',
                'error_message' => 'Sync interrupted or stale.',
            ]);
        }

        $this->info("{$stale->count()} sync log pending basi (>{$minutes} menit) ditandai failed.");

        return self::SUCCESS;
    }
}
