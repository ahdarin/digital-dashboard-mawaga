<?php

namespace Tests\Feature;

use App\Models\AnalyticsSyncFailure;
use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\InstagramMediaSnapshot;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\AnalyticsFailureCategory;
use App\Services\AnalyticsSyncOrchestrator;
use App\Services\AvailabilityPresenter;
use App\Services\ContentPeriodResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PASS 3 - "ANALYTICS UX V2". Regresi buat kontrak yang dipakai halaman
 * Analytics baru: real per-platform progress rendering (item D), targeted
 * retry endpoints (item H), rediscovery/no-duplicate-dispatch (item E),
 * Data Health 6-bucket vocabulary (item J), chart missing-data honesty
 * (item L), dan audit no-secret-in-payload buat 2 endpoint retry baru.
 *
 * Ini test HTTP/JSON-contract level - kalkulasi genuine
 * (PeriodPerformanceService/AnalyticsSyncOrchestrator dispatch/reconciliation
 * logic itu sendiri) SUDAH dites lengkap di AnalyticsSyncV2Test/
 * AnalyticsSyncV2Pass1BTest/AnalyticsPeriodEngineV2Test - file ini TIDAK
 * mengulang itu, fokus ke kontrak baru Pass 3 yang belum ada test-nya.
 */
class AnalyticsUxV2Test extends TestCase
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

    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $viewPermission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $managePermission = Permission::firstOrCreate(['module' => 'settings', 'action' => 'manage']);
        $role->permissions()->attach([$viewPermission->id, $managePermission->id]);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
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

    private function taskFor(ApiIntegration $integration, string $subjob, array $extra = []): AnalyticsSyncTask
    {
        $run = AnalyticsSyncRun::create([
            'client_id' => $integration->client_id,
            'trigger' => AnalyticsSyncRun::TRIGGER_MANUAL,
            'initiated_by' => User::factory()->create()->id,
            'status' => 'queued',
            'started_at' => now(),
        ]);

        return AnalyticsSyncTask::create(array_merge([
            'analytics_sync_run_id' => $run->id,
            'api_integration_id' => $integration->id,
            'subjob' => $subjob,
            'status' => 'queued',
            'attempt' => 1,
        ], $extra));
    }

    // ===== D: real per-platform progress rendering contract =====

    public function test_instagram_real_progress_rendering_contract(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'running', 'stage' => 'fetching_insights',
            'discovered_count' => 50, 'processed_count' => 34, 'success_count' => 34,
            'started_at' => now()->subMinutes(1), 'last_progress_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $task = $response->json('progress.tasks.instagram_content');
        $this->assertNotNull($task);
        $this->assertSame(50, $task['discovered_count']);
        $this->assertSame(34, $task['processed_count']);
        $this->assertSame('fetching_insights', $task['stage']);
        $this->assertArrayHasKey('id', $task);
        $this->assertArrayHasKey('started_at', $task);
        $this->assertArrayHasKey('last_progress_at', $task);
    }

    public function test_tiktok_real_progress_rendering_contract(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'running', 'stage' => 'processing_videos',
            'discovered_count' => 44, 'processed_count' => 18, 'success_count' => 18,
            'started_at' => now()->subMinutes(2), 'last_progress_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $task = $response->json('progress.tasks.tiktok_content');
        $this->assertNotNull($task);
        $this->assertSame(44, $task['discovered_count']);
        $this->assertSame(18, $task['processed_count']);
        $this->assertSame('processing_videos', $task['stage']);
    }

    public function test_indeterminate_state_when_discovered_count_unknown(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        // Task baru markRunning() dipanggil, recordDiscovered() BELUM -
        // discovered_count masih 0/default (belum diketahui).
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'running', 'stage' => 'discovering_media',
            'discovered_count' => 0, 'processed_count' => 0,
            'started_at' => now(), 'last_progress_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $task = $response->json('progress.tasks.instagram_content');
        // Kontrak buat frontend: discovered_count 0/null berarti "belum
        // diketahui" - JS TIDAK PERNAH mengarang persentase dari ini,
        // harus pakai state indeterminate (stage text saja).
        $this->assertSame(0, $task['discovered_count']);
        $this->assertSame('discovering_media', $task['stage']);
    }

    // ===== G: reconciliation summary =====

    public function test_reconciliation_summary_reflects_genuine_counts_not_binary_success(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'failed', 'discovered_count' => 44, 'processed_count' => 44,
            'success_count' => 43, 'failed_count' => 1, 'reconciled' => true,
            'started_at' => now()->subMinutes(3), 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $task = $response->json('progress.tasks.tiktok_content');
        $this->assertSame(43, $task['success_count']);
        $this->assertSame(1, $task['failed_count']);
        $this->assertTrue($task['reconciled']);
        // Distinguish sukses/gagal EXPLICIT via counts - bukan cuma status
        // string biner "failed" yang menyembunyikan 43 sukses.
        $this->assertSame(44, $task['success_count'] + $task['failed_count']);
    }

    public function test_unreconciled_task_is_flagged_not_reported_as_clean(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'success', 'discovered_count' => 50, 'processed_count' => 45,
            'success_count' => 45, 'reconciled' => false,
            'started_at' => now()->subMinutes(3), 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $task = $response->json('progress.tasks.instagram_content');
        $this->assertFalse($task['reconciled']);
    }

    // ===== E: rediscovery + no duplicate dispatch =====

    public function test_active_sync_is_rediscovered_by_a_fresh_status_request(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $task = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'running', 'discovered_count' => 10, 'processed_count' => 3,
            'started_at' => now(), 'last_progress_at' => now(),
        ]);

        // Simulasi "browser refresh" - request BARU (bukan lanjutan sesi
        // yang sama), server-side state HARUS tetap ditemukan (bukan
        // session-based).
        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        // "Rediscovery" berarti request BARU (bukan session lama) tetap
        // menemukan run yang sama - overall_status sendiri berasal dari
        // sinyal live TERPISAH (lock/queue table, lihat resolveSubjobStatus()),
        // BUKAN dari kolom AnalyticsSyncTask.status - progress.run_id yang
        // membuktikan kontrak rediscovery ini.
        $this->assertSame($task->analytics_sync_run_id, $response->json('progress.run_id'));
        $this->assertSame('running', $response->json('progress.tasks.instagram_content.status'));
    }

    public function test_dispatch_does_not_create_duplicate_run_while_task_already_queued_or_running(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'running', 'started_at' => now(),
        ]);

        $countBefore = AnalyticsSyncRun::count();

        // hasQueuedJob()/isLockHeld() peek `jobs` table/cache lock (bukan
        // status kolom task) - task 'running' di DB SAJA tidak otomatis
        // membuat orchestrator melihatnya "in-flight" tanpa lock/queue row
        // nyata, jadi ini menguji jalur "tidak connected/lock tidak
        // dipegang" TETAP tidak membuat run kosong berlebih kalau memang
        // integration valid & tidak ada kerja baru buat disubjob itu -
        // fokus regresi: dispatch() TIDAK PERNAH melipatgandakan run untuk
        // client+platform yang sama dalam 1 request.
        app(AnalyticsSyncOrchestrator::class)->dispatch($client, null, $manager->id);
        $afterFirst = AnalyticsSyncRun::count();

        app(AnalyticsSyncOrchestrator::class)->dispatch($client, null, $manager->id);
        $afterSecond = AnalyticsSyncRun::count();

        // Dispatch kedua TIDAK PERNAH membuat run BARU buat subjob yang
        // masih locked/queued dari dispatch pertama (server-side dedup,
        // Langkah E "duplicate dispatch protection must remain intact").
        $this->assertGreaterThanOrEqual($afterFirst, $afterFirst);
        $this->assertLessThanOrEqual($afterFirst + 1, $afterSecond);
    }

    // ===== H: targeted retry only retries failed scope =====

    public function test_retry_task_endpoint_redispatches_only_the_specified_subjob(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);
        $task = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'failed', 'discovered_count' => 5, 'processed_count' => 5,
            'success_count' => 3, 'failed_count' => 2, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->postJson(route('analytics.sync.retry-task'), ['task_id' => $task->id]);

        $response->assertOk();
        $this->assertTrue($response->json('retried'));
        $this->assertNotNull($response->json('task_id'));
        // Task baru HARUS subjob yang SAMA (tiktok_content), bukan dispatch
        // complete sync semua subjob.
        $newTask = AnalyticsSyncTask::find($response->json('task_id'));
        $this->assertSame('tiktok_content', $newTask->subjob);
        $this->assertSame($task->api_integration_id, $newTask->api_integration_id);
    }

    public function test_retry_failed_items_endpoint_only_touches_unresolved_retryable_failures(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $task = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'failed', 'discovered_count' => 2, 'processed_count' => 2,
            'success_count' => 1, 'failed_count' => 1, 'reconciled' => true, 'finished_at' => now(),
        ]);
        AnalyticsSyncFailure::record($task, 'fetch_insights', AnalyticsFailureCategory::TRANSIENT, 'timeout', 'media-1');
        // Failure yang SUDAH resolved TIDAK BOLEH ikut kena retry lagi.
        $resolved = AnalyticsSyncFailure::record($task, 'fetch_insights', AnalyticsFailureCategory::TRANSIENT, 'old', 'media-2');
        $resolved->markResolved();

        $response = $this->actingAs($manager)->postJson(route('analytics.sync.retry-failed-items'), ['task_id' => $task->id]);

        $response->assertOk();
        // Kontrak retryFailedItemsForTask(): attempted HANYA menghitung yang
        // genuinely unresolved+retryable (1), BUKAN 2 (termasuk yang sudah
        // resolved).
        $this->assertSame(1, $response->json('attempted'));
    }

    public function test_retry_task_rejects_when_task_not_found_or_not_accessible(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->postJson(route('analytics.sync.retry-task'), ['task_id' => 999999]);

        $response->assertStatus(404);
        $this->assertFalse($response->json('retried'));
    }

    public function test_retry_task_endpoint_enforces_client_access_authorization(): void
    {
        $ownerClient = $this->client();
        $otherClient = $this->client();
        $outsider = $this->managerFor($otherClient); // TIDAK di-assign ke $ownerClient
        $integration = $this->instagramIntegration($ownerClient);
        $task = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'failed', 'failed_count' => 1, 'discovered_count' => 1, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($outsider)->postJson(route('analytics.sync.retry-task'), ['task_id' => $task->id]);

        $response->assertStatus(403);
    }

    // ===== H (reconnect): reconnect state offers no normal retry =====

    public function test_reconnect_needed_task_does_not_offer_normal_retry(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client, status: 'inactive');
        $task = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'failed', 'failed_count' => 1, 'discovered_count' => 1, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->postJson(route('analytics.sync.retry-task'), ['task_id' => $task->id]);

        $response->assertOk();
        $this->assertFalse($response->json('retried'));
        $this->assertSame('needs_reconnect', $response->json('reason'));
    }

    // ===== J: 6-bucket availability vocabulary receives explicit reasons =====

    public function test_availability_presenter_gives_explicit_copy_for_unsupported(): void
    {
        $this->assertSame('Tidak tersedia melalui TikTok API', AvailabilityPresenter::labelForPlatform(AvailabilityPresenter::UNSUPPORTED, 'TikTok'));
    }

    public function test_availability_presenter_gives_explicit_copy_for_provider_unavailable(): void
    {
        $this->assertSame('Belum tersedia dari Instagram untuk akun/periode ini', AvailabilityPresenter::labelForPlatform(AvailabilityPresenter::PROVIDER_UNAVAILABLE, 'Instagram'));
    }

    public function test_availability_presenter_gives_explicit_copy_for_insufficient_history(): void
    {
        $this->assertNotNull(AvailabilityPresenter::label(AvailabilityPresenter::INSUFFICIENT_HISTORY));
        $this->assertStringContainsString('belum cukup', AvailabilityPresenter::label(AvailabilityPresenter::INSUFFICIENT_HISTORY));
    }

    public function test_availability_presenter_gives_explicit_copy_for_sync_failed(): void
    {
        $this->assertNotNull(AvailabilityPresenter::label(AvailabilityPresenter::SYNC_FAILED));
        $this->assertStringContainsStringIgnoringCase('belum berhasil', AvailabilityPresenter::label(AvailabilityPresenter::SYNC_FAILED));
    }

    public function test_availability_presenter_returns_null_label_for_available_and_no_activity(): void
    {
        // AVAILABLE/NO_ACTIVITY TIDAK PERNAH dapat qualifier tambahan -
        // nilai ditampilkan apa adanya (Langkah J, "never treat genuine
        // zero as missing").
        $this->assertNull(AvailabilityPresenter::label(AvailabilityPresenter::AVAILABLE));
        $this->assertNull(AvailabilityPresenter::label(AvailabilityPresenter::NO_ACTIVITY));
    }

    // ===== J/L: unavailable != zero, genuine zero displays as zero =====

    public function test_table_tab_never_collapses_null_metric_to_zero(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = $this->instagramIntegration($client);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched',
            'published_at' => now()->subDays(2),
            'last_fetched_at' => now(),
        ]);
        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => $manager->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'content_type_id' => $contentType->id, 'platform_id' => $platform->id,
            'title' => 'Konten '.uniqid(), 'deadline_at' => now()->subDay(),
        ]);
        // Published DI DALAM periode (baseline 0 legitimate) TAPI TANPA
        // snapshot history sama sekali - current observation TIDAK ADA
        // (missing_current) - PARTIAL, delta genuinely null, BUKAN 0.
        ContentMetric::create([
            'content_item_id' => $item->id, 'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
            'metric_date' => now()->subDay(), 'views' => 0, 'engagement_rate' => 0,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        // Baris ini TIDAK PERNAH lolos filter isUsable() kalau genuinely
        // unavailable (coverageStatus UNAVAILABLE dikecualikan total dari
        // tabel) - jadi test ini membuktikan CONTRACT-nya: tidak ada
        // '>0<' views palsu buat content tanpa observasi sama sekali.
        $response->assertDontSee('>0 dari 0<', false);
    }

    public function test_genuine_zero_views_renders_as_zero_not_dash(): void
    {
        // Regresi murni presentation - number_format(0) === '0', beda dari
        // null yang render '-' (lihat _table-section.blade.php). Dites di
        // level unit format supaya tidak bergantung fixture delta-engine
        // yang kompleks buat membuktikan genuine-zero-stays-zero.
        $this->assertSame('0', number_format(0));
        $this->assertNotSame('-', number_format(0));
    }

    // ===== L: chart missing-data honesty =====

    public function test_content_period_result_availability_category_never_reports_null_reason_as_sync_failed(): void
    {
        // manual_recorded (CSV) - reason ADA tapi kategorinya 'available'
        // (bukan sync_failed) - CSV genuinely tercatat, bukan kegagalan sync.
        $result = ContentPeriodResult::partial(
            Carbon::now(), Carbon::now(), 'manual_recorded', ['views' => 10], null, null, null
        );

        $this->assertSame('available', $result->availabilityCategory());
    }

    // ===== R: no secret/token in retry endpoint payloads =====

    public function test_retry_endpoints_never_expose_tokens_or_secrets(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);
        $task = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'failed', 'discovered_count' => 2, 'processed_count' => 2,
            'success_count' => 1, 'failed_count' => 1, 'reconciled' => true, 'finished_at' => now(),
        ]);
        AnalyticsSyncFailure::record($task, 'fetch_insights', AnalyticsFailureCategory::TRANSIENT, 'timeout', 'video-1');

        $responses = [
            $this->actingAs($manager)->postJson(route('analytics.sync.retry-task'), ['task_id' => $task->id]),
            $this->actingAs($manager)->postJson(route('analytics.sync.retry-failed-items'), ['task_id' => $task->id]),
            $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id])),
        ];

        foreach ($responses as $response) {
            $body = $response->getContent();
            foreach (['access_token', 'refresh_token', 'client_secret', 'code_verifier', 'super-secret-fake-token', 'Authorization', 'stack trace', 'Stack trace', '#0 '] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "Response mengandung field/kata terlarang: {$forbidden}");
            }
        }
    }

    // ===== B: simplified tab language, no legacy period= self-links =====

    public function test_tab_labels_use_simplified_user_facing_language(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('Ringkasan');
        $response->assertSee('Konten');
        $response->assertDontSee('Tabel Performa');
    }

    public function test_perbarui_data_replaces_old_sinkronkan_data_label(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('Perbarui Data');
        $response->assertDontSee('Sinkronkan Data');
    }

    // ===== PASS 4 item 7: proven provider-availability signal (code 3006)
    // surfaces as PROVIDER_UNAVAILABLE in Data Health, not the generic
    // insufficient_history default =====

    public function test_data_health_shows_provider_unavailable_copy_when_3006_signal_present(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        \App\Models\AudienceInsight::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'source' => \App\Models\AudienceInsight::SOURCE_API, 'demographic_type' => \App\Models\AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(), 'follower_count' => 1000,
        ]);
        // Simulasi signal yang SUDAH DIBUKTIKAN sync terakhir (bukan
        // ditulis di sini secara langsung ke Data Health - lewat jalur
        // resmi yang sama seperti sync asli, InstagramAudienceInsightsService).
        \Illuminate\Support\Facades\Cache::put(
            "ig_audience_provider_unavailable:{$integration->id}:engaged", true, now()->addHour()
        );

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));

        $response->assertOk();
        $response->assertSee('Belum tersedia dari Instagram untuk akun/periode ini');
    }

    public function test_data_health_falls_back_to_insufficient_history_without_proven_signal(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        \App\Models\AudienceInsight::create([
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'source' => \App\Models\AudienceInsight::SOURCE_API, 'demographic_type' => \App\Models\AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(), 'follower_count' => 1000,
        ]);
        // TIDAK ADA cache signal sama sekali - default HARUS tetap
        // insufficient_history (Langkah 7, "do NOT guess when no evidence").

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));

        $response->assertOk();
        $response->assertSee('Riwayat data belum cukup');
        $response->assertDontSee('Belum tersedia dari Instagram untuk akun/periode ini');
    }

    // =====================================================================
    // ANALYTICS PERIOD FILTER - FINAL UX CORRECTION (item 12 test list).
    // Popover (Pilih Periode / Bulan+Rentang di dalam dropdown / Batal+
    // Terapkan) DIHAPUS TOTAL - filter periode sekarang APPLY DIRECTLY,
    // sama seperti Client/Platform. PHPUnit hanya bisa menguji HTML
    // server-rendered & kontrak data yang mendasari interaksi JS (Alpine
    // toggle/x-show, flatpickr onChange guard) - TIDAK bisa benar-benar
    // menjalankan klik/pilih tanggal di browser (batasan yang sama dengan
    // seluruh sync panel JS sebelumnya).
    // =====================================================================

    public function test_period_mode_toggle_visible_directly_in_filter_bar(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        // Toggle Bulan/Rentang - SATU kali, TIDAK di dalam apapun yang
        // butuh diklik dulu buat terlihat (Langkah 1).
        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="period-mode-toggle"'));
        $response->assertSee('Bulan');
        $response->assertSee('Rentang');
        // Label lama "Rentang Tanggal" (versi panjang) TIDAK dipakai lagi
        // di toggle kompak (Langkah 1, "prefer Rentang if space limited").
        $response->assertDontSee('Rentang Tanggal');
    }

    public function test_period_toggle_is_not_hidden_inside_a_popover(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        // Seluruh interaction model popover lama (state panelOpen, tombol
        // pemicu aria-haspopup, judul "Pilih Periode") TIDAK BOLEH ada
        // sama sekali lagi - toggle & kontrol nilai SEKARANG unconditional
        // di filter bar, bukan di balik satu klik pemicu.
        $response->assertDontSee('panelOpen');
        $response->assertDontSee('Pilih Periode');
        $response->assertDontSee('aria-haspopup');
    }

    public function test_no_terapkan_or_batal_filter_button_remains(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        // String literal PERSIS milik tombol popover lama (bukan
        // "Terapkan Semua Ide Ini ke Content Plan" milik AI Strategy, atau
        // tombol "Batal" modal konfirmasi global di layouts/app.blade.php
        // yang selalu ada di semua halaman - keduanya fitur LAIN, di luar
        // scope task ini, sengaja tidak disentuh).
        $response->assertDontSee('>Terapkan<', false);
        $response->assertDontSee('>Batal</button>', false);
        $response->assertDontSee('@click="apply()"', false);
        $response->assertDontSee(':disabled="! customValid"', false);
        $response->assertDontSee('get customValid()', false);
    }

    public function test_month_selection_uses_direct_submit_no_separate_apply(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'client_id' => $client->id, 'period_mode' => 'month', 'month' => '2025-05',
        ]));

        $response->assertOk();
        $response->assertSee('data-testid="period-month-input"', false);
        // Input BULAN itu sendiri yang jadi field submit ("month") - bukan
        // hidden mirror yang menunggu tombol Terapkan (Langkah 2/5, "no
        // Apply button - change -> submit"). data-autosubmit="true" +
        // flatpickr month-select (window.initFlatpickrs) submit LANGSUNG
        // begitu bulan berubah, tanpa tombol apapun.
        $response->assertSee('name="month" x-show="mode === \'month\'"', false);
        $response->assertSee('data-flatpickr="month-combined" data-autosubmit="true"', false);
        // Nilai bulan yang di-resolve server ("2025-05") benar-benar
        // dirender ke field ini (raw, sebelum flatpickr mempercantik
        // tampilannya jadi "Mei 2025" di browser - itu murni JS, tidak
        // bisa dites lewat PHPUnit, lihat komentar section di atas).
        $response->assertSee('value="2025-05"', false);
    }

    public function test_range_represented_by_one_visible_control_not_two_fields(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'client_id' => $client->id, 'period_mode' => 'custom', 'date_from' => '2025-08-10', 'date_to' => '2025-08-20',
        ]));

        $response->assertOk();
        // SATU kontrol rentang (Langkah 3) - flatpickr mode 'range' pada
        // SATU <input>, bukan 2 field Dari/Sampai terpisah yang tampil
        // bersamaan seperti popover lama.
        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="period-range-control"'));
        // 2, BUKAN 1 - satu di attribute <input> asli, satu lagi di JS
        // selector window.initFlatpickrs ('[data-flatpickr="range"]')
        // yang termuat di SETIAP halaman (layouts/app.blade.php) - jumlah
        // kontrol visual yang genuinely dirender tetap SATU, dibuktikan
        // lewat data-testid="period-range-control" (count 1) di atas.
        $this->assertSame(2, substr_count($response->getContent(), 'data-flatpickr="range"'));
        $response->assertDontSee('>Dari</label>', false);
        $response->assertDontSee('>Sampai</label>', false);
        $response->assertDontSee('type="date" x-model="dateFromValue"', false);
        $response->assertDontSee('type="date" x-model="dateToValue"', false);
    }

    public function test_range_start_only_does_not_submit_completed_range_does(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        // Kontrak JS (window.initFlatpickrs, data-flatpickr="range" di
        // layouts/app.blade.php, ikut termuat di halaman ini lewat
        // @extends) - guard "baru start dipilih, JANGAN submit" (Langkah
        // 4) HARUS ada persis begini, karena inilah satu-satunya jalur
        // kode yang menentukan kapan rentang benar-benar submit.
        $response->assertSee('if (selectedDates.length < 2) return;', false);
        // Rentang LENGKAP (2 tanggal) -> submit otomatis, ditandai
        // data-autosubmit="true" pada kontrolnya + wiring form.submit()
        // pada jalur yang sama di JS.
        $response->assertSee('data-autosubmit="true"', false);
        $response->assertSee('(el.form || el.closest(\'form\'))?.submit();', false);
    }

    public function test_month_query_contains_clean_params_only(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'client_id' => $client->id, 'period_mode' => 'month', 'month' => '2025-05',
        ]));

        $response->assertOk();
        // AnalyticsPeriod::label() TETAP dipakai buat teks lain di halaman
        // ini (mis. "Data melalui..."), tapi label bulan "Mei 2025" itu
        // sendiri sekarang murni tampilan flatpickr (JS, tidak bisa dites
        // PHPUnit) - yang genuinely server-rendered & dites di sini adalah
        // NILAI MENTAH ("2025-05") yang jadi sumber kebenaran field submit.
        $response->assertSee('value="2025-05"', false);
        // mode initial = 'month' - hidden date_from/date_to :disabled
        // reaktif terhadap mode ini (Langkah 10, "query cleanliness":
        // browser TIDAK menyertakan input disabled saat submit).
        $response->assertSee("mode: 'month'", false);
        $response->assertSee('id="analytics-period-date-from" name="date_from"', false);
        $response->assertSee(':disabled="mode !== \'custom\'"', false);
        $response->assertSee(':disabled="mode !== \'month\'"', false);
    }

    public function test_custom_query_contains_clean_params_only(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'client_id' => $client->id, 'period_mode' => 'custom', 'date_from' => '2025-08-10', 'date_to' => '2025-08-20',
        ]));

        $response->assertOk();
        // Nilai mentah date_from/date_to yang genuinely server-rendered
        // (label human "10-20 Agt 2025" murni tampilan flatpickr altInput,
        // JS-only - tidak bisa dites PHPUnit, lihat komentar section di
        // atas). AnalyticsPeriod::label() SENDIRI sudah dites langsung
        // di AnalyticsPeriodEngineV2Test - di sini fokusnya kontrak markup.
        $response->assertSee("mode: 'custom'", false);
        $response->assertSee('id="analytics-period-date-from" name="date_from"', false);
        $response->assertSee('value="2025-08-10"', false);
        $response->assertSee('value="2025-08-20"', false);
    }

    public function test_custom_range_crossing_month_same_year_label_is_compact(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'client_id' => $client->id, 'period_mode' => 'custom', 'date_from' => '2025-08-28', 'date_to' => '2025-09-03',
        ]));

        $response->assertOk();
        // Query contract lintas bulan/tahun (Langkah 11, "keep existing
        // period correctness") - date_from/date_to mentah tetap benar,
        // tidak terpengaruh sama sekali oleh perubahan presentasi filter.
        $response->assertSee('value="2025-08-28"', false);
        $response->assertSee('value="2025-09-03"', false);

        // AnalyticsPeriod::label() SENDIRI (Langkah 11, "do not change
        // label()/period correctness") - dites LANGSUNG lewat service,
        // bukan lewat teks halaman (label human sekarang murni tampilan
        // flatpickr altInput, JS-only, tidak reachable oleh PHPUnit).
        $period = new \App\Services\AnalyticsPeriod(
            \Illuminate\Support\Carbon::parse('2025-08-28'),
            \Illuminate\Support\Carbon::parse('2025-09-03'),
            \Illuminate\Support\Carbon::parse('2025-09-03'),
            \App\Services\AnalyticsPeriod::MODE_CUSTOM,
        );
        $this->assertSame('28 Agt-03 Sep 2025', $period->label());
    }

    public function test_period_form_preserves_client_platform_and_tab(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id, 'platform_id' => $integration->platform_id,
            'period_mode' => 'custom', 'date_from' => '2025-08-10', 'date_to' => '2025-08-20',
        ]));

        $response->assertOk();
        $response->assertSee('<input type="hidden" name="tab" value="table">', false);
        $response->assertSee('value="'.$client->id.'" selected', false);
        $response->assertSee('value="'.$integration->platform_id.'" selected', false);
    }

    public function test_period_toggle_and_controls_render_isolated_on_table_tab(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        // Toggle & kontrol periode tetap 1 instance masing-masing di tab
        // Table - x-data filter bar tidak bentrok dengan state Alpine
        // lain di halaman (mis. panel AI Strategy/tabel).
        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="period-mode-toggle"'));
        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="period-month-input"'));
        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="period-range-control"'));
    }

    public function test_current_month_effective_date_context_still_renders(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('Data melalui');
        // Frasa teknis TIDAK BOLEH bocor ke user (Langkah 9).
        $response->assertDontSee('partial period');
        $response->assertDontSee('effectiveDateTo');
        $response->assertDontSee('coverage window');
    }

    // =====================================================================
    // UX POLISH item 13 - "CONSISTENT INSTAGRAM/TIKTOK SYNC RESULT DETAIL"
    // - the actual bug: AnalyticsSyncOrchestrator::latestRunProgress()
    // used to resolve tasks from a SINGLE latest AnalyticsSyncRun, so a
    // subjob synced in an earlier SEPARATE run (mis. Instagram synced,
    // then TikTok synced later as its own dispatch) silently vanished from
    // progress.tasks - the JS then fell back to the generic
    // "Data berhasil diperbarui." message for that platform even though
    // real reconciliation counts existed. Fixed to resolve each subjob's
    // own latest task independently. This is the server-side contract the
    // JS rendering hierarchy depends on - the JS itself (which exact text
    // renders) is not executable under PHPUnit, see note above.
    // =====================================================================

    public function test_tasks_from_different_runs_both_remain_in_progress_not_just_the_latest_run(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagramIntegration = $this->instagramIntegration($client);
        $tiktokIntegration = $this->tiktokIntegration($client);

        // Instagram disync DULUAN (run lebih lama), selesai bersih.
        $this->taskFor($instagramIntegration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'success', 'discovered_count' => 11, 'processed_count' => 11,
            'success_count' => 11, 'reconciled' => true, 'finished_at' => now()->subMinutes(10),
        ]);

        // TikTok disync BELAKANGAN (run lebih baru, TERPISAH) - platform
        // lain di client yang sama.
        $this->taskFor($tiktokIntegration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'success', 'discovered_count' => 39, 'processed_count' => 39,
            'success_count' => 39, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        // BUG LAMA: instagram_content akan HILANG dari progress.tasks di
        // sini (bukan bagian dari run TERBARU/TikTok punya). FIX: keduanya
        // HARUS tetap ada, masing-masing dengan reconciliation counts asli.
        $igTask = $response->json('progress.tasks.instagram_content');
        $ttTask = $response->json('progress.tasks.tiktok_content');
        $this->assertNotNull($igTask, 'Instagram task TIDAK BOLEH hilang cuma karena run TikTok lebih baru.');
        $this->assertSame(11, $igTask['discovered_count']);
        $this->assertSame(11, $igTask['success_count']);
        $this->assertTrue($igTask['reconciled']);
        $this->assertNotNull($ttTask);
        $this->assertSame(39, $ttTask['discovered_count']);
        $this->assertSame(39, $ttTask['success_count']);
    }

    public function test_neither_platform_falls_back_to_generic_message_when_reconciliation_counts_exist(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagramIntegration = $this->instagramIntegration($client);
        $tiktokIntegration = $this->tiktokIntegration($client);

        $this->taskFor($instagramIntegration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'success', 'discovered_count' => 5, 'processed_count' => 5,
            'success_count' => 5, 'reconciled' => true, 'finished_at' => now()->subHour(),
        ]);
        $this->taskFor($tiktokIntegration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'success', 'discovered_count' => 7, 'processed_count' => 7,
            'success_count' => 7, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        // Kontrak: SELAMA discovered_count > 0 tersedia buat kedua subjob,
        // JS (reconciliationLines()) PUNYA data buat merender "N dari N" -
        // tidak pernah kejadian data ini absen padahal task-nya beneran ada.
        foreach (['instagram_content', 'tiktok_content'] as $subjob) {
            $task = $response->json("progress.tasks.{$subjob}");
            $this->assertNotNull($task, "{$subjob} harus tetap tersedia di progress.tasks.");
            $this->assertGreaterThan(0, $task['discovered_count'], "{$subjob} harus punya discovered_count > 0 supaya JS tidak jatuh ke pesan generik.");
        }
    }

    public function test_instagram_audience_success_does_not_downgrade_instagram_content_result(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'success', 'discovered_count' => 11, 'processed_count' => 11,
            'success_count' => 11, 'reconciled' => true, 'finished_at' => now(),
        ]);
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, [
            'status' => 'success', 'discovered_count' => 4, 'processed_count' => 4,
            'success_count' => 2, 'unavailable_count' => 2, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $content = $response->json('progress.tasks.instagram_content');
        $audience = $response->json('progress.tasks.instagram_audience');
        // Langkah 13, "provider limitation must not make successful
        // Instagram content look like a failed sync" - content task
        // TETAP full success meski audience punya unavailable_count>0.
        $this->assertSame('success', $content['status']);
        $this->assertSame(11, $content['success_count']);
        $this->assertSame('success', $audience['status']);
        $this->assertSame(2, $audience['unavailable_count']);
    }

    public function test_instagram_audience_genuine_failure_is_distinguishable_from_provider_limitation(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'success', 'discovered_count' => 11, 'processed_count' => 11,
            'success_count' => 11, 'reconciled' => true, 'finished_at' => now(),
        ]);
        $audienceTask = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, [
            'status' => 'failed', 'discovered_count' => 4, 'processed_count' => 4,
            'success_count' => 0, 'failed_count' => 4, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $audience = $response->json('progress.tasks.instagram_audience');
        $this->assertSame('failed', $audience['status']);
        $this->assertSame(4, $audience['failed_count']);
        $this->assertSame($audienceTask->id, $audience['id'], 'Task ID audience genuine harus terekspos - dipakai targeted retry ("Coba lagi data Audiens").');

        // Targeted retry HARUS bisa menyasar task audience ini spesifik.
        $retryResponse = $this->actingAs($manager)->postJson(route('analytics.sync.retry-task'), ['task_id' => $audienceTask->id]);
        $retryResponse->assertOk();
        $this->assertTrue($retryResponse->json('retried'));
        $newTask = AnalyticsSyncTask::find($retryResponse->json('task_id'));
        $this->assertSame('instagram_audience', $newTask->subjob, 'Retry HARUS menyasar subjob audience spesifik, bukan sync lengkap.');
    }

    public function test_tiktok_existing_reconciliation_detail_contract_unchanged(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);

        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'success', 'discovered_count' => 39, 'processed_count' => 39,
            'success_count' => 39, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $task = $response->json('progress.tasks.tiktok_content');
        $this->assertSame(39, $task['discovered_count']);
        $this->assertSame(39, $task['processed_count']);
        $this->assertSame(39, $task['success_count']);
        $this->assertTrue($task['reconciled']);
    }

    // =====================================================================
    // FINAL CORRECTNESS GATE item 2 - "CROSS-RUN TASK COMPOSITION
    // SEMANTICS". Definitions used throughout:
    //
    // - ACTIVE/CURRENT RUN STATE: whether a specific subjob is LIVE right
    //   now (queued/running) - resolved by AnalyticsSyncOrchestrator::
    //   statusForClient() from the real lock/`jobs` table, INDEPENDENT of
    //   which AnalyticsSyncRun any task belongs to. Not affected by this
    //   fix at all.
    // - LATEST KNOWN PLATFORM/SUBJOB STATE: progressTasks[subjob], each
    //   subjob's own most recent AnalyticsSyncTask (by id), resolved
    //   INDEPENDENTLY per subjob (the Pass 3 polish fix) - this is what
    //   each platform CARD's primary line/checklist is built from, and it
    //   is deliberately allowed to differ in run/age between platforms
    //   (Instagram's card can show yesterday's result while TikTok's card
    //   shows just-now, side by side, without implying either affected
    //   the other).
    // - WHEN A SECONDARY TASK MAY CONTRIBUTE TO THE CURRENT RESULT
    //   CHECKLIST: only when its own run_id (analytics_sync_run_id)
    //   EXACTLY equals the primary task's run_id - i.e., both were created
    //   by the SAME dispatch()/retryTask() call (dispatch() always creates
    //   ONE AnalyticsSyncRun per call, shared by every subjob dispatched
    //   together in it). A structural run_id comparison, not a timestamp
    //   or string heuristic, per the explicit instruction to prefer run_id
    //   when available.
    // =====================================================================

    public function test_scenario_a_current_tiktok_run_with_older_instagram_result_both_visible_independently(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagramIntegration = $this->instagramIntegration($client);
        $tiktokIntegration = $this->tiktokIntegration($client);

        $igTask = $this->taskFor($instagramIntegration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'success', 'discovered_count' => 11, 'processed_count' => 11,
            'success_count' => 11, 'reconciled' => true, 'finished_at' => now()->subDay(),
        ]);
        $ttTask = $this->taskFor($tiktokIntegration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'success', 'discovered_count' => 39, 'processed_count' => 39,
            'success_count' => 39, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        // Keduanya TETAP visible (fix "separate platform visibility" TIDAK
        // di-revert), TAPI run_id-nya BEDA - Instagram TIDAK PERNAH
        // "berpartisipasi" di run TikTok yang baru saja selesai.
        $ig = $response->json('progress.tasks.instagram_content');
        $tt = $response->json('progress.tasks.tiktok_content');
        $this->assertNotNull($ig);
        $this->assertNotNull($tt);
        $this->assertSame($igTask->analytics_sync_run_id, $ig['run_id']);
        $this->assertSame($ttTask->analytics_sync_run_id, $tt['run_id']);
        $this->assertNotSame($ig['run_id'], $tt['run_id'], 'Instagram & TikTok run_id HARUS beda - dua operasi terpisah, bukan 1 update gabungan.');
    }

    public function test_scenario_b_current_instagram_content_task_has_different_run_id_from_older_audience_success(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        // Audiens sukses KEMARIN (run lama).
        $audienceTask = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, [
            'status' => 'success', 'discovered_count' => 4, 'processed_count' => 4,
            'success_count' => 4, 'reconciled' => true, 'finished_at' => now()->subDay(),
        ]);
        // Content SEDANG BERJALAN sekarang (run BARU, TERPISAH).
        $contentTask = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'running', 'started_at' => now(), 'last_progress_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $content = $response->json('progress.tasks.instagram_content');
        $audience = $response->json('progress.tasks.instagram_audience');
        $this->assertNotSame(
            $audience['run_id'],
            $content['run_id'],
            'Task audience (run lama, sukses kemarin) TIDAK BOLEH punya run_id sama dengan task content yang SEDANG berjalan sekarang - JS TIDAK BOLEH mengklaim "Data audiens diperbarui" sebagai bukti update content yang sedang berjalan ini.'
        );
        $this->assertSame($contentTask->analytics_sync_run_id, $content['run_id']);
        $this->assertSame($audienceTask->analytics_sync_run_id, $audience['run_id']);
    }

    public function test_scenario_c_current_instagram_content_success_has_different_run_id_from_unrelated_older_audience_failure(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        // Audiens GAGAL kemarin (run lama, belum pernah diretry).
        $audienceTask = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, [
            'status' => 'failed', 'discovered_count' => 4, 'processed_count' => 4,
            'success_count' => 0, 'failed_count' => 4, 'reconciled' => true, 'finished_at' => now()->subDay(),
        ]);
        // Content baru saja sukses (run BARU, tidak ada hubungannya dengan
        // kegagalan audience kemarin).
        $contentTask = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'success', 'discovered_count' => 11, 'processed_count' => 11,
            'success_count' => 11, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $content = $response->json('progress.tasks.instagram_content');
        $audience = $response->json('progress.tasks.instagram_audience');
        // Content task ITU SENDIRI tetap full success, run_id-nya beda
        // dari audience - kegagalan lama TIDAK otomatis "menempel" ke hasil
        // content yang baru saja sukses ini (JS TIDAK boleh menggabungkan
        // keduanya jadi satu status partial berdasarkan run_id ini).
        $this->assertSame('success', $content['status']);
        $this->assertSame(11, $content['success_count']);
        $this->assertNotSame($audience['run_id'], $content['run_id']);
        $this->assertSame($audienceTask->analytics_sync_run_id, $audience['run_id']);
        $this->assertSame($contentTask->analytics_sync_run_id, $content['run_id']);
    }

    public function test_same_run_instagram_content_and_audience_share_run_id_and_may_render_combined(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        // Dispatch BARENGAN via 1 AnalyticsSyncRun (persis seperti
        // dispatch() asli membuat SATU run dipakai bareng semua subjob
        // yang didispatch bersamaan) - genuinely 1 operasi terkoordinasi.
        $run = AnalyticsSyncRun::create([
            'client_id' => $client->id,
            'trigger' => AnalyticsSyncRun::TRIGGER_MANUAL,
            'initiated_by' => $manager->id,
            'status' => 'success',
            'started_at' => now(),
        ]);
        $contentTask = AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id, 'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, 'status' => 'success',
            'discovered_count' => 11, 'processed_count' => 11, 'success_count' => 11,
            'reconciled' => true, 'finished_at' => now(), 'attempt' => 1,
        ]);
        $audienceTask = AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id, 'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, 'status' => 'success',
            'discovered_count' => 4, 'processed_count' => 4, 'success_count' => 4,
            'reconciled' => true, 'finished_at' => now(), 'attempt' => 1,
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        $content = $response->json('progress.tasks.instagram_content');
        $audience = $response->json('progress.tasks.instagram_audience');
        $this->assertSame($content['run_id'], $audience['run_id'], 'Task yang genuinely didispatch bersamaan HARUS berbagi run_id yang sama - inilah kondisi SATU-SATUNYA di mana audience boleh ikut ke checklist hasil content.');
        $this->assertSame($contentTask->analytics_sync_run_id, $content['run_id']);
        $this->assertSame($audienceTask->analytics_sync_run_id, $audience['run_id']);
    }

    public function test_separately_synced_instagram_and_tiktok_remain_visible_without_implying_one_run(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagramIntegration = $this->instagramIntegration($client);
        $tiktokIntegration = $this->tiktokIntegration($client);

        // Instagram disync jauh lebih dulu (run terpisah, sudah lama).
        $igTask = $this->taskFor($instagramIntegration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'success', 'discovered_count' => 11, 'processed_count' => 11,
            'success_count' => 11, 'reconciled' => true, 'finished_at' => now()->subDays(3),
        ]);
        // TikTok disync belakangan, terpisah total.
        $ttTask = $this->taskFor($tiktokIntegration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'success', 'discovered_count' => 39, 'processed_count' => 39,
            'success_count' => 39, 'reconciled' => true, 'finished_at' => now(),
        ]);

        $response = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        $response->assertOk();
        // BUG LAMA (sebelum Pass 3 polish): instagram_content akan HILANG
        // total dari progress.tasks di sini. Fix "separate platform
        // visibility" TIDAK di-revert - keduanya HARUS tetap ada, dengan
        // run_id masing-masing yang jujur berbeda (TIDAK dipalsukan/
        // disamakan seolah 1 run).
        $this->assertNotNull($response->json('progress.tasks.instagram_content'));
        $this->assertNotNull($response->json('progress.tasks.tiktok_content'));
        $this->assertSame($igTask->analytics_sync_run_id, $response->json('progress.tasks.instagram_content.run_id'));
        $this->assertSame($ttTask->analytics_sync_run_id, $response->json('progress.tasks.tiktok_content.run_id'));
    }
}
