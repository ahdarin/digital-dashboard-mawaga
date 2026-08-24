<?php

namespace App\Services;

use App\Exceptions\TikTokApiException;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\ContentMetric;
use App\Models\ContentPublication;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use Illuminate\Support\Carbon;

/**
 * Orkestrasi sync TikTok 1 ApiIntegration - MIRROR InstagramAnalyticsSyncService
 * (dipakai bareng Artisan command & SyncTikTokAnalyticsJob, biar business
 * logic cuma ada satu tempat), TAPI field & metric mapping TikTok BEDA dari
 * Instagram - lihat computeEngagementRate() & saveMetric() di bawah, JANGAN
 * asumsikan formula/field Instagram berlaku sama di sini (Langkah 2 & 18,
 * "TikTok API fields != Instagram fields", "Do not blindly use an Instagram
 * engagement formula").
 *
 * TIDAK bertanggung jawab: validasi --month, resolve client/user, cek
 * overlap sync - tetap tugas caller (Command/Job), sama seperti Instagram.
 */
class TikTokAnalyticsSyncService
{
    /**
     * @return array{0: string, 1: Carbon, 2: Carbon} [sync_mode, since, until]
     */
    public function resolveSyncWindow(?string $month): array
    {
        if (! $month) {
            $months = config('analytics.tiktok_default_sync_months');

            return ['default', now()->subMonths($months)->startOfDay(), now()];
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
     * Jalankan sync penuh: profile -> video list (dibatasi $until, TikTok
     * TIDAK punya filter since server-side - lihat TikTokAnalyticsService::
     * getVideoList()) -> matching -> content_metrics + tiktok_video_snapshots.
     * $syncLog HARUS sudah dibuat caller dengan status 'pending'.
     *
     * @return array{existing_matched: int, newly_matched: int, unmatched: int, ambiguous: int, failed: int, metrics_saved: int, details: array<int, string>, video_count: int, username: ?string, has_more: bool, stopped_early: bool, oldest_fetched: ?string, newest_fetched: ?string}
     */
    public function sync(ApiIntegration $integration, AnalyticsSyncLog $syncLog, Carbon $until, int $userId): array
    {
        $result = (new TikTokAnalyticsService($integration))->sync($until);

        $profile = $result['profile'];
        $platform = Platform::find($integration->platform_id);

        $integration->update([
            'status' => 'active',
            'external_account_id' => $profile['open_id'] ?? null,
            'external_username' => $profile['username'] ?? $profile['display_name'] ?? null,
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        // Profile stats (follower_count dkk) DISATUKAN ke alur sync ini
        // (bukan tombol/Job terpisah) - keputusan desain sengaja, lihat
        // docs/TIKTOK_INTEGRATION.md "Profile/Stats Sync": user/info/
        // dipanggil SEKALI per sync (sama seperti Instagram getProfile()),
        // jadi menambah Job/tombol kedua cuma buat data yang sudah didapat
        // gratis di panggilan yang sama adalah pemborosan API call, bukan
        // fitur baru.
        $this->saveProfileSnapshot($integration, $profile);

        $summary = $this->persistVideos($result['videos'], $platform, $integration, $userId, $syncLog);

        $metricsSaved = $summary['metrics_saved'];
        $unresolvedCount = $summary['unmatched'] + $summary['ambiguous'];
        $status = ($metricsSaved > 0 || count($result['videos']) === 0) ? 'success' : 'failed';

        $syncLog->update([
            'status' => $status,
            'synced_count' => $metricsSaved,
            'skipped_count' => $unresolvedCount + $summary['failed'],
            'error_message' => ! empty($summary['details']) ? implode(' | ', array_slice($summary['details'], 0, 8)) : null,
        ]);

        return [
            ...$summary,
            'metrics_saved' => $metricsSaved,
            'video_count' => count($result['videos']),
            'username' => $profile['username'] ?? $profile['display_name'] ?? null,
            'has_more' => $result['has_more'],
            'stopped_early' => $result['stopped_early'],
            'oldest_fetched' => $result['oldest_fetched'] ? Carbon::createFromTimestamp($result['oldest_fetched'])->toDateTimeString() : null,
            'newest_fetched' => $result['newest_fetched'] ? Carbon::createFromTimestamp($result['newest_fetched'])->toDateTimeString() : null,
        ];
    }

    /**
     * follower_count/following_count/likes_count/video_count (kalau scope
     * user.info.stats granted) disimpan sebagai snapshot TERTANGGAL lewat
     * AudienceInsight generik yang SUDAH ADA (bukan tabel/model baru) -
     * platform_id sudah cukup mengisolasi ini dari data Instagram (Langkah
     * 9 & 13). Field demografis (gender/age/top_locations/active_hours)
     * SENGAJA TIDAK diisi (tetap NULL) - standar TikTok Display API TIDAK
     * menyediakan ini, dan NULL bermakna "tidak tersedia", BUKAN nol
     * (Langkah 9, "DO NOT fabricate ... NULL/unavailable != zero").
     *
     * Kalau scope user.info.stats TIDAK granted (follower_count dkk tidak
     * ada di $profile sama sekali), snapshot TIDAK dibuat - UI harus
     * menampilkan "Data tidak tersedia melalui TikTok API", bukan baris
     * kosong bermakna 0.
     */
    private function saveProfileSnapshot(ApiIntegration $integration, array $profile): void
    {
        if (! array_key_exists('follower_count', $profile)) {
            return;
        }

        \App\Models\AudienceInsight::updateOrCreate(
            [
                'client_id' => $integration->client_id,
                'platform_id' => $integration->platform_id,
                'source' => \App\Models\AudienceInsight::SOURCE_TIKTOK_API,
                'demographic_type' => \App\Models\AudienceInsight::TYPE_SUMMARY,
                'snapshot_date' => now()->toDateString(),
            ],
            [
                'follower_count' => $profile['follower_count'] ?? null,
            ]
        );
    }

    /**
     * @return array{existing_matched: int, newly_matched: int, unmatched: int, ambiguous: int, failed: int, details: array<int, string>}
     */
    private function persistVideos(array $videoResults, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog): array
    {
        // ContentPublicationMatcher DIPAKAI APA ADANYA (tidak ada versi
        // TikTok terpisah) - class itu sudah generik lewat $integration->
        // platform_id, lihat audit arsitektur di docs/TIKTOK_INTEGRATION.md.
        $matcher = new ContentPublicationMatcher();

        $summary = ['existing_matched' => 0, 'newly_matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'failed' => 0, 'metrics_saved' => 0, 'details' => []];

        foreach ($videoResults as $item) {
            // ContentPublicationMatcher mengharap key generik (id, permalink,
            // timestamp, caption) - TikTok pakai nama field beda (share_url,
            // create_time, title/video_description), jadi di-remap dulu di
            // sini SEBELUM masuk matcher, biar matcher tetap platform-agnostic
            // tanpa perlu tahu bentuk asli tiap API.
            $media = [
                'id' => $item['id'],
                'permalink' => $item['share_url'] ?? null,
                'timestamp' => isset($item['create_time']) ? Carbon::createFromTimestamp($item['create_time'])->toIso8601String() : null,
                'caption' => $item['video_description'] ?? $item['title'] ?? null,
            ];

            $result = $matcher->match($integration, $media);

            if ($result->status === 'unmatched') {
                $summary['unmatched']++;
                $snapshot = $this->saveSnapshot($integration, $item, 'unmatched');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary);
                continue;
            }

            if ($result->status === 'ambiguous') {
                $summary['ambiguous']++;
                $summary['details'][] = "Video {$item['id']}: ambiguous - {$result->reason}";
                $snapshot = $this->saveSnapshot($integration, $item, 'ambiguous');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary);
                continue;
            }

            $wasExisting = $result->matchedBy === 'external_post_id';

            try {
                $publication = $this->getOrCreatePublication($result, $media, $platform, $integration, $userId);
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['details'][] = "Video {$item['id']}: gagal simpan publication - {$e->getMessage()}";
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

    private function saveMetricSafely(array $item, TikTokVideoSnapshot $snapshot, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?int $contentItemId, array &$summary): void
    {
        try {
            $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
            $summary['metrics_saved']++;
        } catch (\Throwable $e) {
            $summary['failed']++;
            $summary['details'][] = "Video {$item['id']}: gagal simpan content_metrics - {$e->getMessage()}";
        }
    }

    private function getOrCreatePublication(ContentPublicationMatchResult $result, array $media, Platform $platform, ApiIntegration $integration, int $userId): ContentPublication
    {
        if ($result->publication) {
            if (! $result->publication->external_post_id) {
                $result->publication->update([
                    'external_post_id' => $media['id'],
                    'api_integration_id' => $integration->id,
                ]);
            }

            return $result->publication;
        }

        return ContentPublication::updateOrCreate(
            ['content_item_id' => $result->contentItem->id, 'platform_id' => $platform->id],
            [
                'external_post_id' => $media['id'],
                'api_integration_id' => $integration->id,
                'published_by' => $userId,
                'published_at' => $media['timestamp'] ? Carbon::parse($media['timestamp']) : now(),
                'post_url' => $media['permalink'],
                'caption_final' => $media['caption'],
            ]
        );
    }

    /**
     * Upsert snapshot video ini ke tiktok_video_snapshots - dipanggil buat
     * SETIAP video yang diproses (matched/unmatched/ambiguous), sama pola
     * dengan InstagramAnalyticsSyncService::saveSnapshot(). Unique key
     * (api_integration_id, external_post_id) bikin ini aman dipanggil
     * berkali-kali tanpa duplicate.
     */
    private function saveSnapshot(ApiIntegration $integration, array $item, string $matchStatus, ?int $contentPublicationId = null): TikTokVideoSnapshot
    {
        return TikTokVideoSnapshot::updateOrCreate(
            ['api_integration_id' => $integration->id, 'external_post_id' => $item['id']],
            [
                'share_url' => $item['share_url'] ?? null,
                'title' => $item['title'] ?? null,
                'video_description' => $item['video_description'] ?? null,
                'duration' => $item['duration'] ?? null,
                'cover_image_url' => $item['cover_image_url'] ?? null,
                'match_status' => $matchStatus,
                'content_publication_id' => $contentPublicationId,
                'published_at' => isset($item['create_time']) ? Carbon::createFromTimestamp($item['create_time']) : null,
                'last_fetched_at' => now(),
            ]
        );
    }

    /**
     * Simpan metrik video ke content_metrics buat SEMUA video (matched
     * MAUPUN unmatched/ambiguous) - sama pola dengan Instagram. Kunci
     * upsert: (tiktok_video_snapshot_id, metric_date) - kolom TERPISAH
     * dari instagram_media_snapshot_id, tidak pernah tabrakan.
     *
     * MAPPING METRIK RESMI (Langkah 12, TikTok video/list & video/query):
     *   view_count    -> views
     *   like_count    -> likes
     *   comment_count -> comments
     *   share_count   -> shares
     *
     * reach/impressions/saves/profile_visit SELALU NULL - TikTok Display
     * API v2 standar TIDAK mengembalikan metrik ini untuk video publik
     * (beda dari Instagram Graph API yang punya /insights per media).
     * JANGAN diisi 0 - NULL secara eksplisit berarti "API ini tidak
     * menyediakan", 0 berarti "API bilang nilainya nol" - dua makna beda
     * (Langkah 12, "Unavailable metric: NULL not 0").
     */
    private function saveMetric(array $item, TikTokVideoSnapshot $snapshot, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?int $contentItemId): void
    {
        ContentMetric::updateOrCreate(
            [
                'tiktok_video_snapshot_id' => $snapshot->id,
                // Metrik TikTok kumulatif sejak video terbit (sama sifatnya
                // dengan Instagram) - dikunci ke tanggal publish video asli,
                // bukan tanggal sync, biar sync berulang update baris yang
                // sama.
                'metric_date' => isset($item['create_time']) ? Carbon::createFromTimestamp($item['create_time'])->toDateString() : now()->toDateString(),
            ],
            [
                'content_item_id' => $contentItemId,
                'client_id' => $integration->client_id,
                'platform_id' => $platform->id,
                'imported_by' => $userId,
                'sync_log_id' => $syncLog->id,
                'views' => $item['view_count'] ?? 0,
                'engagement_rate' => $this->computeEngagementRate($item),
                'likes' => $item['like_count'] ?? null,
                'comments' => $item['comment_count'] ?? null,
                'shares' => $item['share_count'] ?? null,
                // Kolom yang TikTok Display API standar TIDAK sediakan -
                // dibiarkan null lewat updateOrCreate (tidak disebut di sini
                // = default model/kolom, yang untuk reach/impressions/saves/
                // profile_visit semuanya nullable tanpa default non-null).
            ]
        );
    }

    /**
     * FORMULA TIKTOK - SENGAJA TERPISAH dari Instagram
     * (InstagramAnalyticsSyncService::computeEngagementRate() memprioritaskan
     * `reach`, fallback ke `views`). TikTok Display API standar TIDAK PERNAH
     * menyediakan reach, jadi denominatornya SELALU views - bukan kebetulan
     * "fallback jarang kepakai" seperti di Instagram, tapi memang satu-satunya
     * pilihan valid untuk platform ini. Didokumentasikan eksplisit di
     * docs/TIKTOK_INTEGRATION.md "Engagement Formula" - JANGAN diubah diam-diam
     * kalau nanti scope tambahan TikTok memberi metrik baru, update dokumen +
     * komentar ini bersamaan (Langkah 18, "Do not silently change... formula").
     *
     * engagement_rate = (likes + comments + shares) / views * 100
     */
    private function computeEngagementRate(array $item): float
    {
        $views = $item['view_count'] ?? 0;

        if ($views <= 0) {
            return 0.0;
        }

        $interactions = ($item['like_count'] ?? 0) + ($item['comment_count'] ?? 0) + ($item['share_count'] ?? 0);

        return round(min($interactions / $views * 100, 999.99), 2);
    }

    /**
     * Sama semantik persis dengan InstagramAnalyticsSyncService::markFailed()
     * - integration cuma ditandai 'inactive' (butuh Reconnect) kalau
     * kategorinya AUTHENTICATION (token/scope benar-benar rusak).
     */
    public function markFailed(ApiIntegration $integration, AnalyticsSyncLog $syncLog, string $message, string $category = TikTokApiException::UNKNOWN): void
    {
        $syncLog->update([
            'status' => 'failed',
            'synced_count' => 0,
            'skipped_count' => 0,
            'error_message' => $message,
        ]);

        $updates = ['last_error' => $message];
        if ($category === TikTokApiException::AUTHENTICATION) {
            $updates['status'] = 'inactive';
        }

        $integration->update($updates);
    }
}
