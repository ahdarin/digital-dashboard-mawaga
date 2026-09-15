<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use App\Services\PeriodPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Audit sync horizon + snapshot retention - content_metric_snapshots
 * disimpan ROLLING 120 hari (bukan 90), retention TIDAK memotong 91-120
 * hari (buffer buat baseline boundary/scheduler gap/API down). Semantik
 * cutoff: age 0-119 dipertahankan, age >= 120 dihapus (DELETE WHERE
 * snapshot_date <= today - 120 hari, INKLUSIF di batas 120) - lihat
 * docblock PruneContentMetricSnapshots buat penjelasan lengkap.
 */
class PruneContentMetricSnapshotsTest extends TestCase
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

    private function snapshotAtAge(Client $client, Platform $platform, int $ageDays, ?int $instagramMediaSnapshotId = null): ContentMetricSnapshot
    {
        return ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $instagramMediaSnapshotId,
            'snapshot_date' => now()->subDays($ageDays)->toDateString(),
            'views' => 100 + $ageDays,
        ]);
    }

    public function test_snapshot_age_90_retained(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $snapshot = $this->snapshotAtAge($client, $platform, 90);

        Artisan::call('analytics:prune-content-metric-snapshots');

        $this->assertDatabaseHas('content_metric_snapshots', ['id' => $snapshot->id]);
    }

    public function test_snapshot_age_91_retained(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $snapshot = $this->snapshotAtAge($client, $platform, 91);

        Artisan::call('analytics:prune-content-metric-snapshots');

        $this->assertDatabaseHas('content_metric_snapshots', ['id' => $snapshot->id]);
    }

    public function test_snapshot_age_119_retained(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $snapshot = $this->snapshotAtAge($client, $platform, 119);

        Artisan::call('analytics:prune-content-metric-snapshots');

        $this->assertDatabaseHas('content_metric_snapshots', ['id' => $snapshot->id]);
    }

    /**
     * Boundary age=120 - eksplisit DIHAPUS (bukan dipertahankan). Retention
     * "120 hari" berarti TEPAT 120 distinct calendar dates dipertahankan
     * (age 0-119), konsisten dengan contoh rolling resmi fitur ini ("day
     * 121 masuk -> delete oldest day 1 (age 120) -> days 2-121 remain").
     */
    public function test_snapshot_age_120_boundary_pruned(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $snapshot = $this->snapshotAtAge($client, $platform, 120);

        Artisan::call('analytics:prune-content-metric-snapshots');

        $this->assertDatabaseMissing('content_metric_snapshots', ['id' => $snapshot->id]);
    }

    public function test_snapshot_older_than_120_pruned(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $snapshot = $this->snapshotAtAge($client, $platform, 200);

        Artisan::call('analytics:prune-content-metric-snapshots');

        $this->assertDatabaseMissing('content_metric_snapshots', ['id' => $snapshot->id]);
    }

    public function test_pruning_does_not_touch_current_metric_or_source_snapshot_rows(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = ApiIntegration::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake',
        ]);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'caption' => 'Test',
            'match_status' => 'unmatched',
            'published_at' => now()->subDays(200),
            'last_fetched_at' => now(),
        ]);
        $metric = ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id,
            'imported_by' => \App\Models\User::factory()->create()->id,
            'metric_date' => now()->subDays(200),
            'views' => 9999,
        ]);
        // Snapshot histori yang genuinely lebih tua dari retention.
        $oldSnapshot = $this->snapshotAtAge($client, $platform, 200, $media->id);

        Artisan::call('analytics:prune-content-metric-snapshots');

        $this->assertDatabaseMissing('content_metric_snapshots', ['id' => $oldSnapshot->id]);
        // ContentMetric (current/latest state) TIDAK disentuh.
        $this->assertDatabaseHas('content_metrics', ['id' => $metric->id, 'views' => 9999]);
        // Source content identity (InstagramMediaSnapshot) TIDAK disentuh.
        $this->assertDatabaseHas('instagram_media_snapshots', ['id' => $media->id]);
    }

    /**
     * Pruning TIDAK BOLEH merusak perhitungan boundary 90-hari yang valid -
     * baseline (age 91, TEPAT 1 hari sebelum period_start periode 90 hari)
     * HARUS tetap ada setelah pruning (91 < 120 retention), sehingga
     * PeriodPerformanceService masih bisa menghitung delta full coverage.
     */
    public function test_pruning_does_not_break_valid_90_day_boundary_calculation(): void
    {
        $client = $this->client();
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $integration = ApiIntegration::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake',
        ]);
        $media = InstagramMediaSnapshot::create([
            'api_integration_id' => $integration->id,
            'external_post_id' => 'ig-'.uniqid(),
            'caption' => 'Test',
            'match_status' => 'unmatched',
            'published_at' => now()->subDays(200),
            'last_fetched_at' => now(),
        ]);
        ContentMetric::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id,
            'imported_by' => \App\Models\User::factory()->create()->id,
            'metric_date' => now()->subDays(200),
            'views' => 5000,
        ]);

        $periodStart = now()->subDays(89)->startOfDay(); // 90-day window: today-89 .. today
        $periodEnd = now()->startOfDay();

        // Baseline TEPAT 1 hari sebelum period_start (age 90 - boundary
        // ideal) + current di period_end (age 0).
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $periodStart->copy()->subDay()->toDateString(), 'views' => 1000,
        ]);
        ContentMetricSnapshot::create([
            'client_id' => $client->id, 'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $media->id,
            'snapshot_date' => $periodEnd->toDateString(), 'views' => 4200,
        ]);

        Artisan::call('analytics:prune-content-metric-snapshots');

        $result = app(PeriodPerformanceService::class)->computeClientPeriod($client->id, $periodStart, $periodEnd, null);

        $this->assertSame(3200, $result['totals']['views'], '4200-1000=3200 - baseline age 90 (< 120 retention) harus tetap ada setelah pruning.');
        $this->assertSame('full', $result['coverage']['status']);
    }

    /**
     * RETENTION POLICY DECISION (Langkah audit "sync bulan tertentu tidak
     * ada loading/hilang setelah 1 minggu?") - jadwal otomatis prune
     * SEMPAT sengaja dinonaktifkan (retention policy belum final,
     * deletion irreversible) sampai ada keputusan eksplisit. Keputusan
     * itu sekarang sudah diambil: retensi 120 hari rolling DIAKTIFKAN
     * (lihat routes/console.php + docblock content_metric_snapshot_
     * retention_days di config/analytics.php buat alasan lengkap) sebagai
     * jawaban ke kekhawatiran storage tumbuh tak terbatas, TANPA
     * menghapus ContentMetric/identitas konten - tes ini (dulu memastikan
     * jadwal TIDAK aktif) sekarang membalik arah, memastikan jadwal itu
     * BENAR-BENAR terdaftar dengan waktu yang diharapkan - regresi diam-
     * diam (baris ke-comment lagi, atau jam berubah tanpa sadar) HARUS
     * ketahuan di sini, bukan cuma dari isi routes/console.php sebagai teks.
     */
    public function test_automatic_prune_schedule_is_registered_and_active(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $event = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'analytics:prune-content-metric-snapshots'));

        $this->assertNotNull($event, 'analytics:prune-content-metric-snapshots HARUS terdaftar di scheduler (retensi 120 hari sekarang aktif).');
        $this->assertSame('0 3 * * *', $event->expression, 'Harus dailyAt(03:00) - digeser 15 menit sebelum analytics:auto-sync (03:15) biar tidak tumpang tindih.');
        $this->assertSame(config('app.timezone'), $event->timezone, 'Timezone HARUS eksplisit dari config(app.timezone), konsisten dengan analytics:auto-sync.');

        // Cuma SATU jadwal terdaftar buat prune command ini - jangan sampai
        // ada duplikasi baris Schedule:: yang bikin dijalankan 2x/hari.
        $pruneEvents = collect($events)->filter(fn ($e) => str_contains($e->command ?? '', 'analytics:prune-content-metric-snapshots'));
        $this->assertCount(1, $pruneEvents, 'HARUS cuma 1 jadwal otomatis buat prune command ini.');
    }

    public function test_pruning_is_scoped_to_content_metric_snapshots_only(): void
    {
        // Sanity - pastikan command benar-benar cuma query 1 tabel, tidak
        // ada efek samping ke tabel lain yang tidak dites eksplisit di atas
        // (AudienceInsight/AnalyticsSyncLog/ContentItem) - constructor
        // command hanya bergantung ke ContentMetricSnapshot::class.
        $reflection = new \ReflectionClass(\App\Console\Commands\PruneContentMetricSnapshots::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('AudienceInsight::', $source);
        $this->assertStringNotContainsString('AnalyticsSyncLog::', $source);
        $this->assertStringNotContainsString('ContentItem::', $source);
        $this->assertStringNotContainsString('->truncate(', $source);
        $this->assertStringNotContainsString('DB::statement', $source);
    }
}
