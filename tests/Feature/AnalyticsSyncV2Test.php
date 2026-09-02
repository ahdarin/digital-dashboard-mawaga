<?php

namespace Tests\Feature;

use App\Exceptions\InstagramApiException;
use App\Models\AnalyticsSyncFailure;
use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\AnalyticsFailureCategory;
use App\Services\AnalyticsSyncOrchestrator;
use App\Services\InstagramAnalyticsSyncService;
use App\Services\TikTokAnalyticsSyncService;
use App\Jobs\SyncInstagramAnalyticsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Analytics V2 Phase B - structured sync run/task/failure foundation.
 * Regression buat: recoverable progress lintas "browser refresh" (server-
 * side, bukan session), duplicate protection scheduled/manual SATU pipeline
 * yang sama, progress counter Instagram/TikTok, reconciliation invariant,
 * targeted retry (item-level & task-level), klasifikasi
 * unsupported/provider_unavailable != failure, dan no-secret-in-payload.
 */
class AnalyticsSyncV2Test extends TestCase
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

    private function instagramIntegration(Client $client, string $status = 'active'): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'integration_name' => 'IG', 'status' => $status, 'access_token' => 'super-secret-fake-token',
            'external_username' => 'creator', 'external_account_id' => 'ig-user-'.uniqid(),
        ]);
    }

    private function tiktokIntegration(Client $client, string $status = 'active'): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'TikTok'])->id,
            'integration_name' => 'TT', 'status' => $status, 'access_token' => 'super-secret-fake-token',
            'external_username' => 'creator',
        ]);
    }

    private function userId(): int
    {
        return User::factory()->create(['status' => 'active'])->id;
    }

    private function orchestrator(): AnalyticsSyncOrchestrator
    {
        return app(AnalyticsSyncOrchestrator::class);
    }

    private function syncLog(ApiIntegration $integration): AnalyticsSyncLog
    {
        return AnalyticsSyncLog::create([
            'client_id' => $integration->client_id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $this->userId(),
            'source_type' => 'api_sync',
            'status' => 'pending',
            'sync_mode' => 'default',
            'range_from' => now()->subDays(90)->toDateString(),
            'range_to' => now()->toDateString(),
            'synced_count' => 0,
            'skipped_count' => 0,
        ]);
    }

    private function task(ApiIntegration $integration, string $subjob): AnalyticsSyncTask
    {
        $run = AnalyticsSyncRun::create([
            'client_id' => $integration->client_id,
            'trigger' => AnalyticsSyncRun::TRIGGER_MANUAL,
            'initiated_by' => $this->userId(),
            'status' => 'queued',
            'started_at' => now(),
        ]);

        return AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id,
            'api_integration_id' => $integration->id,
            'subjob' => $subjob,
            'status' => 'queued',
            // Eksplisit (bukan andalkan DB default) - Model::create() TIDAK
            // otomatis re-fetch kolom yang tidak disebut, $task->attempt di
            // memori akan null (bukan 1) kalau tidak diisi eksplisit di sini.
            'attempt' => 1,
        ]);
    }

    // ===== manual + scheduled use same orchestration pipeline =====

    public function test_manual_and_scheduled_dispatch_create_run_with_correct_trigger(): void
    {
        Queue::fake();
        $clientManual = $this->client();
        $igManual = $this->instagramIntegration($clientManual);
        $clientScheduled = $this->client();
        $igScheduled = $this->instagramIntegration($clientScheduled);

        $resultManual = $this->orchestrator()->dispatch($clientManual, $igManual->platform_id, $this->userId());
        $resultScheduled = $this->orchestrator()->dispatch($clientScheduled, $igScheduled->platform_id, $this->userId(), AnalyticsSyncRun::TRIGGER_SCHEDULED);

        $this->assertSame(AnalyticsSyncRun::TRIGGER_MANUAL, AnalyticsSyncRun::find($resultManual['run_id'])->trigger);
        $this->assertSame(AnalyticsSyncRun::TRIGGER_SCHEDULED, AnalyticsSyncRun::find($resultScheduled['run_id'])->trigger);
        $this->assertNull(AnalyticsSyncRun::find($resultScheduled['run_id'])->initiated_by, 'Scheduled trigger TIDAK PERNAH atribut ke user tertentu.');

        // SATU-SATUNYA jalur dispatch (Langkah "same orchestration
        // pipeline") - keduanya menghasilkan Task dengan subjob+job class
        // yang identik.
        Queue::assertPushed(SyncInstagramAnalyticsJob::class, 2);
    }

    // ===== duplicate protection: scheduled tidak dispatch dobel atas manual yang masih queued =====

    public function test_scheduler_does_not_dispatch_duplicate_when_task_already_queued(): void
    {
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        // Job SUNGGUHAN masuk tabel `jobs` (bukan Queue::fake - biar
        // hasQueuedJob() beneran mendeteksinya), TANPA benar2 dieksekusi.
        // KEDUA subjob Instagram (content+audience) di-queue duluan biar
        // TIDAK ADA subjob yang genuinely baru buat platform ini - assertion
        // "0 Task baru" jadi valid tanpa noise dari subjob lain yang
        // legitimately dispatch normal.
        Queue::connection('database')->push(
            new SyncInstagramAnalyticsJob($ig->id, 'default', now()->subDays(90)->toDateString(), now()->toDateString(), $this->userId())
        );
        Queue::connection('database')->push(
            new \App\Jobs\SyncInstagramAudienceJob($ig->id, $this->userId())
        );

        $result = $this->orchestrator()->dispatch($client, $ig->platform_id, $this->userId(), AnalyticsSyncRun::TRIGGER_SCHEDULED);

        $this->assertContains(AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $result['dispatched'], 'Tetap dilaporkan dispatched dari POV caller (sudah in-flight).');
        $this->assertContains(AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, $result['dispatched']);
        $this->assertNull($result['run_id'], 'TIDAK ADA Run/Task baru dibuat buat subjob yang semuanya sudah in-flight.');
        $this->assertSame(0, AnalyticsSyncTask::count());
    }

    // ===== browser refresh recovers server-side active run state =====

    public function test_browser_refresh_recovers_active_run_progress(): void
    {
        Queue::fake();
        $client = $this->client();
        $ig = $this->instagramIntegration($client);

        $result = $this->orchestrator()->dispatch($client, $ig->platform_id, $this->userId());

        // Simulasikan 2 "browser request" terpisah - keduanya query ulang
        // dari server, TIDAK ADA state di sisi client yang dipertahankan.
        $firstPoll = $this->orchestrator()->latestRunProgress($client, $ig->platform_id);
        $secondPoll = $this->orchestrator()->latestRunProgress($client, $ig->platform_id);

        $this->assertNotNull($firstPoll);
        $this->assertSame($result['run_id'], $firstPoll['run_id']);
        $this->assertSame($firstPoll['run_id'], $secondPoll['run_id'], 'Progress run yang SAMA harus tetap ditemukan ulang, browser refresh tidak membatalkan apapun.');
        $this->assertArrayHasKey(AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, $firstPoll['tasks']);
    }

    // ===== Instagram progress counters =====

    public function test_instagram_content_task_progress_counters(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'me/media')) {
                return Http::response(['data' => [
                    ['id' => 'ig-1', 'media_type' => 'IMAGE', 'media_product_type' => 'IMAGE', 'timestamp' => now()->toIso8601String(), 'permalink' => 'https://instagram.com/p/1'],
                ]], 200);
            }
            if (str_contains($url, 'me?') || str_contains($url, '/me')) {
                return Http::response(['id' => '999', 'username' => 'creator', 'account_type' => 'BUSINESS', 'media_count' => 1], 200);
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [
                    ['name' => 'reach', 'values' => [['value' => 500]]],
                    ['name' => 'likes', 'values' => [['value' => 20]]],
                ]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        app(InstagramAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), now(), $this->userId(), $task);

        $task->refresh();
        $this->assertSame(1, $task->discovered_count);
        $this->assertSame(1, $task->success_count);
        $this->assertSame(0, $task->failed_count);
        $this->assertSame('success', $task->status);
        $this->assertTrue($task->reconciled);
        $this->assertNotNull($task->started_at);
        $this->assertNotNull($task->last_progress_at);
        $this->assertNotNull($task->finished_at);
    }

    // ===== TikTok progress counters =====

    public function test_tiktok_content_task_progress_counters(): void
    {
        $client = $this->client();
        $integration = $this->tiktokIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'user/info')) {
                return Http::response(['data' => ['user' => ['open_id' => 'tt-999', 'display_name' => 'creator', 'avatar_url' => null]]], 200);
            }
            if (str_contains($url, 'video/list')) {
                return Http::response(['data' => ['videos' => [[
                    'id' => 'tt-1', 'create_time' => now()->timestamp, 'view_count' => 300, 'like_count' => 10, 'comment_count' => 1, 'share_count' => 0,
                ]], 'has_more' => false, 'cursor' => 0]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        app(TikTokAnalyticsSyncService::class)->sync($integration, $this->syncLog($integration), now()->subDays(90), $this->userId(), $task);

        $task->refresh();
        $this->assertSame(1, $task->discovered_count);
        $this->assertSame(1, $task->success_count);
        $this->assertSame('success', $task->status);
        $this->assertTrue($task->reconciled);
    }

    // ===== reconciliation =====

    public function test_reconciliation_success_when_all_discovered_items_accounted_for(): void
    {
        $task = AnalyticsSyncTask::create([
            'analytics_sync_run_id' => AnalyticsSyncRun::create(['client_id' => $this->client()->id, 'trigger' => 'manual', 'status' => 'queued'])->id,
            'api_integration_id' => $this->instagramIntegration($this->client())->id,
            'subjob' => 'instagram_content',
            'discovered_count' => 5,
            'success_count' => 3,
            'unavailable_count' => 1,
            'skipped_count' => 0,
            'failed_count' => 1,
        ]);

        $this->assertTrue($task->isReconciled(), '3+1+0+1 = 5 = discovered - harus reconciled.');
    }

    public function test_reconciliation_flags_mismatch_when_discovered_not_fully_accounted(): void
    {
        $task = AnalyticsSyncTask::create([
            'analytics_sync_run_id' => AnalyticsSyncRun::create(['client_id' => $this->client()->id, 'trigger' => 'manual', 'status' => 'queued'])->id,
            'api_integration_id' => $this->instagramIntegration($this->client())->id,
            'subjob' => 'instagram_content',
            'discovered_count' => 5,
            'success_count' => 3,
            'unavailable_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
        ]);

        $this->assertFalse($task->isReconciled(), '3 != 5 - HARUS diflag mismatch, bukan dilaporkan clean success.');

        $task->finish('success');
        $this->assertFalse($task->fresh()->reconciled, 'finish() TIDAK BOLEH menyembunyikan mismatch reconciliation walau status yang dilaporkan success.');
    }

    // ===== unsupported != failure, provider_unavailable != failure =====

    public function test_unsupported_metric_is_not_counted_as_technical_failure(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-story-1',
            'media_product_type' => 'STORY',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration), $this->userId(), $task);

        $task->refresh();
        $this->assertSame(1, $task->unavailable_count, 'STORY (unsupported_metric) HARUS masuk unavailable_count.');
        $this->assertSame(0, $task->failed_count, 'unsupported BUKAN kegagalan teknis - TIDAK boleh masuk failed_count.');
        $failure = AnalyticsSyncFailure::where('analytics_sync_task_id', $task->id)->first();
        $this->assertNull($failure, 'Definitive unsupported TIDAK membuat baris AnalyticsSyncFailure retryable (percuma diretry).');
    }

    public function test_provider_unavailable_audience_demographic_is_not_saved_as_zero(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'followers_count')) {
                return Http::response(['followers_count' => 1200], 200);
            }
            if (str_contains($url, 'metric=reach')) {
                return Http::response(['data' => [['values' => [['end_time' => now()->toIso8601String(), 'value' => 50]]]]], 200);
            }
            if (str_contains($url, 'online_followers')) {
                return Http::response(['data' => [['values' => []]]], 200);
            }
            // Demographics - HTTP 200 tapi results kosong (threshold Meta valid,
            // BUKAN error) - lihat InstagramAudienceInsightsService docblock.
            if (str_contains($url, 'demographics')) {
                return Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 404);
        });

        (new \App\Services\InstagramAudienceInsightsService($integration))->sync($this->syncLog($integration), $task);

        $task->refresh();
        $this->assertSame(3, $task->unavailable_count, '3 demographic breakdown (follower/reached/engaged) semuanya kosong -> provider_unavailable, bukan 0.');
        $this->assertSame(1, $task->success_count, 'Summary (followers+reach+active_hours) tetap sukses independen dari demographics.');
        $this->assertSame('success', $task->status, 'Demographics unavailable TIDAK menggagalkan task audience secara keseluruhan.');
        $this->assertDatabaseMissing('audience_insights', ['client_id' => $client->id, 'demographic_type' => 'follower']);
    }

    // ===== token/reconnect non-retryable, transient retryable =====

    public function test_authentication_category_is_never_retryable(): void
    {
        $this->assertFalse(AnalyticsFailureCategory::isRetryable(AnalyticsFailureCategory::AUTHENTICATION));
        $this->assertFalse(AnalyticsFailureCategory::isRetryable(AnalyticsFailureCategory::UNSUPPORTED));
        $this->assertFalse(AnalyticsFailureCategory::isRetryable(AnalyticsFailureCategory::PROVIDER_UNAVAILABLE));
    }

    public function test_transient_category_is_retryable_but_bounded_by_existing_job_tries(): void
    {
        $this->assertTrue(AnalyticsFailureCategory::isRetryable(AnalyticsFailureCategory::TRANSIENT));

        // Bounded automatic retry SUDAH ADA (Job::$tries=3 existing,
        // TIDAK diubah sesi ini) - sanity check konstanta itu masih 3
        // (infinite retry loop TIDAK PERNAH boleh terjadi).
        $job = new SyncInstagramAnalyticsJob(1, 'default', now()->toDateString(), now()->toDateString(), 1);
        $this->assertSame(3, $job->tries);
    }

    public function test_needs_reconnect_integration_is_rejected_from_item_level_retry(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client, 'inactive');
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        $result = $this->orchestrator()->retryFailedItemsForTask($task, $this->userId());

        $this->assertSame(['retried' => false, 'reason' => 'needs_reconnect'], $result);
    }

    // ===== targeted item-level retry =====

    public function test_targeted_item_level_retry_resolves_specific_failed_media(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-retry-1',
            'media_product_type' => 'IMAGE',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        // SATU fake stateful buat seluruh test (Http::fake() dipanggil
        // berkali-kali DALAM 1 test TIDAK menimpa registrasi sebelumnya -
        // pattern '*' yang didaftarkan duluan tetap match selamanya - jadi
        // pakai 1 closure + flag mutable, bukan re-fake() di tengah test).
        $providerHealthy = false;
        Http::fake(function ($request) use (&$providerHealthy) {
            if (str_contains($request->url(), '/insights')) {
                return $providerHealthy
                    ? Http::response(['data' => [
                        ['name' => 'reach', 'values' => [['value' => 800]]],
                        ['name' => 'likes', 'values' => [['value' => 40]]],
                    ]], 200)
                    : Http::response(['error' => ['message' => 'temporarily down']], 500);
            }

            return Http::response(['error' => 'unexpected URL: '.$request->url()], 404);
        });

        // Fase 1 - transient failure, populate AnalyticsSyncFailure.
        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration), $this->userId(), $task);

        $failure = AnalyticsSyncFailure::where('analytics_sync_task_id', $task->id)->where('external_item_id', 'ig-retry-1')->first();
        $this->assertNotNull($failure);
        $this->assertTrue($failure->retryable);
        $this->assertNull($failure->resolved_at);
        $this->assertSame(1, $task->fresh()->failed_count);

        // Fase 2 - provider sudah pulih, retry HANYA item yang gagal.
        $providerHealthy = true;
        $result = $this->orchestrator()->retryFailedItemsForTask($task, $this->userId());

        $this->assertSame(1, $result['attempted']);
        $this->assertSame(1, $result['resolved']);
        $this->assertSame(0, $result['still_failed']);
        $this->assertNotNull($failure->fresh()->resolved_at);
        $this->assertDatabaseHas('content_metric_snapshots', ['instagram_media_snapshot_id' => $media->id, 'snapshot_date' => now()->toDateString()]);
    }

    // ===== task-level retry =====

    public function test_task_level_retry_creates_new_run_and_dispatches_same_subjob(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);
        $task->finish('failed');

        $result = $this->orchestrator()->retryTask($task, $this->userId());

        $this->assertTrue($result['retried']);
        $newTask = AnalyticsSyncTask::find($result['task_id']);
        $this->assertSame('instagram_content', $newTask->subjob);
        $this->assertSame(AnalyticsSyncRun::TRIGGER_RETRY, $newTask->run->trigger);
        $this->assertSame(2, $newTask->attempt);
        Queue::assertPushed(SyncInstagramAnalyticsJob::class, fn ($job) => $job->syncTaskId === $newTask->id);
    }

    public function test_task_level_retry_rejects_in_flight_task(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);
        $task->update(['status' => 'running']);

        $result = $this->orchestrator()->retryTask($task, $this->userId());

        $this->assertFalse($result['retried']);
        $this->assertSame('already_in_flight', $result['reason']);
    }

    // ===== same-day upsert, next-day new observation (retry path) =====

    public function test_same_day_retry_upserts_snapshot_not_duplicate(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-sameday-1',
            'media_product_type' => 'IMAGE',
            'published_at' => now()->subDays(10),
            'match_status' => 'unmatched',
            'last_fetched_at' => now()->subDays(5),
        ]);
        $task = $this->task($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT);

        $providerHealthy = false;
        Http::fake(function ($request) use (&$providerHealthy) {
            if (str_contains($request->url(), '/insights')) {
                return $providerHealthy
                    ? Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 100]]]]], 200)
                    : Http::response(['error' => ['message' => 'down']], 500);
            }

            return Http::response(['error' => 'unexpected URL: '.$request->url()], 404);
        });

        app(InstagramAnalyticsSyncService::class)->refreshKnownMedia($integration, $this->syncLog($integration), $this->userId(), $task);

        $providerHealthy = true;
        $this->orchestrator()->retryFailedItemsForTask($task, $this->userId());
        // Retry lagi hari yang SAMA (tidak ada failure tersisa buat
        // diretry lagi, tapi upsert-nya sendiri sudah kejadian di baris di
        // atas) - assertion di bawah membuktikan tidak ada duplikat baris.
        $this->orchestrator()->retryFailedItemsForTask($task, $this->userId());

        $this->assertSame(1, \App\Models\ContentMetricSnapshot::where('instagram_media_snapshot_id', InstagramMediaSnapshot::where('external_post_id', 'ig-sameday-1')->value('id'))
            ->where('snapshot_date', now()->toDateString())->count());
    }

    // ===== no secret/token in sync-status payload =====

    public function test_sync_status_endpoint_payload_never_contains_access_token(): void
    {
        Queue::fake();
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $manager = User::factory()->create(['status' => 'active']);
        $role = \App\Models\Role::create(['name' => 'Manager Test '.uniqid()]);
        $perm = \App\Models\Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $role->permissions()->attach($perm->id);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        $this->orchestrator()->dispatch($client, $integration->platform_id, $this->userId());

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id, 'platform_id' => $integration->platform_id]));

        $response->assertOk();
        $this->assertStringNotContainsString('super-secret-fake-token', $response->getContent());
        $this->assertStringNotContainsString('access_token', $response->getContent());
    }
}
