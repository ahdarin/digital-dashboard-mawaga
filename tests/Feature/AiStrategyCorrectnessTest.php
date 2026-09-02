<?php

namespace Tests\Feature;

use App\Models\AiStrategyInsight;
use App\Models\ApiIntegration;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Permission;
use App\Models\PerformanceAnomaly;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\AiStrategyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4.2 - AI Strategy correctness follow-ups yang diaudit setelah Phase
 * 4.1 di-approve secara arsitektur: audience input coupling ke performance
 * aggregate (harus lepas, ditentukan dari requested context), strict
 * validation di endpoint mutating/berbayar (bukan tolerant fallback ala
 * read-only display filter), anomaly platform scoping via ContentMetric
 * (bukan ContentItem legacy scalar), context-exact history behavior.
 */
class AiStrategyCorrectnessTest extends TestCase
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

    private function geminiPayload(string $summary): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode([
                    'summary' => $summary,
                    'action_items' => ['Item A'],
                    'suggested_split' => [['label' => 'Education', 'value' => 100]],
                    'top_pillars' => [['name' => 'Education', 'reasoning' => 'Test']],
                    'content_ideas' => [
                        ['pillar' => 'Education', 'title' => 'Judul', 'brief' => 'Brief', 'type' => 'Video', 'platform' => 'Instagram'],
                    ],
                ])]]]],
            ],
        ];
    }

    private function fakeGeminiStrategyResponse(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiPayload('Ringkasan test.'), 200)]);
        config(['services.gemini.api_key' => 'fake-key-for-test']);
    }

    /** Metric CSV/manual di dalam window rolling ($daysAgo hari lalu). */
    private function recentCsvMetric(Client $client, Platform $platform, int $daysAgo = 5): ContentItem
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
            'deadline_at' => now()->subDays($daysAgo),
        ]);
        ContentWorkflow::create(['content_item_id' => $item->id, 'current_status' => 'uploaded', 'is_overdue' => false]);
        ContentMetric::create([
            'content_item_id' => $item->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now()->subDays($daysAgo),
            'views' => 500,
            'engagement_rate' => 3.5,
        ]);

        return $item;
    }

    private function csvAudience(Client $client, Platform $platform, int $followerCount, array $genderBreakdown = ['male' => 60, 'female' => 40]): AudienceInsight
    {
        return AudienceInsight::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'source' => AudienceInsight::SOURCE_CSV,
            'demographic_type' => AudienceInsight::TYPE_GENERIC,
            'snapshot_date' => now(),
            'follower_count' => $followerCount,
            'gender_breakdown' => $genderBreakdown,
        ]);
    }

    // ===================================================================
    // Section 2: Audience input must follow REQUESTED CONTEXT, not
    // performance-aggregate coupling.
    // ===================================================================

    public function test_tiktok_selected_with_no_usable_performance_still_gets_tiktok_audience(): void
    {
        $client = $this->client();
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);
        // TikTok follower data ADA, TAPI TIDAK ADA content performance
        // TikTok sama sekali (roster kosong buat TikTok) - performance_by_platform
        // tidak akan pernah punya key TikTok, audience TIDAK BOLEH ikut hilang.
        $this->csvAudience($client, $tiktok, 5000);

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, now()->format('Y-m'), $tiktok->id);

        $this->assertArrayNotHasKey('TikTok', $summary['performance_by_platform']->toArray(), 'Precondition: memang tidak ada performance data TikTok.');
        $this->assertArrayHasKey('TikTok', $summary['audience_by_platform']);
        $this->assertSame(5000, $summary['audience_by_platform']['TikTok']['follower_count']);
    }

    public function test_instagram_selected_does_not_leak_tiktok_audience(): void
    {
        $client = $this->client();
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $this->csvAudience($client, $tiktok, 5000);

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, now()->format('Y-m'), $instagram->id);

        $this->assertArrayNotHasKey('TikTok', $summary['audience_by_platform'], 'Audience TikTok TIDAK BOLEH bocor ke context Instagram.');
    }

    public function test_tiktok_selected_does_not_leak_instagram_audience(): void
    {
        $client = $this->client();
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);
        $this->csvAudience($client, $instagram, 8000);

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, now()->format('Y-m'), $tiktok->id);

        $this->assertArrayNotHasKey('Instagram', $summary['audience_by_platform'], 'Audience Instagram TIDAK BOLEH bocor ke context TikTok.');
    }

    public function test_all_platforms_keeps_audience_platform_separated_not_merged(): void
    {
        $client = $this->client();
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);
        $this->csvAudience($client, $instagram, 8000, ['male' => 30, 'female' => 70]);
        $this->csvAudience($client, $tiktok, 5000, ['male' => 60, 'female' => 40]);

        $summary = app(AiStrategyService::class)->buildPerformanceSummary($client, now()->format('Y-m'), null);

        $this->assertArrayHasKey('Instagram', $summary['audience_by_platform']);
        $this->assertArrayHasKey('TikTok', $summary['audience_by_platform']);
        // Platform-separated, BUKAN demographic merge (mis. rata-rata
        // follower_count atau gabungan gender_breakdown) - tiap platform
        // harus tetap angka aslinya sendiri.
        $this->assertSame(8000, $summary['audience_by_platform']['Instagram']['follower_count']);
        $this->assertSame(5000, $summary['audience_by_platform']['TikTok']['follower_count']);
        $this->assertSame(['male' => 30, 'female' => 70], $summary['audience_by_platform']['Instagram']['gender_breakdown']);
        $this->assertSame(['male' => 60, 'female' => 40], $summary['audience_by_platform']['TikTok']['gender_breakdown']);
    }

    // ===================================================================
    // Section 3: Strict validation for the mutating/billed generate
    // endpoint - SUPERSEDED by AiStrategyMonthSelectionTest.php's
    // invalid/future/missing-month tests (period 7/30/90 tidak ada lagi
    // di endpoint ini - lihat Phase 4.1 v2, "AI Strategy Month Selection").
    // ===================================================================

    // ===================================================================
    // Section 4: Notable anomaly platform scoping - deterministic via
    // ContentMetric.platform_id, ambiguous multi-platform content excluded
    // entirely rather than guessed.
    // ===================================================================

    public function test_anomaly_from_ambiguous_multiplatform_content_excluded_from_both_platform_contexts(): void
    {
        $client = $this->client();
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);

        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'content_type_id' => $contentType->id, 'platform_id' => $instagram->id,
            'title' => 'Multi Platform Content', 'deadline_at' => now(),
        ]);
        $importer = User::factory()->create();
        // Content item ini GENUINELY multi-platform - ContentMetric di 2
        // platform berbeda buat content_item_id yang SAMA.
        ContentMetric::create(['content_item_id' => $item->id, 'client_id' => $client->id, 'platform_id' => $instagram->id, 'imported_by' => $importer->id, 'metric_date' => now(), 'views' => 100]);
        ContentMetric::create(['content_item_id' => $item->id, 'client_id' => $client->id, 'platform_id' => $tiktok->id, 'imported_by' => $importer->id, 'metric_date' => now(), 'views' => 200]);

        PerformanceAnomaly::create([
            'content_item_id' => $item->id, 'type' => 'spike', 'percent_change' => 80,
            'views_on_date' => 200, 'baseline_avg_views' => 100, 'detected_date' => now(),
        ]);

        $service = app(AiStrategyService::class);
        $summaryTiktok = $service->buildPerformanceSummary($client, now()->format('Y-m'), $tiktok->id);
        $summaryInstagram = $service->buildPerformanceSummary($client, now()->format('Y-m'), $instagram->id);
        $summaryAll = $service->buildPerformanceSummary($client, now()->format('Y-m'), null);

        $this->assertCount(0, $summaryTiktok['notable_anomalies'], 'Anomaly dari content multi-platform ambigu HARUS dikecualikan dari filter TikTok spesifik (bukan ditebak).');
        $this->assertCount(0, $summaryInstagram['notable_anomalies'], 'Anomaly dari content multi-platform ambigu HARUS dikecualikan dari filter Instagram spesifik (bukan ditebak).');
        $this->assertCount(1, $summaryAll['notable_anomalies'], 'All Platforms tetap menampilkan semua anomaly client ini, ambigu atau tidak.');
    }

    public function test_anomaly_from_single_platform_content_correctly_attributed_no_leak(): void
    {
        $client = $this->client();
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);

        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'content_type_id' => $contentType->id, 'platform_id' => $instagram->id,
            'title' => 'Instagram Only Content', 'deadline_at' => now(),
        ]);
        ContentMetric::create(['content_item_id' => $item->id, 'client_id' => $client->id, 'platform_id' => $instagram->id, 'imported_by' => User::factory()->create()->id, 'metric_date' => now(), 'views' => 100]);

        PerformanceAnomaly::create([
            'content_item_id' => $item->id, 'type' => 'spike', 'percent_change' => 60,
            'views_on_date' => 160, 'baseline_avg_views' => 100, 'detected_date' => now(),
        ]);

        $service = app(AiStrategyService::class);
        $summaryInstagram = $service->buildPerformanceSummary($client, now()->format('Y-m'), $instagram->id);
        $summaryTiktok = $service->buildPerformanceSummary($client, now()->format('Y-m'), $tiktok->id);

        $this->assertCount(1, $summaryInstagram['notable_anomalies'], 'Content single-platform Instagram harus tetap ke-attribute ke Instagram (deterministic, bukan over-excluded).');
        $this->assertCount(0, $summaryTiktok['notable_anomalies'], 'Anomaly Instagram TIDAK BOLEH bocor ke context TikTok.');
    }

    // ===================================================================
    // Section 5: Coverage prompt structural honesty - reminder repeated
    // near end of prompt, not just once near the raw data.
    // ===================================================================

    public function test_coverage_reminder_appears_before_output_format_instruction_for_unavailable(): void
    {
        $service = app(AiStrategyService::class);
        $method = new \ReflectionMethod($service, 'buildPrompt');
        $method->setAccessible(true);

        $data = $this->samplePromptData(['coverage_status' => 'unavailable', 'coverage_from' => null, 'coverage_to' => null]);
        $prompt = $method->invoke($service, $data);

        $this->assertStringContainsString('INGAT SEKALI LAGI', $prompt);
        $this->assertStringContainsString('TIDAK ADA angka performa asli', $prompt);

        $reminderPos = strpos($prompt, 'INGAT SEKALI LAGI');
        $formatInstructionPos = strpos($prompt, 'Balas HANYA dalam format JSON');
        $this->assertNotFalse($reminderPos);
        $this->assertNotFalse($formatInstructionPos);
        $this->assertLessThan($formatInstructionPos, $reminderPos, 'Coverage reminder harus muncul SEBELUM instruksi format output (reinforcement dekat akhir prompt).');
    }

    public function test_coverage_reminder_appears_before_output_format_instruction_for_partial(): void
    {
        $service = app(AiStrategyService::class);
        $method = new \ReflectionMethod($service, 'buildPrompt');
        $method->setAccessible(true);

        $data = $this->samplePromptData(['coverage_status' => 'partial', 'coverage_from' => now()->toDateString(), 'coverage_to' => now()->toDateString()]);
        $prompt = $method->invoke($service, $data);

        $reminderPos = strpos($prompt, 'INGAT SEKALI LAGI');
        $formatInstructionPos = strpos($prompt, 'Balas HANYA dalam format JSON');
        $this->assertNotFalse($reminderPos);
        $this->assertLessThan($formatInstructionPos, $reminderPos);
    }

    private function samplePromptData(array $overrides): array
    {
        return array_merge([
            'client_name' => 'Test Client',
            'selected_month' => '2026-08',
            'period' => '4 Aug 2026 - 2 Sep 2026',
            'total_views' => 500,
            'avg_engagement_rate' => 2.5,
            'performance_by_pillar' => [],
            'performance_by_platform' => [],
            'top_5_content' => [],
            'target_content_count' => 5,
        ], $overrides);
    }

    // ===================================================================
    // Section 6: AI context / history behavior - Generate Ulang pada
    // context sama, current page tampilkan yang TERBARU, history simpan
    // keduanya.
    // ===================================================================

    public function test_two_generate_ulang_same_context_current_shows_newest_history_keeps_both(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $this->recentCsvMetric($client, $instagram, 0);
        $month = now()->format('Y-m');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiPayload('Ringkasan pertama.'), 200)
                ->push($this->geminiPayload('Ringkasan kedua (terbaru).'), 200),
        ]);
        config(['services.gemini.api_key' => 'fake-key-for-test']);

        $this->actingAs($manager)->post(route('analytics.ai-strategy'), ['client_id' => $client->id, 'analysis_month' => $month, 'platform_id' => $instagram->id]);
        $this->actingAs($manager)->post(route('analytics.ai-strategy'), ['client_id' => $client->id, 'analysis_month' => $month, 'platform_id' => $instagram->id]);

        $this->assertSame(2, AiStrategyInsight::where('client_id', $client->id)->where('platform_id', $instagram->id)->count());

        $page = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id, 'analysis_month' => $month, 'platform_id' => $instagram->id]));
        $page->assertOk();
        $page->assertSee('Ringkasan kedua (terbaru).');
        $page->assertDontSee('Ringkasan pertama.');

        $history = $this->actingAs($manager)->get(route('analytics.ai-strategy.history', ['client_id' => $client->id]));
        $history->assertOk();
        $history->assertSee('Ringkasan pertama.');
        $history->assertSee('Ringkasan kedua (terbaru).');
    }
}
