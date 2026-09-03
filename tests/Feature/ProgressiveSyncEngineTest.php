<?php

namespace Tests\Feature;

use App\Jobs\ProcessInstagramSyncChunkJob;
use App\Jobs\ProcessTikTokSyncChunkJob;
use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncFailure;
use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\AnalyticsSyncTaskItem;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\AnalyticsSyncOrchestrator;
use App\Services\InstagramAnalyticsSyncService;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PROGRESSIVE 90-DAY SYNC ENGINE - RESILIENCE PASS.
 *
 * Exercises the new plan-once/process-in-chunks pipeline directly (job
 * handle() called synchronously, exactly like RefreshKnownContentTest calls
 * service methods directly) - NOT through Queue::fake()+never-executed
 * dispatch, which only proves dispatch PARAMETERS, not the actual chunked
 * execution behavior this pass adds.
 */
class ProgressiveSyncEngineTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);
    }

    private function instagramIntegration(Client $client): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    private function tiktokIntegration(Client $client): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'TikTok'])->id,
            'integration_name' => 'TT', 'status' => 'active', 'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    private function userId(): int
    {
        return User::factory()->create(['status' => 'active'])->id;
    }

    private function task(ApiIntegration $integration, string $subjob, int $userId): AnalyticsSyncTask
    {
        $run = AnalyticsSyncRun::create([
            'client_id' => $integration->client_id,
            'trigger' => AnalyticsSyncRun::TRIGGER_MANUAL,
            'initiated_by' => $userId,
            'status' => 'queued',
            'started_at' => now(),
        ]);

        return AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id,
            'api_integration_id' => $integration->id,
            'subjob' => $subjob,
            'status' => 'queued',
        ]);
    }

    private function syncLog(ApiIntegration $integration, int $userId): AnalyticsSyncLog
    {
        return AnalyticsSyncLog::create([
            'client_id' => $integration->client_id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $userId,
            'source_type' => 'api_sync',
            'status' => 'pending',
            'sync_mode' => 'default',
            'range_from' => now()->subDays(90)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
        ]);
    }

    /**
     * Runs the ENTIRE progressive chain synchronously (discovery job, then
     * every chunk job in sequence) without a real queue - mirrors what
     * ProcessInstagramSyncChunkJob would do across multiple dispatch/handle
     * cycles, just driven directly in the test process.
     */
    private function runProgressiveInstagramChain(ApiIntegration $integration, AnalyticsSyncTask $task, int $userId, string $rangeFrom, string $rangeTo): void
    {
        Queue::fake();
        (new SyncInstagramAnalyticsJob($integration->id, 'default', $rangeFrom, $rangeTo, $userId, $task->id))
            ->handle(app(InstagramAnalyticsSyncService::class));

        Queue::assertPushed(ProcessInstagramSyncChunkJob::class, function ($job) use (&$chunkIndex) {
            $chunkIndex = $job->chunkIndex;

            return true;
        });

        while ($nextChunk = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)
            ->where('status', AnalyticsSyncTaskItem::STATUS_PENDING)
            ->min('chunk_index')) {
            (new ProcessInstagramSyncChunkJob($integration->id, $task->id, AnalyticsSyncLog::where('api_integration_id', $integration->id)->latest()->first()->id, $userId, $nextChunk))
                ->handle(app(InstagramAnalyticsSyncService::class));
        }

        // Task's own finalize is triggered by the LAST chunk job's own
        // handle() (no more pending chunk_index left) - but since we drive
        // the loop above off "still has pending items", the final chunk's
        // own dispatch-or-finalize branch already ran finalizeProgressiveRun()
        // inside that last handle() call above.
    }

    private function igMediaResponse(array $items): \Closure
    {
        return function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => count($items)], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 100]]],
                    ['name' => 'likes', 'values' => [['value' => 5]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        };
    }

    // ===== Discovery once, partitioned into rolling age buckets =====

    public function test_discovery_runs_once_and_partitions_workload_into_age_buckets(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [
            ['id' => 'ig-recent', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDays(5)->toIso8601String(), 'permalink' => 'https://instagram.com/p/1'],
            ['id' => 'ig-mid', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDays(35)->toIso8601String(), 'permalink' => 'https://instagram.com/p/2'],
            ['id' => 'ig-older', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDays(65)->toIso8601String(), 'permalink' => 'https://instagram.com/p/3'],
        ];
        Http::fake($this->igMediaResponse($items));

        $mediaListCallCount = 0;
        Http::fake(function ($request) use ($items, &$mediaListCallCount) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                $mediaListCallCount++;

                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 3], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $plan = app(InstagramAnalyticsSyncService::class)->planProgressiveRun($integration, $task, now()->subDays(90), now());

        $this->assertSame(1, $mediaListCallCount, 'Discovery (me/media) HARUS cuma dipanggil SEKALI per run, bukan 1x per bucket 0-30/30-60/60-90.');
        $this->assertSame(3, $plan['discovery_count']);

        $rows = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)->orderBy('id')->get();
        $this->assertSame(3, $rows->count());
        $this->assertSame(\App\Services\SyncStageBoundary::STAGE_RECENT, $rows->firstWhere('external_item_id', 'ig-recent')->stage);
        $this->assertSame(\App\Services\SyncStageBoundary::STAGE_MID, $rows->firstWhere('external_item_id', 'ig-mid')->stage);
        $this->assertSame(\App\Services\SyncStageBoundary::STAGE_OLDER, $rows->firstWhere('external_item_id', 'ig-older')->stage);
    }

    public function test_stage_boundary_is_exact_at_29_30_59_60_days(): void
    {
        $now = now();
        // 29 hari = masih STAGE 1 (0-29), 30 hari = sudah STAGE 2 (30-59).
        $this->assertSame(\App\Services\SyncStageBoundary::STAGE_RECENT, \App\Services\SyncStageBoundary::stageFor($now->copy()->subDays(29), $now));
        $this->assertSame(\App\Services\SyncStageBoundary::STAGE_MID, \App\Services\SyncStageBoundary::stageFor($now->copy()->subDays(30), $now));
        $this->assertSame(\App\Services\SyncStageBoundary::STAGE_MID, \App\Services\SyncStageBoundary::stageFor($now->copy()->subDays(59), $now));
        $this->assertSame(\App\Services\SyncStageBoundary::STAGE_OLDER, \App\Services\SyncStageBoundary::stageFor($now->copy()->subDays(60), $now));
        $this->assertSame(\App\Services\SyncStageBoundary::STAGE_OLDER, \App\Services\SyncStageBoundary::stageFor($now->copy()->subDays(89), $now));
    }

    // ===== Configurable chunk size, multiple chunks aggregate to ONE task =====

    public function test_configurable_chunk_size_partitions_into_multiple_chunks_under_one_task(): void
    {
        config(['analytics.sync_chunk_size' => 2]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = array_map(fn ($i) => [
            'id' => "ig-recent-{$i}", 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE',
            'timestamp' => now()->subDays($i)->toIso8601String(), 'permalink' => "https://instagram.com/p/{$i}",
        ], range(1, 5));

        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 5], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $plan = app(InstagramAnalyticsSyncService::class)->planProgressiveRun($integration, $task, now()->subDays(90), now());

        // 5 media / chunk_size 2 = 3 chunks (2+2+1), semua STAGE 1 (semua < 30 hari).
        $this->assertSame(3, $plan['total_chunks']);
        $this->assertSame(1, AnalyticsSyncTask::where('analytics_sync_run_id', $task->analytics_sync_run_id)->count(), 'HARUS tetap SATU AnalyticsSyncTask, chunking TIDAK PERNAH membuat task/run terpisah per chunk.');

        $chunkCounts = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)
            ->selectRaw('chunk_index, count(*) as c')->groupBy('chunk_index')->pluck('c', 'chunk_index');
        $this->assertEquals([1 => 2, 2 => 2, 3 => 1], $chunkCounts->toArray());
    }

    // ===== Full chain: progress persists per chunk, terminal reconciliation =====

    public function test_full_progressive_chain_persists_progress_and_reconciles(): void
    {
        config(['analytics.sync_chunk_size' => 2]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = array_map(fn ($i) => [
            'id' => "ig-full-{$i}", 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE',
            'timestamp' => now()->subDays($i)->toIso8601String(), 'permalink' => "https://instagram.com/p/{$i}",
        ], range(1, 5));

        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 5], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->runProgressiveInstagramChain($integration, $task, $userId, now()->subDays(90)->toDateString(), now()->toDateString());

        $task->refresh();
        $this->assertSame(5, $task->discovered_count);
        $this->assertSame(5, $task->success_count);
        $this->assertSame(5, $task->processed_count);
        $this->assertTrue($task->reconciled);
        $this->assertSame('success', $task->status);
        $this->assertNotNull($task->finished_at);

        // ContentMetric genuinely persisted per item (progress WAS durable,
        // not held back until the very end - Langkah 14).
        $this->assertSame(5, InstagramMediaSnapshot::where('api_integration_id', $integration->id)->count());
    }

    // ===== Item failure does not abort the chunk or the run =====

    public function test_one_bad_media_does_not_block_remaining_items_in_chunk(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [
            ['id' => 'ig-good-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1'],
            ['id' => 'ig-bad', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDays(2)->toIso8601String(), 'permalink' => 'p/2'],
            ['id' => 'ig-good-2', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDays(3)->toIso8601String(), 'permalink' => 'p/3'],
        ];

        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 3], 200);
            }
            if (str_contains($url, '18099') || str_contains($url, 'ig-bad/insights')) {
                return Http::response(['error' => ['message' => 'boom', 'code' => 999]], 500);
            }
            if (str_contains($url, '/insights')) {
                // ig-bad ID tidak match pola khusus di atas - bedakan lewat query id.
                if (str_contains($url, 'ig-bad')) {
                    return Http::response(['error' => ['message' => 'boom', 'code' => 999]], 500);
                }

                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->runProgressiveInstagramChain($integration, $task, $userId, now()->subDays(90)->toDateString(), now()->toDateString());

        $task->refresh();
        $this->assertSame(3, $task->discovered_count);
        $this->assertSame(2, $task->success_count, 'Kedua media BAIK tetap sukses walau 1 media lain di chunk yang sama gagal.');
        $this->assertSame(1, $task->failed_count);
        $this->assertSame('partial', $task->status, 'Run dengan sisa kegagalan HARUS PARTIAL, bukan diam-diam success.');
        $this->assertTrue($task->reconciled);

        $failure = AnalyticsSyncFailure::where('analytics_sync_task_id', $task->id)->first();
        $this->assertNotNull($failure);
        $this->assertSame('ig-bad', $failure->external_item_id);
    }

    /**
     * Section 12/32 - the EXISTING targeted-retry mechanism
     * (InstagramAnalyticsSyncService::retryFailedItems(), unchanged by this
     * pass) must keep working unmodified against AnalyticsSyncFailure rows
     * written by the NEW progressive chunk processor - it only ever reads
     * AnalyticsSyncFailure by task_id, agnostic to which code path wrote
     * them, so no retry-specific change was needed - this proves that.
     */
    public function test_existing_targeted_retry_resolves_failures_from_progressive_run(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [['id' => 'ig-retry-me', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1']];
        // Http::fake() closures ACCUMULATE (first-registered-wins on a
        // matching URL) rather than replace - a shared flag (bukan 2
        // panggilan Http::fake() terpisah) yang menentukan sukses/gagal
        // per fase, supaya "provider pulih sekarang" genuinely diterapkan
        // saat retryFailedItems() dipanggil di bawah.
        $providerRecovered = false;
        Http::fake(function ($request) use ($items, &$providerRecovered) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return $providerRecovered
                    ? Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200)
                    : Http::response(['error' => ['message' => 'temporarily down']], 500);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->runProgressiveInstagramChain($integration, $task, $userId, now()->subDays(90)->toDateString(), now()->toDateString());
        $task->refresh();
        $this->assertSame(1, $task->failed_count);
        // Cuma 1 item total DAN gagal semua (0 sukses) - genuinely 'failed',
        // bukan 'partial' (partial butuh SEBAGIAN sukses, lihat skenario
        // "one bad media" di atas yang punya 2 sukses + 1 gagal).
        $this->assertSame('failed', $task->status);

        $providerRecovered = true;

        $retryResult = app(InstagramAnalyticsSyncService::class)->retryFailedItems($task, $userId);

        $this->assertSame(1, $retryResult['attempted']);
        $this->assertSame(1, $retryResult['resolved'], 'Retry HANYA menyasar item yang gagal, dan berhasil resolve begitu provider sudah pulih.');
    }

    // ===== Idempotency: re-running an already-processed chunk does not double count =====

    public function test_reprocessing_an_already_terminal_chunk_does_not_double_count(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [['id' => 'ig-idem-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1']];

        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $service = app(InstagramAnalyticsSyncService::class);
        $service->planProgressiveRun($integration, $task, now()->subDays(90), now());
        $syncLog = $this->syncLog($integration, $userId);

        $service->processChunk($task, 1, $syncLog, $userId);
        $task->refresh();
        $this->assertSame(1, $task->success_count);

        // Simulasikan worker retry chunk yang SAMA (mis. setelah timeout,
        // Laravel re-run job yang sama dari awal) - item sudah 'success',
        // HARUS di-skip, TIDAK diproses/dihitung ulang.
        $service->processChunk($task, 1, $syncLog, $userId);
        $task->refresh();
        $this->assertSame(1, $task->success_count, 'Chunk yang sudah terminal TIDAK BOLEH dihitung ulang saat job yang sama di-retry.');
        $this->assertSame(1, $task->processed_count);
    }

    // ===== Discovery/known-refresh disjointness preserved under chunking =====

    public function test_known_refresh_excludes_media_already_in_discovery_set(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        // Media INI sudah dikenal sistem (known) DAN akan muncul lagi di
        // discovery run ini (published_at masih dalam window 90 hari).
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-shared',
            'media_product_type' => 'IMAGE', 'published_at' => now()->subDays(5),
            'match_status' => 'unmatched', 'last_fetched_at' => now()->subDays(3),
        ]);
        // Media LAIN yang genuinely stale, TIDAK ada di discovery run ini.
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id, 'external_post_id' => 'ig-stale-only',
            'media_product_type' => 'IMAGE', 'published_at' => now()->subDays(40),
            'match_status' => 'unmatched', 'last_fetched_at' => now()->subDays(35),
        ]);

        $items = [['id' => 'ig-shared', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDays(5)->toIso8601String(), 'permalink' => 'p/1']];
        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $plan = app(InstagramAnalyticsSyncService::class)->planProgressiveRun($integration, $task, now()->subDays(90), now());

        $this->assertSame(1, $plan['discovery_count']);
        $this->assertSame(1, $plan['known_refresh_count'], 'ig-shared TIDAK BOLEH dihitung dua kali - hanya ig-stale-only yang jadi known_refresh candidate.');

        $knownItem = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)->where('source', AnalyticsSyncTaskItem::SOURCE_KNOWN_REFRESH)->first();
        $this->assertSame('ig-stale-only', $knownItem->external_item_id);
        $this->assertSame(1, AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)->where('external_item_id', 'ig-shared')->count(), 'ig-shared HARUS cuma 1 baris (discovery), bukan 2 (discovery + known_refresh).');
    }

    // ===== Duplicate click reuses the active run/task (across chunk gaps) =====

    public function test_duplicate_dispatch_while_task_running_does_not_create_second_run(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();

        $orchestrator = app(AnalyticsSyncOrchestrator::class);
        $first = $orchestrator->dispatch($client, null, $userId);
        $this->assertNotEmpty($first['dispatched']);

        // Simulasikan JEDA antar-chunk progresif: TIDAK ADA lock cache yang
        // genuinely held, TIDAK ADA baris di tabel `jobs` (chunk job
        // berikutnya belum sempat di-dispatch) - hanya AnalyticsSyncTask
        // yang masih 'running' menandakan run ini genuinely masih aktif.
        $task = AnalyticsSyncTask::where('analytics_sync_run_id', $first['run_id'])->first();
        $task->update(['status' => 'running']);

        $second = $orchestrator->dispatch($client, null, $userId);

        // dispatch() TIDAK PERNAH mengembalikan run_id yang sudah ada
        // ('run_id' null artinya "tidak ada run BARU yang dibuat" -
        // kontrak lama ini TIDAK diubah pass ini) - bukti "tidak ada run
        // kedua" yang benar adalah subjob tetap dilaporkan dispatched TANPA
        // baris AnalyticsSyncRun baru genuinely tercipta di DB.
        $this->assertNotEmpty($second['dispatched'], 'Subjob tetap dilaporkan dispatched (in-flight), bukan skipped/not_connected.');
        $this->assertNull($second['run_id'], 'Klik kedua SELAGI task masih running (walau tidak ada lock/jobs-row aktif) TIDAK BOLEH membuat AnalyticsSyncRun baru.');
        $this->assertSame(1, AnalyticsSyncRun::where('client_id', $client->id)->count());
    }

    // ===== TikTok: progressive engine preserves rangeFrom cutoff + batched known-refresh =====

    public function test_tiktok_progressive_discovery_preserves_rangefrom_cutoff(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, $userId);

        $cutoff = now()->subDays(10)->startOfDay();
        Http::fake(function ($request) use ($cutoff) {
            $url = $request->url();
            if (str_contains($url, 'video/list')) {
                return Http::response(['data' => [
                    'videos' => [
                        ['id' => 'tt-recent', 'create_time' => now()->subDays(2)->timestamp, 'view_count' => 10, 'like_count' => 1, 'comment_count' => 0, 'share_count' => 0, 'share_url' => 'https://tiktok.com/@x/video/1'],
                        // Video ini LEBIH TUA dari cutoff - video/list TIDAK
                        // punya filter since server-side, jadi harus di-stop
                        // client-side (rangeFrom lower-bound, TIDAK regresi
                        // ke bug rangeTo lama).
                        ['id' => 'tt-too-old', 'create_time' => $cutoff->copy()->subDays(5)->timestamp, 'view_count' => 5, 'like_count' => 0, 'comment_count' => 0, 'share_count' => 0, 'share_url' => 'https://tiktok.com/@x/video/2'],
                    ],
                    'has_more' => false,
                ]], 200);
            }
            if (str_contains($url, 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-999', 'display_name' => 'creator']]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $plan = app(TikTokAnalyticsSyncService::class)->planProgressiveRun($integration, $task, $cutoff);

        $this->assertSame(1, $plan['discovery_count'], 'Video di bawah rangeFrom cutoff TIDAK BOLEH ikut ter-discover (client-side stop tetap bekerja di jalur progresif).');
        $this->assertSame('tt-recent', AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)->first()->external_item_id);
    }

    public function test_tiktok_known_refresh_chunk_uses_one_batched_query_call(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, $userId);

        for ($i = 0; $i < 5; $i++) {
            TikTokVideoSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => "tt-known-{$i}",
                'published_at' => now()->subDays(10), 'match_status' => 'unmatched', 'last_fetched_at' => now()->subDays(5),
            ]);
        }

        $queryCallCount = 0;
        Http::fake(function ($request) use (&$queryCallCount) {
            $url = $request->url();
            if (str_contains($url, 'video/list')) {
                return Http::response(['data' => ['videos' => [], 'has_more' => false]], 200);
            }
            if (str_contains($url, 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-999', 'display_name' => 'creator']]], 200);
            }
            if (str_contains($url, 'video/query')) {
                $queryCallCount++;
                $ids = $request->data()['filters']['video_ids'] ?? [];

                return Http::response(['data' => ['videos' => array_map(fn ($id) => [
                    'id' => $id, 'create_time' => now()->subDays(10)->timestamp,
                    'view_count' => 10, 'like_count' => 1, 'comment_count' => 0, 'share_count' => 0,
                ], $ids)]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $service = app(TikTokAnalyticsSyncService::class);
        $plan = $service->planProgressiveRun($integration, $task, now()->subDays(90)->startOfDay());
        $this->assertSame(5, $plan['known_refresh_count']);

        $syncLog = $this->syncLog($integration, $userId);
        $knownChunkIndex = AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)
            ->where('source', AnalyticsSyncTaskItem::SOURCE_KNOWN_REFRESH)->value('chunk_index');
        $service->processChunk($task, $knownChunkIndex, $syncLog, $userId);

        $this->assertSame(1, $queryCallCount, 'SATU chunk (<=20 video) HARUS memicu SATU panggilan queryVideos() batched, bukan 1 panggilan per video.');
        $task->refresh();
        $this->assertSame(5, $task->success_count);
    }

    // ===== Scheduled sync uses the same progressive engine =====

    public function test_scheduled_trigger_also_uses_progressive_task_status_guard(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();

        $result = app(AnalyticsSyncOrchestrator::class)->dispatch($client, null, $userId, AnalyticsSyncRun::TRIGGER_SCHEDULED);
        $task = AnalyticsSyncTask::where('analytics_sync_run_id', $result['run_id'])->first();

        Queue::assertPushed(SyncInstagramAnalyticsJob::class, function ($job) use ($task) {
            return $job->syncTaskId === $task->id;
        });

        // Scheduled dispatch juga tunduk pada guard task-status yang sama -
        // klik manual/scheduled kedua selagi task ini masih aktif TIDAK
        // BOLEH membuat run kedua (Langkah 25, "execution semantics must
        // not differ").
        $task->update(['status' => 'running']);
        $second = app(AnalyticsSyncOrchestrator::class)->dispatch($client, null, $userId);
        $this->assertNull($second['run_id']);
        $this->assertSame(1, AnalyticsSyncRun::where('client_id', $client->id)->count());
    }

    // =====================================================================
    // FINAL CLOSURE GATE
    // =====================================================================

    // ===== Issue 1: terminal status must have terminal stage =====

    public function test_success_terminal_task_has_completed_stage(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [['id' => 'ig-term-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1']];
        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->runProgressiveInstagramChain($integration, $task, $userId, now()->subDays(90)->toDateString(), now()->toDateString());

        $task->refresh();
        $this->assertSame('success', $task->status);
        $this->assertSame(AnalyticsSyncTask::STAGE_COMPLETED, $task->stage, 'Task terminal HARUS punya stage terminal (completed), BUKAN processing_recent/previous/older yang tertinggal.');

        $progress = app(AnalyticsSyncOrchestrator::class)->latestRunProgress($client, null);
        $this->assertSame('completed', $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['stage']);
    }

    public function test_partial_terminal_task_has_completed_stage(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [
            ['id' => 'ig-partial-good', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1'],
            ['id' => 'ig-partial-bad', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDays(2)->toIso8601String(), 'permalink' => 'p/2'],
        ];
        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 2], 200);
            }
            if (str_contains($url, '/insights')) {
                if (str_contains($url, 'ig-partial-bad')) {
                    return Http::response(['error' => ['message' => 'boom', 'code' => 999]], 500);
                }

                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->runProgressiveInstagramChain($integration, $task, $userId, now()->subDays(90)->toDateString(), now()->toDateString());

        $task->refresh();
        $this->assertSame('partial', $task->status);
        $this->assertSame(AnalyticsSyncTask::STAGE_COMPLETED, $task->stage);
    }

    public function test_failed_terminal_task_has_completed_stage(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [['id' => 'ig-allfail', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1']];
        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['error' => ['message' => 'boom', 'code' => 999]], 500);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->runProgressiveInstagramChain($integration, $task, $userId, now()->subDays(90)->toDateString(), now()->toDateString());

        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertSame(AnalyticsSyncTask::STAGE_COMPLETED, $task->stage);
    }

    public function test_reconnect_required_terminal_task_has_non_processing_stage(): void
    {
        config(['analytics.sync_chunk_size' => 20]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [['id' => 'ig-authfail', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1']];
        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->runProgressiveInstagramChain($integration, $task, $userId, now()->subDays(90)->toDateString(), now()->toDateString());

        $task->refresh();
        $this->assertSame('inactive', $integration->fresh()->status);
        $this->assertSame('needs_reconnect', $task->status, 'Auth failure HARUS tercermin sebagai needs_reconnect di task, BUKAN failed generik - jalur progresif sebelumnya kehilangan sinyal ini (bug ditemukan & diperbaiki di finalizeProgressiveRun()).');
        $this->assertSame(AnalyticsSyncTask::STAGE_COMPLETED, $task->stage, 'Auth failure juga HARUS reach stage terminal, BUKAN tertinggal di stage processing.');
        $this->assertNotSame('processing_recent', $task->stage);
        $this->assertNotSame('processing_previous', $task->stage);
        $this->assertNotSame('processing_older', $task->stage);
    }

    // ===== Issue 2: unified retry duplicate guard =====

    public function test_retry_task_while_another_task_active_does_not_duplicate_work(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();

        // Task LAMA, sudah terminal (partial) - kandidat retry.
        $oldTask = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);
        $oldTask->update(['status' => 'partial', 'finished_at' => now()->subMinutes(5), 'stage' => AnalyticsSyncTask::STAGE_COMPLETED]);

        // TAPI ada task BARU yang genuinely masih aktif buat integration+
        // subjob yang SAMA (mis. user klik "Perbarui Data" duluan, run itu
        // masih di tengah chunk chain) - lock cache/jobs-table TIDAK
        // genuinely held (jeda antar-chunk), cuma AnalyticsSyncTask.status
        // yang jadi sinyal.
        $activeTask = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);
        $activeTask->update(['status' => 'running']);

        $result = app(AnalyticsSyncOrchestrator::class)->retryTask($oldTask, $userId);

        $this->assertFalse($result['retried'], 'retryTask() HARUS menolak begitu ADA task lain yang masih aktif buat integration+subjob yang sama - guard SAMA PERSIS dengan dispatch().');
        $this->assertSame('already_in_flight', $result['reason']);
        $this->assertSame(2, AnalyticsSyncTask::where('api_integration_id', $integration->id)->count(), 'TIDAK ADA task ketiga yang dibuat.');
    }

    public function test_retry_task_after_terminal_partial_task_works(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();

        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);
        $task->update(['status' => 'partial', 'finished_at' => now()->subMinutes(5), 'stage' => AnalyticsSyncTask::STAGE_COMPLETED]);

        $result = app(AnalyticsSyncOrchestrator::class)->retryTask($task, $userId);

        $this->assertTrue($result['retried'], 'Retry task terminal (partial), TANPA task lain yang aktif, HARUS berhasil di-dispatch ulang.');
        $this->assertNotNull($result['task_id']);
        Queue::assertPushed(SyncInstagramAnalyticsJob::class, fn ($job) => $job->syncTaskId === $result['task_id']);
    }

    public function test_normal_dispatch_and_retry_agree_on_active_task_semantics(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();

        $orchestrator = app(AnalyticsSyncOrchestrator::class);
        $first = $orchestrator->dispatch($client, null, $userId);
        $task = AnalyticsSyncTask::where('analytics_sync_run_id', $first['run_id'])->first();
        $task->update(['status' => 'running']); // simulasikan jeda antar-chunk, lihat test lain

        // "Perbarui Data" kedua kali - ditolak (via hasActiveTask()).
        $secondDispatch = $orchestrator->dispatch($client, null, $userId);
        $this->assertNull($secondDispatch['run_id']);

        // "Coba lagi" terhadap task YANG SAMA (masih aktif) - ditolak lewat
        // guard YANG SAMA PERSIS, bukan logic terpisah yang bisa drift.
        $retryResult = $orchestrator->retryTask($task, $userId);
        $this->assertFalse($retryResult['retried']);
        $this->assertSame('already_in_flight', $retryResult['reason']);

        $this->assertSame(1, AnalyticsSyncRun::where('client_id', $client->id)->count(), 'Dispatch normal MAUPUN retry, keduanya SEPAKAT - tidak satupun membuat run kedua selagi task pertama aktif.');
    }

    public function test_targeted_retry_failed_items_behavior_preserved(): void
    {
        // Regression guard - retryFailedItemsForTask() (item-level, BUKAN
        // task-level retryTask()) TIDAK disentuh sama sekali oleh unifikasi
        // guard Issue 2, TETAP hanya menyasar AnalyticsSyncFailure milik
        // task ini (lihat test_existing_targeted_retry_resolves_failures_from_progressive_run
        // di atas buat bukti fungsionalnya utuh) - test ini murni memverifikasi
        // orchestrator masih merutekan ke method yang benar.
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);
        $task->update(['status' => 'partial', 'finished_at' => now(), 'discovered_count' => 1, 'failed_count' => 1]);

        $result = app(AnalyticsSyncOrchestrator::class)->retryFailedItemsForTask($task, $userId);
        $this->assertArrayHasKey('attempted', $result, 'retryFailedItemsForTask() HARUS tetap balikin kontrak item-level (attempted/resolved/still_failed), bukan kontrak task-level retryTask().');
    }

    // ===== Issue 3: chunk soft deadline - stop safely, continue same chunk =====

    public function test_chunk_stops_at_soft_deadline_and_continuation_resumes_same_chunk(): void
    {
        config(['analytics.sync_chunk_size' => 20, 'analytics.sync_chunk_soft_deadline_seconds' => 0]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [
            ['id' => 'ig-deadline-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1'],
            ['id' => 'ig-deadline-2', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDays(2)->toIso8601String(), 'permalink' => 'p/2'],
        ];
        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 2], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 10]]]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $service = app(InstagramAnalyticsSyncService::class);
        $service->planProgressiveRun($integration, $task, now()->subDays(90), now());
        $syncLog = $this->syncLog($integration, $userId);

        // Deadline = 0 detik - HARUS berhenti sebelum item PERTAMA sekalipun
        // (deadline dicek sebelum tiap item, termasuk yang pertama).
        $result = $service->processChunk($task, 1, $syncLog, $userId);

        $this->assertTrue($result['deadline_reached']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(2, AnalyticsSyncTaskItem::where('analytics_sync_task_id', $task->id)->where('status', AnalyticsSyncTaskItem::STATUS_PENDING)->count(), 'Item yang belum sempat diambil HARUS tetap pending, TIDAK hilang.');

        // Continuation: config normal lagi, proses ULANG chunk_index YANG
        // SAMA (bukan next) - kedua item HARUS berhasil diselesaikan.
        config(['analytics.sync_chunk_soft_deadline_seconds' => 200]);
        $result2 = $service->processChunk($task, 1, $syncLog, $userId);
        $this->assertSame(2, $result2['processed']);
        $this->assertFalse($result2['deadline_reached']);

        $task->refresh();
        $this->assertSame(2, $task->success_count);
    }

    public function test_process_job_redispatches_same_chunk_index_when_deadline_reached(): void
    {
        Queue::fake();
        config(['analytics.sync_chunk_size' => 20, 'analytics.sync_chunk_soft_deadline_seconds' => 0]);
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);

        $items = [['id' => 'ig-jobdeadline', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->subDay()->toIso8601String(), 'permalink' => 'p/1']];
        Http::fake(function ($request) use ($items) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => $items], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $service = app(InstagramAnalyticsSyncService::class);
        $service->planProgressiveRun($integration, $task, now()->subDays(90), now());
        $syncLog = $this->syncLog($integration, $userId);

        (new ProcessInstagramSyncChunkJob($integration->id, $task->id, $syncLog->id, $userId, 1))->handle($service);

        Queue::assertPushed(ProcessInstagramSyncChunkJob::class, function ($job) {
            return $job->chunkIndex === 1; // chunk_index YANG SAMA, bukan chunk 2 yang tidak ada.
        });
        $task->refresh();
        $this->assertNull($task->finished_at, 'Task TIDAK BOLEH finalize selagi masih ada item pending akibat deadline - lanjut chunk yang sama.');
    }

    // ===== Issue 4: timeout < retry_after relationship, verified at runtime =====

    public function test_chunk_job_timeout_stays_below_configured_retry_after(): void
    {
        $chunkTimeout = (new ProcessInstagramSyncChunkJob(1, 1, 1, 1, 1))->timeout;
        $tiktokChunkTimeout = (new ProcessTikTokSyncChunkJob(1, 1, 1, 1, 1))->timeout;
        $retryAfter = config('queue.connections.database.retry_after');

        $this->assertLessThan($retryAfter, $chunkTimeout, 'ProcessInstagramSyncChunkJob timeout HARUS < retry_after runtime (bukan cuma .env.example), atau job yang masih genuinely berjalan bisa diambil ulang worker lain dan diproses dua kali.');
        $this->assertLessThan($retryAfter, $tiktokChunkTimeout);
        $this->assertGreaterThanOrEqual(60, $retryAfter - $chunkTimeout, 'Margin aman HARUS tetap ada (bukan cuma "1 detik lebih besar").');
    }

    // =====================================================================
    // IMMEDIATE-FAILURE INCIDENT INVESTIGATION
    //
    // Real reproduction (live queue worker, live Instagram API, integration
    // id=12) found NO immediate-failure bug - a fresh dispatch completed
    // cleanly end-to-end (discovered=11, processed=11, success=11,
    // status=success). It DID surface one genuine latent bug: replaying a
    // chunk job whose task was ALREADY fully resolved (concretely observed
    // via leftover job rows from an earlier manual test session) caused an
    // unbounded cascade of empty "next chunk" dispatches, because the old
    // next-chunk lookup only checked chunk_index EXISTENCE, not whether
    // that chunk actually still had pending work. Fixed in both
    // ProcessInstagramSyncChunkJob/ProcessTikTokSyncChunkJob by filtering
    // the lookup to status=pending. This test reproduces that exact
    // scenario directly.
    // =====================================================================

    public function test_replaying_chunk_of_already_completed_task_does_not_cascade_empty_dispatches(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $userId);
        $syncLog = $this->syncLog($integration, $userId);

        // Task SUDAH selesai sepenuhnya - 3 chunk, SEMUA baris sudah
        // 'success' (persis kondisi task 8 di reproduksi nyata setelah
        // live run selesai, SEBELUM leftover job lama sempat direplay).
        foreach ([1, 2, 3] as $chunkIndex) {
            AnalyticsSyncTaskItem::create([
                'analytics_sync_task_id' => $task->id,
                'external_item_id' => "ig-done-{$chunkIndex}",
                'stage' => 1, 'source' => AnalyticsSyncTaskItem::SOURCE_DISCOVERY,
                'chunk_index' => $chunkIndex, 'status' => AnalyticsSyncTaskItem::STATUS_SUCCESS,
            ]);
        }
        $task->update(['status' => 'success', 'stage' => AnalyticsSyncTask::STAGE_COMPLETED, 'discovered_count' => 3, 'processed_count' => 3, 'success_count' => 3, 'reconciled' => true, 'finished_at' => now()->subMinutes(10)]);

        $service = app(InstagramAnalyticsSyncService::class);

        // Replay chunk 1 (mis. Laravel retry setelah worker hiccup padahal
        // chunk ini sebenarnya sudah sukses lama sebelumnya) - HARUS TIDAK
        // dispatch job baru apapun, karena TIDAK ADA status=pending di
        // MANAPUN buat task ini (chunk 2 dan 3 SUDAH resolved juga).
        (new ProcessInstagramSyncChunkJob($integration->id, $task->id, $syncLog->id, $userId, 1))->handle($service);

        Queue::assertNotPushed(ProcessInstagramSyncChunkJob::class);
        $task->refresh();
        $this->assertSame('success', $task->status, 'finalizeProgressiveRun() dipanggil ulang HARUS idempotent - status yang sudah terminal TIDAK berubah.');
        $this->assertSame(3, $task->success_count, 'Replay TIDAK BOLEH menghitung ulang/menambah counter yang sudah final.');
    }

    // =====================================================================
    // SYNC RUNTIME / USER-PATH VERIFICATION - stale-failed-run selection
    //
    // Real HTTP-kernel reproduction (genuine POST /analytics/sync + GET
    // /analytics/sync-status through routing/middleware/auth/CSRF, driven
    // by a real persistent `php artisan queue:work --tries=3 --max-time=3600
    // --sleep=3` worker against the real Instagram integration) found NO
    // discrepancy: with no worker running, the status endpoint correctly
    // reported overall_status=queued (never "failed"); with the real
    // worker running, it automatically consumed discovery -> audience ->
    // chunk1 -> chunk2 -> chunk3 with zero manual intervention and reached
    // overall_status=success/stage=completed. These tests lock in the one
    // remaining hypothesis explicitly requested for regression coverage:
    // that an OLD failed task never masks a NEW active/successful one.
    // =====================================================================

    private function makeTerminalTask(ApiIntegration $integration, string $subjob, string $status, int $ageMinutes): AnalyticsSyncTask
    {
        $task = $this->task($integration, $subjob, $this->userId());
        $task->update([
            'status' => $status,
            'stage' => AnalyticsSyncTask::STAGE_COMPLETED,
            'finished_at' => now()->subMinutes($ageMinutes),
            'created_at' => now()->subMinutes($ageMinutes),
        ]);

        return $task;
    }

    public function test_latest_run_progress_shows_new_queued_task_over_old_failed_one_instagram_content(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        $this->makeTerminalTask($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, 'failed', 30);
        $newTask = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $this->userId());

        $progress = app(AnalyticsSyncOrchestrator::class)->latestRunProgress($client, null);

        $this->assertSame($newTask->id, $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['id'], 'Task BARU (queued) HARUS yang ditampilkan, BUKAN task lama yang failed.');
        $this->assertSame('queued', $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status']);
    }

    public function test_latest_run_progress_shows_new_running_task_over_old_failed_one(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        $this->makeTerminalTask($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, 'failed', 30);
        $newTask = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $this->userId());
        $newTask->update(['status' => 'running', 'stage' => 'processing_recent']);

        $progress = app(AnalyticsSyncOrchestrator::class)->latestRunProgress($client, null);

        $this->assertSame($newTask->id, $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['id']);
        $this->assertSame('running', $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'running HARUS menang atas failed lama.');
    }

    public function test_latest_run_progress_shows_new_success_task_over_old_failed_one(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        $this->makeTerminalTask($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, 'failed', 30);
        $newTask = $this->makeTerminalTask($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, 'success', 1);

        $progress = app(AnalyticsSyncOrchestrator::class)->latestRunProgress($client, null);

        $this->assertSame($newTask->id, $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['id']);
        $this->assertSame('success', $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'success BARU HARUS menang atas failed lama.');
    }

    public function test_latest_run_progress_stale_task_selection_holds_for_instagram_audience(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);

        $this->makeTerminalTask($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, 'failed', 30);
        $newTask = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, $this->userId());

        $progress = app(AnalyticsSyncOrchestrator::class)->latestRunProgress($client, null);

        $this->assertSame($newTask->id, $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE]['id']);
        $this->assertSame('queued', $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE]['status']);
    }

    public function test_latest_run_progress_stale_task_selection_holds_for_tiktok_content(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);

        $this->makeTerminalTask($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, 'failed', 30);
        $newTask = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, $this->userId());

        $progress = app(AnalyticsSyncOrchestrator::class)->latestRunProgress($client, null);

        $this->assertSame($newTask->id, $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]['id']);
        $this->assertSame('queued', $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT]['status']);
    }

    /**
     * MIRROR real reproduction (Section 4) - a task just created (status
     * still 'queued', not yet touched by ANY worker) must never be
     * presented as 'failed'. latestRunProgress() is the reliable unit-level
     * signal here (reads AnalyticsSyncTask.status directly - true in every
     * environment). statusForClient()'s overall_status is coupled to a
     * REAL `jobs` DB-table row existing (Queue::fake() in PHPUnit captures
     * dispatched jobs in-memory instead of writing that row, since
     * phpunit.xml sets QUEUE_CONNECTION=sync - a PHPUnit-only artifact of
     * how fakes work, not a code path this unit test can exercise) - that
     * exact mechanism was independently verified against the REAL
     * `database` queue driver via a genuine HTTP request in this
     * investigation (POST /analytics/sync then GET /analytics/sync-status,
     * no worker running yet): it correctly returned overall_status=
     * "queued", never "failed" - see final report Section 2.
     */
    public function test_no_worker_leaves_task_reporting_queued_not_failed(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $userId = $this->userId();

        $result = app(AnalyticsSyncOrchestrator::class)->dispatch($client, null, $userId);
        $this->assertNotNull($result['run_id']);

        $task = AnalyticsSyncTask::where('analytics_sync_run_id', $result['run_id'])
            ->where('subjob', AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT)->first();
        $this->assertSame('queued', $task->status, 'Task yang baru dibuat, belum disentuh worker manapun, HARUS queued.');

        $progress = app(AnalyticsSyncOrchestrator::class)->latestRunProgress($client, null);
        $this->assertSame('queued', $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status']);
        $this->assertNotSame('failed', $progress['tasks'][AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT]['status'], 'Task yang baru saja di-dispatch (belum sempat diproses worker) TIDAK PERNAH boleh terlihat sebagai failed.');
    }
}
