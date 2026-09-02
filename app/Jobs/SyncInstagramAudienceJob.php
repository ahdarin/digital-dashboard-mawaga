<?php

namespace App\Jobs;

use App\Exceptions\InstagramApiException;
use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
use App\Services\InstagramAudienceInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Sync account-level Audience Insights - job TERPISAH dari
 * SyncInstagramAnalyticsJob (content). Lock key beda prefix
 * ("instagram-audience-sync-{id}" vs "instagram-sync-{id}") supaya audience
 * & content sync buat integration yang sama boleh jalan BERSAMAAN, tidak
 * saling blokir - dua-duanya independen (audience nggak butuh media/matcher
 * sama sekali).
 *
 * Payload cuma id + primitif (sama seperti job content) - token TIDAK
 * PERNAH ikut serialize, dibaca ulang dari DB (encrypted cast) begitu
 * job jalan.
 */
class SyncInstagramAudienceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    // Audience sync jauh lebih ringan dari content (1-11 API call, bukan
    // per-media) - 300 detik (5 menit) sudah margin besar.
    private const LOCK_EXPIRE_SECONDS = 300;

    public function __construct(
        public readonly int $apiIntegrationId,
        public readonly int $userId,
        public readonly bool $backfill = false,
        public readonly ?int $syncTaskId = null,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    private static function lockKeySuffix(int $apiIntegrationId): string
    {
        return "instagram-audience-sync-{$apiIntegrationId}";
    }

    public static function cacheLockKey(int $apiIntegrationId): string
    {
        return 'laravel-queue-overlap:'.self::class.':'.self::lockKeySuffix($apiIntegrationId);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::lockKeySuffix($this->apiIntegrationId)))
                ->expireAfter(self::LOCK_EXPIRE_SECONDS)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $integration = ApiIntegration::findOrFail($this->apiIntegrationId);
        $service = new InstagramAudienceInsightsService($integration);

        // Backfill (one-time, saat integration baru connect) cuma isi
        // reach historis - TIDAK bikin AnalyticsSyncLog sendiri (bukan
        // "sync" reguler, dan tidak ada demographic_type yang relevan buat
        // dicatat per hari - reach summary row-nya sendiri yang jadi bukti).
        if ($this->backfill) {
            $service->backfillReachHistory();
            return;
        }

        $syncLog = AnalyticsSyncLog::firstOrCreate(
            [
                'api_integration_id' => $integration->id,
                'status' => 'pending',
                'source_type' => 'audience_api_sync',
                'range_from' => now()->toDateString(),
                'range_to' => now()->toDateString(),
            ],
            [
                'client_id' => $integration->client_id,
                'platform_id' => $integration->platform_id,
                'imported_by' => $this->userId,
                'sync_mode' => 'default',
            ]
        );

        $task = $this->syncTaskId ? AnalyticsSyncTask::find($this->syncTaskId) : null;

        try {
            $service->sync($syncLog, $task);
        } catch (InstagramApiException $e) {
            if (! $e->isRetryable()) {
                $service->markFailed($syncLog, $e->getMessage(), $e->category);
                $task?->finish($e->category === InstagramApiException::AUTHENTICATION ? 'needs_reconnect' : 'failed');
                $this->fail($e);
                return;
            }

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $integration = ApiIntegration::find($this->apiIntegrationId);
        if (! $integration) {
            return;
        }

        $syncLog = AnalyticsSyncLog::where('api_integration_id', $this->apiIntegrationId)
            ->where('status', 'pending')
            ->where('source_type', 'audience_api_sync')
            ->where('range_from', now()->toDateString())
            ->latest()
            ->first();

        if (! $syncLog) {
            return;
        }

        $category = $e instanceof InstagramApiException ? $e->category : InstagramApiException::UNKNOWN;
        $message = $category === InstagramApiException::AUTHENTICATION
            ? $e->getMessage()
            : 'Sync audience gagal setelah beberapa kali percobaan: '.$e->getMessage();

        (new InstagramAudienceInsightsService($integration))->markFailed($syncLog, $message, $category);

        $this->syncTaskId && AnalyticsSyncTask::find($this->syncTaskId)
            ?->finish($category === InstagramApiException::AUTHENTICATION ? 'needs_reconnect' : 'failed');
    }
}
