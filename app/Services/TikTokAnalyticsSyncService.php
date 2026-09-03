<?php

namespace App\Services;

use App\Exceptions\TikTokApiException;
use App\Models\AnalyticsSyncFailure;
use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncTask;
use App\Models\AnalyticsSyncTaskItem;
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
     * Analytics V2 Phase B - $task OPSIONAL, MIRROR
     * InstagramAnalyticsSyncService::sync() (lihat docblock di sana).
     *
     * @return array{existing_matched: int, newly_matched: int, unmatched: int, ambiguous: int, failed: int, metrics_saved: int, details: array<int, string>, video_count: int, username: ?string, has_more: bool, stopped_early: bool, oldest_fetched: ?string, newest_fetched: ?string}
     */
    public function sync(ApiIntegration $integration, AnalyticsSyncLog $syncLog, Carbon $cutoff, int $userId, ?AnalyticsSyncTask $task = null): array
    {
        // PASS 4.1 - MIRROR InstagramAnalyticsSyncService::sync() (lihat
        // docblock di sana buat penjelasan lengkap) - batas exclude buat
        // refreshKnownVideos() dalam RUN yang sama.
        $runStartedAt = now();

        $task?->markRunning('discovering_videos');

        $result = (new TikTokAnalyticsService($integration))->sync($cutoff);

        // PASS 4.1 - dedupe by video ID SEBELUM discovered_count dihitung
        // (MIRROR InstagramAnalyticsSyncService::sync() - defensif terhadap
        // cursor-based pagination genuinely mengembalikan 1 video 2x).
        $videos = $this->deduplicateById($result['videos']);

        $profile = $result['profile'];
        $platform = Platform::find($integration->platform_id);

        $integration->update([
            'status' => 'active',
            'external_account_id' => $profile['open_id'] ?? null,
            'external_username' => $profile['username'] ?? $profile['display_name'] ?? null,
            'last_synced_at' => now(),
            'last_error' => null,
            // PASS 1B - identitas profil (avatar_large_url diprioritaskan
            // atas avatar_url biasa kalau scope user.info.profile granted -
            // resolusi lebih baik buat tampilan; bio/is_verified/
            // profile_deep_link butuh scope profile juga, TIDAK ADA di
            // response kalau scope-nya ditolak user - array_key_exists
            // check (bukan ?? null) biar TIDAK menimpa nilai lama dengan
            // null cuma karena field itu absen di response kali ini.
            ...array_filter([
                'external_display_name' => $profile['display_name'] ?? null,
                'external_avatar_url' => $profile['avatar_large_url'] ?? $profile['avatar_url'] ?? null,
                'external_bio' => $profile['bio_description'] ?? null,
                'external_verified' => array_key_exists('is_verified', $profile) ? (bool) $profile['is_verified'] : null,
                'external_profile_url' => $profile['profile_deep_link'] ?? null,
            ], fn ($v) => $v !== null),
        ]);

        // Profile stats (follower_count dkk) DISATUKAN ke alur sync ini
        // (bukan tombol/Job terpisah) - keputusan desain sengaja, lihat
        // docs/TIKTOK_INTEGRATION.md "Profile/Stats Sync": user/info/
        // dipanggil SEKALI per sync (sama seperti Instagram getProfile()),
        // jadi menambah Job/tombol kedua cuma buat data yang sudah didapat
        // gratis di panggilan yang sama adalah pemborosan API call, bukan
        // fitur baru.
        $this->saveProfileSnapshot($integration, $profile);

        $task?->recordDiscovered(count($videos), 'processing_videos');

        $summary = $this->persistVideos($videos, $platform, $integration, $userId, $syncLog, $task);

        $metricsSaved = $summary['metrics_saved'];
        $unresolvedCount = $summary['unmatched'] + $summary['ambiguous'];
        $status = ($metricsSaved > 0 || count($videos) === 0) ? 'success' : 'failed';

        $syncLog->update([
            'status' => $status,
            'synced_count' => $metricsSaved,
            'skipped_count' => $unresolvedCount + $summary['failed'],
            'error_message' => ! empty($summary['details']) ? implode(' | ', array_slice($summary['details'], 0, 8)) : null,
        ]);

        $task?->finish($summary['failed'] > 0 ? ($metricsSaved > 0 ? 'partial' : 'failed') : $status);

        return [
            ...$summary,
            'metrics_saved' => $metricsSaved,
            'video_count' => count($videos),
            'username' => $profile['username'] ?? $profile['display_name'] ?? null,
            'has_more' => $result['has_more'],
            'stopped_early' => $result['stopped_early'],
            'oldest_fetched' => $result['oldest_fetched'] ? Carbon::createFromTimestamp($result['oldest_fetched'])->toDateTimeString() : null,
            'newest_fetched' => $result['newest_fetched'] ? Carbon::createFromTimestamp($result['newest_fetched'])->toDateTimeString() : null,
            // PASS 4.1 - diteruskan ke refreshKnownVideos() lewat caller (Job).
            'run_started_at' => $runStartedAt,
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
     * PASS 4.1 - $excludeFetchedSince (dari sync()['run_started_at'] milik
     * RUN yang sama) MIRROR InstagramAnalyticsSyncService::refreshKnownMedia()
     * (lihat docblock di sana) - mengecualikan video yang baru disentuh
     * sync() barusan, supaya kandidat method ini SECARA STRUKTURAL disjoint
     * dan discovered_count dua fase (sekarang dijumlahkan additive) genuinely
     * = union unik video, BUKAN video yang sama dihitung dua kali.
     *
     * @return array{refreshed_count: int, failed_count: int, skipped_count: int, total_count: int, auth_failed: bool}
     */
    public function refreshKnownVideos(ApiIntegration $integration, AnalyticsSyncLog $syncLog, int $userId, ?AnalyticsSyncTask $task = null, ?Carbon $excludeFetchedSince = null): array
    {
        $budget = max(0, (int) config('analytics.tiktok_known_refresh_budget'));

        // ROLLING 90-DAY SYNC COVERAGE - FINAL CORRECTION PASS: MIRROR
        // InstagramAnalyticsSyncService::refreshKnownMedia() (lihat
        // docblock di sana) - known video eligible buat refresh normal
        // HANYA kalau published_at (create_time TikTok) masih di dalam
        // rolling coverage window yang sama dengan discovery
        // (tiktok_default_sync_days). Video di luar window TETAP TERSIMPAN,
        // cuma tidak lagi ikut rotasi refresh normal.
        $coverageLowerBound = now()->subDays((int) config('analytics.tiktok_default_sync_days'))->startOfDay();

        $staleKnownVideos = TikTokVideoSnapshot::where('api_integration_id', $integration->id)
            ->where('published_at', '>=', $coverageLowerBound)
            ->when($excludeFetchedSince, fn ($q) => $q->where(fn ($q2) => $q2
                ->whereNull('last_fetched_at')
                ->orWhere('last_fetched_at', '<', $excludeFetchedSince)))
            ->orderByRaw('last_fetched_at IS NOT NULL')
            ->orderBy('last_fetched_at', 'asc')
            ->limit($budget)
            ->get(['id', 'external_post_id', 'content_publication_id']);

        $summary = ['refreshed_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'total_count' => $staleKnownVideos->count(), 'auth_failed' => false];

        $task?->markRunning('refreshing_known_videos');
        $task?->recordDiscovered($staleKnownVideos->count());

        if ($staleKnownVideos->isEmpty()) {
            $task?->finish('success');

            return $summary;
        }

        $platform = Platform::find($integration->platform_id);
        $service = new TikTokAnalyticsService($integration);
        $byExternalId = $staleKnownVideos->keyBy('external_post_id');

        foreach ($staleKnownVideos->pluck('external_post_id')->chunk(20) as $batch) {
            try {
                $videoResults = $this->queryVideosWithBoundedRetry($service, $batch->values()->all());
            } catch (TikTokApiException $e) {
                $summary['failed_count'] += $batch->count();

                if ($e->category === TikTokApiException::AUTHENTICATION) {
                    // Token rusak - batch berikutnya juga pasti gagal
                    // identik, percuma lanjut. Integration ditandai butuh
                    // reconnect (Langkah 6) - BUKAN sekadar failed_count
                    // tinggi yang tidak actionable.
                    $summary['auth_failed'] = true;
                    $this->markNeedsReconnect($integration, $e->getMessage());
                    if ($task) {
                        $task->incrementFailed();
                        AnalyticsSyncFailure::record($task, 'fetch_video_batch', AnalyticsFailureCategory::AUTHENTICATION, $e->getMessage());
                    }
                    break;
                }

                Log::warning('TikTok refreshKnownVideos: queryVideos batch gagal, dilewati (sync utama TIDAK terpengaruh)', [
                    'client_id' => $integration->client_id,
                    'batch_size' => $batch->count(),
                    'category' => $e->category,
                    'error' => $e->getMessage(),
                ]);
                if ($task) {
                    $task->incrementFailed();
                    AnalyticsSyncFailure::record($task, 'fetch_video_batch', AnalyticsFailureCategory::fromApiExceptionCategory($e->category), $e->getMessage());
                }
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
                if ($task) {
                    $task->incrementFailed();
                    AnalyticsSyncFailure::record($task, 'fetch_video_batch', AnalyticsFailureCategory::UNKNOWN, $e->getMessage());
                }
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
                    $snapshot->update([...$this->videoMetadataFields($item), 'last_fetched_at' => now()]);
                    $summary['refreshed_count']++;
                    $task?->incrementSuccess();
                } catch (\Throwable $e) {
                    $summary['failed_count']++;
                    Log::warning('TikTok refreshKnownVideos: gagal simpan metric/snapshot buat 1 video, dilewati', [
                        'client_id' => $integration->client_id,
                        'tiktok_video_snapshot_id' => $snapshot->id,
                        'error' => $e->getMessage(),
                    ]);
                    if ($task) {
                        $task->incrementFailed();
                        AnalyticsSyncFailure::record($task, 'fetch_video_batch', AnalyticsFailureCategory::UNKNOWN, $e->getMessage(), $item['id'] ?? null, $contentItemId);
                    }
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
            $task?->incrementUnavailable($missingIds->count());
        }

        $this->recordRefreshFailureMarker($syncLog, $summary['failed_count'], $summary['total_count']);

        if ($task) {
            $status = $summary['auth_failed']
                ? 'needs_reconnect'
                : ($summary['failed_count'] > 0 ? ($summary['refreshed_count'] > 0 ? 'partial' : 'failed') : 'success');
            $task->finish($status);
        }

        return $summary;
    }

    /**
     * PASS 1B (Langkah "VERIFY ITEM-LEVEL TRANSIENT RETRY") - job-level
     * $tries=3 (SyncTikTokAnalyticsJob) HANYA retry kalau exception lolos
     * sampai ke handle() job - begitu queryVideos() gagal DI DALAM loop
     * batch ini, ke-catch LOKAL (tidak pernah throw ke atas), jadi
     * $tries=3 TIDAK PERNAH benar-benar retry 1 batch yang gagal transient.
     * Method ini nutup gap itu SEBELUM baris dicatat sebagai unresolved
     * failure:
     *
     * - AUTHENTICATION -> TIDAK diretry sama sekali, lempar langsung
     *   (caller yang urus needs_reconnect).
     * - RATE_LIMIT -> 1x retry, DIDAHULUI jeda pendek (provider-aware
     *   backoff - percuma retry instan selagi window rate-limit aktif).
     * - NETWORK/SERVER_ERROR -> 1x retry, TANPA jeda (blip sesaat biasanya
     *   pulih instan).
     * - MALFORMED_RESPONSE/UNKNOWN -> TIDAK diretry (bukan jelas-jelas
     *   transient, retry buta berisiko memperlambat tanpa manfaat jelas).
     *
     * Total percobaan DIBATASI 2 (1 percobaan awal + maksimal 1 retry) -
     * TIDAK PERNAH loop tak terbatas, gagal setelah retry tetap dilempar
     * ke caller apa adanya buat dicatat sebagai unresolved
     * AnalyticsSyncFailure (retryable=true, bisa diretry lagi lewat
     * retryFailedItems() eksplisit nanti).
     */
    private function queryVideosWithBoundedRetry(TikTokAnalyticsService $service, array $videoIds): array
    {
        try {
            return $service->queryVideos($videoIds);
        } catch (TikTokApiException $e) {
            if ($e->category === TikTokApiException::AUTHENTICATION
                || in_array($e->category, [TikTokApiException::MALFORMED_RESPONSE, TikTokApiException::UNKNOWN], true)) {
                throw $e;
            }

            if ($e->category === TikTokApiException::RATE_LIMIT) {
                usleep(1_500_000); // 1.5 detik - jeda pendek, provider-aware, TIDAK menahan worker terlalu lama.
            }

            Log::info('TikTok queryVideos: retry bounded 1x setelah kegagalan transient', [
                'category' => $e->category,
                'batch_size' => count($videoIds),
            ]);

            // Percobaan KEDUA (terakhir) - exception apapun di sini dilempar
            // APA ADANYA ke caller, tidak ada retry lagi.
            return $service->queryVideos($videoIds);
        }
    }

    /**
     * Analytics V2 Phase B - "TARGETED RETRY", item-level. MIRROR
     * InstagramAnalyticsSyncService::retryFailedItems() - lihat docblock
     * di sana. TikTok bedanya: video di-query BATCHED (≤20 ID/panggilan,
     * batas resmi TikTok), bukan satu-satu.
     *
     * @return array{attempted: int, resolved: int, still_failed: int}
     */
    public function retryFailedItems(AnalyticsSyncTask $task, int $userId): array
    {
        $failures = AnalyticsSyncFailure::where('analytics_sync_task_id', $task->id)->retryable()->get();
        $summary = ['attempted' => $failures->count(), 'resolved' => 0, 'still_failed' => 0];

        if ($failures->isEmpty()) {
            return $summary;
        }

        $integration = $task->integration;
        $platform = Platform::find($integration->platform_id);
        $service = new TikTokAnalyticsService($integration);

        $syncLog = AnalyticsSyncLog::create([
            'client_id' => $integration->client_id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $userId,
            'source_type' => 'api_sync',
            'status' => 'success',
            'sync_mode' => 'default',
            'range_from' => now()->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
        ]);

        $byExternalId = TikTokVideoSnapshot::where('api_integration_id', $integration->id)
            ->whereIn('external_post_id', $failures->pluck('external_item_id')->filter())
            ->get(['id', 'external_post_id', 'content_publication_id'])
            ->keyBy('external_post_id');
        $failuresByExternalId = $failures->filter(fn ($f) => $f->external_item_id !== null)->keyBy('external_item_id');

        foreach ($byExternalId->keys()->chunk(20) as $batch) {
            try {
                $videoResults = $this->queryVideosWithBoundedRetry($service, $batch->values()->all());
            } catch (TikTokApiException $e) {
                foreach ($batch as $id) {
                    $failuresByExternalId->get($id)?->markAttemptFailedAgain();
                }
                $summary['still_failed'] += $batch->count();

                if ($e->category === TikTokApiException::AUTHENTICATION) {
                    $this->markNeedsReconnect($integration, $e->getMessage());
                    break;
                }

                continue;
            }

            $returnedIds = [];
            foreach ($videoResults as $item) {
                $snapshot = $byExternalId->get($item['id'] ?? null);
                $failure = $failuresByExternalId->get($item['id'] ?? null);
                if (! $snapshot || ! $failure) {
                    continue;
                }
                $returnedIds[] = $item['id'];

                $contentItemId = $snapshot->content_publication_id
                    ? ContentPublication::whereKey($snapshot->content_publication_id)->value('content_item_id')
                    : null;

                try {
                    $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
                    $this->recordSnapshot($snapshot, $item, $integration, $platform, $contentItemId);
                    $snapshot->update([...$this->videoMetadataFields($item), 'last_fetched_at' => now()]);
                    $failure->markResolved();
                    $task->incrementSuccess();
                    $summary['resolved']++;
                } catch (\Throwable $e) {
                    $failure->markAttemptFailedAgain();
                    $summary['still_failed']++;
                }
            }

            foreach ($batch->diff($returnedIds) as $missingId) {
                $failuresByExternalId->get($missingId)?->markAttemptFailedAgain();
                $summary['still_failed']++;
            }
        }

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

    // =====================================================================
    // PROGRESSIVE 90-DAY SYNC ENGINE - RESILIENCE PASS. MIRROR
    // InstagramAnalyticsSyncService's plan/processChunk/finalize methods,
    // TAPI TikTok-SPECIFIC di dua tempat penting (Langkah 27, "preserve
    // TikTok-specific semantics"):
    //
    // 1. Discovery (video/list/) SUDAH mengembalikan metrik LENGKAP per
    //    video (VIDEO_FIELDS dipakai IDENTIK oleh video/list/ DAN
    //    video/query/ - lihat TikTokAnalyticsService::getVideoList()) -
    //    BEDA dari Instagram yang butuh 1 getMediaInsights() terpisah per
    //    media. Makanya processDiscoveryTaskItem() TikTok di bawah TIDAK
    //    PERNAH memanggil provider API sama sekali (murni matching+DB
    //    upsert dari payload yang sudah lengkap) - chunking discovery di
    //    sini semata demi UX progresif/durability yang konsisten dengan
    //    Instagram, BUKAN karena ada risiko network N+1 yang sama.
    // 2. Known-refresh (source=known_refresh) TETAP pakai video/query/
    //    BATCHED (queryVideosWithBoundedRetry(), <=20 ID/panggilan, batas
    //    resmi TikTok) - SATU panggilan API per CHUNK (bukan per item),
    //    karena sync_chunk_size default (20) SENGAJA sama persis dengan
    //    batas batch resmi TikTok itu.
    // =====================================================================

    /**
     * @return array{total_chunks: int, discovery_count: int, known_refresh_count: int, username: ?string}
     */
    public function planProgressiveRun(ApiIntegration $integration, AnalyticsSyncTask $task, Carbon $cutoff): array
    {
        $task->markRunning('discovering_videos');

        $providerService = new TikTokAnalyticsService($integration);
        $result = $providerService->sync($cutoff);
        $videos = $this->deduplicateById($result['videos']);
        $profile = $result['profile'];

        $integration->update([
            'status' => 'active',
            'external_account_id' => $profile['open_id'] ?? null,
            'external_username' => $profile['username'] ?? $profile['display_name'] ?? null,
            'last_synced_at' => now(),
            'last_error' => null,
            ...array_filter([
                'external_display_name' => $profile['display_name'] ?? null,
                'external_avatar_url' => $profile['avatar_large_url'] ?? $profile['avatar_url'] ?? null,
                'external_bio' => $profile['bio_description'] ?? null,
                'external_verified' => array_key_exists('is_verified', $profile) ? (bool) $profile['is_verified'] : null,
                'external_profile_url' => $profile['profile_deep_link'] ?? null,
            ], fn ($v) => $v !== null),
        ]);
        $this->saveProfileSnapshot($integration, $profile);

        $now = now();
        $chunkSize = max(1, (int) config('analytics.sync_chunk_size'));

        $buckets = [SyncStageBoundary::STAGE_RECENT => [], SyncStageBoundary::STAGE_MID => [], SyncStageBoundary::STAGE_OLDER => []];
        foreach ($videos as $item) {
            $publishedAt = isset($item['create_time']) ? Carbon::createFromTimestamp($item['create_time']) : $now;
            $buckets[SyncStageBoundary::stageFor($publishedAt, $now)][] = $item;
        }

        $chunkIndex = 0;
        $rows = [];
        foreach ([SyncStageBoundary::STAGE_RECENT, SyncStageBoundary::STAGE_MID, SyncStageBoundary::STAGE_OLDER] as $stage) {
            foreach (array_chunk($buckets[$stage], $chunkSize) as $chunk) {
                $chunkIndex++;
                foreach ($chunk as $item) {
                    $rows[] = [
                        'analytics_sync_task_id' => $task->id,
                        'external_item_id' => $item['id'],
                        'media_type' => null,
                        'published_at' => isset($item['create_time']) ? Carbon::createFromTimestamp($item['create_time']) : null,
                        'stage' => $stage,
                        'source' => AnalyticsSyncTaskItem::SOURCE_DISCOVERY,
                        'chunk_index' => $chunkIndex,
                        'status' => AnalyticsSyncTaskItem::STATUS_PENDING,
                        // Video/list SUDAH mengandung metrik lengkap - payload
                        // menyimpan item MENTAH apa adanya, processDiscoveryTaskItem()
                        // TIDAK PERNAH perlu query ulang.
                        'payload' => json_encode($item),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        $discoveredExternalIds = array_column($videos, 'id');
        $budget = max(0, (int) config('analytics.tiktok_known_refresh_budget'));
        $knownCandidates = $budget > 0
            ? TikTokVideoSnapshot::where('api_integration_id', $integration->id)
                ->where('published_at', '>=', now()->subDays((int) config('analytics.tiktok_default_sync_days'))->startOfDay())
                ->when(! empty($discoveredExternalIds), fn ($q) => $q->whereNotIn('external_post_id', $discoveredExternalIds))
                ->orderByRaw('last_fetched_at IS NOT NULL')
                ->orderBy('last_fetched_at', 'asc')
                ->limit($budget)
                ->get(['external_post_id'])
            : collect();

        foreach (array_chunk($knownCandidates->all(), $chunkSize) as $chunk) {
            $chunkIndex++;
            foreach ($chunk as $snapshot) {
                $rows[] = [
                    'analytics_sync_task_id' => $task->id,
                    'external_item_id' => $snapshot->external_post_id,
                    'media_type' => null,
                    'published_at' => null,
                    'stage' => 0,
                    'source' => AnalyticsSyncTaskItem::SOURCE_KNOWN_REFRESH,
                    'chunk_index' => $chunkIndex,
                    'status' => AnalyticsSyncTaskItem::STATUS_PENDING,
                    'payload' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $insertBatch) {
            AnalyticsSyncTaskItem::insert($insertBatch);
        }

        $task->recordDiscovered(count($rows), 'processing_recent');

        return [
            'total_chunks' => $chunkIndex,
            'discovery_count' => count($videos),
            'known_refresh_count' => $knownCandidates->count(),
            'username' => $profile['username'] ?? $profile['display_name'] ?? null,
        ];
    }

    /**
     * @return array{processed: int, auth_failed: bool}
     */
    /**
     * @return array{processed: int, auth_failed: bool, deadline_reached: bool}
     */
    public function processChunk(AnalyticsSyncTask $task, int $chunkIndex, AnalyticsSyncLog $syncLog, int $userId): array
    {
        $integration = $task->integration;
        $items = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)
            ->where('chunk_index', $chunkIndex)
            ->where('status', AnalyticsSyncTaskItem::STATUS_PENDING)
            ->get();

        if ($items->isEmpty()) {
            return ['processed' => 0, 'auth_failed' => false, 'deadline_reached' => false];
        }

        $platform = Platform::find($integration->platform_id);
        $source = $items->first()->source;
        $authFailed = false;

        if ($source === AnalyticsSyncTaskItem::SOURCE_DISCOVERY) {
            // MIRROR InstagramAnalyticsSyncService::processChunk() - discovery
            // TikTok TIDAK PERNAH panggil provider API di sini sama sekali
            // (video/list SUDAH bawa metrik lengkap, lihat docblock plan/
            // processDiscoveryTaskItem() di atas), jadi deadline dicek murni
            // sebagai jaring pengaman DB-lambat, bukan risiko utama platform ini.
            $deadline = now()->addSeconds((int) config('analytics.sync_chunk_soft_deadline_seconds'));
            $processed = 0;
            $deadlineReached = false;

            foreach ($items as $taskItem) {
                if (now()->greaterThan($deadline)) {
                    $deadlineReached = true;
                    break;
                }
                $this->processDiscoveryTaskItem($taskItem, $platform, $integration, $userId, $syncLog, $task);
                $processed++;
            }

            return ['processed' => $processed, 'auth_failed' => false, 'deadline_reached' => $deadlineReached];
        }

        // Known-refresh - SATU panggilan queryVideos() batched buat SELURUH
        // chunk (<=20 ID, batas resmi TikTok - lihat processKnownRefreshChunk()),
        // TIDAK bisa/perlu dipecah pakai deadline soft: queryVideosWithBoundedRetry()
        // sendiri SUDAH bounded (1x retry maks, ~2x20 detik timeout = ~40
        // detik worst case buat SATU chunk) - jauh di bawah $timeout job
        // (300 detik) tanpa perlu logic tambahan.
        $authFailed = $this->processKnownRefreshChunk($items, $platform, $integration, $userId, $syncLog, $task);

        return ['processed' => $items->count(), 'auth_failed' => $authFailed, 'deadline_reached' => false];
    }

    private function processDiscoveryTaskItem(AnalyticsSyncTaskItem $taskItem, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, AnalyticsSyncTask $task): void
    {
        $item = $taskItem->payload;
        $media = [
            'id' => $item['id'],
            'permalink' => $item['share_url'] ?? null,
            'timestamp' => isset($item['create_time']) ? Carbon::createFromTimestamp($item['create_time'])->toIso8601String() : null,
            'caption' => $item['video_description'] ?? $item['title'] ?? null,
        ];

        $matcher = new ContentPublicationMatcher();
        $result = $matcher->match($integration, $media);
        $contentItemId = null;

        if ($result->status === 'unmatched') {
            $snapshot = $this->saveSnapshot($integration, $item, 'unmatched');
        } elseif ($result->status === 'ambiguous') {
            $snapshot = $this->saveSnapshot($integration, $item, 'ambiguous');
        } else {
            try {
                $publication = $this->getOrCreatePublication($result, $media, $platform, $integration, $userId);
                $snapshot = $this->saveSnapshot($integration, $item, 'matched', $publication->id);
                $contentItemId = $publication->content_item_id;
            } catch (\Throwable $e) {
                $this->saveSnapshot($integration, $item, 'unmatched');
                $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_FAILED, 'last_error' => \Illuminate\Support\Str::limit($e->getMessage(), 500), 'core_completed_at' => now()]);
                $task->incrementFailed();
                AnalyticsSyncFailure::record($task, 'fetch_video_batch', AnalyticsFailureCategory::UNKNOWN, "gagal simpan publication - {$e->getMessage()}", $item['id'] ?? $taskItem->external_item_id, null);

                return;
            }
        }

        try {
            $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
        } catch (\Throwable $e) {
            $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_FAILED, 'last_error' => $e->getMessage(), 'core_completed_at' => now()]);
            $task->incrementFailed();
            AnalyticsSyncFailure::record($task, 'fetch_video_batch', AnalyticsFailureCategory::UNKNOWN, $e->getMessage(), $item['id'] ?? $taskItem->external_item_id, $contentItemId);

            return;
        }

        $optionalStatus = 'not_applicable'; // Langkah 27 - TikTok tidak punya stage optional-insight seperti Instagram Reels/Feed.
        try {
            $this->recordSnapshot($snapshot, $item, $integration, $platform, $contentItemId);
        } catch (\Throwable $e) {
            $optionalStatus = 'failed';
            Log::warning('ContentMetricSnapshot write failed after ContentMetric succeeded (TikTok, progressive)', [
                'client_id' => $integration->client_id,
                'tiktok_video_snapshot_id' => $snapshot->id,
                'error' => $e->getMessage(),
            ]);
        }

        $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_SUCCESS, 'core_completed_at' => now(), 'optional_status' => $optionalStatus]);
        $task->incrementSuccess();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AnalyticsSyncTaskItem>  $taskItems
     * @return bool true kalau auth failed (caller HARUS stop, TIDAK dispatch chunk berikutnya).
     */
    private function processKnownRefreshChunk($taskItems, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, AnalyticsSyncTask $task): bool
    {
        $service = new TikTokAnalyticsService($integration);
        $ids = $taskItems->pluck('external_item_id')->all();

        try {
            $videoResults = $this->queryVideosWithBoundedRetry($service, $ids);
        } catch (TikTokApiException $e) {
            $authFailed = $e->category === TikTokApiException::AUTHENTICATION;
            if ($authFailed) {
                $this->markNeedsReconnect($integration, $e->getMessage());
            }

            foreach ($taskItems as $taskItem) {
                $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_FAILED, 'last_error' => $e->getMessage(), 'core_completed_at' => now()]);
                $task->incrementFailed();
                AnalyticsSyncFailure::record($task, 'fetch_video_batch', $authFailed ? AnalyticsFailureCategory::AUTHENTICATION : AnalyticsFailureCategory::fromApiExceptionCategory($e->category), $e->getMessage(), $taskItem->external_item_id, null);
            }

            return $authFailed;
        }

        $byId = collect($videoResults)->keyBy('id');

        foreach ($taskItems as $taskItem) {
            $video = $byId->get($taskItem->external_item_id);
            $snapshot = TikTokVideoSnapshot::where('api_integration_id', $integration->id)
                ->where('external_post_id', $taskItem->external_item_id)
                ->first();

            if (! $video || ! $snapshot) {
                // TikTok query sukses TAPI video ini tidak dibalikin (sudah
                // dihapus user) ATAU snapshot-nya sudah hilang - jawaban
                // DEFINITIF, bukan kegagalan (Langkah 21 prinsip yang sama).
                $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_SKIPPED, 'core_completed_at' => now()]);
                $task->incrementSkipped();
                $snapshot?->update(['last_fetched_at' => now()]);

                continue;
            }

            $contentItemId = $snapshot->content_publication_id
                ? ContentPublication::whereKey($snapshot->content_publication_id)->value('content_item_id')
                : null;

            try {
                $this->saveMetric($video, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
                $this->recordSnapshot($snapshot, $video, $integration, $platform, $contentItemId);
                $snapshot->update([...$this->videoMetadataFields($video), 'last_fetched_at' => now()]);
                $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_SUCCESS, 'core_completed_at' => now()]);
                $task->incrementSuccess();
            } catch (\Throwable $e) {
                $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_FAILED, 'last_error' => $e->getMessage(), 'core_completed_at' => now()]);
                $task->incrementFailed();
                AnalyticsSyncFailure::record($task, 'fetch_video_batch', AnalyticsFailureCategory::UNKNOWN, $e->getMessage(), $taskItem->external_item_id, $contentItemId);
            }
        }

        return false;
    }

    public function finalizeProgressiveRun(AnalyticsSyncTask $task, AnalyticsSyncLog $syncLog): void
    {
        $task->refresh();

        $metricsSaved = $task->success_count;
        $unresolvedCount = $task->failed_count + $task->unavailable_count + $task->skipped_count;
        $status = $metricsSaved > 0 || $task->discovered_count === 0 ? 'success' : 'failed';

        $syncLog->update([
            'status' => $status,
            'synced_count' => $metricsSaved,
            'skipped_count' => $unresolvedCount,
        ]);

        $finalStatus = $task->failed_count > 0
            ? ($metricsSaved > 0 ? 'partial' : 'failed')
            : $status;

        // FINAL CLOSURE GATE (Langkah 1) - MIRROR InstagramAnalyticsSyncService::
        // finalizeProgressiveRun() (lihat docblock di sana buat penjelasan
        // lengkap kenapa ini perlu).
        $integrationInactive = \App\Models\ApiIntegration::whereKey($task->api_integration_id)->value('status') !== 'active';
        if ($integrationInactive && $task->failed_count > 0) {
            $finalStatus = 'needs_reconnect';
        }

        $this->recordRefreshFailureMarker($syncLog, $task->failed_count, $task->discovered_count);

        $task->finish($finalStatus);
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
        // PASS 1B - following_count/likes_count/video_count SUDAH diminta
        // (scope user.info.stats, sama request dengan follower_count) tapi
        // dulu DIBUANG, tidak pernah ditulis - fix murni persist, TIDAK ADA
        // panggilan API tambahan. Kalau TIDAK SATUPUN field stats ada di
        // response (scope user.info.stats ditolak user), keluar lebih awal
        // persis seperti perilaku lama (jangan bikin baris summary kosong).
        if (! array_key_exists('follower_count', $profile)
            && ! array_key_exists('following_count', $profile)
            && ! array_key_exists('likes_count', $profile)
            && ! array_key_exists('video_count', $profile)) {
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
            array_filter([
                'follower_count' => $profile['follower_count'] ?? null,
                'following_count' => $profile['following_count'] ?? null,
                'likes_count' => $profile['likes_count'] ?? null,
                'video_count' => $profile['video_count'] ?? null,
            ], fn ($v) => $v !== null)
        );
    }

    /**
     * PASS 4.1 - MIRROR InstagramAnalyticsSyncService::deduplicateById()
     * (lihat docblock di sana - dedupe 1 halaman pagination by video ID,
     * kemunculan pertama menang).
     *
     * @param  array<int, array<string, mixed>>  $videos
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateById(array $videos): array
    {
        $seen = [];

        return array_values(array_filter($videos, function ($item) use (&$seen) {
            $id = $item['id'] ?? null;
            if ($id === null || isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;

            return true;
        }));
    }

    /**
     * @return array{existing_matched: int, newly_matched: int, unmatched: int, ambiguous: int, failed: int, details: array<int, string>}
     */
    private function persistVideos(array $videoResults, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?AnalyticsSyncTask $task = null): array
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
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary, $task);
                continue;
            }

            if ($result->status === 'ambiguous') {
                $summary['ambiguous']++;
                $summary['details'][] = "Video {$item['id']}: ambiguous - {$result->reason}";
                $snapshot = $this->saveSnapshot($integration, $item, 'ambiguous');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary, $task);
                continue;
            }

            $wasExisting = $result->matchedBy === 'external_post_id';

            try {
                $publication = $this->getOrCreatePublication($result, $media, $platform, $integration, $userId);
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['details'][] = "Video {$item['id']}: gagal simpan publication - {$e->getMessage()}";
                $snapshot = $this->saveSnapshot($integration, $item, 'unmatched');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary, $task);
                continue;
            }

            $wasExisting ? $summary['existing_matched']++ : $summary['newly_matched']++;
            $snapshot = $this->saveSnapshot($integration, $item, 'matched', $publication->id);

            $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, $publication->content_item_id, $summary, $task);
        }

        return $summary;
    }

    private function saveMetricSafely(array $item, TikTokVideoSnapshot $snapshot, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?int $contentItemId, array &$summary, ?AnalyticsSyncTask $task = null): void
    {
        try {
            $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
            $summary['metrics_saved']++;
            $task?->incrementSuccess();
        } catch (\Throwable $e) {
            $summary['failed']++;
            $summary['details'][] = "Video {$item['id']}: gagal simpan content_metrics - {$e->getMessage()}";

            if ($task) {
                $task->incrementFailed();
                AnalyticsSyncFailure::record($task, 'fetch_video_batch', AnalyticsFailureCategory::UNKNOWN, $e->getMessage(), $item['id'] ?? null, $contentItemId);
            }

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
    /**
     * PASS 1B (Langkah "cover_image_url has a limited provider TTL...
     * Keep/query-refresh semantics safe") - field metadata video (SEMUANYA
     * dikirim ulang TikTok tiap panggilan video/list MAUPUN video/query,
     * tidak ada biaya API tambahan buat menyimpannya ulang). Dipakai
     * BARENGAN oleh saveSnapshot() (sync utama) DAN refreshKnownVideos()/
     * retryFailedItems() (rotasi/retry) - SATU sumber pemetaan field,
     * supaya cover_image_url yang TTL-nya terbatas (dan title/description/
     * share_url/duration/height/width yang SECARA TEORI stabil tapi tidak
     * ada ruginya tetap disegarkan) tidak pernah "macet" di nilai lama
     * begitu content ini keluar dari discovery window normal.
     *
     * @return array<string, mixed>
     */
    private function videoMetadataFields(array $item): array
    {
        return [
            'share_url' => $item['share_url'] ?? null,
            'title' => $item['title'] ?? null,
            'video_description' => $item['video_description'] ?? null,
            'duration' => $item['duration'] ?? null,
            'height' => $item['height'] ?? null,
            'width' => $item['width'] ?? null,
            'cover_image_url' => $item['cover_image_url'] ?? null,
            // PASS 1 micro-fix - "??" (bukan array_key_exists ternary) SUDAH
            // benar buat kontrak ini: key absen -> null (unknown, TIDAK
            // ditebak "bukan AI-generated"), key ada & true -> true, key
            // ada & false -> false. JANGAN ganti ke "?: null" (itu akan
            // salah mengubah false eksplisit jadi null).
            'is_aigc' => $item['is_aigc'] ?? null,
        ];
    }

    private function saveSnapshot(ApiIntegration $integration, array $item, string $matchStatus, ?int $contentPublicationId = null): TikTokVideoSnapshot
    {
        return TikTokVideoSnapshot::updateOrCreate(
            ['api_integration_id' => $integration->id, 'external_post_id' => $item['id']],
            [
                ...$this->videoMetadataFields($item),
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
