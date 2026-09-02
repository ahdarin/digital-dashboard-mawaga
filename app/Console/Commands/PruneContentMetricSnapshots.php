<?php

namespace App\Console\Commands;

use App\Models\ContentMetricSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Audit sync horizon + snapshot retention - content_metric_snapshots
 * (histori observasi harian, Phase 2) disimpan ROLLING 120 hari, BUKAN 90 -
 * 90-hari analytics horizon butuh observasi baseline SEBELUM period_start
 * (PeriodPerformanceService), plus buffer 91-120 hari buat: boundary
 * baseline, scheduler yang kelewat 1-2 hari, API down sementara, sync
 * telat, observation gap. Retention 120 BUKAN dikurangi jadi exact 90 -
 * lihat config/analytics.php:content_metric_snapshot_retention_days.
 *
 * SEMANTIK INCLUSIVE/EXCLUSIVE (eksplisit, biar tidak ambigu): retention
 * ROLLING - tepat $retentionDays hari kalender TERBARU (age 0 s/d
 * $retentionDays-1) yang dipertahankan. snapshot_date dengan age PERSIS
 * $retentionDays (atau lebih tua) DIHAPUS. Contoh retention=120: age
 * 0-119 dipertahankan (120 distinct calendar dates), age 120 (dan lebih
 * tua) dihapus - cutoff = today - $retentionDays hari, DELETE WHERE
 * snapshot_date <= cutoff (bukan strictly-less-than) - lihat contoh
 * "day 121 masuk -> delete oldest day 1 -> days 2-121 remain (120 hari)"
 * di spesifikasi fitur ini.
 *
 * HANYA content_metric_snapshots yang disentuh - ContentMetric (current/
 * latest state), InstagramMediaSnapshot/TikTokVideoSnapshot (source
 * content identity), ContentItem, AudienceInsight, AnalyticsSyncLog SAMA
 * SEKALI TIDAK disentuh. Content identity (media/video yang pernah
 * di-sync) TETAP ada selamanya - cuma histori OBSERVASI HARIAN performa
 * yang di-rolling, bukan identitas kontennya.
 *
 * Idempoten & aman dijalankan berkali-kali (query DELETE biasa berdasar
 * snapshot_date, bukan operasi tabel destructive) - dijadwalkan harian
 * lewat routes/console.php.
 */
class PruneContentMetricSnapshots extends Command
{
    protected $signature = 'analytics:prune-content-metric-snapshots';

    protected $description = 'Hapus content_metric_snapshots yang lebih tua dari retention window (default 120 hari, rolling) - tidak menyentuh ContentMetric/source snapshot/ContentItem/AudienceInsight/AnalyticsSyncLog.';

    public function handle(): int
    {
        $retentionDays = (int) config('analytics.content_metric_snapshot_retention_days', 120);
        $cutoff = now()->subDays($retentionDays)->startOfDay();

        // age >= $retentionDays dihapus (snapshot_date <= cutoff, INKLUSIF
        // di batas) - lihat docblock kelas buat penjelasan lengkap kenapa
        // batasnya di sini, bukan strictly-less-than.
        $deletedCount = ContentMetricSnapshot::whereDate('snapshot_date', '<=', $cutoff->toDateString())->delete();

        $this->info("Selesai. {$deletedCount} content_metric_snapshots dihapus (snapshot_date <= {$cutoff->toDateString()}, retention {$retentionDays} hari rolling).");

        // Log count SAJA, tidak ada data row/token/secret apapun.
        Log::info('analytics:prune-content-metric-snapshots selesai', [
            'retention_days' => $retentionDays,
            'cutoff_date' => $cutoff->toDateString(),
            'deleted_count' => $deletedCount,
        ]);

        return self::SUCCESS;
    }
}
