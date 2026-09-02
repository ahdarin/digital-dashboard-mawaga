<?php

namespace Tests\Feature;

use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Platform;
use App\Models\User;
use App\Services\InstagramAudienceInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KI-08 - live-verified 2026-08-31 lewat panggilan API nyata ke akun Metro
 * (@metrosoftware, integration production) bahwa reached/engaged audience
 * demographics BUKAN "selalu tersedia" - Meta bisa balikin HTTP 200 dengan
 * results kosong, atau (khusus engaged) error code 3006 "Not enough users".
 * Test ini mengunci kontrak itu lewat HTTP fake supaya regresi ke arah
 * "unavailable jadi 0 palsu" atau "1 metric unavailable menggagalkan sync"
 * ketahuan tanpa perlu akun Instagram nyata.
 */
class InstagramAudienceInsightsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function integration(): ApiIntegration
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $client = Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'integration_name' => 'Meta Graph API',
            'access_token' => 'fake-long-lived-token',
            'status' => 'active',
            'external_account_id' => '999999',
            'external_username' => 'metrosoftware_test',
        ]);
    }

    private function syncLog(ApiIntegration $integration): AnalyticsSyncLog
    {
        $user = User::factory()->create();

        return AnalyticsSyncLog::create([
            'client_id' => $integration->client_id,
            'platform_id' => $integration->platform_id,
            'api_integration_id' => $integration->id,
            'imported_by' => $user->id,
            'source_type' => 'audience_api_sync',
            'status' => 'pending',
            'sync_mode' => 'default',
            'range_from' => now()->toDateString(),
            'range_to' => now()->toDateString(),
        ]);
    }

    /**
     * Nilai default buat 4 panggilan yang SELALU dilakukan sync() di luar
     * demographics (followers_count, reach, online_followers) plus
     * follower_demographics (4 breakdown, tanpa timeframe) - supaya tiap
     * test cukup override apa yang relevan (reached/engaged) saja.
     *
     * @param  array<string, mixed>  $overrides  key "{metric}|{breakdown}|{timeframe}" (timeframe "none" kalau tidak dikirim)
     */
    private function fakeInstagram(array $overrides = []): void
    {
        $emptyBreakdown = fn () => ['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]];
        $genderResult = fn (int $male, int $female) => [
            'data' => [['total_value' => ['breakdowns' => [['results' => [
                ['dimension_values' => ['M'], 'value' => $male],
                ['dimension_values' => ['F'], 'value' => $female],
            ]]]]]],
        ];

        $responses = [
            'follower_demographics|gender|none' => Http::response($genderResult(60, 40), 200),
            'follower_demographics|age|none' => Http::response($emptyBreakdown(), 200),
            'follower_demographics|city|none' => Http::response($emptyBreakdown(), 200),
            'follower_demographics|country|none' => Http::response($emptyBreakdown(), 200),
        ];

        foreach ($overrides as $key => $response) {
            $responses[$key] = $response;
        }

        Http::fake(function (Request $request) use ($responses) {
            $url = $request->url();

            if (str_contains($url, '/me')) {
                return Http::response(['followers_count' => 758], 200);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $metric = $query['metric'] ?? null;

            if ($metric === 'reach') {
                return Http::response(['data' => [['values' => [
                    ['end_time' => now()->toISOString(), 'value' => 778],
                ]]]], 200);
            }

            if ($metric === 'online_followers') {
                return Http::response(['data' => [['values' => [
                    ['value' => array_fill(0, 24, 1)],
                ]]]], 200);
            }

            $breakdown = $query['breakdown'] ?? null;
            $timeframe = $query['timeframe'] ?? 'none';
            $key = "{$metric}|{$breakdown}|{$timeframe}";

            return $responses[$key] ?? Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200);
        });
    }

    public function test_usable_demographic_result_is_saved(): void
    {
        $integration = $this->integration();
        $syncLog = $this->syncLog($integration);

        $this->fakeInstagram([
            'reached_audience_demographics|gender|this_month' => Http::response([
                'data' => [['total_value' => ['breakdowns' => [['results' => [
                    ['dimension_values' => ['M'], 'value' => 70],
                    ['dimension_values' => ['F'], 'value' => 30],
                ]]]]]],
            ], 200),
        ]);

        $service = new InstagramAudienceInsightsService($integration);
        $result = $service->sync($syncLog);

        $this->assertContains(AudienceInsight::TYPE_REACHED, $result['demographics_saved']);
        $this->assertDatabaseHas('audience_insights', [
            'client_id' => $integration->client_id,
            'platform_id' => $integration->platform_id,
            'demographic_type' => AudienceInsight::TYPE_REACHED,
            'source' => AudienceInsight::SOURCE_API,
        ]);

        $row = AudienceInsight::where('demographic_type', AudienceInsight::TYPE_REACHED)->first();
        $this->assertEquals(['male' => 70.0, 'female' => 30.0], $row->gender_breakdown);
    }

    public function test_http_200_with_empty_results_marks_demographic_unavailable(): void
    {
        $integration = $this->integration();
        $syncLog = $this->syncLog($integration);

        // Tidak perlu override apa pun - default fake sudah balikin
        // results kosong (200) untuk reached di this_month & this_week.
        $this->fakeInstagram();

        $service = new InstagramAudienceInsightsService($integration);
        $result = $service->sync($syncLog);

        $this->assertContains(AudienceInsight::TYPE_REACHED, $result['demographics_unavailable']);
        $this->assertDatabaseMissing('audience_insights', [
            'client_id' => $integration->client_id,
            'demographic_type' => AudienceInsight::TYPE_REACHED,
        ]);
        $this->assertSame('success', $syncLog->fresh()->status);
        // PASS 4 (Langkah 7) - "200 + kosong" TIDAK PUNYA bukti eksplisit
        // dari Meta (bukan error response, bukan kode spesifik apapun) -
        // TIDAK BOLEH ditandai provider_unavailable, harus TETAP jatuh ke
        // default insufficient_history yang jujur ("do NOT guess when no
        // evidence exists").
        $this->assertFalse(InstagramAudienceInsightsService::isKnownProviderUnavailable($integration->id, AudienceInsight::TYPE_REACHED));
    }

    public function test_meta_error_3006_marks_demographic_unavailable_not_sync_failure(): void
    {
        $integration = $this->integration();
        $syncLog = $this->syncLog($integration);

        $this->fakeInstagram([
            'engaged_audience_demographics|gender|this_month' => Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200),
            'engaged_audience_demographics|age|this_month' => Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200),
            'engaged_audience_demographics|city|this_month' => Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200),
            'engaged_audience_demographics|country|this_month' => Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200),
            'engaged_audience_demographics|gender|this_week' => Http::response(['error' => ['code' => 3006, 'message' => 'Not enough users']], 400),
            'engaged_audience_demographics|age|this_week' => Http::response(['error' => ['code' => 3006, 'message' => 'Not enough users']], 400),
            'engaged_audience_demographics|city|this_week' => Http::response(['error' => ['code' => 3006, 'message' => 'Not enough users']], 400),
            'engaged_audience_demographics|country|this_week' => Http::response(['error' => ['code' => 3006, 'message' => 'Not enough users']], 400),
        ]);

        $service = new InstagramAudienceInsightsService($integration);
        $result = $service->sync($syncLog);

        $this->assertContains(AudienceInsight::TYPE_ENGAGED, $result['demographics_unavailable']);
        $this->assertDatabaseMissing('audience_insights', [
            'client_id' => $integration->client_id,
            'demographic_type' => AudienceInsight::TYPE_ENGAGED,
        ]);
        $this->assertSame('success', $syncLog->fresh()->status);
        // Integration tidak boleh ditandai butuh reconnect - 3006 bukan
        // masalah token/auth.
        $this->assertSame('active', $integration->fresh()->status);
        // PASS 4 (Langkah 7) - code 3006 ADALAH bukti eksplisit dari Meta
        // (HTTP error response terstruktur) - HARUS ditandai
        // provider_unavailable buat Data Health, TIDAK jatuh ke default
        // insufficient_history yang lebih lemah.
        $this->assertTrue(InstagramAudienceInsightsService::isKnownProviderUnavailable($integration->id, AudienceInsight::TYPE_ENGAGED));
        // Demographic_type LAIN yang tidak pernah kena 3006 TIDAK ikut
        // tertandai (signal per-type, bukan per-integration global).
        $this->assertFalse(InstagramAudienceInsightsService::isKnownProviderUnavailable($integration->id, AudienceInsight::TYPE_FOLLOWER));
    }

    public function test_sync_stays_success_when_both_reached_and_engaged_are_unavailable(): void
    {
        $integration = $this->integration();
        $syncLog = $this->syncLog($integration);

        // Default fake: reached kosong di semua timeframe. Tambahkan
        // engaged kosong juga (200, tanpa error) supaya keduanya
        // unavailable sekaligus dalam 1 sync.
        $this->fakeInstagram([
            'engaged_audience_demographics|gender|this_month' => Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200),
            'engaged_audience_demographics|gender|this_week' => Http::response(['data' => [['total_value' => ['breakdowns' => [['results' => []]]]]]], 200),
        ]);

        $service = new InstagramAudienceInsightsService($integration);
        $result = $service->sync($syncLog);

        $this->assertSame(['reached', 'engaged'], $result['demographics_unavailable']);
        $this->assertTrue($result['summary_saved']);
        $this->assertContains(AudienceInsight::TYPE_FOLLOWER, $result['demographics_saved']);
        $this->assertSame('success', $syncLog->fresh()->status);

        $this->assertDatabaseHas('audience_insights', [
            'client_id' => $integration->client_id,
            'demographic_type' => AudienceInsight::TYPE_SUMMARY,
        ]);
        $this->assertDatabaseHas('audience_insights', [
            'client_id' => $integration->client_id,
            'demographic_type' => AudienceInsight::TYPE_FOLLOWER,
        ]);
    }
}
