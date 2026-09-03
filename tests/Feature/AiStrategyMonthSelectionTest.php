<?php

namespace Tests\Feature;

use App\Models\AiStrategyInsight;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\InstagramMediaSnapshot;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\AiStrategyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4.1 (v2) - "AI Strategy Month Selection". Product-semantics
 * correction: AI Strategy sekarang menganalisis 1 CALENDAR MONTH yang
 * dipilih user via <input type="month"> khusus AI Strategy (BUKAN lagi
 * rolling period 7/30/90 yang mengikuti filter global Analytics - itu
 * TETAP dipakai Overview/Table/Audience, dua konsep waktu sengaja
 * dipisah). TIDAK ADA previous-month comparison - "previous_month" bukan
 * requirement. Replaces AiStrategyRollingPeriodTest.php (dihapus - hampir
 * semua isinya menguji resolveWindow()/period rolling yang sudah tidak
 * ada lagi).
 */
class AiStrategyMonthSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $client = Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);

        ClientPackage::create([
            'client_id' => $client->id,
            'package_name_snapshot' => 'Basic',
            'monthly_content_quota' => 8,
            'monthly_design_quota' => 4,
            'start_date' => now()->subMonths(2),
            'status' => 'active',
        ]);

        return $client;
    }

    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $viewPermission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $managePermission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'manage']);
        $role->permissions()->attach([$viewPermission->id, $managePermission->id]);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    private function viewerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Viewer Test '.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view'])->id);
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($role->id);
        $staff->assignedClients()->attach($client->id);

        return $staff;
    }

    private function fakeGeminiStrategyResponse(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'summary' => 'Ringkasan test.',
                        'action_items' => ['Item A'],
                        'suggested_split' => [['label' => 'Education', 'value' => 100]],
                        'top_pillars' => [['name' => 'Education', 'reasoning' => 'Test']],
                        'content_ideas' => [
                            ['pillar' => 'Education', 'title' => 'Judul', 'brief' => 'Brief', 'type' => 'Video', 'platform' => 'Instagram'],
                        ],
                    ])]]]],
                ],
            ], 200),
        ]);
        config(['services.gemini.api_key' => 'fake-key-for-test']);
    }

    /** Metric CSV/manual di HARI INI - selalu jatuh di dalam bulan berjalan, terlepas tanggal berapa test ini dijalankan. */
    private function currentMonthCsvMetric(Client $client, Platform $platform): void
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->month,
            'year' => now()->year,
            'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'title' => 'Konten '.uniqid(),
            'deadline_at' => now(),
        ]);
        ContentWorkflow::create(['content_item_id' => $item->id, 'current_status' => 'uploaded', 'is_overdue' => false]);
        ContentMetric::create([
            'content_item_id' => $item->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now(),
            'views' => 500,
            'engagement_rate' => 3.5,
        ]);
    }

    // ===== 1: default AI month = current month =====

    public function test_default_ai_month_is_current_month(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('value="'.now()->format('Y-m').'"', false);
    }

    // ===== 2: selecting 2026-08 (or any past month) produces correct boundaries =====

    public function test_selecting_a_past_month_produces_full_month_boundaries(): void
    {
        $pastMonth = now()->subMonthNoOverflow()->format('Y-m');
        $window = app(AiStrategyService::class)->resolveMonthWindow($pastMonth);

        $expectedStart = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $pastMonth.'-01')->startOfDay();
        $expectedEnd = $expectedStart->copy()->endOfMonth()->endOfDay();

        $this->assertSame($expectedStart->toDateString(), $window['start']->toDateString());
        $this->assertSame($expectedEnd->toDateString(), $window['end']->toDateString());
    }

    // ===== 3: current unfinished month ends at today, not future endOfMonth =====

    public function test_current_unfinished_month_ends_at_today_not_end_of_month(): void
    {
        $currentMonth = now()->format('Y-m');
        $window = app(AiStrategyService::class)->resolveMonthWindow($currentMonth);

        $this->assertSame(now()->toDateString(), $window['end']->toDateString());
        $naturalEndOfMonth = now()->copy()->endOfMonth()->toDateString();
        if ($naturalEndOfMonth !== now()->toDateString()) {
            $this->assertNotSame($naturalEndOfMonth, $window['end']->toDateString(), 'Bulan berjalan yang belum selesai TIDAK BOLEH diperlakukan seolah sudah sampai akhir bulan.');
        }
    }

    // ===== 4: selected month survives generate request =====

    public function test_selected_month_survives_generate_request(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $this->currentMonthCsvMetric($client, $instagram);
        $this->fakeGeminiStrategyResponse();
        $currentMonth = now()->format('Y-m');

        $response = $this->actingAs($manager)->post(route('analytics.ai-strategy'), [
            'client_id' => $client->id, 'analysis_month' => $currentMonth,
        ]);

        $response->assertRedirect(route('analytics', ['client_id' => $client->id, 'analysis_month' => $currentMonth]));
        $insight = AiStrategyInsight::where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame($currentMonth, $insight->period_start->format('Y-m'));
    }

    // ===== 5: invalid month rejected safely =====

    public function test_invalid_month_format_rejected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->fakeGeminiStrategyResponse();

        $response = $this->actingAs($manager)->post(route('analytics.ai-strategy'), [
            'client_id' => $client->id, 'analysis_month' => 'not-a-month',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('ai_error');
        $this->assertDatabaseMissing('ai_strategy_insights', ['client_id' => $client->id]);
        Http::assertNothingSent();
    }

    public function test_future_month_rejected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->fakeGeminiStrategyResponse();
        $futureMonth = now()->addMonths(2)->format('Y-m');

        $response = $this->actingAs($manager)->post(route('analytics.ai-strategy'), [
            'client_id' => $client->id, 'analysis_month' => $futureMonth,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('ai_error');
        $this->assertDatabaseMissing('ai_strategy_insights', ['client_id' => $client->id]);
        Http::assertNothingSent();
    }

    public function test_missing_month_rejected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $this->fakeGeminiStrategyResponse();

        $response = $this->actingAs($manager)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);

        $response->assertRedirect();
        $response->assertSessionHas('ai_error');
        $this->assertDatabaseMissing('ai_strategy_insights', ['client_id' => $client->id]);
        Http::assertNothingSent();
    }

    // ===== 6: AI Strategy uses PeriodPerformanceService, not publish-date ContentMetric =====

    public function test_ai_performance_summary_uses_period_delta_not_publish_date_sum(): void
    {
        $client = $this->client();
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = ApiIntegration::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake',
        ]);
        $month = now()->subMonthNoOverflow()->format('Y-m');
        $window = app(AiStrategyService::class)->resolveMonthWindow($month);

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - roster SEKARANG
        // cohort publikasi (published_at) - published DI DALAM bulan yang
        // dianalisis (kalau masih di luar, konten ini SEKARANG genuinely
        // TIDAK termasuk cohort bulan ini - itu bukan bug, itu koreksi
        // "empty August" yang sedang dibuktikan file lain).
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'caption' => 'Test',
            'match_status' => 'unmatched',
            'published_at' => $window['start']->copy()->addDay(),
            'last_fetched_at' => now(),
        ]);

        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $window['end']->toDateString(), 'views' => 4500,
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'instagram_media_snapshot_id' => $media->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now(),
            'views' => 4500,
        ]);

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, $month, null);

        $this->assertSame(4500, $summary['total_views'], 'total_views HARUS performa TERKINI genuine (4500, ContentMetric.views apa adanya), bukan delta periode atau 0.');
        $this->assertSame('full', $summary['coverage_status']);
    }

    // ===== 7: historical month without snapshot history is unavailable/partial, never fabricated =====

    public function test_historical_month_without_snapshot_history_is_unavailable(): void
    {
        $client = $this->client();
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = ApiIntegration::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake',
        ]);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'caption' => 'Test',
            'match_status' => 'unmatched',
            'published_at' => now()->subMonths(6),
            'last_fetched_at' => now(),
        ]);
        // ContentMetric (current/latest) ADA, TAPI content_metric_snapshots
        // TIDAK PERNAH ada di bulan yang dianalisis sama sekali - simulasi
        // "snapshot collection baru mulai belakangan, bulan ini belum
        // pernah kesentuh".
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'instagram_media_snapshot_id' => $media->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now()->subMonths(6),
            'views' => 9999,
        ]);

        $month = now()->subMonthNoOverflow()->format('Y-m');
        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, $month, null);

        $this->assertSame('unavailable', $summary['coverage_status']);
        // Angka TIDAK PERNAH diam-diam jadi lifetime cumulative (9999) -
        // harus 0 (tidak ada usable rows), jujur bukan fabricated.
        $this->assertSame(0, $summary['total_views']);
    }

    // ===== 8: content published inside selected month may legitimately baseline zero =====

    public function test_content_published_inside_selected_month_may_legitimately_baseline_zero(): void
    {
        $client = $this->client();
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = ApiIntegration::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake',
        ]);

        $month = now()->subMonthNoOverflow()->format('Y-m');
        $window = app(AiStrategyService::class)->resolveMonthWindow($month);
        // Publish DI DALAM bulan yang dianalisis (bukan sebelumnya) -
        // baseline=0 SAH di sini (Langkah 4, "Jangan baseline=0 kecuali
        // content memang publish di dalam selected month").
        $publishedAt = $window['start']->copy()->addDays(3);

        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'caption' => 'Test',
            'match_status' => 'unmatched',
            'published_at' => $publishedAt,
            'last_fetched_at' => now(),
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $window['end']->toDateString(), 'views' => 800,
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id,
            'instagram_media_snapshot_id' => $media->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => $publishedAt,
            'views' => 800,
        ]);

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, $month, null);

        // baseline 0 (konten belum ada sebelum publish) -> gain = 800-0 = 800, full coverage.
        $this->assertSame(800, $summary['total_views']);
        $this->assertSame('full', $summary['coverage_status']);
    }

    // ===== 9: previous month is NOT required =====

    public function test_previous_month_fields_not_present_in_summary(): void
    {
        $client = $this->client();
        $month = now()->format('Y-m');

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, $month, null);

        $this->assertArrayNotHasKey('previous_period_start', $summary);
        $this->assertArrayNotHasKey('previous_period_end', $summary);
        $this->assertArrayNotHasKey('trend_vs_previous_period_percent', $summary);
    }

    // ===== 10: AI prompt explicitly contains selected month + coverage metadata =====

    public function test_ai_prompt_contains_selected_month_and_coverage_metadata(): void
    {
        $service = app(AiStrategyService::class);
        $method = new \ReflectionMethod($service, 'buildPrompt');
        $method->setAccessible(true);

        $data = $this->samplePromptData([
            'selected_month' => '2026-08',
            'coverage_status' => 'partial',
            'coverage_from' => '2026-08-15',
            'coverage_to' => '2026-08-31',
        ]);

        $prompt = $method->invoke($service, $data);

        $this->assertStringContainsString('Agustus 2026', $prompt);
        $this->assertStringContainsString('coverage_status', $prompt);
        $this->assertStringContainsString('2026-08-15', $prompt);
        $this->assertStringContainsString('2026-08-31', $prompt);
    }

    // ===== 11: partial coverage produces honest AI context =====

    public function test_partial_coverage_produces_honest_ai_context(): void
    {
        $service = app(AiStrategyService::class);
        $method = new \ReflectionMethod($service, 'buildPrompt');
        $method->setAccessible(true);

        $data = $this->samplePromptData([
            'selected_month' => '2026-08',
            'coverage_status' => 'partial',
            'coverage_from' => '2026-08-15',
            'coverage_to' => '2026-08-31',
        ]);

        $prompt = $method->invoke($service, $data);

        $this->assertStringContainsString('JANGAN', $prompt);
        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - coverage_status=
        // partial SEKARANG hanya membatasi klaim PERTUMBUHAN, total_views/
        // top_5_content di atas SUDAH genuine & lengkap (TIDAK PERLU
        // qualifier) - prompt HARUS eksplisit menegaskan itu.
        $this->assertStringContainsString('SUDAH genuine & lengkap', $prompt);
        $this->assertStringContainsString('Agustus 2026', $prompt);
    }

    public function test_unavailable_coverage_produces_honest_ai_context(): void
    {
        $service = app(AiStrategyService::class);
        $method = new \ReflectionMethod($service, 'buildPrompt');
        $method->setAccessible(true);

        $data = $this->samplePromptData([
            'selected_month' => '2026-08',
            'coverage_status' => 'unavailable',
            'coverage_from' => null,
            'coverage_to' => null,
        ]);

        $prompt = $method->invoke($service, $data);

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - unavailable
        // SEKARANG hanya berarti riwayat pertumbuhan tidak tersedia, BUKAN
        // "tidak ada data performa sama sekali" (itu klaim yang SALAH di
        // bawah semantik baru - current performance genuine & lengkap).
        $this->assertStringContainsString('INI TIDAK BERARTI data performa kosong', $prompt);
        $this->assertStringContainsString('Agustus 2026', $prompt);
    }

    public function test_current_month_in_progress_note_present_in_prompt(): void
    {
        $service = app(AiStrategyService::class);
        $method = new \ReflectionMethod($service, 'buildPrompt');
        $method->setAccessible(true);

        $data = $this->samplePromptData([
            'selected_month' => now()->format('Y-m'),
            'is_current_month_in_progress' => true,
            'coverage_status' => 'full',
        ]);

        $prompt = $method->invoke($service, $data);

        $this->assertStringContainsString('MASIH BERJALAN', $prompt);
    }

    private function samplePromptData(array $overrides): array
    {
        return array_merge([
            'client_name' => 'Test Client',
            'selected_month' => '2026-08',
            'period' => '1 Aug 2026 - 31 Aug 2026',
            'is_current_month_in_progress' => false,
            'total_views' => 500,
            'avg_engagement_rate' => 2.5,
            'performance_by_pillar' => [],
            'performance_by_platform' => [],
            'top_5_content' => [],
            'target_content_count' => 5,
        ], $overrides);
    }

    // ===== 12: platform selection remains respected =====

    public function test_platform_selection_remains_respected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);
        $this->currentMonthCsvMetric($client, $tiktok);
        $this->fakeGeminiStrategyResponse();
        $currentMonth = now()->format('Y-m');

        $this->actingAs($manager)->post(route('analytics.ai-strategy'), [
            'client_id' => $client->id, 'analysis_month' => $currentMonth, 'platform_id' => $tiktok->id,
        ]);

        $insight = AiStrategyInsight::where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame($tiktok->id, $insight->platform_id);
    }

    // ===== Retained from the old rolling-period suite, adapted to month =====

    public function test_all_platforms_persists_platform_id_null(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $this->currentMonthCsvMetric($client, $instagram);
        $this->fakeGeminiStrategyResponse();

        $this->actingAs($manager)->post(route('analytics.ai-strategy'), [
            'client_id' => $client->id, 'analysis_month' => now()->format('Y-m'),
        ]);

        $insight = AiStrategyInsight::where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertNull($insight->platform_id);
    }

    public function test_old_existing_row_remains_all_platforms(): void
    {
        $client = $this->client();
        $generator = User::factory()->create();

        $insight = AiStrategyInsight::create([
            'client_id' => $client->id,
            'platform_id' => null,
            'generated_by' => $generator->id,
            'period_start' => now()->subMonths(3)->startOfMonth(),
            'period_end' => now()->subMonths(3)->endOfMonth(),
            'summary' => 'Historical monthly analysis, older schema.',
            'action_items' => [],
            'status' => 'completed',
        ]);

        $this->assertNull($insight->fresh()->platform_id);
        $this->assertNull($insight->fresh()->platform);
    }

    public function test_latest_lookup_does_not_cross_platform(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);
        ApiIntegration::create(['client_id' => $client->id, 'platform_id' => $tiktok->id, 'integration_name' => 'TikTok', 'status' => 'active', 'access_token' => 'fake']);
        $window = app(AiStrategyService::class)->resolveMonthWindow(now()->format('Y-m'));

        AiStrategyInsight::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id, 'generated_by' => $manager->id,
            'period_start' => $window['start'], 'period_end' => $window['end'],
            'summary' => 'Ringkasan khusus Instagram.', 'action_items' => [], 'status' => 'completed',
        ]);
        AiStrategyInsight::create([
            'client_id' => $client->id, 'platform_id' => $tiktok->id, 'generated_by' => $manager->id,
            'period_start' => $window['start'], 'period_end' => $window['end'],
            'summary' => 'Ringkasan khusus TikTok.', 'action_items' => [], 'status' => 'completed',
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id, 'platform_id' => $tiktok->id]));

        $response->assertOk();
        $response->assertSee('Ringkasan khusus TikTok.');
        $response->assertDontSee('Ringkasan khusus Instagram.');
    }

    public function test_latest_lookup_does_not_cross_month(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $currentMonth = now()->format('Y-m');
        $pastMonth = now()->subMonthNoOverflow()->format('Y-m');
        $currentWindow = app(AiStrategyService::class)->resolveMonthWindow($currentMonth);
        $pastWindow = app(AiStrategyService::class)->resolveMonthWindow($pastMonth);

        AiStrategyInsight::create([
            'client_id' => $client->id, 'platform_id' => null, 'generated_by' => $manager->id,
            'period_start' => $currentWindow['start'], 'period_end' => $currentWindow['end'],
            'summary' => 'Ringkasan bulan berjalan.', 'action_items' => [], 'status' => 'completed',
        ]);
        AiStrategyInsight::create([
            'client_id' => $client->id, 'platform_id' => null, 'generated_by' => $manager->id,
            'period_start' => $pastWindow['start'], 'period_end' => $pastWindow['end'],
            'summary' => 'Ringkasan bulan lalu.', 'action_items' => [], 'status' => 'completed',
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id, 'analysis_month' => $pastMonth]));

        $response->assertOk();
        $response->assertSee('Ringkasan bulan lalu.');
        $response->assertDontSee('Ringkasan bulan berjalan.');
    }

    public function test_generate_ulang_creates_another_row_same_context(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $this->currentMonthCsvMetric($client, $instagram);
        $this->fakeGeminiStrategyResponse();
        $currentMonth = now()->format('Y-m');

        $this->actingAs($manager)->post(route('analytics.ai-strategy'), ['client_id' => $client->id, 'analysis_month' => $currentMonth, 'platform_id' => $instagram->id]);
        $this->actingAs($manager)->post(route('analytics.ai-strategy'), ['client_id' => $client->id, 'analysis_month' => $currentMonth, 'platform_id' => $instagram->id]);

        $count = AiStrategyInsight::where('client_id', $client->id)->where('platform_id', $instagram->id)->count();
        $this->assertSame(2, $count, 'Generate Ulang pada context sama harus boleh membuat insight baru.');
    }

    public function test_malicious_unrelated_platform_id_rejected(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->post(route('analytics.ai-strategy'), [
            'client_id' => $client->id,
            'analysis_month' => now()->format('Y-m'),
            'platform_id' => 999999,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('ai_error');
        $this->assertDatabaseMissing('ai_strategy_insights', ['client_id' => $client->id]);
    }

    public function test_history_renders_actual_date_range_and_platform(): void
    {
        $client = $this->client();
        $generator = User::factory()->create();
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $window = app(AiStrategyService::class)->resolveMonthWindow(now()->subMonthNoOverflow()->format('Y-m'));

        AiStrategyInsight::create([
            'client_id' => $client->id, 'platform_id' => $instagram->id, 'generated_by' => $generator->id,
            'period_start' => $window['start'], 'period_end' => $window['end'],
            'summary' => 'Ringkasan.', 'action_items' => [], 'status' => 'completed',
        ]);

        $staff = $this->viewerFor($client);
        $response = $this->actingAs($staff)->get(route('analytics.ai-strategy.history', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee($window['start']->translatedFormat('d M Y'));
        $response->assertSee($window['end']->translatedFormat('d M Y'));
        $response->assertSee('Instagram');
    }

    public function test_month_header_used_not_generic_days_terakhir_copy(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertDontSee('independen dari filter periode');
        $html = $response->getContent();
        $this->assertStringNotContainsString('hari terakhir ·', $html, 'AI Strategy header harus pakai label bulan, bukan lagi "X hari terakhir".');
    }
}
