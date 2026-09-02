<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\ContentPeriodResult;
use App\Services\PeriodPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Regresi Phase 3 (Period Performance Calculation Engine) - test 1-13 dari
 * spesifikasi (unit-level, langsung ke PeriodPerformanceService::computeContentDelta()
 * / computeDailyGainSeries(), tanpa perlu simulasi sync API penuh - snapshot
 * dibuat langsung lewat model karena engine ini murni baca content_metric_snapshots).
 *
 * Integration-level test (Overview/Table/Export/Content Detail/Dashboard/
 * Report/AI Strategy/Anomaly pakai engine ini) ada di file terpisah per
 * consumer (test 14-25).
 */
class PeriodPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PeriodPerformanceService
    {
        return app(PeriodPerformanceService::class);
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

    private function instagramMedia(Client $client, ?Carbon $publishedAt = null): InstagramMediaSnapshot
    {
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
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
            'published_at' => $publishedAt ?? now()->subDays(200),
            'last_fetched_at' => now(),
        ]);
    }

    private function tiktokVideo(Client $client, ?Carbon $publishedAt = null): TikTokVideoSnapshot
    {
        $platform = Platform::firstOrCreate(['name' => 'TikTok']);
        $integration = ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'integration_name' => 'TT',
            'status' => 'active',
            'access_token' => 'fake',
            'external_username' => 'creator',
        ]);

        return TikTokVideoSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'tt-'.uniqid(),
            'match_status' => 'unmatched',
            'published_at' => $publishedAt ?? now()->subDays(200),
            'last_fetched_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function snapshot(Client $client, Platform $platform, string $identityColumn, int $identityId, Carbon $date, array $extra = []): ContentMetricSnapshot
    {
        return ContentMetricSnapshot::create(array_merge([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            $identityColumn => $identityId,
            'snapshot_date' => $date->toDateString(),
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function contentMetric(Client $client, Platform $platform, array $extra = []): ContentMetric
    {
        return ContentMetric::create(array_merge([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'imported_by' => User::factory()->create()->id,
            'metric_date' => now()->subDays(200),
            'views' => 0,
            'engagement_rate' => 0,
        ], $extra));
    }

    private function instagramPlatform(): Platform
    {
        return Platform::firstOrCreate(['name' => 'Instagram']);
    }

    private function tiktokPlatform(): Platform
    {
        return Platform::firstOrCreate(['name' => 'TikTok']);
    }

    // ===== 1: Valid full period: baseline boundary + current -> exact delta =====

    public function test_full_period_with_ideal_boundary_baseline_and_current_gives_exact_delta(): void
    {
        $client = $this->client();
        $media = $this->instagramMedia($client, now()->subDays(200));
        $platform = $this->instagramPlatform();

        $periodStart = now()->subDays(6)->startOfDay(); // 7-day window: start..end inclusive = 7 days
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 8000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 10000]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertSame(ContentPeriodResult::FULL, $result->coverageStatus);
        $this->assertSame(2000, $result->views());
        $this->assertNull($result->reason);
    }

    // ===== 2: Published inside period: baseline zero legitimate =====

    public function test_content_published_inside_period_uses_legitimate_zero_baseline(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();
        $publishedAt = now()->subDays(3); // di dalam periode

        $media = $this->instagramMedia($client, $publishedAt);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 500]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $publishedAt, $periodStart, $periodEnd);

        $this->assertSame(ContentPeriodResult::FULL, $result->coverageStatus);
        $this->assertSame(500, $result->views(), 'Baseline 0 legitimate (konten belum ada sebelum publish) - delta = current cumulative.');
    }

    // ===== 3: Old content + no boundary baseline: NOT baseline zero =====

    public function test_old_content_without_boundary_baseline_does_not_assume_zero(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $publishedAt = now()->subDays(200); // lama, jauh sebelum periode
        $media = $this->instagramMedia($client, $publishedAt);

        $periodStart = now()->subDays(29)->startOfDay();
        $periodEnd = now()->startOfDay();

        // TIDAK ADA snapshot sebelum period_start sama sekali - snapshot
        // pertama justru DI DALAM periode (riwayat baru mulai belakangan).
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, now()->subDays(5), ['views' => 20000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 20400]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $publishedAt, $periodStart, $periodEnd);

        // Delta HARUS 400 (20400-20000, observed gain sejak riwayat mulai),
        // BUKAN 20400 (yang akan terjadi kalau baseline keliru diasumsikan 0).
        $this->assertSame(400, $result->views());
        $this->assertSame(ContentPeriodResult::PARTIAL, $result->coverageStatus);
        $this->assertSame('history_started_mid_period', $result->reason);
    }

    // ===== 4: Old content + first snapshot mid-period: partial coverage =====

    public function test_first_snapshot_mid_period_gives_partial_coverage_with_correct_coverage_from(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(29)->startOfDay();
        $periodEnd = now()->startOfDay();
        $firstObservation = now()->subDays(10);

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $firstObservation, ['views' => 1000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 1300]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertSame(ContentPeriodResult::PARTIAL, $result->coverageStatus);
        $this->assertSame($firstObservation->toDateString(), $result->coverageFrom->toDateString());
        $this->assertSame(300, $result->views());
    }

    // ===== 5: Latest snapshot before period end: partial, not full =====

    public function test_latest_snapshot_before_period_end_is_partial_not_full(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 8000]);
        // Observasi terakhir KEMARIN, bukan hari ini (period_end).
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd->copy()->subDay(), ['views' => 9500]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertSame(ContentPeriodResult::PARTIAL, $result->coverageStatus);
        $this->assertSame('current_before_period_end', $result->reason);
        $this->assertSame(1500, $result->views());
    }

    // ===== 6: Negative cumulative correction: not silently zero =====

    public function test_negative_cumulative_delta_is_not_silently_clamped_to_zero(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 10000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 9500]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        // Phase 3.1 correction: metric reset TIDAK LAGI membuat SELURUH
        // content unavailable - views (metric yang benar2 kena reset) jadi
        // NULL (bukan angka negatif/di-clamp 0), tapi content-nya sendiri
        // tetap 'usable' sebagai 'partial' (bukan 'unavailable') dengan
        // reason yang menandai ada koreksi data.
        $this->assertSame(ContentPeriodResult::PARTIAL, $result->coverageStatus);
        $this->assertSame('metric_reset_or_correction', $result->reason);
        $this->assertNull($result->delta['views'], 'Views yang kena reset harus NULL (tidak diketahui), bukan angka negatif atau di-clamp ke 0.');
    }

    // ===== Phase 3.1 - negative delta scope harus period-relevant, bukan lifetime scan =====

    public function test_historical_correction_before_baseline_does_not_invalidate_current_period(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        // Koreksi Juni (jauh SEBELUM baseline periode ini) - TIDAK relevan
        // buat periode September, tidak boleh membuat September unavailable
        // atau partial-karena-reset.
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, now()->subDays(90), ['views' => 10000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, now()->subDays(89), ['views' => 9500]);

        $periodStart = now()->subDays(29)->startOfDay();
        $periodEnd = now()->startOfDay();
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 15000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 18000]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertSame(ContentPeriodResult::FULL, $result->coverageStatus);
        $this->assertNull($result->reason);
        $this->assertSame(3000, $result->delta['views']);
    }

    public function test_correction_within_relevant_interval_is_detected(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(9)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 15000]);
        // Penurunan DI TENGAH interval yang relevan (antara baseline & current)
        // harus tetap ketahuan walau endpoint akhir lebih tinggi dari awal.
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->addDays(4), ['views' => 12000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 18000]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertSame(ContentPeriodResult::PARTIAL, $result->coverageStatus);
        $this->assertSame('metric_reset_or_correction', $result->reason);
        $this->assertNull($result->delta['views']);
    }

    public function test_likes_correction_does_not_fabricate_likes_zero(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 10000, 'likes' => 500, 'reach' => 8000, 'comments' => 10, 'shares' => 2, 'saves' => 3]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 11000, 'likes' => 490, 'reach' => 8600, 'comments' => 15, 'shares' => 3, 'saves' => 4]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertNull($result->delta['likes'], 'Likes yang turun (correction) harus NULL, bukan angka negatif atau dipaksa 0.');
        $this->assertNull($result->engagementRate, 'engagement_rate butuh likes sebagai komponen wajib - kalau likes NULL, seluruh engagement_rate periode ini NULL.');
    }

    public function test_valid_views_remain_usable_when_unrelated_engagement_component_is_corrected(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 10000, 'likes' => 500]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 11000, 'likes' => 490]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        // Views TETAP valid & content tetap usable walau likes kena koreksi -
        // TIDAK boleh dibuang begitu saja karena metric lain yang bermasalah.
        $this->assertTrue($result->isUsable());
        $this->assertSame(ContentPeriodResult::PARTIAL, $result->coverageStatus, 'Coverage harus turun jadi partial (bukan diam-diam tetap full) begitu ada koreksi terdeteksi.');
        $this->assertSame(1000, $result->delta['views']);
        $this->assertNull($result->delta['likes']);
    }

    // ===== 7: Repeated same-day snapshot does not affect delta incorrectly =====

    public function test_repeated_same_day_snapshot_upsert_does_not_duplicate_or_skew_delta(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 8000]);
        // Same-day upsert (sync dijalankan 2x hari ini) - updateOrCreate di
        // recordSnapshot() Phase 2 sudah menjamin 1 baris per hari, di sini
        // langsung update baris yang sama (bukan create baris ke-2).
        $today = ContentMetricSnapshot::updateOrCreate(
            ['client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id, 'snapshot_date' => $periodEnd->toDateString()],
            ['views' => 9800]
        );
        ContentMetricSnapshot::updateOrCreate(
            ['client_id' => $client->id, 'platform_id' => $platform->id, 'instagram_media_snapshot_id' => $media->id, 'snapshot_date' => $periodEnd->toDateString()],
            ['views' => 10000]
        );

        $this->assertSame(1, ContentMetricSnapshot::where('instagram_media_snapshot_id', $media->id)->where('snapshot_date', $periodEnd->toDateString())->count());

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertSame(2000, $result->views(), 'Harus pakai nilai TERAKHIR (10000) dari baris yang sama, bukan 9800 atau jumlah keduanya.');
    }

    // ===== 8: 7-day != 30-day when history supports difference =====

    public function test_7_day_and_30_day_periods_give_different_results_when_history_supports_it(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, now()->subDays(30), ['views' => 5000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, now()->subDays(7), ['views' => 8000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, now()->startOfDay(), ['views' => 9000]);

        $result7 = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, now()->subDays(6)->startOfDay(), now()->startOfDay());
        $result30 = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, now()->subDays(29)->startOfDay(), now()->startOfDay());

        $this->assertSame(1000, $result7->views(), '9000-8000 (baseline tepat di boundary ideal 7 hari).');
        $this->assertSame(4000, $result30->views(), '9000-5000 (baseline tepat di boundary ideal 30 hari).');
        $this->assertNotSame($result7->views(), $result30->views());
    }

    // ===== 9: 90-day period works =====

    public function test_90_day_period_works(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(89)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), ['views' => 1000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 4500]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertSame(ContentPeriodResult::FULL, $result->coverageStatus);
        $this->assertSame(3500, $result->views());
    }

    // ===== 10: Missing daily snapshots do not fabricate daily distribution =====

    public function test_missing_daily_snapshots_do_not_fabricate_daily_distribution(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(4)->startOfDay();
        $periodEnd = now()->startOfDay();

        // Snapshot cuma ada di awal & akhir window (bolong 3 hari di tengah).
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart, ['views' => 1000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, ['views' => 2000]);

        $series = $this->service()->computeDailyGainSeries($client->id, $periodStart, $periodEnd);

        $byDate = collect($series)->keyBy('date');

        // Total gain (+1000) TIDAK BOLEH dibagi rata ke hari-hari di
        // tengah - semua hari yang tidak punya pasangan snapshot berurutan
        // harus gap (null), bukan angka yang dikarang.
        foreach ($series as $point) {
            if ($point['date'] !== $periodEnd->toDateString()) {
                $this->assertNull($point['value'], "Tanggal {$point['date']} seharusnya gap (tidak ada pasangan snapshot berurutan).");
                $this->assertTrue($point['has_gap']);
            }
        }

        // periodStart sendiri juga gap dari sudut pandang series ini karena
        // titik SEBELUM periodStart (periodStart - 1 hari) tidak pernah
        // di-snapshot sama sekali di test ini.
        $this->assertTrue($byDate[$periodStart->toDateString()]['has_gap']);
    }

    // ===== 11: Instagram engagement computed from delta raw metrics =====

    public function test_instagram_engagement_rate_computed_from_delta_raw_metrics(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), [
            'reach' => 1000, 'likes' => 50, 'comments' => 10, 'shares' => 5, 'saves' => 5, 'engagement_rate' => 99.99,
        ]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, [
            'reach' => 2000, 'likes' => 150, 'comments' => 30, 'shares' => 15, 'saves' => 15, 'engagement_rate' => 1.11,
        ]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        // reach delta = 1000, interactions delta = (150-50)+(30-10)+(15-5)+(15-5) = 100+20+10+10 = 140
        // engagement = 140/1000*100 = 14.00 - dihitung ULANG dari delta raw,
        // BUKAN subtract/average kolom engagement_rate snapshot (99.99/1.11 sengaja dibuat absurd biar ketahuan kalau ke-pakai).
        $this->assertSame(14.0, $result->engagementRate);
    }

    // ===== 12: TikTok engagement uses its appropriate denominator =====

    public function test_tiktok_engagement_rate_uses_views_denominator_not_instagram_formula(): void
    {
        $client = $this->client();
        $platform = $this->tiktokPlatform();
        $video = $this->tiktokVideo($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        $this->snapshot($client, $platform, 'tiktok_video_snapshot_id', $video->id, $periodStart->copy()->subDay(), [
            'views' => 1000, 'likes' => 50, 'comments' => 10, 'shares' => 5,
        ]);
        $this->snapshot($client, $platform, 'tiktok_video_snapshot_id', $video->id, $periodEnd, [
            'views' => 2000, 'likes' => 150, 'comments' => 30, 'shares' => 15,
        ]);

        $result = $this->service()->computeContentDelta('tiktok', 'tiktok_video_snapshot_id', $video->id, $video->published_at, $periodStart, $periodEnd);

        // views delta = 1000, interactions delta = 100+20+10 = 130 -> 13.00%
        $this->assertSame(13.0, $result->engagementRate);
    }

    // ===== 13: NULL denominator stays NULL =====

    public function test_null_denominator_stays_null_not_zero(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        // reach NULL di baseline (API tidak menyediakan saat itu), views
        // juga NULL kedua titik -> denominator TIDAK BISA dihitung.
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), [
            'reach' => null, 'views' => null, 'likes' => 10,
        ]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, [
            'reach' => null, 'views' => null, 'likes' => 20,
        ]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertNull($result->engagementRate, 'reach & views delta dua-duanya tidak diketahui -> engagement_rate NULL, bukan 0.');

        // TikTok versi: view_count NULL di salah satu titik.
        $platformTt = $this->tiktokPlatform();
        $video = $this->tiktokVideo($client, now()->subDays(200));
        $this->snapshot($client, $platformTt, 'tiktok_video_snapshot_id', $video->id, $periodStart->copy()->subDay(), ['views' => null, 'likes' => 5]);
        $this->snapshot($client, $platformTt, 'tiktok_video_snapshot_id', $video->id, $periodEnd, ['views' => 500, 'likes' => 20]);

        $resultTt = $this->service()->computeContentDelta('tiktok', 'tiktok_video_snapshot_id', $video->id, $video->published_at, $periodStart, $periodEnd);
        $this->assertNull($resultTt->engagementRate, 'views baseline NULL -> denominator delta tidak diketahui -> NULL, bukan 0.');
    }

    // ===== Phase 3.1 - NULL != 0 di NUMERATOR engagement, bukan cuma denominator =====

    public function test_instagram_missing_supported_component_makes_engagement_null(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        // comments NULL di baseline (API tidak menyediakan saat itu) - reach
        // (denominator) & metric lain lengkap. comments TETAP komponen wajib
        // formula Instagram (didukung platform), jadi engagement TIDAK BOLEH
        // dihitung seolah comments=0.
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), [
            'reach' => 1000, 'likes' => 50, 'comments' => null, 'shares' => 5, 'saves' => 5,
        ]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, [
            'reach' => 2000, 'likes' => 150, 'comments' => 30, 'shares' => 15, 'saves' => 15,
        ]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertNull($result->delta['comments']);
        $this->assertNull($result->engagementRate, 'comments (komponen wajib formula Instagram) tidak diketahui di salah satu titik -> engagement NULL, bukan menghitung seolah comments delta=0.');
    }

    public function test_genuine_zero_component_remains_valid_zero_in_engagement(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $media = $this->instagramMedia($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        // saves genuinely 0 di DUA titik (bukan NULL) - observed zero yang
        // sah, harus tetap ikut dihitung normal (bukan dianggap "tidak
        // diketahui" cuma karena nilainya kebetulan nol).
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodStart->copy()->subDay(), [
            'reach' => 1000, 'likes' => 50, 'comments' => 10, 'shares' => 5, 'saves' => 0,
        ]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $media->id, $periodEnd, [
            'reach' => 2000, 'likes' => 150, 'comments' => 30, 'shares' => 15, 'saves' => 0,
        ]);

        $result = $this->service()->computeContentDelta('instagram', 'instagram_media_snapshot_id', $media->id, $media->published_at, $periodStart, $periodEnd);

        $this->assertSame(0, $result->delta['saves']);
        // reach delta=1000, interactions = (150-50)+(30-10)+(15-5)+(0-0) = 100+20+10+0 = 130 -> 13.00%
        $this->assertSame(13.0, $result->engagementRate, 'saves delta genuinely 0 (bukan NULL) harus tetap ikut dihitung normal, bukan membuat engagement NULL.');
    }

    public function test_tiktok_unsupported_saves_does_not_affect_engagement(): void
    {
        $client = $this->client();
        $platform = $this->tiktokPlatform();
        $video = $this->tiktokVideo($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        // saves SENGAJA tidak diisi sama sekali (selalu NULL buat TikTok,
        // integration ini tidak punya field ini) - TIDAK BOLEH membuat
        // engagement TikTok jadi NULL, karena saves memang bukan bagian
        // formula TikTok sama sekali (bukan "komponen wajib yang kebetulan
        // tidak diketahui").
        $this->snapshot($client, $platform, 'tiktok_video_snapshot_id', $video->id, $periodStart->copy()->subDay(), [
            'views' => 1000, 'likes' => 50, 'comments' => 10, 'shares' => 5,
        ]);
        $this->snapshot($client, $platform, 'tiktok_video_snapshot_id', $video->id, $periodEnd, [
            'views' => 2000, 'likes' => 150, 'comments' => 30, 'shares' => 15,
        ]);

        $result = $this->service()->computeContentDelta('tiktok', 'tiktok_video_snapshot_id', $video->id, $video->published_at, $periodStart, $periodEnd);

        $this->assertNull($result->delta['saves'], 'saves TikTok selalu NULL (unsupported), bukan 0.');
        // views delta=1000, interactions = 100+20+10 = 130 -> 13.00% (saves TIDAK ikut dihitung sama sekali)
        $this->assertSame(13.0, $result->engagementRate);
    }

    public function test_tiktok_missing_supported_interaction_makes_engagement_null(): void
    {
        $client = $this->client();
        $platform = $this->tiktokPlatform();
        $video = $this->tiktokVideo($client, now()->subDays(200));

        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        // comments NULL di current (didukung formula TikTok, tapi belum ada
        // observasinya) - engagement TIDAK BOLEH dihitung seolah comments=0.
        $this->snapshot($client, $platform, 'tiktok_video_snapshot_id', $video->id, $periodStart->copy()->subDay(), [
            'views' => 1000, 'likes' => 50, 'comments' => 10, 'shares' => 5,
        ]);
        $this->snapshot($client, $platform, 'tiktok_video_snapshot_id', $video->id, $periodEnd, [
            'views' => 2000, 'likes' => 150, 'comments' => null, 'shares' => 15,
        ]);

        $result = $this->service()->computeContentDelta('tiktok', 'tiktok_video_snapshot_id', $video->id, $video->published_at, $periodStart, $periodEnd);

        $this->assertNull($result->delta['comments']);
        $this->assertNull($result->engagementRate, 'comments (komponen wajib formula TikTok) tidak diketahui -> engagement NULL, bukan dihitung seolah 0.');
    }

    // ===== Phase 3.1 - CSV/manual coverage TIDAK PERNAH otomatis 'full' =====

    public function test_csv_row_is_marked_partial_manual_recorded_not_full(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $periodStart = now()->subDays(29)->startOfDay();
        $periodEnd = now()->startOfDay();

        // 1 baris CSV di tengah periode 30 hari - genuine, TIDAK dikarang,
        // TAPI kehadirannya TIDAK membuktikan seluruh 30 hari tercatat.
        $csvMetric = $this->contentMetric($client, $platform, [
            'metric_date' => $periodStart->copy()->addDays(2),
            'views' => 777,
            'engagement_rate' => 4.4,
        ]);

        $aggregate = $this->service()->computeAggregate(new Collection(), new Collection([$csvMetric]), $periodStart, $periodEnd);

        $row = collect($aggregate['rows'])->first();
        $this->assertSame(ContentPeriodResult::PARTIAL, $row['result']->coverageStatus, 'CSV row TIDAK BOLEH otomatis full - kehadiran 1 baris tidak membuktikan periode penuh tercatat.');
        $this->assertSame('manual_recorded', $row['result']->reason);
        // Angka NUMERIK-nya TETAP APA ADANYA (tidak disentuh/diubah sama sekali).
        $this->assertSame(777, $row['result']->views());
        $this->assertSame(777, $aggregate['totals']['views']);
    }

    // ===== Phase 3.1 - aggregate coverage tidak boleh 'full' kalau ada content partial/unavailable =====

    public function test_aggregate_coverage_reflects_mixed_full_partial_unavailable_content(): void
    {
        $client = $this->client();
        $platform = $this->instagramPlatform();
        $periodStart = now()->subDays(6)->startOfDay();
        $periodEnd = now()->startOfDay();

        // Content A - full boundary (baseline ideal + current tepat period_end).
        $mediaA = $this->instagramMedia($client, now()->subDays(200));
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $mediaA->id, $periodStart->copy()->subDay(), ['views' => 1000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $mediaA->id, $periodEnd, ['views' => 1500]);
        $metricA = $this->contentMetric($client, $platform, ['instagram_media_snapshot_id' => $mediaA->id]);

        // Content B - partial (baseline lebih tua dari ideal boundary).
        $mediaB = $this->instagramMedia($client, now()->subDays(200));
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $mediaB->id, $periodStart->copy()->subDays(3), ['views' => 2000]);
        $this->snapshot($client, $platform, 'instagram_media_snapshot_id', $mediaB->id, $periodEnd, ['views' => 2800]);
        $metricB = $this->contentMetric($client, $platform, ['instagram_media_snapshot_id' => $mediaB->id]);

        // Content C - unavailable (tidak ada observasi current sama sekali).
        $mediaC = $this->instagramMedia($client, now()->subDays(200));
        $metricC = $this->contentMetric($client, $platform, ['instagram_media_snapshot_id' => $mediaC->id]);

        $aggregate = $this->service()->computeAggregate(new Collection([$metricA, $metricB, $metricC]), new Collection(), $periodStart, $periodEnd);

        $this->assertSame(ContentPeriodResult::PARTIAL, $aggregate['coverage']['status'], 'Campuran full+partial+unavailable TIDAK BOLEH dilaporkan sebagai full.');
        $this->assertSame(3, $aggregate['coverage']['total_content']);
        $this->assertSame(2, $aggregate['coverage']['usable_content'], 'Content unavailable dikecualikan dari usable, TAPI tetap muncul di rows (tidak hilang tanpa jejak).');
        // Total views = A(500) + B(800), C dikecualikan sepenuhnya (bukan 0).
        $this->assertSame(1300, $aggregate['totals']['views']);
    }
}
