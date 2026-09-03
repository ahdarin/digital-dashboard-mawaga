<?php

namespace App\Services;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncInstagramAudienceJob;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ContentMetricSnapshot;
use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 - SATU-SATUNYA orchestrator buat tombol "Sinkronkan Data" global di
 * halaman Analytics/Performa. TIDAK menduplikasi business logic sync sama
 * sekali - cuma memutuskan job MANA yang relevan buat client+platform filter
 * yang dipilih, lalu dispatch job yang SUDAH ADA (SyncInstagramAnalyticsJob,
 * SyncInstagramAudienceJob, SyncTikTokAnalyticsJob) persis seperti
 * SettingsController::syncInstagram()/syncTiktok() &
 * ClientManagementController::syncInstagramAudience() sudah lakukan -
 * signature dispatch, resolveSyncWindow(null) (lookback default Phase 1,
 * BUKAN period display filter), dan lock-peek pattern SEMUA disalin apa
 * adanya dari situ, bukan ditulis ulang beda.
 *
 * Status resolution (queued/running/success/failed/dst) MENGGENERALISASI
 * pola yang SUDAH ADA di ClientManagementController::tiktokSyncStatus()
 * (lock peek + scan tabel `jobs` + AnalyticsSyncLog terakhir) supaya
 * Instagram Content, Instagram Audience, dan TikTok Content punya SATU
 * kontrak status yang identik - Client Detail TikTok polling yang sudah
 * dites TIDAK disentuh sama sekali (endpoint itu tetap ada, tetap pakai
 * logic sendiri, TIDAK di-refactor buat pakai class ini - Langkah 7,
 * "jangan merusak existing Client Detail polling yang sudah dites").
 */
class AnalyticsSyncOrchestrator
{
    public const SUBJOB_INSTAGRAM_CONTENT = 'instagram_content';

    public const SUBJOB_INSTAGRAM_AUDIENCE = 'instagram_audience';

    public const SUBJOB_TIKTOK_CONTENT = 'tiktok_content';

    /**
     * Subjob mana yang relevan buat platform filter GLOBAL ini (Langkah 3).
     * null $platformId = All Platforms.
     *
     * @return array<int, string>
     */
    public function relevantSubjobs(?int $platformId): array
    {
        $instagramId = Platform::where('name', 'Instagram')->value('id');
        $tiktokId = Platform::where('name', 'TikTok')->value('id');

        $wantInstagram = $platformId === null || $platformId === $instagramId;
        $wantTiktok = $platformId === null || $platformId === $tiktokId;

        $subjobs = [];
        if ($wantInstagram) {
            $subjobs[] = self::SUBJOB_INSTAGRAM_CONTENT;
            $subjobs[] = self::SUBJOB_INSTAGRAM_AUDIENCE;
        }
        if ($wantTiktok) {
            $subjobs[] = self::SUBJOB_TIKTOK_CONTENT;
        }

        return $subjobs;
    }

    /**
     * Dispatch sync buat 1 client, di-scope ke platform filter (Langkah
     * 1/3). SELALU client-scoped (Langkah 2 - caller WAJIB sudah
     * memvalidasi user berhak akses $client SEBELUM memanggil ini, method
     * ini sendiri tidak melakukan authorization check).
     *
     * HANYA subjob dengan integration ACTIVE yang benar-benar di-dispatch
     * (Langkah 4) - not_connected/needs_reconnect DI-SKIP diam-diam (bukan
     * error keras), tercermin di 'skipped'. Subjob yang KETAHUAN sudah
     * queued/running (Langkah 11/19 - lock ATAU baris di tabel `jobs`)
     * TIDAK di-dispatch ulang (server-side duplicate protection, bukan
     * cuma andalan tombol UI disabled).
     *
     * Analytics V2 Phase B - $trigger membedakan pemicu (Langkah "AUTO
     * SYNC" - scheduled vs manual HARUS lewat pipeline yang SAMA persis,
     * cuma trigger yang beda) TANPA mengubah perilaku dispatch/duplicate-
     * protection sama sekali - existing lock/queued-job check di bawah
     * SUDAH JADI mekanisme duplicate-protection-nya (subjob yang sudah
     * in-flight TIDAK pernah dapat Run/Task baru, cukup dilaporkan
     * "dispatched" apa adanya seperti sebelumnya).
     *
     * @return array{dispatched: array<int, string>, skipped: array<string, string>, run_id: ?int}
     */
    public function dispatch(Client $client, ?int $platformId, int $userId, string $trigger = AnalyticsSyncRun::TRIGGER_MANUAL): array
    {
        $dispatched = [];
        $skipped = [];
        $run = null;

        foreach ($this->relevantSubjobs($platformId) as $subjob) {
            $integration = $this->integrationFor($client, $subjob);

            if (! $integration) {
                $skipped[$subjob] = 'not_connected';
                continue;
            }

            if ($integration->status !== 'active') {
                $skipped[$subjob] = 'needs_reconnect';
                continue;
            }

            if ($this->hasActiveTask($integration, $subjob)) {
                // Sudah in-flight - anggap "dispatched" dari POV user (sync
                // memang sedang berjalan buat subjob ini), TAPI JANGAN
                // dispatch job kedua, dan JANGAN bikin Run/Task baru buat
                // sesuatu yang sudah punya jejak progress sendiri.
                $dispatched[] = $subjob;
                continue;
            }

            $run ??= AnalyticsSyncRun::create([
                'client_id' => $client->id,
                'trigger' => $trigger,
                'initiated_by' => $trigger === AnalyticsSyncRun::TRIGGER_SCHEDULED ? null : $userId,
                'status' => 'queued',
                'started_at' => now(),
            ]);

            $task = AnalyticsSyncTask::create([
                'analytics_sync_run_id' => $run->id,
                'api_integration_id' => $integration->id,
                'subjob' => $subjob,
                'status' => 'queued',
            ]);

            $this->dispatchOne($subjob, $integration, $userId, $task->id);
            $dispatched[] = $subjob;
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped, 'run_id' => $run?->id];
    }

    private function dispatchOne(string $subjob, ApiIntegration $integration, int $userId, ?int $syncTaskId): void
    {
        match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT => $this->dispatchInstagramContent($integration, $userId, $syncTaskId),
            self::SUBJOB_INSTAGRAM_AUDIENCE => SyncInstagramAudienceJob::dispatch($integration->id, $userId, false, $syncTaskId),
            self::SUBJOB_TIKTOK_CONTENT => $this->dispatchTiktokContent($integration, $userId, $syncTaskId),
        };
    }

    /**
     * resolveSyncWindow(null) SENGAJA - ingestion SELALU pakai default
     * lookback Phase 1 (instagram_default_sync_days), BUKAN period display
     * filter (7/30/90) dari Analytics (Langkah 1, "Period adalah DISPLAY
     * FILTER, jangan jadikan sync mode").
     */
    private function dispatchInstagramContent(ApiIntegration $integration, int $userId, ?int $syncTaskId): void
    {
        [$syncMode, $since, $until] = app(InstagramAnalyticsSyncService::class)->resolveSyncWindow(null);
        SyncInstagramAnalyticsJob::dispatch($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $userId, $syncTaskId);
    }

    private function dispatchTiktokContent(ApiIntegration $integration, int $userId, ?int $syncTaskId): void
    {
        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow(null);
        SyncTikTokAnalyticsJob::dispatch($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $userId, $syncTaskId);
    }

    /**
     * Analytics V2 Phase B - "TARGETED RETRY", task-level. Retry SATU
     * subjob spesifik (mis. Instagram Audience gagal padahal Content
     * sukses, atau TikTok gagal padahal Instagram sukses) TANPA menyentuh
     * subjob lain di run yang sama - dispatch ulang PERSIS jalur normal
     * (job/service yang sama, bukan logic terpisah), trigger='retry' biar
     * kebedanya jelas di audit trail.
     *
     * TIDAK retryable kalau task masih queued/running (in-flight, retry
     * duplikat percuma) ATAU integration butuh reconnect (auth rusak -
     * retry otomatis TIDAK PERNAH mengubah hasil, lihat
     * AnalyticsFailureCategory::isRetryable() - user harus reconnect
     * manual dulu, method ini SENGAJA menolak bukan mencoba lagi).
     *
     * @return array{retried: bool, reason: ?string, task_id: ?int}
     */
    public function retryTask(AnalyticsSyncTask $task, int $userId): array
    {
        $integration = $task->integration;

        if (! $integration || $integration->status !== 'active') {
            return ['retried' => false, 'reason' => 'needs_reconnect', 'task_id' => null];
        }

        // FINAL CLOSURE GATE (Langkah 2) - SATU keputusan "in-flight" yang
        // sama persis dengan dispatch(), lihat docblock hasActiveTask().
        // $task sendiri ($task->status queued/running) SUDAH tercakup di
        // sini (task ini genuinely salah satu task milik integration+
        // subjob ini), jadi pengecekan terpisah ke $task->status yang
        // dulu ada di awal method TIDAK lagi diperlukan - dihapus, bukan
        // dibiarkan dobel dengan makna yang bisa drift.
        if ($this->hasActiveTask($integration, $task->subjob)) {
            return ['retried' => false, 'reason' => 'already_in_flight', 'task_id' => null];
        }

        $run = AnalyticsSyncRun::create([
            'client_id' => $integration->client_id,
            'trigger' => AnalyticsSyncRun::TRIGGER_RETRY,
            'initiated_by' => $userId,
            'status' => 'queued',
            'started_at' => now(),
        ]);

        $newTask = AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id,
            'api_integration_id' => $integration->id,
            'subjob' => $task->subjob,
            'status' => 'queued',
            'attempt' => $task->attempt + 1,
        ]);

        $this->dispatchOne($task->subjob, $integration, $userId, $newTask->id);

        return ['retried' => true, 'reason' => null, 'task_id' => $newTask->id];
    }

    /**
     * Analytics V2 Phase B - "TARGETED RETRY", item-level (Langkah "49/50
     * Instagram media successful: retry only the failed media"). Berbeda
     * dari retryTask() (dispatch job ULANG dari awal) - method ini
     * SYNCHRONOUS, langsung memanggil retryFailedItems() milik sync
     * service yang sesuai, HANYA menyasar AnalyticsSyncFailure yang masih
     * unresolved+retryable milik task ini.
     *
     * @return array{attempted: int, resolved: int, still_failed: int}|array{retried: false, reason: string}
     */
    public function retryFailedItemsForTask(AnalyticsSyncTask $task, int $userId): array
    {
        $integration = $task->integration;

        if (! $integration || $integration->status !== 'active') {
            return ['retried' => false, 'reason' => 'needs_reconnect'];
        }

        return match ($task->subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT => app(InstagramAnalyticsSyncService::class)->retryFailedItems($task, $userId),
            self::SUBJOB_TIKTOK_CONTENT => app(TikTokAnalyticsSyncService::class)->retryFailedItems($task, $userId),
            // Audience TIDAK punya item-level failure (workload-nya metric
            // group tetap, bukan koleksi item) - task-level retryTask()
            // sudah cukup, tidak perlu varian item-level.
            default => ['retried' => false, 'reason' => 'not_applicable'],
        };
    }

    /**
     * Status lengkap (overall + per-subjob) buat 1 client, di-scope ke
     * platform filter yang SAMA dengan dispatch() (Langkah 6/8/9/10).
     * Caller WAJIB sudah memvalidasi akses client sebelum memanggil ini.
     *
     * @return array{overall_status: string, subjobs: array<string, array>, last_observation_at: ?string}
     */
    public function statusForClient(Client $client, ?int $platformId): array
    {
        $subjobStatuses = [];
        foreach ($this->relevantSubjobs($platformId) as $subjob) {
            $subjobStatuses[$subjob] = $this->resolveSubjobStatus($client, $subjob);
        }

        return [
            'overall_status' => $this->computeOverallStatus($subjobStatuses),
            'subjobs' => $subjobStatuses,
            'last_observation_at' => $this->lastObservationAt($client, $platformId)?->toIso8601String(),
        ];
    }

    /**
     * Analytics V2 Phase B - progress terstruktur DAN AMAN buat UI polling
     * (Langkah "Update sync-status endpoint to expose structured, safe
     * progress" - TIDAK PERNAH token/Authorization header/raw payload,
     * murni angka/status/timestamp yang SUDAH publik lewat kolom
     * AnalyticsSyncTask/Run). Browser refresh HARUS bisa "menemukan
     * kembali" run yang masih aktif (Langkah "PROGRESS SEMANTICS", "new
     * browser request must rediscover the existing active run") - method
     * ini SELALU query state TERBARU milik client (bukan session-based),
     * jadi genuinely server-side recoverable, browser refresh/close TIDAK
     * PERNAH memutus progress-nya.
     *
     * BUGFIX (Langkah "CONSISTENT INSTAGRAM/TIKTOK SYNC RESULT DETAIL") -
     * versi lama ambil task dari SATU AnalyticsSyncRun TERBARU SAJA
     * (whereHas + latest()), lalu filter ke subjob relevan DARI RUN ITU.
     * Kalau Instagram & TikTok terakhir kali disync di RUN TERPISAH (mis.
     * user sync per-platform di kesempatan berbeda - platform_id filter
     * beda tiap kali klik "Perbarui Data"), subjob yang BUKAN bagian dari
     * run paling akhir itu "menghilang total" dari progress.tasks - JS
     * lalu jatuh ke fallback pesan generik ("Data berhasil diperbarui.")
     * buat platform itu, padahal reconciliation counts genuine ADA, cuma
     * dari run yang sedikit lebih lama. TERBUKTI nyata: Instagram sync
     * lebih dulu lalu TikTok sync belakangan (atau sebaliknya) bikin
     * platform yang lebih dulu itu kehilangan detail-nya.
     *
     * FIX: task diambil PER SUBJOB (masing-masing task PALING BARU milik
     * subjob itu SENDIRI, id descending, LINTAS run manapun), bukan lagi
     * "seluruh subjob yang kebetulan ada di 1 run yang sama". run_id/
     * trigger/started_at top-level (murni informational/audit - TIDAK ADA
     * rendering JS yang bergantung ke situ, hanya per-task fields yang
     * dipakai) tetap diisi dari run TERBARU di antara task-task yang
     * ditemukan, supaya kontrak return tetap identik buat consumer lama.
     *
     * @return array{run_id: ?int, trigger: ?string, started_at: ?string, tasks: array<string, array>}|null
     */
    public function latestRunProgress(Client $client, ?int $platformId): ?array
    {
        $relevantSubjobs = $this->relevantSubjobs($platformId);

        $tasks = [];
        $latestRun = null;

        foreach ($relevantSubjobs as $subjob) {
            $integration = $this->integrationFor($client, $subjob);
            if (! $integration) {
                continue;
            }

            $task = AnalyticsSyncTask::where('api_integration_id', $integration->id)
                ->where('subjob', $subjob)
                ->latest('id')
                ->first();

            if (! $task) {
                continue;
            }

            // ORPHAN TASK DIAGNOSTIC - SAME effectiveTaskStatus() computation
            // statusFromTask() uses below, so this payload's status/
            // finished_at can NEVER disagree with subjobs[$subjob] the way
            // the pre-fix two-signal design once could (Langkah "SYNC UI
            // STALE TERMINAL STATE BUG FIX" applied structurally again
            // here) - a stale task reports 'failed' + a synthetic
            // finished_at (never written back to the DB row itself) in
            // BOTH places, which is what lets the JS terminal-rendering
            // branch (keyed off task.finished_at) AND the retry button
            // (keyed off task.status==='failed') both engage correctly.
            $effective = $this->effectiveTaskStatus($task);

            $tasks[$subjob] = [
                // PASS 3 (Langkah H, "TARGETED RETRY UX") - 'id' TAMBAHAN
                // (additive, key baru) - JS butuh task_id ini buat manggil
                // POST /analytics/sync/retry-task /retry-failed-items,
                // sebelumnya endpoint retry belum ada jadi id belum pernah
                // perlu diekspos. Angka murni (id integer publik ke user
                // yang sudah authorized lihat client ini), bukan secret.
                'id' => $task->id,
                // FINAL CORRECTNESS GATE (Langkah "CROSS-RUN TASK
                // COMPOSITION SEMANTICS") - 'run_id' TAMBAHAN (additive) -
                // JS BUTUH ini buat tahu apakah task subjob sekunder (mis.
                // instagram_audience) genuinely bagian dari operasi
                // TERKOORDINASI yang SAMA dengan task primary (instagram_
                // content) - dispatch()/retryTask() SELALU membuat SATU
                // AnalyticsSyncRun per PANGGILAN, dipakai bareng SEMUA
                // subjob yang di-dispatch di panggilan itu (lihat dispatch()
                // "$run ??="), jadi run_id yang SAMA = genuinely 1 update
                // terkoordinasi, run_id BEDA = task lain, TIDAK terkait
                // dengan operasi yang sedang/baru selesai ditampilkan.
                'run_id' => $task->analytics_sync_run_id,
                'status' => $effective['status'],
                'stage' => $task->stage,
                'discovered_count' => $task->discovered_count,
                'processed_count' => $task->processed_count,
                'success_count' => $task->success_count,
                'unavailable_count' => $task->unavailable_count,
                'skipped_count' => $task->skipped_count,
                'failed_count' => $task->failed_count,
                'reconciled' => $task->reconciled,
                'started_at' => $task->started_at?->toIso8601String(),
                'last_progress_at' => $task->last_progress_at?->toIso8601String(),
                'finished_at' => $effective['finished_at']?->toIso8601String(),
                'attempt' => $task->attempt,
            ];

            if (! $latestRun || $task->analytics_sync_run_id > $latestRun->id) {
                $latestRun = $task->run;
            }
        }

        if (empty($tasks)) {
            return null;
        }

        return [
            'run_id' => $latestRun?->id,
            'trigger' => $latestRun?->trigger,
            'started_at' => $latestRun?->started_at?->toIso8601String(),
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array{status: string, message: string, synced_count: ?int, skipped_count: ?int, error_message: ?string, finished_at: ?string}
     */
    private function resolveSubjobStatus(Client $client, string $subjob): array
    {
        $integration = $this->integrationFor($client, $subjob);

        if (! $integration) {
            // Langkah 15/22 (Phase 4) - kalau platform ini TIDAK connected
            // TAPI client punya data manual/CSV buat platform itu, JANGAN
            // tampilkan seolah API sync tersedia - beri copy yang jujur
            // soal SUMBER data yang memang sudah ada. Status SEKARANG
            // 'manual_data' (Phase 4.1 Langkah 2) - status TERSENDIRI,
            // BUKAN lagi disamarkan sebagai 'not_connected' dengan pesan
            // beda, supaya konsumen (JS/test) bisa membedakan "tidak ada
            // data apapun" vs "ada data, tapi bukan dari API" secara
            // eksplisit. Dua-duanya tetap dikecualikan sama dari
            // success/failure judgment di computeOverallStatus(). Audience
            // (subjob instagram_audience) SENGAJA TIDAK dicek di sini -
            // audience independen dari content snapshot (Langkah 16), CSV
            // audience punya jalur impor sendiri (AudienceController::
            // importCsv), tidak dicampur di sini.
            if ($subjob !== self::SUBJOB_INSTAGRAM_AUDIENCE && $this->hasManualDataFor($client, $subjob)) {
                return $this->statusPayload('manual_data', $this->manualDataMessage($subjob), null);
            }

            return $this->statusPayload('not_connected', $this->notConnectedMessage($subjob), null);
        }

        if ($integration->status !== 'active') {
            return $this->statusPayload('needs_reconnect', $this->needsReconnectMessage($subjob), null);
        }

        // SYNC UI STALE TERMINAL STATE BUG FIX - single source of truth.
        // Previously this method decided busy/queued/running/failed from a
        // SEPARATE signal (cache lock peek + `jobs` table scan + latest
        // AnalyticsSyncLog heuristic below) than what latestRunProgress()
        // presents (AnalyticsSyncTask.status, read directly) - two
        // independently-computed signals with NO structural guarantee of
        // agreeing. Reproduced concretely: seed an OLD terminal
        // AnalyticsSyncTask (status=failed) then dispatch a NEW one - every
        // poll during the real run showed both signals correctly agreeing
        // in this environment, but the two-signal design itself is the
        // real defect the video's symptom points to (a stale terminal
        // result rendered concurrently with a newer active/completed run)
        // - eliminating the second, independently-computed signal removes
        // that entire class of bug structurally, rather than chasing the
        // exact interleaving that triggers it. When an AnalyticsSyncTask
        // exists, it is now ALWAYS authoritative here. Legacy fallback
        // (below, unchanged) only applies when NO Task exists at all - a
        // sync dispatched outside AnalyticsSyncOrchestrator (the
        // historical --month CLI/Settings-form path), which never creates
        // a Task row.
        $latestTask = AnalyticsSyncTask::where('api_integration_id', $integration->id)
            ->where('subjob', $subjob)
            ->latest('id')
            ->first();

        if ($latestTask) {
            return $this->statusFromTask($latestTask, $subjob, $integration);
        }

        $jobClass = $this->jobClassFor($subjob);
        $sourceType = $this->sourceTypeFor($subjob);

        $running = $this->isLockHeld($jobClass, $integration->id);
        $queued = ! $running && $this->hasQueuedJob($jobClass, $integration->id);
        $stale = false;

        $lastLog = AnalyticsSyncLog::where('api_integration_id', $integration->id)
            ->where('source_type', $sourceType)
            ->latest()
            ->first();

        if ($running) {
            $status = 'running';
        } elseif ($queued) {
            $status = 'queued';
        } else {
            // PROGRESSIVE 90-DAY SYNC ENGINE (Langkah 6/13/17) - a
            // progressive run passes through MANY short-lived chunk jobs;
            // between chunk N finishing and chunk N+1 being picked up,
            // neither isLockHeld() nor hasQueuedJob() above is true (no
            // lock held, no row in `jobs` yet), so mapLogStatus() would
            // otherwise judge staleness PURELY from the syncLog's static
            // updated_at (which never changes mid-run - the log stays
            // 'pending' until the run's very last chunk). A genuinely
            // active AnalyticsSyncTask's last_progress_at DOES advance on
            // every chunk (every increment*() call touches it), so it is
            // used here as the freshness anchor whenever a non-terminal
            // task exists for this subjob - this is what keeps a
            // multi-chunk run correctly reported 'running' regardless of
            // how many chunks/how long the FULL 90-day workload takes,
            // instead of only within a single fixed grace window.
            $activeTaskProgressAt = AnalyticsSyncTask::where('api_integration_id', $integration->id)
                ->where('subjob', $subjob)
                ->whereIn('status', ['queued', 'running'])
                ->latest('id')
                ->value('last_progress_at');

            $status = $this->mapLogStatus($subjob, $lastLog, $activeTaskProgressAt);
            if ($lastLog?->status === 'pending' && $status === 'failed') {
                $stale = true; // dipetakan failed KARENA stale, bukan kegagalan asli - lihat mapLogStatus()
            }
        }

        // Langkah 6/14 (Phase 4) - "sukses" tapi Phase 2 snapshot-history
        // gagal TIDAK BOLEH tetap dilaporkan sebagai success sempurna -
        // turunkan ke partial. Deteksi lewat SnapshotFailureMarker (Phase
        // 4.1 Langkah 4 - marker TERSENTRALISASI, dipakai bareng oleh
        // writer di InstagramAnalyticsSyncService/TikTokAnalyticsSyncService
        // supaya wording writer & detector di sini TIDAK BISA diam-diam
        // drift beda), TANPA menambah kolom baru ke analytics_sync_logs.
        if ($status === 'success' && SnapshotFailureMarker::detectedIn($lastLog?->error_message)) {
            $status = 'partial';
        }

        // Snapshot maintenance correction (Langkah 5) - sama alasan persis
        // seperti SnapshotFailureMarker di atas: refresh known-content yang
        // sebagian gagal TIDAK BOLEH tetap dilaporkan sukses sempurna.
        if ($status === 'success' && KnownContentRefreshFailureMarker::detectedIn($lastLog?->error_message)) {
            $status = 'partial';
        }

        $message = $stale ? $this->staleMessage($subjob) : $this->messageFor($subjob, $status);

        return $this->statusPayload($status, $message, $lastLog);
    }

    /**
     * SYNC UI STALE TERMINAL STATE BUG FIX - status derived DIRECTLY from
     * the given AnalyticsSyncTask (the exact same row latestRunProgress()
     * shows), not from a separately-computed lock/jobs-table/log heuristic.
     * A task's own status is always current (queued/running are durable
     * for the task's WHOLE lifetime, see AnalyticsSyncTask::markRunning()/
     * finish() - no staleness heuristic is needed here: an abandoned task
     * eventually reaches a terminal status on its own via Laravel's normal
     * job-retry exhaustion, see final report Section 5 of the prior
     * closure-gate pass).
     *
     * @return array{status: string, message: string, synced_count: ?int, skipped_count: ?int, error_message: ?string, finished_at: ?string}
     */
    private function statusFromTask(AnalyticsSyncTask $task, string $subjob, ApiIntegration $integration): array
    {
        $lastLog = AnalyticsSyncLog::where('api_integration_id', $integration->id)
            ->where('source_type', $this->sourceTypeFor($subjob))
            ->latest()
            ->first();

        $effective = $this->effectiveTaskStatus($task);

        if (in_array($effective['status'], ['queued', 'running'], true)) {
            return $this->statusPayload($effective['status'], $this->messageFor($subjob, $effective['status']), $lastLog);
        }

        if ($effective['status'] === 'failed' && $task->status !== 'failed') {
            // ORPHAN TASK DIAGNOSTIC - task terdeteksi STALE (lihat
            // isTaskStale()), bukan genuinely failed di DB - pesan KHUSUS
            // "terhenti", bukan seolah-olah ada error API asli.
            return $this->statusPayload('failed', $this->staleMessage($subjob), $lastLog);
        }

        $status = $task->status; // success/partial/failed/needs_reconnect

        // MIRROR the legacy path's downgrade rules (Langkah 5/6/14) - a
        // task reported as a clean 'success' by AnalyticsSyncTask::finish()
        // can still have a partial-write marker recorded in the sync log
        // (Phase 2 snapshot-history write failing after content_metrics
        // itself succeeded) - that must still downgrade the presented
        // status to 'partial', exactly as before.
        if ($status === 'success' && SnapshotFailureMarker::detectedIn($lastLog?->error_message)) {
            $status = 'partial';
        }
        if ($status === 'success' && KnownContentRefreshFailureMarker::detectedIn($lastLog?->error_message)) {
            $status = 'partial';
        }

        $message = $status === 'needs_reconnect'
            ? $this->needsReconnectMessage($subjob)
            : $this->messageFor($subjob, $status);

        return $this->statusPayload($status, $message, $lastLog);
    }

    /**
     * Phase 4.1 Langkah 3 - PERBAIKAN dari Phase 4 (dulu 'pending' SELALU
     * dipetakan ke 'running' tanpa batas waktu, berpotensi poll selamanya
     * kalau worker crash sebelum sempat update status akhir). Live signal
     * (lock/jobs table, sudah dicek di resolveSubjobStatus() SEBELUM method
     * ini dipanggil) SELALU menang - method ini HANYA dipanggil begitu
     * KEDUANYA sudah pasti negatif (lock tidak dipegang, tidak ada baris di
     * `jobs`), yang berarti proses job (kalau pernah ada) SUDAH PASTI
     * berhenti dijalankan.
     *
     * 'pending' yang MASIH BARU (umur < staleThresholdSecondsFor()) diberi
     * grace window singkat sebagai 'running' (transisi wajar antara log
     * dibuat vs lock benar2 ke-acquire/ke-release - lihat komentar
     * threshold di staleThresholdSecondsFor()). 'pending' yang SUDAH lebih
     * tua dari itu berarti job itu PASTI crash tanpa sempat update status
     * akhir - dikembalikan sebagai 'failed' (pesan aman, BUKAN endless
     * 'running', BUKAN 'success' palsu, TIDAK expose exception/token).
     */
    private function mapLogStatus(string $subjob, ?AnalyticsSyncLog $lastLog, ?\Illuminate\Support\Carbon $activeTaskProgressAt = null): string
    {
        if ($lastLog?->status !== 'pending') {
            return match ($lastLog?->status) {
                'success' => 'success',
                'failed' => 'failed',
                default => 'idle',
            };
        }

        // PROGRESSIVE 90-DAY SYNC ENGINE - kalau ada AnalyticsSyncTask
        // aktif (queued/running) buat subjob ini, freshness-nya dihitung
        // dari last_progress_at task itu (SELALU >= updated_at syncLog,
        // dan ikut maju tiap chunk selesai), BUKAN dari syncLog->updated_at
        // yang statis sepanjang run progresif - lihat pemanggil.
        $referenceTime = $activeTaskProgressAt ?? $lastLog->updated_at;
        $ageSeconds = $referenceTime?->diffInSeconds(now()) ?? PHP_FLOAT_MAX;

        return $ageSeconds > $this->staleThresholdSecondsFor($subjob) ? 'failed' : 'running';
    }

    /**
     * Ambang stale DIDASARKAN pada lifecycle job yang SUNGGUHAN, bukan
     * angka dikarang: LOCK_EXPIRE_SECONDS masing-masing job class (600
     * detik buat SyncInstagramAnalyticsJob/SyncTikTokAnalyticsJob, 300
     * detik buat SyncInstagramAudienceJob - lihat konstanta di file job
     * masing-masing) + margin 60 detik (jeda wajar antara AnalyticsSyncLog
     * dibuat vs WithoutOverlapping lock benar2 ke-acquire/ke-release).
     * Kalau lock SUDAH TIDAK dipegang DAN tidak ada baris `jobs` TAPI log
     * masih 'pending' lebih lama dari ini, job itu PASTI sudah berhenti
     * (worker crash) - lock yang benar2 masih hidup TIDAK PERNAH sampai ke
     * method ini (sudah ketangkep sebagai 'running' oleh isLockHeld() di
     * resolveSubjobStatus() duluan).
     */
    private function staleThresholdSecondsFor(string $subjob): int
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_AUDIENCE => 360, // 300 (LOCK_EXPIRE_SECONDS) + 60
            default => 660, // 600 (LOCK_EXPIRE_SECONDS) + 60
        };
    }

    /**
     * @return array{status: string, message: string, synced_count: ?int, skipped_count: ?int, error_message: ?string, finished_at: ?string}
     */
    /**
     * ORPHAN TASK DIAGNOSTIC - single authoritative computation of "what
     * should a queued/running task ACTUALLY report", shared by
     * statusFromTask() (display) AND latestRunProgress() (progress
     * payload) AND hasActiveTask() (dispatch/retry duplicate-protection)
     * so all three can never independently drift, exactly the same
     * discipline as the earlier SYNC UI STALE TERMINAL STATE BUG FIX.
     *
     * REPRODUCED LIVE (real DB + real queue:work): a task can legitimately
     * remain status=running/stage=discovering_media in the DB forever if
     * the job that owns it dies (worker crash, Railway redeploy) and
     * nothing ever calls finish() on it again - Laravel's own retry
     * mechanism usually DOES eventually reach failed() and finish(), but
     * only after a multi-minute window (or never, if no worker returns to
     * consume the retry at all). This method gives the UI an independent
     * safety net that does not depend on that retry ever actually firing.
     *
     * @return array{status: string, finished_at: ?Carbon}
     */
    private function effectiveTaskStatus(AnalyticsSyncTask $task): array
    {
        if (in_array($task->status, ['queued', 'running'], true) && $this->isTaskStale($task)) {
            return ['status' => 'failed', 'finished_at' => $task->last_progress_at ?? $task->started_at ?? $task->created_at];
        }

        return ['status' => $task->status, 'finished_at' => $task->finished_at];
    }

    /**
     * A queued/running task is STALE only when BOTH signals agree there is
     * no executable work left capable of progressing it: (1) no live lock
     * AND no queued row for the job class that owns this subjob (checking
     * BOTH the top-level sync job AND, for the 2 progressive-engine
     * subjobs, the per-chunk job - a healthy task deep in chunk processing
     * legitimately has zero queued SyncInstagramAnalyticsJob/
     * SyncTikTokAnalyticsJob rows, only ProcessXSyncChunkJob ones), AND
     * (2) last_progress_at (which now advances on every discovery page AND
     * every chunk item, see AnalyticsSyncTask::touchDiscoveryProgress()/
     * incrementX()) has not moved in longer than staleThresholdSecondsFor()
     * - the SAME threshold + SAME "elapsed time is only trusted once no
     * live job signal exists" reasoning the legacy mapLogStatus() fallback
     * path already uses (Langkah "Do NOT use elapsed time alone if a real
     * job can legitimately still be active").
     */
    private function isTaskStale(AnalyticsSyncTask $task): bool
    {
        $integrationId = $task->api_integration_id;
        $jobClass = $this->jobClassFor($task->subjob);

        if ($this->isLockHeld($jobClass, $integrationId) || $this->hasQueuedJob($jobClass, $integrationId)) {
            return false;
        }

        $chunkJobClass = $this->chunkJobClassFor($task->subjob);
        if ($chunkJobClass && $this->hasQueuedJob($chunkJobClass, $integrationId)) {
            return false;
        }

        $referenceTime = $task->last_progress_at ?? $task->started_at ?? $task->created_at;
        $ageSeconds = $referenceTime ? $referenceTime->diffInSeconds(now()) : PHP_FLOAT_MAX;

        return $ageSeconds > $this->staleThresholdSecondsFor($task->subjob);
    }

    private function chunkJobClassFor(string $subjob): ?string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT => \App\Jobs\ProcessInstagramSyncChunkJob::class,
            self::SUBJOB_TIKTOK_CONTENT => \App\Jobs\ProcessTikTokSyncChunkJob::class,
            default => null,
        };
    }

    private function statusPayload(string $status, string $message, ?AnalyticsSyncLog $lastLog): array
    {
        $hasResult = $lastLog && in_array($status, ['success', 'partial', 'failed'], true);

        return [
            'status' => $status,
            'message' => $message,
            // synced_count/skipped_count APA ADANYA dari AnalyticsSyncLog -
            // Langkah 18, JANGAN diberi label "video ditemukan" dkk yang
            // tidak dijamin kontraknya (lihat catatan synced_count di
            // ClientManagementController::tiktokSyncStatus()).
            'synced_count' => $hasResult ? $lastLog->synced_count : null,
            'skipped_count' => $hasResult ? $lastLog->skipped_count : null,
            'error_message' => $hasResult ? $lastLog->error_message : null,
            'finished_at' => $hasResult ? $lastLog->updated_at?->toIso8601String() : null,
        ];
    }

    /**
     * Overall state machine (Phase 4.1 Langkah 2 - PERBAIKAN dari Phase 4).
     *
     * 'not_connected'/'manual_data' = TIDAK ADA ApiIntegration sama sekali
     * buat subjob itu ("belum bisa disync, tidak ada yang bisa direfresh")
     * - dikecualikan TOTAL dari success/failure judgment, PERSIS seperti
     * Phase 4 asli (manual-only platform TIDAK PERNAH bikin overall gagal).
     *
     * 'needs_reconnect' BEDA - integration-nya ADA tapi TIDAK VALID
     * (Langkah 2 audit: Phase 4 asli keliru menyamakan ini dengan
     * not_connected). Sekarang IKUT dihitung:
     * - SEMUA subjob yang actionable (integration ada) needs_reconnect ->
     *   overall = needs_reconnect (contoh F: semua integration inactive).
     * - SEBAGIAN needs_reconnect, sebagian lain sukses/gagal -> overall =
     *   partial (refresh yang diminta TIDAK LENGKAP - contoh C: IG sukses +
     *   TikTok butuh reconnect).
     * - TIDAK ADA yang needs_reconnect DAN semua actionable sukses ->
     *   success (contoh D).
     *
     * @param  array<string, array{status: string}>  $subjobStatuses
     */
    private function computeOverallStatus(array $subjobStatuses): string
    {
        $statuses = collect($subjobStatuses)->pluck('status');

        if ($statuses->isEmpty()) {
            return 'idle';
        }

        // Informational SAJA - tidak ada integration buat subjob ini sama
        // sekali (baik memang belum connect, ATAU cuma punya data manual).
        $actionable = $statuses->reject(fn ($s) => in_array($s, ['not_connected', 'manual_data'], true));

        if ($actionable->isEmpty()) {
            // SEMUA subjob relevan informational - tidak ada satupun yang
            // benar2 bisa di-refresh via API.
            return 'not_connected';
        }

        if ($actionable->contains('running')) {
            return 'running';
        }

        if ($actionable->contains('queued')) {
            return 'queued';
        }

        $attempted = $actionable->reject(fn ($s) => $s === 'idle');

        if ($attempted->isEmpty()) {
            // Cuma ada subjob 'idle' (integration active, TAPI belum
            // PERNAH disync sama sekali) - belum ada operasi buat dilacak.
            return 'idle';
        }

        $reconnect = $attempted->filter(fn ($s) => $s === 'needs_reconnect');

        if ($reconnect->count() === $attempted->count()) {
            // SEMUA integration yang relevan butuh reconnect (contoh F).
            return 'needs_reconnect';
        }

        if ($reconnect->isNotEmpty()) {
            // Campuran needs_reconnect + subjob lain (sukses/gagal) -
            // refresh yang diminta TIDAK LENGKAP walau sebagian sukses
            // (contoh C) - JANGAN overall=success.
            return 'partial';
        }

        if ($attempted->every(fn ($s) => $s === 'success')) {
            return 'success';
        }

        if ($attempted->every(fn ($s) => $s === 'failed')) {
            return 'failed';
        }

        // Campuran success/failed/partial (tanpa needs_reconnect) -
        // Langkah 20: JANGAN rollback yang sudah sukses, JANGAN klaim
        // overall failed kalau ada data valid yang masuk.
        return 'partial';
    }

    /**
     * Freshness (Langkah 14) - observasi genuine TERAKHIR yang benar2
     * tersimpan, BUKAN period coverage (itu urusan PeriodPerformanceService,
     * tidak disentuh di sini sama sekali - Langkah 6 kickoff "JANGAN
     * mengubah calculation semantics Phase 3/3.1").
     */
    public function lastObservationAt(Client $client, ?int $platformId): ?Carbon
    {
        $latest = ContentMetricSnapshot::where('client_id', $client->id)
            ->when($platformId, fn ($q) => $q->where('platform_id', $platformId))
            ->max('updated_at');

        return $latest ? Carbon::parse($latest) : null;
    }

    /**
     * PROGRESSIVE SYNC ENGINE - FINAL CLOSURE GATE (Langkah 2): satu-
     * satunya keputusan "apakah subjob ini sudah in-flight" - dipakai
     * IDENTIK oleh dispatch() (tombol "Perbarui Data" biasa) DAN
     * retryTask() (tombol "Coba lagi"), menghapus split semantik yang
     * sebelumnya ada (dispatch() sudah task-status-based, retryTask()
     * masih murni lock/jobs-table peek yang basi lintas jeda antar-chunk
     * progresif - lihat catatan lama di dispatch() yang sekarang pindah
     * ke sini).
     *
     * Task-status (queued/running) adalah SINYAL UTAMA - durable
     * sepanjang masa hidup SELURUH chunk chain progresif, TIDAK PERNAH
     * "kosong" di jeda antar-chunk seperti lock cache/baris `jobs`.
     * Lock/queued-job peek TETAP dipertahankan sebagai defense-in-depth
     * buat race sempit SEBELUM Task pertama sempat dibuat (celah singkat
     * antara dispatch() lolos pengecekan lock lama dan baris Task baru
     * benar2 ter-commit).
     */
    private function hasActiveTask(ApiIntegration $integration, string $subjob): bool
    {
        // ORPHAN TASK DIAGNOSTIC - a queued/running task row alone is no
        // longer sufficient (see isTaskStale()/effectiveTaskStatus()) -
        // without this, a genuinely orphaned task (worker gone, no live
        // job left) would permanently block the user's own "Coba lagi"
        // from ever dispatching a fresh attempt, even after the UI
        // correctly starts showing it as failed.
        $activeTask = AnalyticsSyncTask::where('api_integration_id', $integration->id)
            ->where('subjob', $subjob)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($activeTask && ! $this->isTaskStale($activeTask)) {
            return true;
        }

        $jobClass = $this->jobClassFor($subjob);

        return $this->isLockHeld($jobClass, $integration->id) || $this->hasQueuedJob($jobClass, $integration->id);
    }

    /**
     * Peek non-invasif ke WithoutOverlapping lock (pola PERSIS
     * ClientManagementController::tiktokSyncStatus()/syncInstagramAudience()
     * - get lalu langsung release kalau berhasil, cuma buat tahu APAKAH
     * sedang dipegang, bukan buat benar2 memegangnya).
     */
    private function isLockHeld(string $jobClass, int $integrationId): bool
    {
        $lock = Cache::lock($jobClass::cacheLockKey($integrationId), 10);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * Generalisasi ClientManagementController::hasQueuedTiktokJob() - scan
     * tabel `jobs` (queue driver 'database') buat instance job class
     * manapun yang property apiIntegrationId-nya cocok. Dipakai KEDUANYA:
     * status resolution (Langkah 6/11) DAN dispatch() (Langkah 19,
     * duplicate protection server-side).
     */
    private function hasQueuedJob(string $jobClass, int $integrationId): bool
    {
        $shortName = class_basename($jobClass);

        return DB::table('jobs')
            ->where('payload', 'like', "%{$shortName}%")
            ->get(['payload'])
            ->contains(function ($row) use ($jobClass, $integrationId) {
                $payload = json_decode($row->payload, true);
                $serializedCommand = $payload['data']['command'] ?? null;
                if (! is_string($serializedCommand)) {
                    return false;
                }

                $command = @unserialize($serializedCommand);

                return $command instanceof $jobClass && $command->apiIntegrationId === $integrationId;
            });
    }

    private function integrationFor(Client $client, string $subjob): ?ApiIntegration
    {
        $platformName = $subjob === self::SUBJOB_TIKTOK_CONTENT ? 'TikTok' : 'Instagram';

        return $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', $platformName))
            ->first();
    }

    private function jobClassFor(string $subjob): string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT => SyncInstagramAnalyticsJob::class,
            self::SUBJOB_INSTAGRAM_AUDIENCE => SyncInstagramAudienceJob::class,
            self::SUBJOB_TIKTOK_CONTENT => SyncTikTokAnalyticsJob::class,
        };
    }

    private function sourceTypeFor(string $subjob): string
    {
        return $subjob === self::SUBJOB_INSTAGRAM_AUDIENCE ? 'audience_api_sync' : 'api_sync';
    }

    private function notConnectedMessage(string $subjob): string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT, self::SUBJOB_INSTAGRAM_AUDIENCE => 'Instagram belum terhubung untuk client ini.',
            self::SUBJOB_TIKTOK_CONTENT => 'TikTok belum terhubung untuk client ini.',
        };
    }

    /**
     * CSV/manual = baris ContentMetric dengan KEDUA snapshot FK null (Phase
     * 3 docblock computeAggregate() - identitas persis yang dipakai
     * calculation engine buat memisahkan jalur CSV dari API, disalin
     * kriterianya di sini, BUKAN logic baru).
     */
    private function hasManualDataFor(Client $client, string $subjob): bool
    {
        $platformName = $subjob === self::SUBJOB_TIKTOK_CONTENT ? 'TikTok' : 'Instagram';
        $platformId = Platform::where('name', $platformName)->value('id');

        if (! $platformId) {
            return false;
        }

        return \App\Models\ContentMetric::where('client_id', $client->id)
            ->where('platform_id', $platformId)
            ->whereNull('instagram_media_snapshot_id')
            ->whereNull('tiktok_video_snapshot_id')
            ->exists();
    }

    private function manualDataMessage(string $subjob): string
    {
        $platform = $subjob === self::SUBJOB_TIKTOK_CONTENT ? 'TikTok' : 'Instagram';

        return "Data {$platform} untuk client ini berasal dari input manual.";
    }

    private function needsReconnectMessage(string $subjob): string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT, self::SUBJOB_INSTAGRAM_AUDIENCE => 'Koneksi Instagram butuh dihubungkan ulang.',
            self::SUBJOB_TIKTOK_CONTENT => 'Koneksi TikTok butuh dihubungkan ulang.',
        };
    }

    private function messageFor(string $subjob, string $status): string
    {
        $label = $this->labelFor($subjob);

        return match ($status) {
            'queued' => "{$label}: sedang antre...",
            'running' => "{$label}: sedang mengambil data...",
            'partial' => "{$label}: selesai sebagian.",
            'success' => "{$label}: berhasil disinkronkan.",
            'failed' => "{$label}: gagal disinkronkan.",
            default => "{$label}: belum pernah disinkronkan.",
        };
    }

    /**
     * Pesan KHUSUS buat kasus stale-pending (Phase 4.1 Langkah 3) - beda
     * dari kegagalan sync asli (yang biasanya punya error_message jelas
     * dari API), ini murni "kita tidak tahu apa yang terjadi, proses-nya
     * berhenti tanpa laporan akhir" - TIDAK menyebut kata teknis
     * (exception/worker/lock) ke user, cukup ajakan coba lagi.
     */
    private function staleMessage(string $subjob): string
    {
        $label = $this->labelFor($subjob);

        return "{$label}: sinkronisasi sebelumnya terhenti tanpa status akhir yang jelas. Coba sinkronkan ulang.";
    }

    private function labelFor(string $subjob): string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT => 'Analitik Konten Instagram',
            self::SUBJOB_INSTAGRAM_AUDIENCE => 'Audience Insights Instagram',
            self::SUBJOB_TIKTOK_CONTENT => 'Analitik TikTok',
        };
    }
}
