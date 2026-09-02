<?php

namespace Tests\Feature;

use App\Services\InstagramAnalyticsSyncService;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi Phase 1 item 4 - default content ingestion lookback HARUS exact
 * 90 hari (config *_default_sync_days), BUKAN lagi "2 bulan"
 * (*_default_sync_months, dihapus). Historical/manual --month sync TIDAK
 * disentuh sama sekali (masih per-bulan seperti sebelumnya).
 */
class SyncDefaultLookbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_default_sync_window_looks_back_exactly_90_days(): void
    {
        [$syncMode, $since, $until] = app(InstagramAnalyticsSyncService::class)->resolveSyncWindow(null);

        $this->assertSame('default', $syncMode);
        $this->assertSame(now()->subDays(90)->toDateString(), $since->toDateString());
        $this->assertSame('00:00:00', $since->format('H:i:s'));
    }

    public function test_tiktok_default_sync_window_looks_back_exactly_90_days(): void
    {
        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow(null);

        $this->assertSame('default', $syncMode);
        $this->assertSame(now()->subDays(90)->toDateString(), $since->toDateString());
        $this->assertSame('00:00:00', $since->format('H:i:s'));
    }

    public function test_instagram_historical_month_sync_unaffected_by_default_lookback_change(): void
    {
        [$syncMode, $since, $until] = app(InstagramAnalyticsSyncService::class)->resolveSyncWindow('2026-07');

        $this->assertSame('historical', $syncMode);
        $this->assertSame('2026-07-01', $since->toDateString());
        $this->assertSame('2026-07-31', $until->toDateString());
    }

    public function test_tiktok_historical_month_sync_unaffected_by_default_lookback_change(): void
    {
        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow('2026-07');

        $this->assertSame('historical', $syncMode);
        $this->assertSame('2026-07-01', $since->toDateString());
        $this->assertSame('2026-07-31', $until->toDateString());
    }

    public function test_config_uses_days_semantics_not_months(): void
    {
        $this->assertSame(90, config('analytics.instagram_default_sync_days'));
        $this->assertSame(90, config('analytics.tiktok_default_sync_days'));
        $this->assertNull(config('analytics.instagram_default_sync_months'));
        $this->assertNull(config('analytics.tiktok_default_sync_months'));
    }
}
