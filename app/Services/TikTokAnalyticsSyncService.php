<?php

namespace App\Services;

use App\Exceptions\TikTokApiException;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPublication;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
            $days = config('analytics.tiktok_default_sync_days');

            // Sama persis catatan calendar semantics di
            // InstagramAnalyticsSyncService::resolveSyncWindow() - subDays($days)
            // TANPA "-1" DISENGAJA (91 hari kalender ingestion vs 90 hari
            // kalender di filter dashboard) sebagai buffer aman 1 hari,
            // bukan angka yang belum diaudit.
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
     * Jalankan sync penuh: profile -> video list (dibatasi $cutoff = lower
     * bound rentang sync, TikTok TIDAK punya filter since server-side -
     * lihat TikTokAnalyticsService::getVideoList()) -> matching ->
     * content_metrics + tiktok_video_snapshots. $syncLog HARUS sudah dibuat
     * caller dengan status 'pending'.
     *
     * @return array{existing_matched: int, newly_matched: int, unmatched: int, ambiguous: int, failed: int, metrics_saved: int, details: array<int, string>, video_count: int, username: ?string, has_more: bool, stopped_early: bool, oldest_fetched: ?string, newest_fetched: ?string}
     */
    public function sync(ApiIntegration $integration, AnalyticsSyncLog $syncLog, Carbon $cutoff, int $userId): array
    {
        $result = (new TikTokAnalyticsService($integration))->sync($cutoff);

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
     * Snapshot maintenance correction (audit sync horizon, "keep discovery
     * and observation separate") - refresh metrik buat video yang SUDAH
     * DIKENAL sistem. video/list TIDAK PERNAH mengembalikan video lama
     * lagi begitu terlewat cutoff $since, padahal video itu genuinely
     * bisa masih dapat views/engagement baru hari ini - method ini TIDAK
     * melakukan discovery ulang (tidak ada paging list), cukup query
     * LANGSUNG by ID lewat TikTokAnalyticsService::queryVideos() (endpoint
     * video/query/ resmi TikTok, dirancang persis buat ini - lihat
     * docblock-nya), di-batch 20 ID/request (limit resmi TikTok, BUKAN
     * pilihan kita).
     *
     * SENGAJA TIDAK dibatasi published_at/discovery window/retention
     * window sama sekali - content age TIDAK menentukan apakah observasi
     * hari ini masih dibutuhkan. SELURUH known video integration ini
     * eligible (termasuk unmatched).
     *
     * Selection: rotating, urut last_fetched_at ASC (paling lama tidak
     * di-refresh duluan - "IS NOT NULL" duluan di ORDER BY supaya NULL,
     * kalau pernah ada, selalu diprioritaskan PALING AWAL; kolom ini
     * NOT NULL di schema saat ini jadi baris NULL genuine belum pernah
     * terjadi, tapi urutan ini tetap defensif benar kalau itu berubah),
     * dibatasi config('analytics.tiktok_known_refresh_budget') video per
     * panggilan (jauh lebih besar dari budget Instagram - queryVideos()
     * genuinely batched 20/request, biayanya jauh lebih murah per video).
     *
     * Dipanggil TERPISAH dari sync() normal oleh caller (Command/Job),
     * DIBUNGKUS try/catch DI CALLER - kegagalan tak terduga di sini TIDAK
     * PERNAH boleh menggagalkan/retry-loop sync utama yang sudah berhasil.
     * TAPI failed_count > 0 TETAP direkam ke $syncLog->error_message lewat
     * KnownContentRefreshFailureMarker, supaya AnalyticsSyncOrchestrator
     * bisa menurunkan status jadi 'partial'.
     *
     * @return array{refreshed_count: int, failed_count: int, skipped_count: int, total_count: int, auth_failed: bool}
     */
    public function refreshKnownVideos(ApiIntegration $integration, AnalyticsSyncLog $syncLog, int $userId): array
    {
        $budget = max(0, (int) config('analytics.tiktok_known_refresh_budget'));

        $staleKnownVideos = TikTokVideoSnapshot::where('api_integration_id', $integration->id)
            ->orderByRaw('last_fetched_at IS NOT NULL')
            ->orderBy('last_fetched_at', 'asc')
            ->limit($budget)
            ->get(['id', 'external_post_id', 'content_publication_id']);

        $summary = ['refreshed_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'total_count' => $staleKnownVideos->count(), 'auth_failed' => false];

        if ($staleKnownVideos->isEmpty()) {
            return $summary;
        }

        $platform = Platform::find($integration->platform_id);
        $service = new TikTokAnalyticsService($integration);
        $byExternalId = $staleKnownVideos->keyBy('external_post_id');

        foreach ($staleKnownVideos->pluck('external_post_id')->chunk(20) as $batch) {
            try {
                $videoResults = $service->queryVideos($batch->values()->all());
            } catch (TikTokApiException $e) {
                $summary['failed_count'] += $batch->count();

                if ($e->category === TikTokApiException::AUTHENTICATION) {
                    // Token rusak - batch berikutnya juga pasti gagal
                    // identik, percuma lanjut. Integration ditandai butuh
                    // reconnect (Langkah 6) - BUKAN sekadar failed_count
                    // tinggi yang tidak actionable.
                    $summary['auth_failed'] = true;
                    $this->markNeedsReconnect($integration, $e->getMessage());
                    break;
                }

                Log::warning('TikTok refreshKnownVideos: queryVideos batch gagal, dilewati (sync utama TIDAK terpengaruh)', [
                    'client_id' => $integration->client_id,
                    'batch_size' => $batch->count(),
                    'category' => $e->category,
                    'error' => $e->getMessage(),
                ]);
                // Transient (network/rate_limit/server_error/malformed) -
                // TIDAK advance last_fetched_at video di batch ini, coba
                // lagi rotasi berikutnya lebih cepat.
                continue;
            } catch (\Throwable $e) {
                $summary['failed_count'] += $batch->count();
                Log::warning('TikTok refreshKnownVideos: queryVideos batch gagal (exception tak terduga), dilewati', [
                    'client_id' => $integration->client_id,
                    'batch_size' => $batch->count(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $returnedIds = [];
            foreach ($videoResults as $item) {
                $snapshot = $byExternalId->get($item['id'] ?? null);
                if (! $snapshot) {
                    continue;
                }
                $returnedIds[] = $item['id'];

                $contentItemId = $snapshot->content_publication_id
                    ? ContentPublication::whereKey($snapshot->content_publication_id)->value('content_item_id')
                    : null;

                try {
                    // Snapshot Phase 2 - identity sama (tiktok_video_snapshot_id)
                    // + snapshot_date HARI INI (never publish date) - upsert
                    // same-day, bukan histori baru (Langkah 8).
                    $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
                    $this->recordSnapshot($snapshot, $item, $integration, $platform, $contentItemId);
                    $snapshot->update(['last_fetched_at' => now()]);
                    $summary['refreshed_count']++;
                } catch (\Throwable $e) {
                    $summary['failed_count']++;
                    Log::warning('TikTok refreshKnownVideos: gagal simpan metric/snapshot buat 1 video, dilewati', [
                        'client_id' => $integration->client_id,
                        'tiktok_video_snapshot_id' => $snapshot->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Transient - TIDAK advance last_fetched_at.
                }
            }

            // Video yang query-nya sukses TAPI TikTok tidak balikin video
            // itu di response (mis. sudah dihapus user) - dicatat sebagai
            // skipped, BUKAN failed (bukan error di sisi kita), DAN
            // dianggap "sudah dicek hari ini" (Langkah 7 - ini jawaban
            // definitif "tidak ada", bukan kegagalan transient) - advance
            // last_fetched_at supaya tidak query ulang video yang sama
            // tiap rotasi padahal jawabannya sudah pasti.
            $missingIds = $batch->diff($returnedIds);
            foreach ($missingIds as $missingId) {
                $byExternalId->get($missingId)?->update(['last_fetched_at' => now()]);
            }
            $summary['skipped_count'] += $missingIds->count();
        }

        $this->recordRefreshFailureMarker($syncLog, $summary['failed_count'], $summary['total_count']);

        return $summary;
    }

    /**
     * Langkah 6 - integration ditandai butuh reconnect TANPA menyentuh
     * status $syncLog (sync UTAMA sudah selesai & sukses sebelum refresh
     * ini dipanggil - method ini BUKAN markFailed(), sengaja tidak
     * menandai syncLog 'failed').
     */
    private function markNeedsReconnect(ApiIntegration $integration, string $message): void
    {
        $integration->update(['status' => 'inactive', 'last_error' => $message]);
    }

    /**
     * Langkah 5 - failed_count > 0 TIDAK BOLEH "menghilang" jadi success
     * sempurna. APPEND (bukan overwrite) marker ke $syncLog->error_message
     * yang sudah ditulis sync() utama.
     */
    private function recordRefreshFailureMarker(AnalyticsSyncLog $syncLog, int $failedCount, int $totalCount): void
    {
        if ($failedCount <= 0) {
            return;
        }

        $marker = KnownContentRefreshFailureMarker::wrap($failedCount, $totalCount);
        $existing = $syncLog->fresh()?->error_message;

        $syncLog->update([
            'error_message' => $existing ? "{$existing} | {$marker}" : $marker,
        ]);
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

            return;
        }

        // MIRROR InstagramAnalyticsSyncService::saveMetricSafely() - lihat
        // catatan di sana soal kenapa recordSnapshot() dipisah try/catch-nya
        // dari saveMetric() (partial condition harus tercatat jelas, tidak
        // boleh dilaporkan sebagai "gagal simpan content_metrics" padahal
        // content_metrics-nya sendiri sudah berhasil).
        try {
            $this->recordSnapshot($snapshot, $item, $integration, $platform, $contentItemId);
        } catch (\Throwable $e) {
            $summary['details'][] = SnapshotFailureMarker::wrap("Video {$item['id']}", $e->getMessage());
            Log::warning('ContentMetricSnapshot write failed after ContentMetric succeeded (TikTok)', [
                'client_id' => $integration->client_id,
                'tiktok_video_snapshot_id' => $snapshot->id,
                'error' => $e->getMessage(),
            ]);
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
     * MIRROR InstagramAnalyticsSyncService::recordSnapshot() - tulis 1 baris
     * content_metric_snapshots per (video, tanggal SYNC hari ini), TERPISAH
     * dari content_metrics di atas (dikunci ke tanggal publish). Identitas
     * row = (tiktok_video_snapshot_id, snapshot_date), BUKAN content_item_id.
     * snapshot_date SELALU now()->toDateString() - never publish date, never
     * backfilled.
     *
     * $item dipakai LANGSUNG (field asli TikTok: view_count/like_count/dst),
     * BUKAN "?? 0" seperti content_metrics.views di atas - kolom snapshot
     * semua nullable, jadi key yang memang tidak ada di response API harus
     * tetap NULL. reach/impressions/saves/profile_visit SENGAJA tidak
     * disebut (dibiarkan default NULL kolom) - TikTok Display API standar
     * tidak pernah menyediakan metric ini (sama seperti saveMetric() di
     * atas). watch_time_avg/completion_rate juga tidak disebut - TikTok
     * Display API standar tidak menyediakan field ini untuk video publik.
     */
    private function recordSnapshot(TikTokVideoSnapshot $snapshot, array $item, ApiIntegration $integration, Platform $platform, ?int $contentItemId): void
    {
        ContentMetricSnapshot::updateOrCreate(
            [
                'tiktok_video_snapshot_id' => $snapshot->id,
                'snapshot_date' => now()->toDateString(),
            ],
            [
                'client_id' => $integration->client_id,
                'platform_id' => $platform->id,
                'content_item_id' => $contentItemId,
                'views' => $item['view_count'] ?? null,
                'likes' => $item['like_count'] ?? null,
                'comments' => $item['comment_count'] ?? null,
                'shares' => $item['share_count'] ?? null,
                'engagement_rate' => $this->computeSnapshotEngagementRate($item),
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
     * Versi NULL-safe computeEngagementRate() KHUSUS content_metric_snapshots
     * (kolomnya nullable, beda dari content_metrics yang NOT NULL) - kalau
     * view_count sendiri tidak ada di response API sama sekali, engagement
     * rate memang TIDAK BISA dihitung (denominator tidak diketahui), jadi
     * hasilnya NULL, BUKAN 0.0. view_count = 0 yang genuinely ada (video
     * belum ditonton) tetap valid dihitung normal lewat computeEngagementRate()
     * (hasilnya 0.0, correctly "diketahui nol").
     */
    private function computeSnapshotEngagementRate(array $item): ?float
    {
        if (($item['view_count'] ?? null) === null) {
            return null;
        }

        return $this->computeEngagementRate($item);
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
