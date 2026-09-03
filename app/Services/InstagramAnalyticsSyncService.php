<?php

namespace App\Services;

use App\Exceptions\InstagramApiException;
use App\Models\AnalyticsSyncFailure;
use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncTask;
use App\Models\AnalyticsSyncTaskItem;
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
     * Analytics V2 Phase B - $task OPSIONAL (nullable, default null - jalur
     * lama tanpa Task, mis. Artisan command langsung, TETAP jalan identik
     * apa adanya) buat progress/reconciliation instrumentation. TIDAK
     * mengubah kontrak return/behavior existing sama sekali kalau $task
     * null.
     *
     * @return array{existing_matched: int, newly_matched: int, unmatched: int, ambiguous: int, failed: int, metrics_saved: int, details: array<int, string>, media_count: int, username: ?string}
     */
    public function sync(ApiIntegration $integration, AnalyticsSyncLog $syncLog, Carbon $since, Carbon $until, int $userId, ?AnalyticsSyncTask $task = null): array
    {
        // PASS 4.1 (Langkah "UNIQUE DISCOVERY/RECONCILIATION CORRECTNESS") -
        // ditangkap SEBELUM media manapun disentuh, dikembalikan ke caller
        // (SyncInstagramAnalyticsJob) buat diteruskan ke refreshKnownMedia()
        // sebagai batas exclude - media yang last_fetched_at-nya di-touch
        // fase INI (mulai dari titik ini) TIDAK BOLEH dipilih ulang oleh
        // fase kedua dalam run yang SAMA (lihat refreshKnownMedia() docblock
        // param $excludeFetchedSince buat penjelasan lengkap).
        $runStartedAt = now();

        $task?->markRunning('discovering_media');

        $result = (new InstagramAnalyticsService($integration))->sync($since, $until);

        // PASS 4.1 - dedupe by media ID SEBELUM discovered_count dihitung
        // ATAUPUN diproses - defensif terhadap kasus pagination cursor-based
        // Instagram genuinely mengembalikan 1 media 2x (item bergeser posisi
        // saat fetch berlangsung, edge case nyata tapi jarang) - TANPA ini,
        // 1 media unik bisa ke-hitung 2x sebagai "discovered" DAN "processed"
        // (Langkah "unique_provider_items", "duplicate ID returned during
        // pagination").
        $media = $this->deduplicateById($result['media']);

        $profile = $result['profile'];
        $platform = Platform::find($integration->platform_id);

        $integration->update([
            'status' => 'active',
            'external_account_id' => $profile['id'] ?? null,
            'external_username' => $profile['username'] ?? null,
            'last_synced_at' => now(),
            'last_error' => null,
            // PASS 1B - identitas profil (name/profile_picture_url, field
            // baru di InstagramAnalyticsService::getProfile()) - array_filter
            // biar TIDAK menimpa nilai lama dengan null kalau field ini
            // kebetulan absen di response.
            //
            // FINAL API COVERAGE GATE - account_type/media_count SUDAH
            // SELALU ada di response getProfile() yang SAMA (nol biaya API
            // tambahan) - dulu dibuang, sekarang dipersist tiap sync biar
            // media_count tetap segar (snapshot-in-time, bukan metric
            // performa - lihat docblock migration).
            ...array_filter([
                'external_display_name' => $profile['name'] ?? null,
                'external_avatar_url' => $profile['profile_picture_url'] ?? null,
                'external_account_type' => $profile['account_type'] ?? null,
                'external_media_count' => $profile['media_count'] ?? null,
            ], fn ($v) => $v !== null),
        ]);

        $task?->recordDiscovered(count($media), 'fetching_insights');

        $summary = $this->persistMedia($media, $platform, $integration, $userId, $syncLog, $task);

        // metrics_saved = baris content_metrics yang beneran ke-upsert -
        // BUKAN lagi cuma existing_matched+newly_matched, soalnya unmatched/
        // ambiguous sekarang juga disimpan (lihat saveMetricSafely, dipanggil
        // di SEMUA jalur persistMedia()). Dulu ini bikin summary "0 metrics
        // saved" walau sebenarnya N baris berhasil kesimpen tiap kali semua
        // media di batch itu unmatched.
        $metricsSaved = $summary['metrics_saved'];
        $unresolvedCount = $summary['unmatched'] + $summary['ambiguous'];
        $status = ($metricsSaved > 0 || count($media) === 0) ? 'success' : 'failed';

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
            'media_count' => count($media),
            'username' => $profile['username'] ?? null,
            'account_media_count' => $profile['media_count'] ?? null,
            // PASS 4.1 - diteruskan ke refreshKnownMedia() lewat caller
            // (Job) sebagai $excludeFetchedSince, BUKAN dipakai di sini.
            'run_started_at' => $runStartedAt,
        ];
    }

    /**
     * Snapshot maintenance correction (audit sync horizon, "keep discovery
     * and observation separate") - refresh metrik buat content yang SUDAH
     * DIKENAL sistem, MIRROR TikTokAnalyticsSyncService::refreshKnownVideos()
     * (Instagram API bedanya: insights per media diambil SATU-SATU via
     * InstagramAnalyticsService::getMediaInsights($mediaId, ...) - Instagram
     * Graph API tidak punya endpoint "batch query by IDs" resmi seperti
     * TikTok video/query/, jadi tidak ada batching di sini, TAPI TETAP
     * direct by-ID lookup - BUKAN discovery ulang/paging getMedia()).
     *
     * SENGAJA TIDAK dibatasi published_at/discovery window/retention
     * window sama sekali - content age TIDAK menentukan apakah observasi
     * hari ini masih dibutuhkan (post lama tetap bisa dapat views baru).
     * SELURUH known media integration ini eligible (termasuk yang
     * unmatched - match_status/content_publication_id TIDAK memengaruhi
     * eligibility, cuma dipakai buat isi $contentItemId kalau ada).
     *
     * Selection: rotating, urut last_fetched_at ASC (paling lama tidak
     * di-refresh duluan - "IS NOT NULL" duluan di ORDER BY supaya NULL,
     * kalau pernah ada, selalu diprioritaskan PALING AWAL; kolom ini
     * NOT NULL di schema saat ini jadi baris NULL genuine belum pernah
     * terjadi, tapi urutan ini tetap defensif benar kalau itu berubah),
     * dibatasi config('analytics.instagram_known_refresh_budget') per
     * panggilan - budget mengontrol BIAYA (1 HTTP call/media, tidak ada
     * batch endpoint), bukan cakupan berdasar tanggal. Setiap media
     * eventually dapat giliran lagi (rotasi ~total_known/budget hari).
     *
     * Dipanggil TERPISAH dari sync() normal oleh caller, DIBUNGKUS
     * try/catch DI CALLER - kegagalan tak terduga di sini TIDAK PERNAH
     * boleh menggagalkan sync utama yang sudah berhasil. TAPI failed_count
     * > 0 TETAP direkam ke $syncLog->error_message lewat
     * KnownContentRefreshFailureMarker, supaya AnalyticsSyncOrchestrator
     * bisa menurunkan status jadi 'partial' - kegagalan TIDAK PERNAH
     * "menghilang" jadi sukses sempurna.
     *
     * PASS 4.1 (Langkah "UNIQUE DISCOVERY/RECONCILIATION CORRECTNESS") -
     * $excludeFetchedSince (opsional, dari sync()['run_started_at'] milik
     * RUN YANG SAMA) MENGECUALIKAN media yang last_fetched_at-nya sudah
     * di-touch fase sync() barusan - BUKTI NYATA live QA: akun kecil
     * (11 media total) akan membuat SELURUH 11 media itu juga terpilih
     * lagi di sini (query "paling lama tidak di-refresh" otomatis memilih
     * SEMUA yang ada kalau totalnya <= budget) - tanpa exclude ini, 11
     * media unik akan diproses UA (accounting) sebagai 22 (11 di sync()
     * + 11 lagi di sini), padahal SATU media = SATU content, bukan dua.
     * Dengan exclude ini, kandidat method ini SECARA STRUKTURAL disjoint
     * dari yang baru disentuh sync() - discovered_count kedua fase
     * (dijumlahkan lewat recordDiscovered() yang SEKARANG additive) jadi
     * genuinely = union unik, bukan lagi berpotensi tumpang tindih.
     * null (default) - method ini TETAP bisa dipanggil independen (mis.
     * command lain / pemanggilan tanpa sync() mendahului) tanpa exclude
     * apapun, perilaku identik dengan sebelum Pass 4.1.
     *
     * @return array{refreshed_count: int, failed_count: int, skipped_count: int, total_count: int, auth_failed: bool}
     */
    public function refreshKnownMedia(ApiIntegration $integration, AnalyticsSyncLog $syncLog, int $userId, ?AnalyticsSyncTask $task = null, ?Carbon $excludeFetchedSince = null): array
    {
        $budget = max(0, (int) config('analytics.instagram_known_refresh_budget'));

        // ROLLING 90-DAY SYNC COVERAGE - FINAL CORRECTION PASS: known media
        // TIDAK LAGI direfresh tanpa batas usia (keputusan produk lama,
        // lihat komentar analytics.instagram_known_refresh_budget di
        // config). Sekarang eligible HANYA kalau published_at masih di
        // dalam rolling coverage window yang SAMA dengan discovery
        // (instagram_default_sync_days) - media di luar window tetap
        // TERSIMPAN (tidak dihapus/didetach), cuma tidak lagi ikut rotasi
        // refresh normal. Pakai config key yang sama persis dengan
        // resolveSyncWindow() supaya window discovery & window eligibility
        // known-refresh TIDAK PERNAH bisa drift satu sama lain.
        $coverageLowerBound = now()->subDays((int) config('analytics.instagram_default_sync_days'))->startOfDay();

        $staleKnownMedia = InstagramMediaSnapshot::where('api_integration_id', $integration->id)
            ->where('published_at', '>=', $coverageLowerBound)
            ->when($excludeFetchedSince, fn ($q) => $q->where(fn ($q2) => $q2
                ->whereNull('last_fetched_at')
                ->orWhere('last_fetched_at', '<', $excludeFetchedSince)))
            ->orderByRaw('last_fetched_at IS NOT NULL')
            ->orderBy('last_fetched_at', 'asc')
            ->limit($budget)
            ->get(['id', 'external_post_id', 'media_product_type', 'published_at', 'content_publication_id']);

        $summary = ['refreshed_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'total_count' => $staleKnownMedia->count(), 'auth_failed' => false];

        $task?->markRunning('refreshing_known_media');
        $task?->recordDiscovered($staleKnownMedia->count());

        if ($staleKnownMedia->isEmpty()) {
            $task?->finish('success');

            return $summary;
        }

        $platform = Platform::find($integration->platform_id);
        $service = new InstagramAnalyticsService($integration);

        foreach ($staleKnownMedia as $snapshot) {
            try {
                $insight = $service->getMediaInsights($snapshot->external_post_id, $snapshot->media_product_type);

                if ($insight['category'] === InstagramApiException::AUTHENTICATION) {
                    // Token rusak - SEMUA media berikutnya di batch ini akan
                    // gagal identik, percuma lanjut (buang budget/API call).
                    // Integration ditandai butuh reconnect (Langkah 6) -
                    // BUKAN sekadar failed_count tinggi yang tidak actionable.
                    $summary['failed_count']++;
                    $summary['auth_failed'] = true;
                    $this->markNeedsReconnect($integration, $insight['error']);
                    if ($task) {
                        $task->incrementFailed();
                        AnalyticsSyncFailure::record($task, 'fetch_insights', \App\Services\AnalyticsFailureCategory::AUTHENTICATION, $insight['error'], $snapshot->external_post_id);
                    }
                    break;
                }

                if ($insight['error']) {
                    $summary['failed_count']++;

                    // Langkah 7 - last_fetched_at HANYA advance buat kondisi
                    // yang DEFINITIF/PERMANEN (content memang tidak ada/
                    // metric memang tidak didukung - percuma dicoba lagi
                    // rotasi berikutnya). transient_api_error SENGAJA TIDAK
                    // advance, supaya dicoba lagi lebih cepat, bukan
                    // menunggu giliran rotasi penuh.
                    $isDefinitive = in_array($insight['category'], ['content_unavailable', 'unsupported_metric'], true);
                    if ($isDefinitive) {
                        $snapshot->update(['last_fetched_at' => now()]);
                    }

                    if ($task) {
                        // Definitif (unsupported/tidak tersedia) BUKAN
                        // kegagalan teknis - masuk unavailable_count,
                        // BUKAN failed_count (Langkah "ERROR/AVAILABILITY
                        // CLASSIFICATION": "do not retry unsupported/
                        // provider-threshold values as though they were
                        // technical failures").
                        $isDefinitive ? $task->incrementUnavailable() : $task->incrementFailed();
                        $category = $insight['category'] === 'unsupported_metric'
                            ? \App\Services\AnalyticsFailureCategory::UNSUPPORTED
                            : ($insight['category'] === 'content_unavailable'
                                ? \App\Services\AnalyticsFailureCategory::PROVIDER_UNAVAILABLE
                                : \App\Services\AnalyticsFailureCategory::TRANSIENT);
                        if (! $isDefinitive) {
                            AnalyticsSyncFailure::record($task, 'fetch_insights', $category, $insight['error'], $snapshot->external_post_id);
                        }
                    }

                    continue;
                }

                $contentItemId = $snapshot->content_publication_id
                    ? ContentPublication::whereKey($snapshot->content_publication_id)->value('content_item_id')
                    : null;

                $item = [
                    'id' => $snapshot->external_post_id,
                    'timestamp' => $snapshot->published_at?->toIso8601String(),
                    'metrics' => $insight['metrics'],
                ];

                // Snapshot Phase 2 - identity sama (instagram_media_snapshot_id)
                // + snapshot_date HARI INI (never publish date) - upsert
                // same-day, bukan histori baru (Langkah 8).
                $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
                $this->recordSnapshot($snapshot, $insight['metrics'], $integration, $platform, $contentItemId);
                $snapshot->update(['last_fetched_at' => now()]);
                $summary['refreshed_count']++;
                $task?->incrementSuccess();
            } catch (\Throwable $e) {
                $summary['failed_count']++;
                $task?->incrementFailed();
                if ($task) {
                    AnalyticsSyncFailure::record($task, 'fetch_insights', \App\Services\AnalyticsFailureCategory::UNKNOWN, $e->getMessage(), $snapshot->external_post_id);
                }
                Log::warning('Instagram refreshKnownMedia: gagal refresh 1 media, dilewati (sync utama TIDAK terpengaruh)', [
                    'client_id' => $integration->client_id,
                    'instagram_media_snapshot_id' => $snapshot->id,
                    'error' => $e->getMessage(),
                ]);
                // Transient/tak terduga - TIDAK advance last_fetched_at,
                // coba lagi rotasi berikutnya.
            }
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
     * Langkah 6 - integration ditandai butuh reconnect TANPA menyentuh
     * status $syncLog (sync UTAMA sudah selesai & sukses sebelum refresh
     * ini dipanggil - method ini BUKAN markFailed(), sengaja tidak
     * menandai syncLog 'failed' supaya sync utama yang genuinely sukses
     * tidak ikut dilaporkan gagal cuma karena observation rotation-nya
     * kena auth error).
     */
    private function markNeedsReconnect(ApiIntegration $integration, string $message): void
    {
        $integration->update(['status' => 'inactive', 'last_error' => $message]);
    }

    // =====================================================================
    // PROGRESSIVE 90-DAY SYNC ENGINE - RESILIENCE PASS
    //
    // Replaces the ONE-JOB-DOES-EVERYTHING default-mode path (old sync() +
    // refreshKnownMedia() called back-to-back inside SyncInstagramAnalyticsJob
    // ::handle()) with a plan-once/process-in-bounded-chunks pipeline. The
    // OLD sync()/refreshKnownMedia()/persistMedia() methods above are LEFT
    // UNTOUCHED - they still serve the CLI --month historical path
    // (analytics:sync-instagram, synchronous, no queue timeout exposure,
    // naturally bounded to ~1 calendar month) and the CLI's own default-mode
    // debugging use, exactly as before. ONLY the queued "Perbarui Data"
    // entry point (AnalyticsSyncOrchestrator::dispatchInstagramContent(),
    // which ALWAYS calls resolveSyncWindow(null)) is rewired to these new
    // methods - see ProcessInstagramSyncChunkJob.
    //
    // planProgressiveRun() does discovery ONCE (getProfile+getMedia, exactly
    // like the old sync() did) and IMMEDIATELY persists every unique
    // discovered item plus every known-refresh rotation candidate into
    // analytics_sync_task_items - NO insight/metric API call happens here.
    // processChunk() is the only place that ever calls getMediaInsights(),
    // and it only ever touches the <= sync_chunk_size rows belonging to ONE
    // chunk_index, which is what keeps a single job execution's duration
    // bounded regardless of how large the 90-day workload is.
    // =====================================================================

    /**
     * @return array{total_chunks: int, discovery_count: int, known_refresh_count: int, username: ?string}
     */
    public function planProgressiveRun(ApiIntegration $integration, AnalyticsSyncTask $task, Carbon $since, Carbon $until): array
    {
        $task->markRunning('discovering_media');

        $providerService = new InstagramAnalyticsService($integration);
        $profile = $providerService->getProfile();
        $media = $this->deduplicateById($providerService->getMedia($since, $until));

        // MIRROR InstagramAnalyticsService::sync()'s per-item normalisasi
        // (thumbnail_url IMAGE media kosong di getMedia() mentah - fallback
        // ke media_url, video pakai thumbnail_url asli) - getMedia() mentah
        // TIDAK melakukan fallback ini sendiri, jadi HARUS diterapkan di
        // sini SEBELUM disimpan ke payload, supaya jalur progresif TIDAK
        // regresi kehilangan thumbnail media IMAGE dibanding jalur lama.
        $media = array_map(fn ($item) => [
            ...$item,
            'thumbnail_url' => $item['thumbnail_url'] ?? $item['media_url'] ?? null,
        ], $media);

        $integration->update([
            'status' => 'active',
            'external_account_id' => $profile['id'] ?? null,
            'external_username' => $profile['username'] ?? null,
            'last_error' => null,
            ...array_filter([
                'external_display_name' => $profile['name'] ?? null,
                'external_avatar_url' => $profile['profile_picture_url'] ?? null,
                'external_account_type' => $profile['account_type'] ?? null,
                'external_media_count' => $profile['media_count'] ?? null,
            ], fn ($v) => $v !== null),
        ]);

        $now = now();
        $chunkSize = max(1, (int) config('analytics.sync_chunk_size'));

        // Newest-first per stage (Langkah 4, "newest content must be
        // processed first") - getMedia() already returns newest-first from
        // Instagram's own pagination order, array_chunk() preserves that
        // order within each stage bucket.
        $buckets = [SyncStageBoundary::STAGE_RECENT => [], SyncStageBoundary::STAGE_MID => [], SyncStageBoundary::STAGE_OLDER => []];
        foreach ($media as $item) {
            $publishedAt = $item['timestamp'] ? Carbon::parse($item['timestamp']) : $now;
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
                        'media_type' => $item['media_product_type'] ?? null,
                        'published_at' => $item['timestamp'] ? Carbon::parse($item['timestamp']) : null,
                        'stage' => $stage,
                        'source' => AnalyticsSyncTaskItem::SOURCE_DISCOVERY,
                        'chunk_index' => $chunkIndex,
                        'status' => AnalyticsSyncTaskItem::STATUS_PENDING,
                        'payload' => json_encode($item),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        $discoveredExternalIds = array_column($media, 'id');

        // Known-refresh candidates - IDENTICAL eligibility/ordering contract
        // as refreshKnownMedia() (rolling 90-day published_at bound, budget-
        // capped rotation by last_fetched_at ASC), PLUS an explicit
        // whereNotIn() against the discovery set just planned above - a
        // STRONGER, deterministic disjointness guarantee than the old
        // timestamp-watermark ($excludeFetchedSince) approach (Langkah 23),
        // since we now have the exact discovered-ID list in hand rather
        // than inferring "touched this run" from a time boundary.
        $budget = max(0, (int) config('analytics.instagram_known_refresh_budget'));
        $knownCandidates = $budget > 0
            ? InstagramMediaSnapshot::where('api_integration_id', $integration->id)
                ->where('published_at', '>=', now()->subDays((int) config('analytics.instagram_default_sync_days'))->startOfDay())
                ->when(! empty($discoveredExternalIds), fn ($q) => $q->whereNotIn('external_post_id', $discoveredExternalIds))
                ->orderByRaw('last_fetched_at IS NOT NULL')
                ->orderBy('last_fetched_at', 'asc')
                ->limit($budget)
                ->get(['external_post_id', 'media_product_type', 'published_at'])
            : collect();

        foreach (array_chunk($knownCandidates->all(), $chunkSize) as $chunk) {
            $chunkIndex++;
            foreach ($chunk as $snapshot) {
                $rows[] = [
                    'analytics_sync_task_id' => $task->id,
                    'external_item_id' => $snapshot->external_post_id,
                    'media_type' => $snapshot->media_product_type,
                    'published_at' => $snapshot->published_at,
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

        $task->recordDiscovered(count($rows), count($rows) > 0 ? 'processing_recent' : 'processing_recent');

        return [
            'total_chunks' => $chunkIndex,
            'discovery_count' => count($media),
            'known_refresh_count' => $knownCandidates->count(),
            'username' => $profile['username'] ?? null,
        ];
    }

    /**
     * Proses SATU chunk (<= sync_chunk_size item, sudah dipartisi
     * planProgressiveRun()) - satu-satunya tempat getMediaInsights() dipanggil
     * di jalur progresif. Idempotent by construction: cuma mengambil baris
     * berstatus 'pending' milik chunk_index ini, jadi retry job yang sama
     * (worker mati/timeout di tengah chunk) TIDAK memproses ulang item yang
     * sudah terminal di percobaan sebelumnya (Langkah 9/10).
     *
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
        $providerService = new InstagramAnalyticsService($integration);
        $authFailed = false;
        $deadlineReached = false;
        $processed = 0;

        // FINAL CLOSURE GATE (Langkah 3) - lihat config('analytics.
        // sync_chunk_soft_deadline_seconds') buat perhitungan lengkap kenapa
        // angka ini aman (margin di bawah $timeout job DAN retry_after).
        // Dicek SEBELUM tiap item (BUKAN menginterupsi request yang sedang
        // berjalan) - item yang belum sempat diambil TETAP 'pending', TIDAK
        // hilang, tinggal diproses chunk_index YANG SAMA di eksekusi job
        // berikutnya (lihat ProcessInstagramSyncChunkJob::handle()).
        $deadline = now()->addSeconds((int) config('analytics.sync_chunk_soft_deadline_seconds'));

        foreach ($items as $taskItem) {
            // Langkah 20 - token sudah diketahui invalid di item SEBELUMNYA
            // dalam chunk ini - STOP, jangan hammering API dengan token yang
            // sama-sama akan gagal identik buat sisa chunk (sisanya tetap
            // 'pending', otomatis diproses ulang begitu user reconnect &
            // retry - TIDAK hilang).
            if ($authFailed) {
                break;
            }

            if (now()->greaterThan($deadline)) {
                $deadlineReached = true;
                break;
            }

            $processed++;
            $authFailed = $taskItem->source === AnalyticsSyncTaskItem::SOURCE_DISCOVERY
                ? $this->processDiscoveryTaskItem($taskItem, $platform, $integration, $userId, $syncLog, $task, $providerService)
                : $this->processKnownRefreshTaskItem($taskItem, $platform, $integration, $userId, $syncLog, $task, $providerService);
        }

        return ['processed' => $processed, 'auth_failed' => $authFailed, 'deadline_reached' => $deadlineReached];
    }

    /**
     * @return bool true kalau auth failed (caller HARUS stop chunk ini).
     */
    private function processDiscoveryTaskItem(AnalyticsSyncTaskItem $taskItem, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, AnalyticsSyncTask $task, InstagramAnalyticsService $providerService): bool
    {
        $item = $taskItem->payload;
        $matcher = new ContentPublicationMatcher();
        $result = $matcher->match($integration, $item);
        $contentItemId = null;

        if ($result->status === 'unmatched') {
            $snapshot = $this->saveSnapshot($integration, $item, 'unmatched');
        } elseif ($result->status === 'ambiguous') {
            $snapshot = $this->saveSnapshot($integration, $item, 'ambiguous');
        } else {
            try {
                $publication = $this->getOrCreatePublication($result, $item, $platform, $integration, $userId);
                $snapshot = $this->saveSnapshot($integration, $item, 'matched', $publication->id);
                $contentItemId = $publication->content_item_id;
            } catch (\Throwable $e) {
                $this->saveSnapshot($integration, $item, 'unmatched');
                $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_FAILED, 'last_error' => \Illuminate\Support\Str::limit($e->getMessage(), 500), 'core_completed_at' => now()]);
                $task->incrementFailed();
                AnalyticsSyncFailure::record($task, 'fetch_insights', \App\Services\AnalyticsFailureCategory::UNKNOWN, "gagal simpan publication - {$e->getMessage()}", $item['id'] ?? $taskItem->external_item_id, null);

                return false;
            }
        }

        $insight = $providerService->getMediaInsights($snapshot->external_post_id, $snapshot->media_product_type);

        return $this->finalizeInsightForTaskItem($taskItem, $item, $insight, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId, $task);
    }

    /**
     * @return bool true kalau auth failed (caller HARUS stop chunk ini).
     */
    private function processKnownRefreshTaskItem(AnalyticsSyncTaskItem $taskItem, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, AnalyticsSyncTask $task, InstagramAnalyticsService $providerService): bool
    {
        $snapshot = InstagramMediaSnapshot::where('api_integration_id', $integration->id)
            ->where('external_post_id', $taskItem->external_item_id)
            ->first();

        if (! $snapshot) {
            // Snapshot-nya sudah hilang (edge case, mis. dihapus manual di
            // antara planning & processing) - tidak ada yang bisa direfresh,
            // TIDAK dihitung sebagai kegagalan genuine (bukan error API).
            $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_SKIPPED, 'core_completed_at' => now()]);
            $task->incrementSkipped();

            return false;
        }

        $item = [
            'id' => $snapshot->external_post_id,
            'timestamp' => $snapshot->published_at?->toIso8601String(),
        ];

        $insight = $providerService->getMediaInsights($snapshot->external_post_id, $snapshot->media_product_type);
        $authFailed = $this->finalizeInsightForTaskItem($taskItem, $item, $insight, $snapshot, $platform, $integration, $userId, $syncLog, $snapshot->content_publication_id ? ContentPublication::whereKey($snapshot->content_publication_id)->value('content_item_id') : null, $task);

        if (! $authFailed && $taskItem->fresh()->status === AnalyticsSyncTaskItem::STATUS_SUCCESS) {
            $snapshot->update(['last_fetched_at' => now()]);
        }

        return $authFailed;
    }

    /**
     * Shared terminal-classification + persistence, dipakai KEDUA source
     * (discovery/known_refresh) - MIRROR klasifikasi error refreshKnownMedia()
     * yang sudah ada (authentication -> stop+reconnect, content_unavailable/
     * unsupported_metric -> definitif/unavailable, sisanya -> retryable
     * failure), TAPI SEKARANG per-TaskItem (bukan cuma $summary array) biar
     * genuinely resumable/retryable per baris (Langkah 8/21 - core SUCCESS
     * tetap tercatat independen dari optional yang gagal, saveMetric()/
     * recordSnapshot() dipisah try/catch persis sama seperti sebelumnya).
     *
     * @return bool true kalau auth failed (caller HARUS stop chunk ini).
     */
    private function finalizeInsightForTaskItem(AnalyticsSyncTaskItem $taskItem, array $item, array $insight, InstagramMediaSnapshot $snapshot, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?int $contentItemId, AnalyticsSyncTask $task): bool
    {
        if ($insight['category'] === InstagramApiException::AUTHENTICATION) {
            $this->markNeedsReconnect($integration, $insight['error']);
            $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_FAILED, 'last_error' => $insight['error'], 'core_completed_at' => now()]);
            $task->incrementFailed();
            AnalyticsSyncFailure::record($task, 'fetch_insights', \App\Services\AnalyticsFailureCategory::AUTHENTICATION, $insight['error'], $item['id'] ?? $taskItem->external_item_id, $contentItemId);

            return true;
        }

        if ($insight['error']) {
            $isDefinitive = in_array($insight['category'], ['content_unavailable', 'unsupported_metric'], true);

            if ($isDefinitive) {
                $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_UNAVAILABLE, 'last_error' => $insight['error'], 'core_completed_at' => now()]);
                $task->incrementUnavailable();
            } else {
                $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_FAILED, 'last_error' => $insight['error'], 'core_completed_at' => now()]);
                $task->incrementFailed();
                $category = $insight['category'] === 'unsupported_metric'
                    ? \App\Services\AnalyticsFailureCategory::UNSUPPORTED
                    : \App\Services\AnalyticsFailureCategory::TRANSIENT;
                AnalyticsSyncFailure::record($task, 'fetch_insights', $category, $insight['error'], $item['id'] ?? $taskItem->external_item_id, $contentItemId);
            }

            return false;
        }

        try {
            $itemWithMetrics = [...$item, 'metrics' => $insight['metrics']];
            $this->saveMetric($itemWithMetrics, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
        } catch (\Throwable $e) {
            $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_FAILED, 'last_error' => $e->getMessage(), 'core_completed_at' => now()]);
            $task->incrementFailed();
            AnalyticsSyncFailure::record($task, 'fetch_insights', \App\Services\AnalyticsFailureCategory::UNKNOWN, $e->getMessage(), $item['id'] ?? $taskItem->external_item_id, $contentItemId);

            return false;
        }

        // CORE sudah tersimpan (Langkah 8: "core completion must remain
        // independently successful") - kegagalan recordSnapshot() di bawah
        // ini TIDAK PERNAH menurunkan TaskItem yang sudah SUCCESS jadi
        // failed, cuma dicatat ke log operasional (MIRROR saveMetricSafely()).
        $optionalStatus = 'success';
        try {
            $this->recordSnapshot($snapshot, $insight['metrics'], $integration, $platform, $contentItemId);
        } catch (\Throwable $e) {
            $optionalStatus = 'failed';
            Log::warning('ContentMetricSnapshot write failed after ContentMetric succeeded (Instagram, progressive)', [
                'client_id' => $integration->client_id,
                'instagram_media_snapshot_id' => $snapshot->id,
                'error' => $e->getMessage(),
            ]);
        }

        $taskItem->update(['status' => AnalyticsSyncTaskItem::STATUS_SUCCESS, 'core_completed_at' => now(), 'optional_status' => $optionalStatus]);
        $task->incrementSuccess();

        return false;
    }

    /**
     * Finalisasi task setelah chunk TERAKHIR (discovery + known_refresh)
     * selesai - dipanggil ProcessInstagramSyncChunkJob. syncLog + task
     * finish() SAMA PERSIS semantiknya dengan jalur lama (status
     * ditentukan reconciliation counts, BUKAN cuma "loop selesai tanpa
     * exception").
     */
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

        // FINAL CLOSURE GATE (Langkah 1, ditemukan lewat penulisan test
        // reconnect-required) - finalizeInsightForTaskItem() SUDAH menandai
        // integration.status='inactive' lewat markNeedsReconnect() begitu
        // token invalid (lihat method itu), TAPI status TERSEBUT sebelumnya
        // TIDAK PERNAH tercermin ke task->status di sini - auth failure
        // cuma jatuh ke 'failed' generik, kehilangan sinyal actionable
        // "needs_reconnect" yang jalur lama (monolithic sync()) SELALU
        // berikan. Integration status='inactive' SEKARANG diperiksa
        // eksplisit di sini supaya KEDUA jalur (progresif & lama) identik.
        $integrationInactive = \App\Models\ApiIntegration::whereKey($task->api_integration_id)->value('status') !== 'active';
        if ($integrationInactive && $task->failed_count > 0) {
            $finalStatus = 'needs_reconnect';
        }

        $this->recordRefreshFailureMarker($syncLog, $task->failed_count, $task->discovered_count);

        $task->finish($finalStatus);
    }

    /**
     * Analytics V2 Phase B - "TARGETED RETRY", item-level (Langkah "49/50
     * Instagram media successful: retry only the failed media"). Berbeda
     * dari refreshKnownMedia() (rotasi budget-bounded SELURUH known media),
     * method ini HANYA menyasar AnalyticsSyncFailure yang tercatat
     * unresolved+retryable buat 1 task tertentu - TIDAK melakukan discovery
     * ulang, TIDAK menyentuh item yang sudah sukses.
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
        $service = new InstagramAnalyticsService($integration);

        // Retry-nya sendiri butuh AnalyticsSyncLog attribution (dipakai
        // saveMetric() buat content_metrics.sync_log_id) - baris BARU, BUKAN
        // reuse log run sebelumnya (yang statusnya sudah final/tertutup).
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

        foreach ($failures as $failure) {
            $snapshot = InstagramMediaSnapshot::where('api_integration_id', $integration->id)
                ->where('external_post_id', $failure->external_item_id)
                ->first();

            if (! $snapshot) {
                // Item ini bahkan tidak sempat ke-snapshot sama sekali
                // (gagal SEBELUM saveSnapshot()) - tidak ada yang bisa
                // diretry per-item, biarkan unresolved buat rotasi
                // refreshKnownMedia() berikutnya yang genuinely discovery
                // ulang.
                $summary['still_failed']++;
                continue;
            }

            try {
                $insight = $service->getMediaInsights($snapshot->external_post_id, $snapshot->media_product_type);

                if ($insight['category'] === InstagramApiException::AUTHENTICATION) {
                    $this->markNeedsReconnect($integration, $insight['error']);
                    $failure->markAttemptFailedAgain();
                    $summary['still_failed']++;
                    break; // token rusak, sisa retry pasti gagal identik
                }

                if ($insight['error']) {
                    $failure->markAttemptFailedAgain();
                    $summary['still_failed']++;
                    continue;
                }

                $contentItemId = $snapshot->content_publication_id
                    ? ContentPublication::whereKey($snapshot->content_publication_id)->value('content_item_id')
                    : null;

                $item = ['id' => $snapshot->external_post_id, 'timestamp' => $snapshot->published_at?->toIso8601String(), 'metrics' => $insight['metrics']];
                $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
                $this->recordSnapshot($snapshot, $insight['metrics'], $integration, $platform, $contentItemId);
                $snapshot->update(['last_fetched_at' => now()]);

                $failure->markResolved();
                $task->incrementSuccess();
                $summary['resolved']++;
            } catch (\Throwable $e) {
                $failure->markAttemptFailedAgain();
                $summary['still_failed']++;
            }
        }

        return $summary;
    }

    /**
     * Langkah 5 - failed_count > 0 TIDAK BOLEH "menghilang" jadi success
     * sempurna. APPEND (bukan overwrite) marker ke $syncLog->error_message
     * yang sudah ditulis sync() utama (mungkin sudah berisi
     * SnapshotFailureMarker sendiri) - AnalyticsSyncOrchestrator men-scan
     * exact prefix string, urutan/gabungan marker lain tidak masalah.
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
     * PASS 4.1 - dedupe 1 halaman hasil pagination by media ID, pertahankan
     * urutan asli (kemunculan PERTAMA yang menang - datanya identik untuk
     * duplikat genuine, jadi salinan mana yang dipertahankan tidak relevan
     * secara substansi). Defensif terhadap edge case cursor-based pagination
     * (item bergeser posisi antar-halaman selagi fetch berlangsung).
     *
     * @param  array<int, array<string, mixed>>  $media
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateById(array $media): array
    {
        $seen = [];

        return array_values(array_filter($media, function ($item) use (&$seen) {
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
    private function persistMedia(array $mediaResults, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?AnalyticsSyncTask $task = null): array
    {
        $matcher = new ContentPublicationMatcher();

        $summary = ['existing_matched' => 0, 'newly_matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'failed' => 0, 'metrics_saved' => 0, 'details' => []];

        foreach ($mediaResults as $item) {
            $result = $matcher->match($integration, $item);

            if ($result->status === 'unmatched') {
                $summary['unmatched']++;
                $snapshot = $this->saveSnapshot($integration, $item, 'unmatched');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary, $task);
                continue;
            }

            if ($result->status === 'ambiguous') {
                $summary['ambiguous']++;
                $summary['details'][] = "Media {$item['id']}: ambiguous - {$result->reason}";
                $snapshot = $this->saveSnapshot($integration, $item, 'ambiguous');
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary, $task);
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
                $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, null, $summary, $task);
                continue;
            }

            $wasExisting ? $summary['existing_matched']++ : $summary['newly_matched']++;
            $snapshot = $this->saveSnapshot($integration, $item, 'matched', $publication->id);

            $this->saveMetricSafely($item, $snapshot, $platform, $integration, $userId, $syncLog, $publication->content_item_id, $summary, $task);
        }

        return $summary;
    }

    /**
     * Bungkus saveMetric() dengan try/catch generik + hitung ke
     * $summary['failed'] kalau gagal - dipakai baik jalur matched maupun
     * unmatched/ambiguous (SEMUA media sekarang tetap dapat baris
     * content_metrics, lihat docblock saveMetric()). $task (Analytics V2
     * Phase B, opsional) dapat increment success/failed yang SAMA PERSIS
     * dengan $summary di atas - dua sisi TIDAK BOLEH pernah drift beda,
     * jadi di-increment BARENGAN di titik yang sama, bukan dihitung ulang
     * terpisah.
     */
    private function saveMetricSafely(array $item, InstagramMediaSnapshot $snapshot, Platform $platform, ApiIntegration $integration, int $userId, AnalyticsSyncLog $syncLog, ?int $contentItemId, array &$summary, ?AnalyticsSyncTask $task = null): void
    {
        try {
            $this->saveMetric($item, $snapshot, $platform, $integration, $userId, $syncLog, $contentItemId);
            $summary['metrics_saved']++;
            $task?->incrementSuccess();
        } catch (\Throwable $e) {
            $summary['failed']++;
            $summary['details'][] = "Media {$item['id']}: gagal simpan content_metrics - {$e->getMessage()}";

            if ($task) {
                $task->incrementFailed();
                AnalyticsSyncFailure::record($task, 'fetch_insights', \App\Services\AnalyticsFailureCategory::UNKNOWN, $e->getMessage(), $item['id'] ?? null, $contentItemId);
            }

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
    /**
     * PASS 1B (Langkah "cover_image_url has a limited provider TTL...
     * Keep/query-refresh semantics safe" - sama prinsip persis buat
     * thumbnail_url Instagram) - field metadata media yang aman disegarkan
     * ulang tiap media ini terlihat lagi (thumbnail_url TTL terbatas;
     * caption/shortcode secara teori stabil tapi tidak ada ruginya ikut
     * disegarkan). Dipakai BARENGAN oleh saveSnapshot() (sync utama) DAN
     * refreshKnownMedia()/retryFailedItems() (rotasi/retry).
     *
     * @return array<string, mixed>
     */
    private function mediaMetadataFields(array $item): array
    {
        return [
            'permalink' => $item['permalink'] ?? null,
            'caption' => $item['caption'] ?? null,
            'thumbnail_url' => $item['thumbnail_url'] ?? null,
            'shortcode' => $item['shortcode'] ?? null,
        ];
    }

    private function saveSnapshot(ApiIntegration $integration, array $item, string $matchStatus, ?int $contentPublicationId = null): InstagramMediaSnapshot
    {
        return InstagramMediaSnapshot::updateOrCreate(
            ['api_integration_id' => $integration->id, 'external_post_id' => $item['id']],
            [
                ...$this->mediaMetadataFields($item),
                'media_type' => $item['media_type'] ?? null,
                'media_product_type' => $item['media_product_type'] ?? null,
                'published_at' => $item['timestamp'] ? Carbon::parse($item['timestamp']) : null,
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
                // FINAL INSTAGRAM OPTIONAL INSIGHTS COMPLETENESS GATE -
                // metric opsional (lihat InstagramAnalyticsService::
                // getOptionalReelsMetrics()/getOptionalFeedMetrics()) -
                // null buat media yang tidak relevan (mis. watch_time_*/
                // skip_rate buat non-Reels, profile_activity/
                // attributed_follows buat non-Feed) ATAU kalau metric
                // genuinely gagal diambil, TIDAK PERNAH 0.
                //
                // watch_time_avg (rata-rata) TERPISAH dari watch_time_total
                // (agregat) - dua metric beda, dua kolom beda (Part 5,
                // "jangan overload"). skip_rate = CURRENT_RATE, TIDAK
                // PERNAH masuk delta periode (lihat fixed field list
                // PeriodPerformanceService::diffMetricsWithResetDetection(),
                // TIDAK diubah pass ini).
                'watch_time_avg' => $metrics['watch_time_avg'] ?? null,
                'watch_time_total' => $metrics['watch_time_total'] ?? null,
                'skip_rate' => $metrics['skip_rate'] ?? null,
                // profile_visits (Meta) -> kolom profile_visit yang SUDAH
                // ADA sejak migration awal (dulu cuma diisi CSV import
                // manual, sekarang JUGA lewat sync API - makna sama, bukan
                // kolom baru/overload, lihat docblock migration).
                'profile_visit' => $metrics['profile_visits'] ?? null,
                'profile_activity' => $metrics['profile_activity'] ?? null,
                'attributed_follows' => $metrics['attributed_follows'] ?? null,
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
     * nol. completion_rate SENGAJA tidak disebut di array values
     * (dibiarkan default NULL kolom) - Instagram API contract
     * (InstagramAnalyticsService::normalizeMetrics()) tidak pernah
     * menyediakan metric ini sama sekali. watch_time_avg/watch_time_total/
     * skip_rate/profile_visit/profile_activity/attributed_follows (FINAL
     * INSTAGRAM OPTIONAL INSIGHTS COMPLETENESS GATE) genuinely
     * terverifikasi tersedia (lihat InstagramAnalyticsService::
     * getOptionalReelsMetrics()/getOptionalFeedMetrics()), disebut
     * eksplisit di bawah - null buat media yang tidak relevan/kegagalan
     * metric opsional itu sendiri, TIDAK PERNAH di-delta (watch_time
     * avg/total dan skip_rate bukan cumulative count yang bisa
     * dikurangkan bermakna - lihat fixed field list
     * PeriodPerformanceService, TIDAK diubah).
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
                'watch_time_avg' => $metrics['watch_time_avg'] ?? null,
                'watch_time_total' => $metrics['watch_time_total'] ?? null,
                'skip_rate' => $metrics['skip_rate'] ?? null,
                'profile_visit' => $metrics['profile_visits'] ?? null,
                'profile_activity' => $metrics['profile_activity'] ?? null,
                'attributed_follows' => $metrics['attributed_follows'] ?? null,
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
