<?php

namespace App\Services;

use App\Exceptions\InstagramApiException;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPublication;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Orkestrasi sync Instagram 1 ApiIntegration - dipakai BARENG oleh Artisan
 * command (analytics:sync-instagram, synchronous, buat CLI/testing) dan
 * SyncInstagramAnalyticsJob (queue, buat tombol Sync Now di web) - biar
 * business logic-nya cuma ada satu tempat, nggak dobel.
 *
 * TIDAK bertanggung jawab: validasi --month, resolve client/user, cek
 * overlap sync - itu tetap tugas caller (Command/Job), karena beda konteks
 * (CLI option vs web request vs queue payload).
 */
class InstagramAnalyticsSyncService
{
    /**
     * @return array{0: string, 1: Carbon, 2: Carbon} [sync_mode, since, until]
     */
    public function resolveSyncWindow(?string $month): array
    {
        if (! $month) {
            $days = config('analytics.instagram_default_sync_days');

            // CATATAN CALENDAR SEMANTICS (pre-Phase-2 check): subDays($days)
            // TANPA "-1" di sini SENGAJA beda dari AnalyticsSummaryService::
            // buildOverviewData() yang pakai subDays($period - 1) - dashboard
            // period=90 mencakup 90 hari kalender (hari ini s/d 89 hari lalu,
            // inklusif keduanya), sedangkan ingestion di sini mencakup 91
            // hari kalender (hari ini s/d 90 hari lalu). Selisih 1 hari ini
            // DISENGAJA sebagai buffer aman - ingestion HARUS mencakup
            // >= horizon filter terpanjang (90 hari) supaya sync tidak
            // pernah kekurangan 1 hari persis di ujung window filter
            // terpanjang, bukan usaha nyamain angka "90" secara harfiah.
            return ['default', now()->subDays($days)->startOfDay(), now()];
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new \InvalidArgumentException("Format --month salah: '{$month}'. Wajib YYYY-MM (contoh: 2026-05).");
        }

        $since = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

        if ($since->isFuture()) {
            throw new \InvalidArgumentException("Bulan '{$month}' ada di masa depan - belum bisa disync.");
        }

        return ['historical', $since, $since->copy()->endOfMonth()];
    }

    /**
     * Jalankan sync penuh: profile -> media (dibatasi since/until) ->
     * insights -> matching -> content_metrics + instagram_media_snapshots.
     * $syncLog HARUS sudah dibuat caller dengan status 'pending' sebelum
     * ini dipanggil (biar UI bisa lihat "Syncing" begitu job/command mulai).
     *
     * Kalau InstagramApiException dilempar (token invalid, rate limit, dst),
     * method ini SENGAJA TIDAK mengubah status syncLog/integration ke
     * failed - itu keputusan CALLER (lihat markFailed() di bawah), karena
     * kalau errornya retryable (network/rate-limit/server_error), job akan
     * dicoba ulang otomatis dan syncLog seharusnya TETAP 'pending' selama
     * proses itu, bukan kelihatan "failed" padahal masih akan dicoba lagi.
     * Caller baru panggil markFailed() begitu benar-benar final (retry
     * habis, atau errornya memang nggak retryable dari awal).
     *
     * @return array{existing_matched: int, newly_matched: int, unmatched: int, ambiguous: int, failed: int, metrics_saved: int, details: array<int, string>, media_count: int, username: ?string}
     */
    public function sync(ApiIntegration $integration, AnalyticsSyncLog $syncLog, Carbon $since, Carbon $until, int $userId): array
    {
        $result = (new InstagramAnalyticsService($integration))->sync($since, $until);

        $profile = $result['profile'];
        $platform = Platform::find($integration->platform_id);

        $integration->update([
            'status' => 'active',
            'external_account_id' => $profile['id'] ?? null,
            'external_username' => $profile['username'] ?? null,
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        $summary = $this->persistMedia($result['media'], $platform, $integration, $userId, $syncLog);

        // metrics_saved = baris content_metrics yang beneran ke-upsert -
        // BUKAN lagi cuma existing_matched+newly_matched, soalnya unmatched/
        // ambiguous sekarang juga disimpan (lihat saveMetricSafely, dipanggil
        // di SEMUA jalur persistMedia()). Dulu ini bikin summary "0 metrics
        // saved" walau sebenarnya N baris berhasil kesimpen tiap kali semua
        // media di batch itu unmatched.
        $metricsSaved = $summary['metrics_saved'];
        $unresolvedCount = $summary['unmatched'] + $summary['ambiguous'];
        $status = ($metricsSaved > 0 || count($result['media']) === 0) ? 'success' : 'failed';

        $syncLog->update([
            'status' => $status,
            'synced_count' => $metricsSaved,
            'skipped_count' => $unresolvedCount + $summary['failed'],
            'error_message' => ! empty($summary['details']) ? implode(' | ', array_slice($summary['details'], 0, 8)) : null,
        ]);

        return [
            ...$summary,
            'metrics_saved' => $metricsSaved,
            'media_count' => count($result['media']),
            'username' => $profile['username'] ?? null,
            'account_media_count' => $profile['media_count'] ?? null,
        ];
    }

    /**
     * @return array{existing_matched: int, newly_matched: int, unmatched: int, ambiguous: int, failed: int, details: array<int, string>}
     */
    private function persistMedia(array $mediaResults, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog): array
    {
        $matcher = new ContentPublicationMatcher();

        $summary = ['existing_matched' => 0, 'newly_matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'failed' => 0, 'metrics_saved' => 0, 'details' => []];

        foreach ($mediaResults as $item) {
            $result = $matcher->match($integration, $item);

            if ($result->status === 'unmatched') {
                $summary['unmatched']++;
                $snapshot = $this->saveSnapshot($integration, $item, 'unmatched');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary);
                continue;
            }

            if ($result->status === 'ambiguous') {
                $summary['ambiguous']++;
                $summary['details'][] = "Media {$item['id']}: ambiguous - {$result->reason}";
                $snapshot = $this->saveSnapshot($integration, $item, 'ambiguous');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary);
                continue;
            }

            // "existing_matched" HANYA kalau external_post_id sudah kesimpen
            // dari sync sebelumnya (Priority 1, jalur cepat). Permalink
            // (Priority 2) juga balikin ContentPublication yang sudah ADA,
            // tapi external_post_id-nya baru di-backfill SEKARANG - itu
            // tetap "newly_matched" (baru pertama kali ke-link), bukan
            // existing, meski row-nya bukan row baru.
            $wasExisting = $result->matchedBy === 'external_post_id';

            try {
                $publication = $this->getOrCreatePublication($result, $item, $platform, $integration, $userId);
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['details'][] = "Media {$item['id']}: gagal simpan publication - {$e->getMessage()}";
                $snapshot = $this->saveSnapshot($integration, $item, 'unmatched');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary);
                continue;
            }

            $wasExisting ? $summary['existing_matched']++ : $summary['newly_matched']++;
            $snapshot = $this->saveSnapshot($integration, $item, 'matched', $publication->id);

            $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, $publication->content_item_id, $summary);
        }

        return $summary;
    }

    /**
     * Bungkus saveMetric() dengan try/catch generik + hitung ke
     * $summary['failed'] kalau gagal - dipakai baik jalur matched maupun
     * unmatched/ambiguous (SEMUA media sekarang tetap dapat baris
     * content_metrics, lihat docblock saveMetric()).
     */
    private function saveMetricSafely(array $item, InstagramMediaSnapshot $snapshot, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?int $contentItemId, array &$summary): void
    {
        try {
            $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
            $summary['metrics_saved']++;
        } catch (\Throwable $e) {
            $summary['failed']++;
            $summary['details'][] = "Media {$item['id']}: gagal simpan content_metrics - {$e->getMessage()}";

            return;
        }

        // content_metrics DI ATAS SUDAH tersimpan (try block barusan
        // berhasil) - kegagalan recordSnapshot() di sini TIDAK boleh
        // dilaporkan sebagai "gagal simpan content_metrics" (keliru, current-
        // state read path tetap utuh) TAPI juga TIDAK boleh didiamkan begitu
        // saja seolah sync ini sempurna (Langkah 10: partial condition harus
        // tercatat jelas). Dicatat ke $summary['details'] (ikut ke
        // syncLog->error_message lewat sync()) + Log::warning buat
        // visibilitas operasional - TANPA access token/secret apapun, TANPA
        // mengubah skema analytics_sync_logs.
        try {
            $this->recordSnapshot($snapshot, $item['metrics'], $integration, $platform, $contentItemId);
        } catch (\Throwable $e) {
            $summary['details'][] = SnapshotFailureMarker::wrap("Media {$item['id']}", $e->getMessage());
            Log::warning('ContentMetricSnapshot write failed after ContentMetric succeeded (Instagram)', [
                'client_id' => $integration->client_id,
                'instagram_media_snapshot_id' => $snapshot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Priority 1/2 (dari matcher) sudah balikin ContentPublication yang
     * sudah ada - tinggal backfill external_post_id/api_integration_id
     * kalau ketemu lewat permalink. Priority 3/4 cuma balikin ContentItem
     * kandidat (belum ada publication-nya), jadi baru dibuat di sini -
     * pakai updateOrCreate kunci (content_item_id, platform_id) (1
     * content_item cuma boleh punya 1 publication per platform).
     */
    private function getOrCreatePublication(ContentPublicationMatchResult $result, array $item, Platform $platform, ApiIntegration $integration, int $userId): ContentPublication
    {
        if ($result->publication) {
            if (! $result->publication->external_post_id) {
                $result->publication->update([
                    'external_post_id' => $item['id'],
                    'api_integration_id' => $integration->id,
                ]);
            }

            return $result->publication;
        }

        return ContentPublication::updateOrCreate(
            ['content_item_id' => $result->contentItem->id, 'platform_id' => $platform->id],
            [
                'external_post_id' => $item['id'],
                'api_integration_id' => $integration->id,
                'published_by' => $userId,
                'published_at' => Carbon::parse($item['timestamp'] ?? now()),
                'post_url' => $item['permalink'] ?? null,
                'caption_final' => $item['caption'] ?? null,
            ]
        );
    }

    /**
     * Upsert snapshot media ini ke instagram_media_snapshots - dipanggil
     * buat SETIAP media yang diproses. Unique key (api_integration_id,
     * external_post_id) bikin ini aman dipanggil berkali-kali (repeated
     * sync, retry job, overlap default/historical) tanpa duplicate.
     */
    private function saveSnapshot(ApiIntegration $integration, array $item, string $matchStatus, ?int $contentPublicationId = null): InstagramMediaSnapshot
    {
        return InstagramMediaSnapshot::updateOrCreate(
            ['api_integration_id' => $integration->id, 'external_post_id' => $item['id']],
            [
                'permalink' => $item['permalink'] ?? null,
                'caption' => $item['caption'] ?? null,
                'media_type' => $item['media_type'] ?? null,
                'media_product_type' => $item['media_product_type'] ?? null,
                'published_at' => $item['timestamp'] ? Carbon::parse($item['timestamp']) : null,
                'thumbnail_url' => $item['thumbnail_url'] ?? null,
                'match_status' => $matchStatus,
                'content_publication_id' => $contentPublicationId,
                'last_fetched_at' => now(),
            ]
        );
    }

    /**
     * Simpan insight media ke content_metrics buat SEMUA media (matched
     * MAUPUN unmatched/ambiguous) - dulu insight yang udah kepanggil dari
     * API cuma disimpan kalau matched, sisanya dibuang percuma (lihat audit
     * "Data Source Architecture"). Sekarang content_metrics jadi
     * source-of-truth tunggal analytics: client_id + instagram_media_
     * snapshot_id SELALU diisi (post Instagram real, apapun status
     * matching-nya), content_item_id cuma keisi kalau memang sudah/baru
     * ke-link.
     *
     * Kunci upsert: (instagram_media_snapshot_id, metric_date) - BUKAN lagi
     * (content_item_id, platform_id, metric_date) yang cuma jalan buat CSV
     * (unique constraint lama itu TETAP dipertahankan, tidak dihapus).
     * Ini juga yang bikin manual-link/schedule-match nanti tinggal UPDATE
     * content_item_id di baris yang SAMA (dicari lewat snapshot_id), bukan
     * bikin baris metric baru - lihat ContentPublicationController::
     * linkInstagramMedia() & getOrCreatePublication() di atas.
     */
    private function saveMetric(array $item, InstagramMediaSnapshot $snapshot, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?int $contentItemId): void
    {
        $metrics = $item['metrics'];

        ContentMetric::updateOrCreate(
            [
                'instagram_media_snapshot_id' => $snapshot->id,
                // Metric Instagram bersifat kumulatif sejak post terbit,
                // bukan harian - dikunci ke tanggal post asli biar sync
                // berulang (termasuk retry job/overlap historical) nge-update
                // baris yang sama, bukan bikin baris baru.
                'metric_date' => Carbon::parse($item['timestamp'] ?? now())->toDateString(),
            ],
            [
                'content_item_id' => $contentItemId,
                'client_id' => $integration->client_id,
                'platform_id' => $platform->id,
                'imported_by' => $userId,
                'sync_log_id' => $syncLog->id,
                'views' => $metrics['views'] ?? 0,
                'engagement_rate' => $this->computeEngagementRate($metrics),
                'reach' => $metrics['reach'],
                'impressions' => $metrics['impressions'],
                'likes' => $metrics['likes'],
                'comments' => $metrics['comments'],
                'shares' => $metrics['shares'],
                'saves' => $metrics['saves'],
            ]
        );
    }

    /**
     * Tulis 1 baris content_metric_snapshots per (media, tanggal SYNC hari
     * ini) - observasi cumulative state TERPISAH dari content_metrics di
     * atas (yang dikunci ke tanggal publish). snapshot_date SELALU
     * now()->toDateString() - never publish date, never backfilled - baris
     * ini representasi "kondisi metric pada saat sync dijalankan", bukan
     * riwayat historis yang direkonstruksi.
     *
     * Identitas row = (instagram_media_snapshot_id, snapshot_date), BUKAN
     * content_item_id - content_item_id cuma metadata nullable di sini,
     * media yang belum/baru di-link tetap dapat baris snapshot yang sama
     * (lihat ContentPublicationController::linkInstagramMedia()).
     * updateOrCreate dengan key itu bikin sync berulang di hari yang sama
     * (retry job, manual sync 2x) meng-update baris yang sama, bukan bikin
     * duplicate.
     *
     * Metric APAPUN diteruskan TANPA "?? 0" (beda dari ContentMetric::views
     * di atas yang kolomnya NOT NULL) - content_metric_snapshots.views dkk
     * semua nullable, jadi NULL asli dari normalizeMetrics() (metric yang
     * memang tidak tersedia dari API) harus tetap NULL, bukan didefault ke
     * nol. profile_visit/watch_time_avg/completion_rate SENGAJA tidak
     * disebut di array values (dibiarkan default NULL kolom) - Instagram
     * API contract (InstagramAnalyticsService::normalizeMetrics()) tidak
     * pernah menyediakan tiga metric ini sama sekali.
     */
    private function recordSnapshot(InstagramMediaSnapshot $snapshot, array $metrics, ApiIntegration $integration, Platform $platform, ?int $contentItemId): void
    {
        ContentMetricSnapshot::updateOrCreate(
            [
                'instagram_media_snapshot_id' => $snapshot->id,
                'snapshot_date' => now()->toDateString(),
            ],
            [
                'client_id' => $integration->client_id,
                'platform_id' => $platform->id,
                'content_item_id' => $contentItemId,
                'views' => $metrics['views'] ?? null,
                'reach' => $metrics['reach'] ?? null,
                'impressions' => $metrics['impressions'] ?? null,
                'likes' => $metrics['likes'] ?? null,
                'comments' => $metrics['comments'] ?? null,
                'shares' => $metrics['shares'] ?? null,
                'saves' => $metrics['saves'] ?? null,
                'engagement_rate' => $this->computeSnapshotEngagementRate($metrics),
            ]
        );
    }

    /**
     * engagement_rate kolom NOT NULL (decimal 5,2) - dihitung dari total
     * interaksi / reach (fallback views kalau reach nggak ada), dibulatkan
     * 2 desimal dan di-cap biar nggak overflow kolomnya.
     */
    private function computeEngagementRate(array $metrics): float
    {
        $interactions = ($metrics['likes'] ?? 0) + ($metrics['comments'] ?? 0)
            + ($metrics['shares'] ?? 0) + ($metrics['saves'] ?? 0);

        $denominator = $metrics['reach'] ?? $metrics['views'] ?? 0;

        if ($denominator <= 0) {
            return 0.0;
        }

        return round(min($interactions / $denominator * 100, 999.99), 2);
    }

    /**
     * Versi NULL-safe computeEngagementRate() KHUSUS content_metric_snapshots
     * (kolomnya nullable, beda dari content_metrics yang NOT NULL) - kalau
     * denominatornya (reach & views) dua-duanya benar-benar tidak tersedia
     * dari API, engagement rate memang TIDAK BISA dihitung sama sekali,
     * jadi hasilnya NULL ("belum diketahui"), BUKAN 0.0 ("diketahui nol") -
     * disiplin NULL != 0 yang sama diterapkan ke metric mentah di atas.
     */
    private function computeSnapshotEngagementRate(array $metrics): ?float
    {
        if (($metrics['reach'] ?? null) === null && ($metrics['views'] ?? null) === null) {
            return null;
        }

        return $this->computeEngagementRate($metrics);
    }

    /**
     * Dipanggil CALLER (Command/Job) begitu sync ini benar-benar final
     * gagal - non-retryable dari awal, atau retry sudah habis. TIDAK
     * dipanggil otomatis dari sync() sendiri (lihat catatan di atas).
     *
     * $integration cuma ditandai 'inactive' (butuh Reconnect) kalau
     * kategorinya AUTHENTICATION - token yang benar-benar rusak. Kegagalan
     * lain (network abis retry, server error, unknown) TIDAK berarti
     * koneksinya rusak - token-nya mungkin masih sah, cuma Instagram/
     * jaringan lagi bermasalah - jadi status integration dibiarkan apa
     * adanya, cuma last_error yang diisi buat visibilitas.
     */
    public function markFailed(ApiIntegration $integration, AnalyticsSyncLog $syncLog, string $message, string $category = InstagramApiException::UNKNOWN): void
    {
        $syncLog->update([
            'status' => 'failed',
            'synced_count' => 0,
            'skipped_count' => 0,
            'error_message' => $message,
        ]);

        $updates = ['last_error' => $message];
        if ($category === InstagramApiException::AUTHENTICATION) {
            $updates['status'] = 'inactive';
        }

        $integration->update($updates);
    }
}
