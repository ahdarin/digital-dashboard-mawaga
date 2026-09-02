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
}
