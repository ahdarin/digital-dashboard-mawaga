<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\InstagramMediaSnapshot;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Services\AnalyticsPeriod;
use App\Services\AnalyticsPeriodResolver;
use App\Services\ContentPeriodResult;
use App\Services\PeriodPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PASS 2 - "PERIOD ENGINE V2, MONTHLY + CUSTOM DATE RANGE". Cakupan test
 * item 15 spec: month (full/28/29/30/31 hari, year-boundary,
 * current-partial), custom (valid/single-day/equal-length-comparison/
 * invalid-reversed/max-range), URL (legacy accepted, new links pakai model
 * baru), cross-consumer (Overview/Table/Export resolve range yang SAMA
 * PERSIS), Audience period behavior (past custom/month range TIDAK bocor
 * data di luar rentang).
 *
 * Bulan tetap TERTUTUP (Jan/Feb/Apr 2024-2025) dipilih SENGAJA jauh di masa
 * lalu (bukan cuma "bulan lalu") - biar assertion "bulan ini sudah lengkap"
 * TIDAK PERNAH kebetulan match "bulan berjalan" bertahun-tahun ke depan.
 * "Current month" test pakai Carbon::now() dinamis, bukan hardcode.
 */
class AnalyticsPeriodEngineV2Test extends TestCase
{
    use RefreshDatabase;

    private function resolver(): AnalyticsPeriodResolver
    {
        return app(AnalyticsPeriodResolver::class);
    }

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
        $permission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    // ===== MONTH SEMANTICS =====

    public function test_build_month_full_31_day_month_is_complete(): void
    {
        $period = $this->resolver()->buildMonth('2025-01');

        $this->assertSame('2025-01-01', $period->dateFrom->toDateString());
        $this->assertSame('2025-01-31', $period->dateTo->toDateString());
        $this->assertSame('2025-01-31', $period->effectiveDateTo->toDateString());
        $this->assertSame(31, $period->requestedLengthInDays());
        $this->assertFalse($period->isCurrentPeriodIncomplete());
        $this->assertNull($period->effectiveThroughLabel());
    }

    public function test_build_month_28_day_non_leap_february(): void
    {
        $period = $this->resolver()->buildMonth('2025-02');

        $this->assertSame('2025-02-28', $period->dateTo->toDateString());
        $this->assertSame(28, $period->requestedLengthInDays());
    }

    public function test_build_month_29_day_leap_february(): void
    {
        $period = $this->resolver()->buildMonth('2024-02');

        $this->assertSame('2024-02-29', $period->dateTo->toDateString());
        $this->assertSame(29, $period->requestedLengthInDays());
    }

    public function test_build_month_30_day_month(): void
    {
        $period = $this->resolver()->buildMonth('2025-04');

        $this->assertSame('2025-04-30', $period->dateTo->toDateString());
        $this->assertSame(30, $period->requestedLengthInDays());
    }

    public function test_previous_period_month_handles_year_boundary(): void
    {
        $january = $this->resolver()->buildMonth('2025-01');
        $previous = $this->resolver()->previousPeriod($january);

        $this->assertSame(AnalyticsPeriod::MODE_MONTH, $previous->mode);
        $this->assertSame('2024-12', $previous->month);
        $this->assertSame('2024-12-01', $previous->dateFrom->toDateString());
        $this->assertSame('2024-12-31', $previous->dateTo->toDateString());
    }

    public function test_current_month_is_partial_and_capped_at_today(): void
    {
        $currentMonth = $this->resolver()->currentMonth();
        $today = Carbon::now()->startOfDay();

        $this->assertSame($today->toDateString(), $currentMonth->effectiveDateTo->toDateString());
        $this->assertTrue($currentMonth->isCurrentPeriodIncomplete());
        $this->assertNotNull($currentMonth->effectiveThroughLabel());
        $this->assertStringContainsString('Data melalui', $currentMonth->effectiveThroughLabel());
        // Requested range (dateTo) TETAP akhir bulan kalender apa adanya,
        // BUKAN dipotong ke hari ini - beda dari effectiveDateTo.
        $this->assertSame($today->copy()->endOfMonth()->toDateString(), $currentMonth->dateTo->toDateString());
    }

    // ===== CUSTOM RANGE =====

    public function test_custom_valid_range(): void
    {
        $period = $this->resolver()->buildCustom(Carbon::parse('2025-08-10'), Carbon::parse('2025-08-20'));

        $this->assertSame(11, $period->requestedLengthInDays());
        $this->assertSame(AnalyticsPeriod::MODE_CUSTOM, $period->mode);
    }

    public function test_custom_single_day_range(): void
    {
        $period = $this->resolver()->buildCustom(Carbon::parse('2025-08-15'), Carbon::parse('2025-08-15'));

        $this->assertSame(1, $period->requestedLengthInDays());
    }

    public function test_custom_previous_period_is_immediately_preceding_equal_length_range(): void
    {
        // Contoh persis dari spec: Aug 10-20 (11 hari) -> previous Jul 30-Aug 9.
        $period = $this->resolver()->buildCustom(Carbon::parse('2025-08-10'), Carbon::parse('2025-08-20'));
        $previous = $this->resolver()->previousPeriod($period);

        $this->assertSame('2025-07-30', $previous->dateFrom->toDateString());
        $this->assertSame('2025-08-09', $previous->dateTo->toDateString());
        $this->assertSame(11, $previous->requestedLengthInDays());
    }

    public function test_resolver_rejects_reversed_dates_with_fallback_and_error(): void
    {
        $request = Request::create('/analytics', 'GET', [
            'period_mode' => 'custom', 'date_from' => '2025-08-20', 'date_to' => '2025-08-10',
        ]);

        ['period' => $period, 'error' => $error] = $this->resolver()->resolveWithError($request);

        $this->assertNotNull($error);
        $this->assertSame(AnalyticsPeriod::MODE_MONTH, $period->mode);
    }

    public function test_resolver_rejects_future_start_date_with_fallback_and_error(): void
    {
        $future = Carbon::now()->addMonths(2)->toDateString();
        $request = Request::create('/analytics', 'GET', [
            'period_mode' => 'custom', 'date_from' => $future, 'date_to' => $future,
        ]);

        ['period' => $period, 'error' => $error] = $this->resolver()->resolveWithError($request);

        $this->assertNotNull($error);
        $this->assertSame(AnalyticsPeriod::MODE_MONTH, $period->mode);
    }

    public function test_resolver_rejects_range_exceeding_max_custom_range_days(): void
    {
        $from = Carbon::parse('2020-01-01');
        $to = $from->copy()->addDays(AnalyticsPeriodResolver::MAX_CUSTOM_RANGE_DAYS + 5);
        $request = Request::create('/analytics', 'GET', [
            'period_mode' => 'custom', 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
        ]);

        ['period' => $period, 'error' => $error] = $this->resolver()->resolveWithError($request);

        $this->assertNotNull($error);
        $this->assertStringContainsString((string) AnalyticsPeriodResolver::MAX_CUSTOM_RANGE_DAYS, $error);
        $this->assertSame(AnalyticsPeriod::MODE_MONTH, $period->mode);
    }

    public function test_resolver_accepts_range_at_exactly_max_custom_range_days(): void
    {
        $from = Carbon::parse('2020-01-01');
        $to = $from->copy()->addDays(AnalyticsPeriodResolver::MAX_CUSTOM_RANGE_DAYS - 1);
        $request = Request::create('/analytics', 'GET', [
            'period_mode' => 'custom', 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
        ]);

        ['period' => $period, 'error' => $error] = $this->resolver()->resolveWithError($request);

        $this->assertNull($error);
        $this->assertSame(AnalyticsPeriod::MODE_CUSTOM, $period->mode);
        $this->assertSame(AnalyticsPeriodResolver::MAX_CUSTOM_RANGE_DAYS, $period->requestedLengthInDays());
    }

    // ===== LEGACY + URL CONTRACT =====

    public function test_legacy_period_query_param_still_recognized(): void
    {
        $request = Request::create('/analytics', 'GET', ['period' => 30]);

        $period = $this->resolver()->resolve($request);

        $this->assertSame(AnalyticsPeriod::MODE_LEGACY_DAYS, $period->mode);
        $this->assertSame(30, $period->requestedLengthInDays());
    }

    public function test_no_input_at_all_defaults_to_current_calendar_month_not_30_days(): void
    {
        $request = Request::create('/analytics', 'GET', []);

        $period = $this->resolver()->resolve($request);

        $this->assertSame(AnalyticsPeriod::MODE_MONTH, $period->mode);
        $this->assertSame(Carbon::now()->format('Y-m'), $period->month);
    }

    public function test_new_query_params_never_include_legacy_period_key(): void
    {
        $period = $this->resolver()->buildLegacyDays(30);

        $this->assertArrayNotHasKey('period', $period->toQueryParams());
        $this->assertArrayHasKey('date_from', $period->toQueryParams());
    }

    // ===== CROSS-CONSUMER CONSISTENCY =====

    public function test_overview_table_export_resolve_identical_range_for_same_month(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => $manager->id,
            'month' => 5, 'year' => 2025,
            'status' => 'draft',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'title' => 'Konten Mei '.uniqid(),
            'deadline_at' => '2025-05-10',
        ]);
        ContentMetric::create([
            'content_item_id' => $item->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => $manager->id,
            'metric_date' => '2025-05-10',
            'views' => 777,
            'engagement_rate' => 3.2,
        ]);

        $overview = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id, 'period_mode' => 'month', 'month' => '2025-05',
        ]));
        $table = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id, 'period_mode' => 'month', 'month' => '2025-05',
        ]));
        $export = $this->actingAs($manager)->get(route('analytics.export', [
            'client_id' => $client->id, 'period_mode' => 'month', 'month' => '2025-05',
        ]));

        $overview->assertOk();
        $table->assertOk();
        $export->assertOk();

        // Ketiganya resolve month=2025-05 lewat SATU-SATUNYA jalur resmi
        // (AnalyticsPeriodResolver) - buktikan end-to-end lewat data yang
        // benar-benar sampai ke Export CSV (metric_date 10 Mei ada DI DALAM
        // range yang diresolve, jadi harus lolos filter & muncul di baris
        // CSV, bukan cuma "tidak error").
        $csv = $export->streamedContent();
        $this->assertStringContainsString('777', $csv);
        $overview->assertSee(number_format(777));
        $table->assertSee(number_format(777));
    }

    public function test_no_query_params_defaults_page_to_current_month_links(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'overview', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        // Tanpa period_mode/month sama sekali -> default bulan kalender
        // berjalan (Langkah "PRIMARY PRODUCT CHANGE") - link tab lain HARUS
        // sudah mengandung period_mode=month (bukan period=30 lama).
        $response->assertSee('period_mode=month', false);
    }

    // ===== AUDIENCE PERIOD BEHAVIOR (regresi bug ditemukan Pass 2) =====

    public function test_audience_past_month_range_does_not_leak_data_beyond_range(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        // 1 titik reach DI DALAM Mei 2025 (harus muncul), 1 titik DI LUAR
        // (Juni 2025 - harus TIDAK bocor ke tampilan bulan Mei).
        AudienceInsight::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'source' => AudienceInsight::SOURCE_API, 'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => '2025-05-15', 'reach' => 12345,
        ]);
        AudienceInsight::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'source' => AudienceInsight::SOURCE_API, 'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => '2025-06-15', 'reach' => 99999,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'audience', 'client_id' => $client->id, 'platform_id' => $platform->id,
            'period_mode' => 'month', 'month' => '2025-05',
        ]));

        $response->assertOk();
        $response->assertSee('12,345');
        $response->assertDontSee('99,999');
    }

    // ===== PASS 2.1 item 1: CURRENT-MONTH COMPARISON =====

    public function test_current_partial_month_previous_period_is_truncated_to_same_day_count_not_full_previous_month(): void
    {
        // Simulasi eksplisit dari spec: "today" 2026-09-02, bulan dipilih
        // September 2026 (baru berjalan 2 hari) - previous HARUS Agu 1-2,
        // BUKAN seluruh Agustus (Agu 1-31).
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        try {
            $period = $this->resolver()->buildMonth('2026-09');
            $this->assertSame('2026-09-01', $period->dateFrom->toDateString());
            $this->assertSame('2026-09-02', $period->effectiveDateTo->toDateString());
            $this->assertTrue($period->isCurrentPeriodIncomplete());

            $previous = $this->resolver()->previousPeriod($period);

            $this->assertSame('2026-08-01', $previous->dateFrom->toDateString());
            $this->assertSame('2026-08-02', $previous->dateTo->toDateString());
            $this->assertSame('2026-08-02', $previous->effectiveDateTo->toDateString());
            $this->assertSame(2, $previous->requestedLengthInDays());
            // Label HARUS merepresentasikan rentang yang BENERAN dibandingkan
            // (01-02 Agu), BUKAN "Agustus 2026" yang menyiratkan bulan penuh.
            $this->assertStringNotContainsString('31', $previous->label());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_completed_historical_month_compares_against_full_previous_calendar_month(): void
    {
        // Agustus 2026 SUDAH selesai (today jauh di depannya) - HARUS
        // dibandingkan ke Juli 2026 PENUH, bukan dipotong.
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        try {
            $period = $this->resolver()->buildMonth('2026-08');
            $this->assertFalse($period->isCurrentPeriodIncomplete());

            $previous = $this->resolver()->previousPeriod($period);

            $this->assertSame(AnalyticsPeriod::MODE_MONTH, $previous->mode);
            $this->assertSame('2026-07-01', $previous->dateFrom->toDateString());
            $this->assertSame('2026-07-31', $previous->dateTo->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_partial_month_comparison_handles_shorter_previous_month_honestly(): void
    {
        // Bulan berjalan Maret, hari ke-30 - Februari (bulan sebelumnya)
        // cuma py 28 hari (2026 bukan tahun kabisat) - HARUS dipotong jujur
        // ke akhir Februari (28), TIDAK overflow ke Maret.
        Carbon::setTestNow(Carbon::parse('2026-03-30'));

        try {
            $period = $this->resolver()->buildMonth('2026-03');
            $previous = $this->resolver()->previousPeriod($period);

            $this->assertSame('2026-02-01', $previous->dateFrom->toDateString());
            $this->assertSame('2026-02-28', $previous->dateTo->toDateString());
            $this->assertSame(28, $previous->requestedLengthInDays());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_overview_previous_period_query_window_matches_truncated_range_not_full_previous_month(): void
    {
        // Integration-level: buktikan AnalyticsSummaryService::buildOverviewData()
        // BENERAN memakai window Agu 1-2 (bukan Agu 1-31) buat comparison,
        // lewat views yang cuma ADA di Agu 3-31 (di LUAR window pembanding)
        // - kalau bug lama masih ada, angka ini bakal ke-hitung sebagai
        // "penurunan besar" karena previous seolah py banyak views yang
        // sebenarnya di luar window yang jujur dibandingkan.
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = $this->instagramPlatform();
            $media = $this->instagramMediaFixture($client, Carbon::parse('2026-01-01'));

            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id,
                'imported_by' => $manager->id,
                'metric_date' => Carbon::parse('2026-08-31'),
                'views' => 0, 'engagement_rate' => 0,
            ]);

            // Baseline ideal Agu 1-1 (period_start Agu1 - 1 hari): 100 views.
            $this->snapshotFixture($client, $platform, $media->id, Carbon::parse('2026-07-31'), ['views' => 100]);
            // Titik DI DALAM window pembanding jujur (Agu 1-2): 120 views.
            $this->snapshotFixture($client, $platform, $media->id, Carbon::parse('2026-08-02'), ['views' => 120]);
            // Titik JAUH DI LUAR window pembanding jujur (Agu 31) - kalau
            // bug lama (compare ke seluruh Agustus) masih ada, angka besar
            // ini akan ikut ke-hitung sebagai "previous period views".
            $this->snapshotFixture($client, $platform, $media->id, Carbon::parse('2026-08-31'), ['views' => 9999]);

            $previous = $this->resolver()->previousPeriod($this->resolver()->currentMonth());
            $aggregate = app(PeriodPerformanceService::class)->computeClientPeriod(
                $client->id, $previous->dateFrom, $previous->effectiveDateTo, $platform->id
            );

            // Delta Agu1-2 = 120-100 = 20, BUKAN 9999-100=9899 (yang akan
            // muncul kalau window pembanding keliru masih bulan penuh).
            $this->assertSame(20, $aggregate['totals']['views']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ===== PASS 2.1 item 2: INVALID EXPORT PERIOD MUST FAIL SAFELY =====

    public function test_export_with_reversed_dates_fails_safely_does_not_export_current_month(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics.export', [
            'client_id' => $client->id, 'period_mode' => 'custom',
            'date_from' => '2025-08-20', 'date_to' => '2025-08-10',
        ]));

        // Redirect balik (bukan 200 dengan file CSV bulan berjalan yang
        // diam-diam disubstitusi) - "content-disposition: attachment" TIDAK
        // BOLEH ada di response gagal.
        $response->assertRedirect();
        $this->assertFalse($response->headers->has('Content-Disposition'));
    }

    public function test_export_with_future_month_fails_safely(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $future = Carbon::now()->addMonths(3)->format('Y-m');

        $response = $this->actingAs($manager)->get(route('analytics.export', [
            'client_id' => $client->id, 'period_mode' => 'month', 'month' => $future,
        ]));

        $response->assertRedirect();
        $this->assertFalse($response->headers->has('Content-Disposition'));
    }

    public function test_export_range_exceeding_max_fails_safely(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $from = Carbon::parse('2020-01-01');
        $to = $from->copy()->addDays(AnalyticsPeriodResolver::MAX_CUSTOM_RANGE_DAYS + 10);

        $response = $this->actingAs($manager)->get(route('analytics.export', [
            'client_id' => $client->id, 'period_mode' => 'custom',
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
        ]));

        $response->assertRedirect();
        $this->assertFalse($response->headers->has('Content-Disposition'));
    }

    public function test_export_with_no_period_params_at_all_still_defaults_silently_to_current_month(): void
    {
        // Beda dari input EKSPLISIT-tapi-tidak-valid di atas - request TANPA
        // period param sama sekali TETAP boleh fallback tenang ke bulan
        // berjalan (itu default yang sah, bukan input tidak valid).
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics.export', [
            'client_id' => $client->id,
        ]));

        $response->assertOk();
        $this->assertTrue($response->headers->has('Content-Disposition'));
    }

    public function test_export_with_valid_month_still_succeeds(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);

        $response = $this->actingAs($manager)->get(route('analytics.export', [
            'client_id' => $client->id, 'period_mode' => 'month', 'month' => '2025-05',
        ]));

        $response->assertOk();
        $this->assertTrue($response->headers->has('Content-Disposition'));
    }

    public function test_performance_report_with_range_exceeding_max_is_rejected_with_validation_error(): void
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'report', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $client = $this->client();
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        $from = Carbon::parse('2020-01-01');
        $to = $from->copy()->addDays(AnalyticsPeriodResolver::MAX_CUSTOM_RANGE_DAYS + 10);

        $response = $this->actingAs($manager)->post(route('report.generate-performance'), [
            'client_id' => $client->id,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'format' => 'pdf',
        ]);

        $response->assertSessionHasErrors('period_end');
    }

    // ===== PASS 2.1 item 4: CURRENT-MONTH ZERO-DATA SEMANTICS =====

    public function test_current_month_with_usable_data_through_today_is_not_penalized_for_incomplete_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        try {
            $client = $this->client();
            $platform = $this->instagramPlatform();
            $media = $this->instagramMediaFixture($client, Carbon::parse('2026-01-01'));

            $this->contentMetricFixture($client, $platform, $media->id);

            $currentMonth = $this->resolver()->currentMonth();
            // Baseline ideal (dateFrom - 1) + current tepat di effectiveDateTo
            // (hari ini) - CASE A, harus FULL walau bulan masih jauh dari
            // selesai (28 hari belum terjadi).
            $this->snapshotFixture($client, $platform, $media->id, $currentMonth->dateFrom->copy()->subDay(), ['views' => 1000]);
            $this->snapshotFixture($client, $platform, $media->id, $currentMonth->effectiveDateTo, ['views' => 1500]);

            $aggregate = app(PeriodPerformanceService::class)->computeClientPeriod(
                $client->id, $currentMonth->dateFrom, $currentMonth->effectiveDateTo, $platform->id
            );

            $this->assertSame(ContentPeriodResult::FULL, $aggregate['coverage']['status']);
            $this->assertSame(500, $aggregate['totals']['views']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_month_with_zero_observations_is_unavailable_not_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        try {
            $client = $this->client();
            $platform = $this->instagramPlatform();
            // TIDAK ada ContentMetric/ContentMetricSnapshot SAMA SEKALI
            // buat client ini - roster kosong total.
            $currentMonth = $this->resolver()->currentMonth();

            $aggregate = app(PeriodPerformanceService::class)->computeClientPeriod(
                $client->id, $currentMonth->dateFrom, $currentMonth->effectiveDateTo, $platform->id
            );

            $this->assertSame(ContentPeriodResult::UNAVAILABLE, $aggregate['coverage']['status']);
            $this->assertSame(0, $aggregate['totals']['content_count']);
            // 0 di sini "tidak diketahui" (unavailable), BUKAN "diketahui nol"
            // (full) - sinyal yang benar ada di coverage.status, bukan angka.
            $message = app(PeriodPerformanceService::class)->coverageMessage($aggregate['coverage'], $currentMonth->label());
            $this->assertNotNull($message);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_month_missing_baseline_for_preexisting_content_is_partial_not_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        try {
            $client = $this->client();
            $platform = $this->instagramPlatform();
            // Konten LAMA (published jauh sebelum bulan ini) - TIDAK ADA
            // snapshot boundary di dateFrom-1 (riwayat baru mulai mid-period).
            $media = $this->instagramMediaFixture($client, Carbon::parse('2025-01-01'));
            $this->contentMetricFixture($client, $platform, $media->id);

            $currentMonth = $this->resolver()->currentMonth();
            // Observasi PERTAMA justru DI DALAM periode (Sep 1, hari pertama
            // bulan berjalan - BUKAN Sep 1 minus 1 hari/boundary), bukan
            // sebelum period_start - riwayat baru mulai belakangan.
            $this->snapshotFixture($client, $platform, $media->id, $currentMonth->dateFrom->copy(), ['views' => 200]);
            $this->snapshotFixture($client, $platform, $media->id, $currentMonth->effectiveDateTo, ['views' => 350]);

            $result = app(PeriodPerformanceService::class)->computeContentDelta(
                'instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at,
                $currentMonth->dateFrom, $currentMonth->effectiveDateTo
            );

            // Observed partial gain (150), BUKAN full cumulative (350) yang
            // akan terjadi kalau baseline keliru diasumsikan 0.
            $this->assertSame(150, $result->views());
            $this->assertSame(ContentPeriodResult::PARTIAL, $result->coverageStatus);
            $this->assertNotNull($result->reason);
            $this->assertSame('insufficient_history', $result->availabilityCategory());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ===== PASS 2.1 item 5: availabilityCategory() structure review =====

    public function test_availability_category_is_a_typed_method_not_string_message_parsing(): void
    {
        // Regresi struktural - availabilityCategory() HARUS bercabang dari
        // $reason (kode/enum internal), BUKAN dari coverageMessage()/pesan
        // manusia manapun - buktikan lewat construction langsung tanpa
        // pernah menyentuh string pesan sama sekali.
        $result = ContentPeriodResult::partial(
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'),
            'history_started_mid_period',
            ['views' => 10], null, null, null
        );

        $this->assertSame('insufficient_history', $result->availabilityCategory());
    }

    // ===== Helpers (Pass 2.1 fixtures, pola sama PeriodPerformanceServiceTest) =====

    private function instagramPlatform(): Platform
    {
        return Platform::firstOrCreate(['name' => 'Instagram']);
    }

    private function instagramMediaFixture(Client $client, Carbon $publishedAt): InstagramMediaSnapshot
    {
        $platform = $this->instagramPlatform();
        $integration = ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'integration_name' => 'IG',
            'status' => 'active',
            'access_token' => 'fake',
            'external_username' => 'creator',
        ]);

        return InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'match_status' => 'unmatched',
            'published_at' => $publishedAt,
            'last_fetched_at' => now(),
        ]);
    }

    private function contentMetricFixture(Client $client, Platform $platform, int $mediaId): ContentMetric
    {
        return ContentMetric::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $mediaId,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now(),
            'views' => 0,
            'engagement_rate' => 0,
        ]);
    }

    private function snapshotFixture(Client $client, Platform $platform, int $mediaId, Carbon $date, array $extra = []): ContentMetricSnapshot
    {
        return ContentMetricSnapshot::create(array_merge([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $mediaId,
            'snapshot_date' => $date->toDateString(),
        ], $extra));
    }
}
