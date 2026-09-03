<?php

namespace Tests\Feature;

use App\Models\AnalyticsSyncRun;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\AnalyticsSyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SETTINGS / INTEGRATION SYNC UX CONSISTENCY FIX. Root cause traced &
 * fixed: SettingsController::syncInstagram()/syncTiktok() dan
 * ClientManagementController::syncInstagramAudience() dispatch job LANGSUNG
 * (Job::dispatch(), TANPA $syncTaskId) - bypass AnalyticsSyncOrchestrator
 * TOTAL, jadi TIDAK PERNAH membuat AnalyticsSyncRun/Task sama sekali buat
 * sync yang dipicu dari Settings/Client Detail. Fix: kartu Instagram/TikTok
 * di Settings SEKARANG dispatch/poll LANGSUNG lewat endpoint yang SAMA
 * dengan halaman Performa (analytics.sync/analytics.sync-status), via
 * shared JS module public/js/analytics-sync-panel.js - method controller
 * lama (syncInstagram()/syncTiktok()/syncInstagramAudience()) TIDAK
 * dihapus (masih dipakai fitur "Sinkronisasi Konten Historis" & Client
 * Detail page, di luar scope perbaikan ini), cuma TIDAK LAGI jadi aksi
 * utama di Settings.
 */
class SettingsIntegrationSyncUxTest extends TestCase
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
        $permissions = collect(['settings,view', 'settings,manage', 'client,manage', 'client,view', 'analytics,view'])
            ->map(function ($pair) {
                [$module, $action] = explode(',', $pair);

                return Permission::firstOrCreate(['module' => $module, 'action' => $action])->id;
            });
        $role->permissions()->attach($permissions);
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

    private function settingsUrl(Client $client): string
    {
        return route('settings', ['tab' => 'integrasi', 'client_id' => $client->id]);
    }

    /**
     * URL diteruskan ke JS lewat @json() Blade (dienkode json_encode), jadi
     * "/" tampil sebagai "\/" di HTML mentah - assertSee harus dibandingkan
     * terhadap bentuk yang sama persis, bukan URL polos.
     */
    private function assertSeeJsonEncodedUrl($response, string $url): void
    {
        $response->assertSee(trim(json_encode($url), '"'), false);
    }

    // ===== item 1: ONE update action per platform =====

    public function test_instagram_settings_card_has_only_one_primary_perbarui_data_action(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get($this->settingsUrl($client));

        $response->assertOk();
        $response->assertSee('id="ig-sync-button"', false);
        // HANYA 1 tombol "Perbarui Data" buat Instagram - substr_count di
        // scope kartu Instagram saja tidak praktis lewat assertSee polos,
        // tapi id unik ig-sync-button HANYA boleh muncul 1x di seluruh
        // halaman (Blade tidak pernah render kartu client yang sama 2x).
        $this->assertSame(1, substr_count($response->getContent(), 'id="ig-sync-button"'));
    }

    public function test_no_separate_instagram_audience_sync_button_remains(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get($this->settingsUrl($client));

        $response->assertOk();
        $response->assertDontSee('id="ig-audience-sync-button"', false);
        $response->assertDontSee('id="ig-content-sync-button"', false);
        $response->assertDontSee('Sinkronkan Audiens');
        $response->assertDontSee('Sinkronkan Konten');
        $response->assertDontSee('Insight Audiens');
    }

    public function test_tiktok_settings_card_has_only_one_update_action(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->tiktokIntegration($client);

        $response = $this->actingAs($manager)->get($this->settingsUrl($client));

        $response->assertOk();
        $response->assertSee('id="tt-sync-button"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'id="tt-sync-button"'));
        $response->assertDontSee('id="tt-content-sync-button"', false);
    }

    public function test_both_platform_buttons_are_labeled_perbarui_data(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client);
        $this->tiktokIntegration($client);

        $response = $this->actingAs($manager)->get($this->settingsUrl($client));

        $response->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), '>Perbarui Data<'), 'Instagram DAN TikTok masing-masing SATU tombol "Perbarui Data".');
    }

    // ===== item 2: same Sync Engine V2, same shared endpoint =====

    public function test_settings_dispatch_wiring_uses_shared_analytics_sync_endpoints(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get($this->settingsUrl($client));

        $response->assertOk();
        // SATU-SATUNYA endpoint dispatch/poll - SAMA PERSIS dengan Analytics,
        // BUKAN endpoint Settings-only baru.
        $this->assertSeeJsonEncodedUrl($response, route('analytics.sync'));
        $this->assertSeeJsonEncodedUrl($response, route('analytics.sync-status'));
        $this->assertSeeJsonEncodedUrl($response, route('analytics.sync.retry-task'));
        $this->assertSeeJsonEncodedUrl($response, route('analytics.sync.retry-failed-items'));
        // platform_id yang benar diteruskan (integration Instagram asli).
        $response->assertSee((string) $integration->platform_id, false);
        // Shared rendering module - BUKAN implementasi kedua yang independen.
        $response->assertSee(asset('js/analytics-sync-panel.js'), false);
    }

    // ===== item 6/7 (report): cross-page shared server-side state =====

    public function test_settings_sees_active_run_started_from_analytics(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        Queue::fake();
        // Simulasi "Analytics men-dispatch sync" - lewat orchestrator asli,
        // SAMA PERSIS jalur yang dipakai AnalyticsController::syncDispatch().
        app(AnalyticsSyncOrchestrator::class)->dispatch($client, $integration->platform_id, $manager->id);

        // Settings poll status client ini - HARUS melihat run yang SAMA
        // (bukan endpoint/mekanisme terpisah).
        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));

        $status->assertOk();
        // overall_status (statusForClient()) dihitung dari lock/queue table
        // real-time - dengan Queue::fake() job memang tidak pernah benar-
        // benar masuk antrean, jadi TIDAK relevan buat dites di sini. Yang
        // membuktikan "Settings melihat run yang sama dari Analytics" adalah
        // progress.tasks (dibaca dari baris AnalyticsSyncTask, sumber yang
        // SAMA dipakai kedua halaman) - itu yang dites.
        $task = $status->json('progress.tasks.instagram_content');
        $this->assertNotNull($task);
        $this->assertSame('queued', $task['status']);
    }

    public function test_analytics_sees_active_run_started_from_settings_dispatch(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);

        Queue::fake();
        // Simulasi "user klik Perbarui Data di Settings" - JS Settings
        // memanggil endpoint analytics.sync yang SAMA PERSIS.
        $dispatch = $this->actingAs($manager)->postJson(route('analytics.sync'), [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]);
        $dispatch->assertOk();

        // Analytics (tanpa platform filter, "All Platforms") HARUS melihat
        // task yang SAMA.
        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));
        $status->assertOk();
        $this->assertNotNull($status->json('progress.tasks.tiktok_content'));
    }

    public function test_reload_does_not_create_duplicate_run_while_task_in_flight(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        Queue::fake();
        $before = AnalyticsSyncRun::count();
        app(AnalyticsSyncOrchestrator::class)->dispatch($client, $integration->platform_id, $manager->id);
        $afterFirst = AnalyticsSyncRun::count();
        $this->assertGreaterThan($before, $afterFirst);

        // "Reload Settings" - poll ulang beberapa kali TIDAK PERNAH
        // dispatch apapun (poll murni GET read-only).
        $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));
        $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));
        $this->assertSame($afterFirst, AnalyticsSyncRun::count(), 'GET status polling TIDAK PERNAH membuat run baru.');
    }

    // ===== item 4: Instagram content+audience = ONE experience =====

    public function test_instagram_content_and_audience_grouped_under_one_platform_result(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);

        $run = AnalyticsSyncRun::create([
            'client_id' => $client->id, 'trigger' => AnalyticsSyncRun::TRIGGER_MANUAL,
            'initiated_by' => $manager->id, 'status' => 'success', 'started_at' => now(),
        ]);
        AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id, 'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, 'status' => 'success',
            'discovered_count' => 50, 'processed_count' => 50, 'success_count' => 50,
            'reconciled' => true, 'finished_at' => now(), 'attempt' => 1,
        ]);
        AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id, 'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, 'status' => 'success',
            'discovered_count' => 4, 'processed_count' => 4, 'success_count' => 4,
            'reconciled' => true, 'finished_at' => now(), 'attempt' => 1,
        ]);

        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));

        $status->assertOk();
        // Data contract yang mendasari "1 kartu Instagram" - SATU controller
        // JS (groups: [DEFAULT_PLATFORM_GROUPS[0]]) membaca KEDUA subjob
        // ini dari SATU response yang sama, dengan run_id yang identik
        // (dispatch bersamaan) - bukti langsung mereka genuinely 1 update.
        $content = $status->json('progress.tasks.instagram_content');
        $audience = $status->json('progress.tasks.instagram_audience');
        $this->assertNotNull($content);
        $this->assertNotNull($audience);
        $this->assertSame($content['run_id'], $audience['run_id']);
    }

    // ===== item 3: real progress rendering contract =====

    public function test_real_discovered_processed_counts_render_in_shared_response(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'running', 'stage' => 'fetching_insights',
            'discovered_count' => 50, 'processed_count' => 32,
            'started_at' => now()->subMinutes(1), 'last_progress_at' => now(),
        ]);

        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));

        $status->assertOk();
        $task = $status->json('progress.tasks.instagram_content');
        $this->assertSame(50, $task['discovered_count']);
        $this->assertSame(32, $task['processed_count']);
        $this->assertSame('fetching_insights', $task['stage']);
        $this->assertSame('running', $task['status']);
    }

    public function test_indeterminate_state_when_discovered_count_unknown(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->tiktokIntegration($client);
        $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_TIKTOK_CONTENT, [
            'status' => 'running', 'stage' => 'discovering_videos',
            'discovered_count' => 0, 'processed_count' => 0,
            'started_at' => now(), 'last_progress_at' => now(),
        ]);

        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));

        $status->assertOk();
        $task = $status->json('progress.tasks.tiktok_content');
        // Kontrak buat JS: discovered_count 0 berarti "belum diketahui" -
        // renderGroup() (shared module) HARUS pakai stage indeterminate,
        // TIDAK PERNAH mengarang persentase dari ini (dibuktikan di
        // smoke-test JS terpisah, di sini kontrak datanya).
        $this->assertSame(0, $task['discovered_count']);
        $this->assertSame('discovering_videos', $task['stage']);
    }

    // ===== item 4 (partial/failed audience): does not make content look failed =====

    public function test_audience_provider_limitation_does_not_make_content_look_failed(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $run = AnalyticsSyncRun::create([
            'client_id' => $client->id, 'trigger' => AnalyticsSyncRun::TRIGGER_MANUAL,
            'initiated_by' => $manager->id, 'status' => 'success', 'started_at' => now(),
        ]);
        AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id, 'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, 'status' => 'success',
            'discovered_count' => 50, 'processed_count' => 50, 'success_count' => 50,
            'reconciled' => true, 'finished_at' => now(), 'attempt' => 1,
        ]);
        AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id, 'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, 'status' => 'success',
            'discovered_count' => 4, 'processed_count' => 4, 'success_count' => 2, 'unavailable_count' => 2,
            'reconciled' => true, 'finished_at' => now(), 'attempt' => 1,
        ]);

        $status = $this->actingAs($manager)->getJson(route('analytics.sync-status', [
            'client_id' => $client->id, 'platform_id' => $integration->platform_id,
        ]));

        $status->assertOk();
        $content = $status->json('progress.tasks.instagram_content');
        $this->assertSame('success', $content['status']);
        $this->assertSame(50, $content['success_count']);
        $this->assertSame(0, $content['failed_count']);
    }

    public function test_audience_technical_failure_exposes_targeted_retry(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $run = AnalyticsSyncRun::create([
            'client_id' => $client->id, 'trigger' => AnalyticsSyncRun::TRIGGER_MANUAL,
            'initiated_by' => $manager->id, 'status' => 'partial', 'started_at' => now(),
        ]);
        AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id, 'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, 'status' => 'success',
            'discovered_count' => 50, 'processed_count' => 50, 'success_count' => 50,
            'reconciled' => true, 'finished_at' => now(), 'attempt' => 1,
        ]);
        $audienceTask = AnalyticsSyncTask::create([
            'analytics_sync_run_id' => $run->id, 'api_integration_id' => $integration->id,
            'subjob' => AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_AUDIENCE, 'status' => 'failed',
            'discovered_count' => 4, 'processed_count' => 4, 'success_count' => 0, 'failed_count' => 4,
            'reconciled' => true, 'finished_at' => now(), 'attempt' => 1,
        ]);

        $retry = $this->actingAs($manager)->postJson(route('analytics.sync.retry-task'), ['task_id' => $audienceTask->id]);

        $retry->assertOk();
        $this->assertTrue($retry->json('retried'));
        $newTask = AnalyticsSyncTask::find($retry->json('task_id'));
        $this->assertSame('instagram_audience', $newTask->subjob, 'Retry HARUS menyasar subjob audience spesifik, bukan full platform refresh.');
    }

    // ===== item 8: needs_reconnect refuses ordinary retry =====

    public function test_needs_reconnect_refuses_ordinary_retry(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client, status: 'inactive');
        $task = $this->taskFor($integration, AnalyticsSyncOrchestrator::SUBJOB_INSTAGRAM_CONTENT, [
            'status' => 'failed', 'discovered_count' => 1, 'failed_count' => 1, 'finished_at' => now(),
        ]);

        $retry = $this->actingAs($manager)->postJson(route('analytics.sync.retry-task'), ['task_id' => $task->id]);

        $retry->assertOk();
        $this->assertFalse($retry->json('retried'));
        $this->assertSame('needs_reconnect', $retry->json('reason'));
    }

    public function test_settings_shows_reconnect_state_for_inactive_instagram_integration(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client, status: 'inactive');

        $response = $this->actingAs($manager)->get($this->settingsUrl($client));

        $response->assertOk();
        // Kartu tetap render (integration ada, cuma butuh reconnect) -
        // tombol "Perbarui Data" TETAP ada di markup (JS yang menukarnya
        // jadi "Hubungkan Ulang" begitu poll() pertama resolve, Langkah 8) -
        // link reconnect asli HARUS tersedia di halaman.
        $this->assertSeeJsonEncodedUrl($response, route('client-management.instagram.connect', $client));
    }

    // ===== item 10: freshness =====

    public function test_settings_freshness_placeholder_present_not_raw_technical_text(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get($this->settingsUrl($client));

        $response->assertOk();
        $response->assertSee('id="ig-freshness"', false);
        $response->assertDontSee('sync_log_id');
        $response->assertDontSee('AnalyticsSyncLog');
    }

    // ===== no secret/token in Settings payload/HTML =====

    public function test_settings_page_and_sync_endpoints_never_expose_tokens(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $integration = $this->instagramIntegration($client);
        $tiktokIntegration = $this->tiktokIntegration($client);

        $pageResponse = $this->actingAs($manager)->get($this->settingsUrl($client));
        $statusResponse = $this->actingAs($manager)->getJson(route('analytics.sync-status', ['client_id' => $client->id]));

        foreach ([$pageResponse, $statusResponse] as $response) {
            $body = $response->getContent();
            foreach (['super-secret-fake-token', 'access_token', 'refresh_token', 'client_secret', 'Authorization', 'stack trace', 'Stack trace'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "Response mengandung field/kata terlarang: {$forbidden}");
            }
        }
    }

    // ===== existing Analytics progress behavior does not regress =====

    public function test_analytics_page_still_renders_sync_panel_and_shared_script_after_extraction(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->instagramIntegration($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('id="analytics-sync-button"', false);
        $response->assertSee('id="analytics-sync-panel"', false);
        $response->assertSee(asset('js/analytics-sync-panel.js'), false);
    }
}
