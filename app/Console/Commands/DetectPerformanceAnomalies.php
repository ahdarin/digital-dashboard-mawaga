<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSyncLog;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * KF3xx — Anomaly Detection (bagian dari Content Analytics, domain PIC 3)
 *
 * Statistik sederhana, BUKAN machine learning: bandingin metrik hari
 * terakhir 1 konten vs rata-rata metrik konten itu di hari-hari sebelumnya.
 * Kalau selisihnya jauh (naik >=50% atau turun >=50% dari rata-rata),
 * otomatis bikin Notification.
 *
 * Juga ngecek AnalyticsSyncLog yang gagal (dari Import CSV) dan bikin
 * notifikasi soal itu juga.
 *
 * Notifikasi dikirim ke semua user yang canSeeAllClients() (CEO/Admin) -
 * bukan cuma PIC yang megang client itu, karena ini insight lintas client
 * yang biasanya perlu diketahui level manajemen.
 *
 * Jalankan manual: php artisan analytics:detect-anomalies
 * Dijadwalkan otomatis lewat routes/console.php (lihat instruksi terpisah).
 */
class DetectPerformanceAnomalies extends Command
{
    protected $signature = 'analytics:detect-anomalies';
    protected $description = 'Deteksi lonjakan/penurunan performa konten & sync gagal, kirim notifikasi otomatis';

    private const SPIKE_THRESHOLD = 1.5;   // naik >= 150% dari rata-rata -> "Trend Detected"
    private const DROP_THRESHOLD = 0.5;    // turun <= 50% dari rata-rata -> "Performa Turun"
    private const MIN_BASELINE_DAYS = 3;   // minimal 3 hari data histori biar rata-ratanya bermakna

    public function handle(): int
    {
        $notifyUsers = User::with('role')->get()->filter(fn ($u) => $u->canSeeAllClients());

        if ($notifyUsers->isEmpty()) {
            $this->warn('Nggak ada user CEO/Admin buat dikirimin notifikasi. Command dihentikan.');
            return self::SUCCESS;
        }

        $anomalyCount = $this->detectContentAnomalies($notifyUsers);
        $syncFailCount = $this->detectFailedSyncs($notifyUsers);

        $this->info("Selesai. {$anomalyCount} anomali performa, {$syncFailCount} sync gagal ter-notifikasi.");

        return self::SUCCESS;
    }

    private function detectContentAnomalies($notifyUsers): int
    {
        $today = Carbon::today();
        $count = 0;

        // Ambil semua content item yang punya metrik HARI INI (metrik baru
        // masuk, entah dari import CSV atau sync) - cuma yang relevan
        // dicek, biar command-nya ringan dan nggak nyisir seluruh histori
        // tiap kali jalan.
        $contentItemIds = ContentMetric::whereDate('metric_date', $today)
            ->distinct()
            ->pluck('content_item_id');

        foreach ($contentItemIds as $contentItemId) {
            $contentItem = ContentItem::with('client')->find($contentItemId);
            if (! $contentItem) {
                continue;
            }

            $todayMetric = ContentMetric::where('content_item_id', $contentItemId)
                ->whereDate('metric_date', $today)
                ->first();

            if (! $todayMetric) {
                continue;
            }

            $baseline = ContentMetric::where('content_item_id', $contentItemId)
                ->whereDate('metric_date', '<', $today)
                ->orderByDesc('metric_date')
                ->limit(30)
                ->get();

            if ($baseline->count() < self::MIN_BASELINE_DAYS) {
                continue; // data historisnya belum cukup buat dibandingin
            }

            $avgViews = $baseline->avg('views');
            if ($avgViews <= 0) {
                continue;
            }

            $ratio = $todayMetric->views / $avgViews;

            $anomalyType = null;
            if ($ratio >= self::SPIKE_THRESHOLD) {
                $anomalyType = 'spike';
            } elseif ($ratio <= self::DROP_THRESHOLD) {
                $anomalyType = 'drop';
            }

            if (! $anomalyType) {
                continue;
            }

            // Hindari notifikasi dobel buat konten+tanggal yang sama
            $alreadyNotified = Notification::where('related_type', ContentItem::class)
                ->where('related_id', $contentItemId)
                ->whereDate('created_at', $today)
                ->where('type', 'ai_insight')
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $percentChange = round(($ratio - 1) * 100);
            $clientName = $contentItem->client->name ?? 'Client';

            if ($anomalyType === 'spike') {
                $title = 'Trend Detected';
                $body = "Konten '{$contentItem->title}' ({$clientName}) tampil {$percentChange}% di atas rata-rata performa 30 hari terakhir.";
            } else {
                $title = 'Performa Turun';
                $body = "Konten '{$contentItem->title}' ({$clientName}) turun ".abs($percentChange)."% dari rata-rata performa 30 hari terakhir.";
            }

            foreach ($notifyUsers as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => $title,
                    'type' => 'ai_insight',
                    'body' => $body,
                    'related_type' => ContentItem::class,
                    'related_id' => $contentItem->id,
                    'is_read' => false,
                ]);
            }

            $count++;
        }

        return $count;
    }

    private function detectFailedSyncs($notifyUsers): int
    {
        $count = 0;

        $failedLogs = AnalyticsSyncLog::with('client')
            ->where('status', 'failed')
            ->whereDate('created_at', Carbon::today())
            ->get();

        foreach ($failedLogs as $log) {
            $alreadyNotified = Notification::where('related_type', AnalyticsSyncLog::class)
                ->where('related_id', $log->id)
                ->where('type', 'system')
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $clientName = $log->client->name ?? 'client tidak diketahui';
            $body = "Sinkronisasi/import data ({$log->source_type}) untuk {$clientName} gagal. Cek Settings > Analytics Integration.";

            foreach ($notifyUsers as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Sync Gagal',
                    'type' => 'system',
                    'body' => $body,
                    'related_type' => AnalyticsSyncLog::class,
                    'related_id' => $log->id,
                    'is_read' => false,
                ]);
            }

            $count++;
        }

        return $count;
    }
}