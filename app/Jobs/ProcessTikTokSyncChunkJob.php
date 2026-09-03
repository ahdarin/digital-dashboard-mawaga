<?php

namespace App\Jobs;

use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncTask;
use App\Models\AnalyticsSyncTaskItem;
use App\Models\ApiIntegration;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PROGRESSIVE 90-DAY SYNC ENGINE - MIRROR ProcessInstagramSyncChunkJob (see
 * its docblock for the full rationale on idempotency/resumability and why
 * WithoutOverlapping is deliberately NOT used here). TikTok-specific: a
 * known_refresh-source chunk maps to exactly ONE queryVideos() batch call
 * (<=20 IDs, TikTok's own official limit, matching sync_chunk_size by
 * construction) rather than one call per item.
 */
class ProcessTikTokSyncChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

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

    public function handle(TikTokAnalyticsSyncService $service): void
    {
        $task = AnalyticsSyncTask::find($this->syncTaskId);
        $integration = ApiIntegration::find($this->apiIntegrationId);
        $syncLog = AnalyticsSyncLog::find($this->syncLogId);

        if (! $task || ! $integration || ! $syncLog) {
            return;
        }

        $task->markRunning($this->stageLabelFor($task));

        $result = $service->processChunk($task, $this->chunkIndex, $syncLog, $this->userId);

        if ($result['auth_failed']) {
            $service->finalizeProgressiveRun($task, $syncLog);

            return;
        }

        if ($result['deadline_reached']) {
            // FINAL CLOSURE GATE (Langkah 3) - MIRROR ProcessInstagramSyncChunkJob.
            self::dispatch($this->apiIntegrationId, $this->syncTaskId, $this->syncLogId, $this->userId, $this->chunkIndex);

            return;
        }

        // IMMEDIATE-FAILURE INCIDENT INVESTIGATION - MIRROR
        // ProcessInstagramSyncChunkJob (lihat docblock di sana) - filtered
        // by status=pending, bukan cuma existence, biar replay/retry
        // sebuah chunk yang task-nya sudah lama selesai TIDAK cascade
        // dispatch job kosong sampai chunk terakhir.
        $nextChunkIndex = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)
            ->where('chunk_index', '>', $this->chunkIndex)
            ->where('status', AnalyticsSyncTaskItem::STATUS_PENDING)
            ->min('chunk_index');

        if ($nextChunkIndex) {
            self::dispatch($this->apiIntegrationId, $this->syncTaskId, $this->syncLogId, $this->userId, (int) $nextChunkIndex);

            return;
        }

        $service->finalizeProgressiveRun($task, $syncLog);
    }

    private function stageLabelFor(AnalyticsSyncTask $task): string
    {
        $stage = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)
            ->where('chunk_index', $this->chunkIndex)
            ->value('stage');

        return match ((int) $stage) {
            \App\Services\SyncStageBoundary::STAGE_RECENT => 'processing_recent',
            \App\Services\SyncStageBoundary::STAGE_MID => 'processing_previous',
            \App\Services\SyncStageBoundary::STAGE_OLDER => 'processing_older',
            default => 'refreshing_known_videos',
        };
    }

    public function failed(\Throwable $e): void
    {
        $task = AnalyticsSyncTask::find($this->syncTaskId);
        $syncLog = AnalyticsSyncLog::find($this->syncLogId);

        if (! $task || ! $syncLog) {
            return;
        }

        app(TikTokAnalyticsSyncService::class)->finalizeProgressiveRun($task, $syncLog);
    }
}
