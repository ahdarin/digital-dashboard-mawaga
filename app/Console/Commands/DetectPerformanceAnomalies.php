<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSyncLog;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\Notification;
use App\Models\PerformanceAnomaly;
use App\Models\User;
use App\Services\PeriodPerformanceService;
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

    public function handle(PeriodPerformanceService $periodPerformanceService): int
    {
        // Deteksi & rekam anomali tetap jalan biarpun nggak ada user
        // CEO/Admin buat dikirimin notifikasi - PerformanceAnomaly yang
        // direkam dipakai juga sama AiStrategyService (buildPerformanceSummary())
        // buat konteks AI Strategy bulan berikutnya, jadi jangan berhenti
        // total kalau notifyUsers kosong, cukup lewatin loop notifikasinya.
        $notifyUsers = User::with('roles')->get()->filter(fn ($u) => $u->canSeeAllClients());

        if ($notifyUsers->isEmpty()) {
            $this->warn('Nggak ada user CEO/Admin - anomali tetap direkam, notifikasi dilewati.');
        }

        $anomalyCount = $this->detectContentAnomalies($notifyUsers)
            + $this->detectApiContentAnomalies($notifyUsers, $periodPerformanceService);
        $syncFailCount = $this->detectFailedSyncs($notifyUsers);

        $this->info("Selesai. {$anomalyCount} anomali performa, {$syncFailCount} sync gagal ter-notifikasi.");

        return self::SUCCESS;
    }

    /**
     * Jalur CSV/manual (Langkah 9H) - TIDAK DIUBAH sama sekali dari
     * semantik lama. metric_date CSV ADALAH observasi genuine per-tanggal
     * yang user ketik sendiri (bukan dikunci ke publish date seperti API),
     * jadi "metrik hari ini" + "rata-rata metric_date < hari ini" TETAP
     * valid dibandingkan langsung di sini - beda dari content API (lihat
     * detectApiContentAnomalies() di bawah, yang genuinely butuh
     * content_metric_snapshots karena content_metrics API cuma py 1 baris
     * per konten, terkunci ke tanggal publish).
     */
    private function detectContentAnomalies($notifyUsers): int
    {
        $today = Carbon::today();
        $count = 0;

        // Ambil semua content item CSV/manual (snapshot FK dua-duanya null)
        // yang punya metrik HARI INI - cuma yang relevan dicek, biar
        // command-nya ringan dan nggak nyisir seluruh histori tiap kali
        // jalan.
        $contentItemIds = ContentMetric::whereDate('metric_date', $today)
            ->whereNull('instagram_media_snapshot_id')
            ->whereNull('tiktok_video_snapshot_id')
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

            // Hindari rekam+notif dobel buat konten+tanggal yang sama - dicek
            // dari PerformanceAnomaly (bukan Notification lagi), soalnya ini
            // sumber kebenaran tunggal sekarang & tetap konsisten walau
            // notifyUsers kosong di run sebelumnya (jadi nggak pernah ada
            // Notification, tapi anomalinya udah kerekam).
            $alreadyRecorded = PerformanceAnomaly::where('content_item_id', $contentItemId)
                ->whereDate('detected_date', $today)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            $percentChange = round(($ratio - 1) * 100);
            $clientName = $contentItem->client->name ?? 'Client';

            PerformanceAnomaly::create([
                'content_item_id' => $contentItem->id,
                'type' => $anomalyType,
                'percent_change' => $percentChange,
                'views_on_date' => $todayMetric->views,
                'baseline_avg_views' => (int) round($avgViews),
                'detected_date' => $today,
            ]);

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

    /**
     * Jalur content API (Instagram/TikTok) - Langkah 9H, "jangan lagi
     * bergantung pada ContentMetric.metric_date sebagai daily API
     * observation". content_metrics API cuma py 1 baris per konten
     * (dikunci ke tanggal publish, di-upsert selamanya) - "metrik hari ini
     * vs rata-rata metric_date sebelumnya" TIDAK PERNAH match buat konten
     * yang sudah lama di-publish (dulu command ini praktis INERT buat
     * konten API). Fix: bandingkan GAIN HARIAN dari content_metric_snapshots
     * (delta cumulative genuine, PeriodPerformanceService::computeContentDailyGains())
     * - views_on_date/baseline_avg_views SEKARANG berarti "gain HARI INI"/
     * "rata-rata gain harian 30 hari" (BUKAN raw cumulative views seperti
     * jalur CSV di atas) - kolom PerformanceAnomaly TIDAK diubah (no
     * migration), cuma makna angkanya buat sumber API.
     */
    private function detectApiContentAnomalies($notifyUsers, PeriodPerformanceService $periodPerformanceService): int
    {
        $today = Carbon::today();
        $rangeStart = $today->copy()->subDays(31);
        $count = 0;

        $todayUnits = ContentMetricSnapshot::whereDate('snapshot_date', $today)
            ->get(['instagram_media_snapshot_id', 'tiktok_video_snapshot_id'])
            ->unique(fn ($s) => $s->instagram_media_snapshot_id ? 'ig-'.$s->instagram_media_snapshot_id : 'tt-'.$s->tiktok_video_snapshot_id);

        foreach ($todayUnits as $unit) {
            $identityColumn = $unit->instagram_media_snapshot_id ? 'instagram_media_snapshot_id' : 'tiktok_video_snapshot_id';
            $identityId = $unit->instagram_media_snapshot_id ?? $unit->tiktok_video_snapshot_id;

            $contentMetric = ContentMetric::where($identityColumn, $identityId)->first();
            if (! $contentMetric || ! $contentMetric->content_item_id) {
                // Sama seperti gap lama jalur CSV (unmatched belum dicek) -
                // TIDAK diperluas scope-nya di sini, konsisten dgn behavior
                // command ini sebelumnya.
                continue;
            }

            $contentItem = ContentItem::with('client')->find($contentMetric->content_item_id);
            if (! $contentItem) {
                continue;
            }

            $gains = $periodPerformanceService->computeContentDailyGains($identityColumn, $identityId, $rangeStart, $today);
            $todayKey = $today->toDateString();

            if (! isset($gains[$todayKey])) {
                continue; // baseline kemarin hilang / metric reset - tidak ada gain HARI INI yang valid dihitung
            }

            $todayGain = $gains[$todayKey];
            $baselineGains = collect($gains)->except($todayKey);

            if ($baselineGains->count() < self::MIN_BASELINE_DAYS) {
                continue; // data historisnya belum cukup buat dibandingin
            }

            $avgGain = $baselineGains->avg();
            if ($avgGain <= 0) {
                continue;
            }

            $ratio = $todayGain / $avgGain;

            $anomalyType = null;
            if ($ratio >= self::SPIKE_THRESHOLD) {
                $anomalyType = 'spike';
            } elseif ($ratio <= self::DROP_THRESHOLD) {
                $anomalyType = 'drop';
            }

            if (! $anomalyType) {
                continue;
            }

            $alreadyRecorded = PerformanceAnomaly::where('content_item_id', $contentItem->id)
                ->whereDate('detected_date', $today)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            $percentChange = round(($ratio - 1) * 100);
            $clientName = $contentItem->client->name ?? 'Client';

            PerformanceAnomaly::create([
                'content_item_id' => $contentItem->id,
                'type' => $anomalyType,
                'percent_change' => $percentChange,
                'views_on_date' => $todayGain,
                'baseline_avg_views' => (int) round($avgGain),
                'detected_date' => $today,
            ]);

            if ($anomalyType === 'spike') {
                $title = 'Trend Detected';
                $body = "Konten '{$contentItem->title}' ({$clientName}) mendapat gain views {$percentChange}% di atas rata-rata gain harian 30 hari terakhir.";
            } else {
                $title = 'Performa Turun';
                $body = "Konten '{$contentItem->title}' ({$clientName}) gain views turun ".abs($percentChange)."% dari rata-rata gain harian 30 hari terakhir.";
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