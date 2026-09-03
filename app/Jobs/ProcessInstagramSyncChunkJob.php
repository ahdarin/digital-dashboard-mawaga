<?php

namespace App\Jobs;

use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncTask;
use App\Models\AnalyticsSyncTaskItem;
use App\Models\ApiIntegration;
use App\Services\InstagramAnalyticsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PROGRESSIVE 90-DAY SYNC ENGINE - RESILIENCE PASS. Processes exactly ONE
 * chunk (<= config('analytics.sync_chunk_size') items, already partitioned
 * by SyncInstagramAnalyticsJob::handleProgressive()/planProgressiveRun())
 * then either dispatches the NEXT chunk or finalizes the AnalyticsSyncTask
 * if this was the last one. The user only ever sees ONE task/run - this
 * job is an internal implementation detail never surfaced to the UI
 * (Langkah 2/6/13 - "do not expose chunk job # to ordinary users").
 *
 * Idempotent/resumable by construction: InstagramAnalyticsSyncService::
 * processChunk() only ever touches analytics_sync_task_items rows still
 * 'pending' for this chunk_index - a Laravel-retried attempt of THIS SAME
 * job (after a timeout/crash mid-chunk) safely skips whatever the previous
 * attempt already resolved (Langkah 9/10).
 *
 * Deliberately does NOT use WithoutOverlapping the way SyncInstagramAnalyticsJob
 * does - that lock is acquire-then-release-per-execution, which would
 * incorrectly treat chunk N+1 as "overlapping" chunk N's still-unexpired
 * lock. Cross-chunk duplicate-run protection instead comes from
 * AnalyticsSyncTask.status staying 'running' for the task's ENTIRE
 * lifetime (all chunks), which AnalyticsSyncOrchestrator::dispatch() now
 * also checks directly (Langkah 16) - a durable, DB-backed guard that
 * does not depend on any lock TTL surviving the variable-length chunk
 * chain.
 */
class ProcessInstagramSyncChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    // A single chunk is bounded to sync_chunk_size items (default 20),
    // each at most 1 core + 1 optional request - 300s is generous headroom
    // even under adverse network conditions (see final report Section 18
    // for the retry_after relationship this was sized against).
    public $timeout = 300;

    public function __construct(
        public readonly int $apiIntegrationId,
        public readonly int $syncTaskId,
        public readonly int $syncLogId,
        public readonly int $userId,
        public readonly int $chunkIndex,
    ) {
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(InstagramAnalyticsSyncService $service): void
    {
        $task = AnalyticsSyncTask::find($this->syncTaskId);
        $integration = ApiIntegration::find($this->apiIntegrationId);
        $syncLog = AnalyticsSyncLog::find($this->syncLogId);

        if (! $task || ! $integration || ! $syncLog) {
            // Run/integration dihapus di antara dispatch & eksekusi (edge
            // case) - tidak ada yang bisa dilanjutkan, TIDAK retry.
            return;
        }

        $stageLabel = $this->stageLabelFor($task);
        $task->markRunning($stageLabel);

        $result = $service->processChunk($task, $this->chunkIndex, $syncLog, $this->userId);

        if ($result['auth_failed']) {
            // Token diketahui invalid di chunk ini - JANGAN dispatch chunk
            // berikutnya (bakal gagal identik, buang budget API) - sisa
            // item TETAP 'pending' (bukan hilang), otomatis diproses lagi
            // begitu user reconnect & retry (Langkah 20).
            $service->finalizeProgressiveRun($task, $syncLog);

            return;
        }

        if ($result['deadline_reached']) {
            // FINAL CLOSURE GATE (Langkah 3) - chunk ini menyentuh soft
            // deadline (config('analytics.sync_chunk_soft_deadline_seconds'))
            // SEBELUM habis - sisa item chunk_index INI TETAP 'pending'.
            // Dispatch ULANG chunk_index YANG SAMA (BUKAN chunk berikutnya)
            // di eksekusi job baru yang bounded durasinya lagi dari nol -
            // ini yang membuat 1 chunk TIDAK PERNAH bisa mendekati
            // $timeout (300 detik) walau provider sedang lambat.
            self::dispatch($this->apiIntegrationId, $this->syncTaskId, $this->syncLogId, $this->userId, $this->chunkIndex);

            return;
        }

        $nextChunkIndex = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)
            ->where('chunk_index', '>', $this->chunkIndex)
            ->min('chunk_index');

        if ($nextChunkIndex) {
            self::dispatch($this->apiIntegrationId, $this->syncTaskId, $this->syncLogId, $this->userId, (int) $nextChunkIndex);

            return;
        }

        $service->finalizeProgressiveRun($task, $syncLog);
    }

    /**
     * Langkah 13/15 - stage label user-facing, DIPILIH dari stage MEDIA
     * TERTINGGI yang masih ada di chunk_index >= chunk ini (bukan cuma
     * chunk saat ini sendiri), supaya "Memperbarui data 30 hari terbaru"
     * TIDAK langsung berubah jadi "sebelumnya" padahal masih ada media
     * recent lain di chunk yang sama.
     */
    private function stageLabelFor(AnalyticsSyncTask $task): string
    {
        $stage = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)
            ->where('chunk_index', $this->chunkIndex)
            ->value('stage');

        return match ((int) $stage) {
            \App\Services\SyncStageBoundary::STAGE_RECENT => 'processing_recent',
            \App\Services\SyncStageBoundary::STAGE_MID => 'processing_previous',
            \App\Services\SyncStageBoundary::STAGE_OLDER => 'processing_older',
            default => 'refreshing_known_media',
        };
    }

    public function failed(\Throwable $e): void
    {
        $task = AnalyticsSyncTask::find($this->syncTaskId);
        $syncLog = AnalyticsSyncLog::find($this->syncLogId);

        if (! $task || ! $syncLog) {
            return;
        }

        // Retry Laravel HABIS buat chunk ini - item yang masih 'pending' di
        // chunk ini/berikutnya TETAP tersimpan (Langkah 9/10, TIDAK hilang),
        // tapi run-nya sendiri harus mencapai status terminal (partial,
        // BUKAN diam-diam dianggap sukses) supaya UI tidak menggantung.
        app(InstagramAnalyticsSyncService::class)->finalizeProgressiveRun($task, $syncLog);
    }
}
