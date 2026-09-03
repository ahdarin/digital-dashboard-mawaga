<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\InstagramMediaSnapshot;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\AiStrategyService;
use App\Services\AnalyticsPeriodResolver;
use App\Services\ContentCohortService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - "PUBLISH-DATE COHORT IS
 * PRIMARY". Dedicated regression file for the exact acceptance scenario the
 * correction was requested against (Langkah 21) and the filter-boundary
 * regression it explicitly asked for (Langkah 23). See ContentCohortService's
 * docblock for the full root-cause explanation and the three-concept model
 * (content cohort / current performance / historical period movement) this
 * file proves.
 */
class PublishCohortSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => 'Cohort Test '.uniqid(),
            'status' => 'active',
        ]);
    }

    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
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

    // =====================================================================
    // Langkah 21 - "TEST EXACT USER CASE".
    //
    // A: published Aug 10, current views 18,573, NO historical August
    //    baseline (the app/sync genuinely did not observe anything until
    //    September - only a snapshot dated in September exists).
    // B: published Aug 20, current views 4,200, valid historical period
    //    data (a genuine August-dated snapshot exists too).
    // C: published Sep 1, current views 10,000.
    //
    // Filter: August. Expected: A and B visible, C not visible. Ringkasan
    // current views: 18,573 + 4,200. A's period gain: insufficient_history.
    // B's period gain: genuine value. AI includes both A and B, must NOT
    // exclude A.
    // =====================================================================

    public function test_exact_user_case_a_and_b_visible_in_august_c_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);

            // A - published Aug 10, current 18,573, but the ONLY observation
            // ever recorded is dated in September (the app/sync genuinely
            // did not exist yet in August) - no snapshot dated in August at
            // all exists for this content.
            $mediaA = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-a-'.uniqid(),
                'match_status' => 'unmatched', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
                'published_at' => Carbon::parse('2026-08-10'), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $mediaA->id, 'imported_by' => $manager->id,
                'metric_date' => now(), 'views' => 18573, 'engagement_rate' => 4.2,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $mediaA->id,
                'snapshot_date' => '2026-09-14', 'views' => 18573,
            ]);

            // B - published Aug 20, current 4,200, genuine August-dated
            // observation exists too (valid historical period data).
            $mediaB = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-b-'.uniqid(),
                'match_status' => 'unmatched', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
                'published_at' => Carbon::parse('2026-08-20'), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $mediaB->id, 'imported_by' => $manager->id,
                'metric_date' => now(), 'views' => 4200, 'engagement_rate' => 3.1,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $mediaB->id,
                'snapshot_date' => '2026-08-21', 'views' => 1000,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $mediaB->id,
                'snapshot_date' => '2026-08-31', 'views' => 4200,
            ]);

            // C - published Sep 1, current 10,000 - belongs to September,
            // must NOT appear when filtering August.
            $mediaC = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-c-'.uniqid(),
                'match_status' => 'unmatched', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
                'published_at' => Carbon::parse('2026-09-01 08:00:00'), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $mediaC->id, 'imported_by' => $manager->id,
                'metric_date' => now(), 'views' => 10000, 'engagement_rate' => 5.0,
            ]);
            ContentMetricSnapshot::create([
                'client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $mediaC->id,
                'snapshot_date' => '2026-09-14', 'views' => 10000,
            ]);

            // --- Ringkasan (Overview) ---
            $overview = $this->actingAs($manager)->get(route('analytics', [
                'tab' => 'overview', 'client_id' => $client->id, 'period_mode' => 'month', 'month' => '2026-08',
            ]));
            $overview->assertOk();
            $overview->assertSee('18,573');
            $overview->assertSee('4,200');
            $overview->assertDontSee('10,000');
            // Ringkasan current views total = 18,573 + 4,200 = 22,773.
            $overview->assertSee('22,773');

            // --- Konten (Table) ---
            $table = $this->actingAs($manager)->get(route('analytics', [
                'tab' => 'table', 'client_id' => $client->id, 'period_mode' => 'month', 'month' => '2026-08',
            ]));
            $table->assertOk();
            $table->assertSee('18,573');
            $table->assertSee('4,200');
            $table->assertDontSee('10,000');
            // A's period gain (secondary) is genuinely unavailable - must
            // read as "insufficient history", never as a fabricated "+0"
            // (AvailabilityPresenter::label(INSUFFICIENT_HISTORY)).
            $table->assertSee('Riwayat data belum cukup');

            // --- Direct cohort roster check (published_at boundary) ---
            $cohort = app(ContentCohortService::class)->computeClientCohort(
                $client->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null
            );
            $this->assertSame(2, $cohort['totals']['content_count'], 'August cohort HARUS berisi PERSIS A dan B, bukan C.');
            $this->assertSame(22773, $cohort['totals']['views']);

            $rowA = collect($cohort['rows'])->first(fn ($r) => $r['content_metric']->instagram_media_snapshot_id === $mediaA->id);
            $rowB = collect($cohort['rows'])->first(fn ($r) => $r['content_metric']->instagram_media_snapshot_id === $mediaB->id);
            $this->assertNotNull($rowA, 'A HARUS ada di cohort Agustus (published Aug 10) walau tidak ada baseline historis Agustus sama sekali.');
            $this->assertNotNull($rowB);
            $this->assertSame('insufficient_history', $rowA['period_result']->availabilityCategory(), 'A - period gain HARUS insufficient_history (bukan unavailable roster-nya - itu beda konsep).');
            $this->assertTrue($rowB['period_result']->isUsable(), 'B - period gain HARUS genuine value (riwayat Agustus ada).');
            $this->assertNotNull($rowB['period_result']->views());

            // --- AI Strategy must include BOTH A and B, must NOT exclude A ---
            $aiSummary = app(AiStrategyService::class)->buildPerformanceSummary($client, '2026-08', null);
            $this->assertSame(2, $aiSummary['content_published_count'], 'AI HARUS menganalisis A dan B, TIDAK BOLEH mengecualikan A hanya karena period-gain-nya tidak tersedia.');
            $this->assertSame(22773, $aiSummary['total_views']);
            $topCurrentViews = collect($aiSummary['top_5_content'])->pluck('current_views')->sort()->values()->all();
            $this->assertSame([4200, 18573], $topCurrentViews);
        } finally {
            Carbon::setTestNow();
        }
    }

    // =====================================================================
    // Langkah 23 - "FILTER BOUNDARIES" regression, canonical application
    // timezone normalization (Carbon default app timezone, no manual UTC
    // conversion - matches ContentCohortService::computeClientCohort()'s
    // own startOfDay()/endOfDay() boundary handling).
    // =====================================================================

    public function test_filter_boundaries_jul31_excluded_aug1_included_aug31_included_sep1_excluded(): void
    {
        $client = $this->client();
        $integration = $this->instagramIntegration($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $boundaryPoints = [
            'jul31-2359-59' => ['at' => '2026-07-31 23:59:59', 'expectIncluded' => false],
            'aug1-0000-00' => ['at' => '2026-08-01 00:00:00', 'expectIncluded' => true],
            'aug31-2359-59' => ['at' => '2026-08-31 23:59:59', 'expectIncluded' => true],
            'sep1-0000-00' => ['at' => '2026-09-01 00:00:00', 'expectIncluded' => false],
        ];

        $mediaIds = [];
        foreach ($boundaryPoints as $key => $point) {
            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id, 'external_post_id' => 'ig-'.$key,
                'match_status' => 'unmatched', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
                'published_at' => Carbon::parse($point['at']), 'last_fetched_at' => now(),
            ]);
            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => User::factory()->create()->id,
                'metric_date' => now(), 'views' => 100, 'engagement_rate' => 1.0,
            ]);
            $mediaIds[$key] = $media->id;
        }

        $cohort = app(ContentCohortService::class)->computeClientCohort(
            $client->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null
        );
        $includedSnapshotIds = collect($cohort['rows'])->pluck('content_metric.instagram_media_snapshot_id')->all();

        foreach ($boundaryPoints as $key => $point) {
            $isIncluded = in_array($mediaIds[$key], $includedSnapshotIds, true);
            $this->assertSame($point['expectIncluded'], $isIncluded, "Boundary case '{$key}' ({$point['at']}) - expected included=".var_export($point['expectIncluded'], true).' but got '.var_export($isIncluded, true).'.');
        }

        $this->assertSame(2, $cohort['totals']['content_count'], 'Hanya aug1-0000-00 dan aug31-2359-59 yang HARUS masuk cohort Agustus.');
    }

    // =====================================================================
    // Langkah 22 - Instagram + TikTok combine correctly under All Platforms,
    // each keeping its own provider-unsupported-metric null semantics.
    // =====================================================================

    public function test_instagram_and_tiktok_combine_under_all_platforms_for_the_same_month_cohort(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $igPlatform = Platform::firstOrCreate(['name' => 'Instagram']);
        $ttPlatform = Platform::firstOrCreate(['name' => 'TikTok']);
        $igIntegration = $this->instagramIntegration($client);
        $ttIntegration = ApiIntegration::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id, 'integration_name' => 'TT',
            'status' => 'active', 'access_token' => 'fake', 'external_username' => 'creator',
        ]);

        $igMedia = InstagramMediaSnapshot::create([
            'api_integration_id' => $igIntegration->id, 'external_post_id' => 'ig-combine',
            'match_status' => 'unmatched', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
            'published_at' => Carbon::parse('2026-08-05'), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $igPlatform->id,
            'instagram_media_snapshot_id' => $igMedia->id, 'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 3000, 'engagement_rate' => 4.0,
            // TikTok tidak punya reach - Instagram-nya di sini genuine ada.
            'reach' => 2800,
        ]);

        $ttVideo = TikTokVideoSnapshot::create([
            'api_integration_id' => $ttIntegration->id, 'external_post_id' => 'tt-combine',
            'match_status' => 'unmatched', 'published_at' => Carbon::parse('2026-08-12'), 'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $ttPlatform->id,
            'tiktok_video_snapshot_id' => $ttVideo->id, 'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 7000, 'engagement_rate' => 6.0,
            // 'reach' TIDAK diisi - TikTok integration ini tidak punya field
            // reach sama sekali (unsupported != zero, TIDAK diisi 0).
        ]);

        $cohort = app(ContentCohortService::class)->computeClientCohort(
            $client->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null
        );

        $this->assertSame(2, $cohort['totals']['content_count']);
        $this->assertSame(10000, $cohort['totals']['views'], 'Instagram (3000) + TikTok (7000) = 10000, keduanya digabung under All Platforms.');

        $ttRow = collect($cohort['rows'])->first(fn ($r) => $r['content_metric']->tiktok_video_snapshot_id === $ttVideo->id);
        $this->assertNull($ttRow['content_metric']->reach, 'TikTok reach TIDAK PERNAH difabrikasi jadi 0 - tetap null (unsupported), bukan angka palsu.');
    }
}
