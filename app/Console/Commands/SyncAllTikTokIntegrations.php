<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\ApiIntegration;
use App\Models\User;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * MIRROR SyncAllInstagramIntegrations - dijadwalkan HARIAN, dispatch sync
 * default (rolling 2 bulan terakhir) buat SEMUA ApiIntegration TikTok yang
 * statusnya active. HANYA default window - historical sync per bulan
 * SELALU manual.
 *
 * Cadence: Daily, disamakan dengan Instagram (Langkah 24, "align reasonably
 * with Instagram") - TikTok video/list dibatasi 10 halaman x 20/halaman per
 * sync (jauh di bawah limit rate umum TikTok API), jadi volume call harian
 * per integration kecil. Tinjau ulang kalau nanti terbukti mendekati batas
 * rate limit TikTok sesungguhnya (belum ada data operasional).
 */
class SyncAllTikTokIntegrations extends Command
{
    protected $signature = 'analytics:sync-all-tiktok';
    protected $description = 'Dispatch sync default (2 bulan terakhir) untuk semua ApiIntegration TikTok yang aktif - dijadwalkan harian';

    public function handle(TikTokAnalyticsSyncService $service): int
    {
        $integrations = ApiIntegration::where('status', 'active')
            ->whereNotNull('access_token')
            ->whereHas('platform', fn ($q) => $q->where('name', 'TikTok'))
            ->get();

        if ($integrations->isEmpty()) {
            $this->info('Tidak ada integrasi TikTok aktif.');
            return self::SUCCESS;
        }

        $userId = User::whereHas('role', fn ($q) => $q->where('name', UserRole::CEO->value))->first()?->id
            ?? User::first()?->id;

        if (! $userId) {
            $this->error('Tidak ada user sama sekali - dibatalkan.');
            return self::FAILURE;
        }

        [$syncMode, $since, $until] = $service->resolveSyncWindow(null);

        $dispatched = 0;
        $skipped = 0;

        foreach ($integrations as $integration) {
            $lock = Cache::lock(SyncTikTokAnalyticsJob::cacheLockKey($integration->id), 10);
            if (! $lock->get()) {
                $this->line("Client {$integration->client_id}: sync masih berjalan, dilewati.");
                $skipped++;
                continue;
            }
            $lock->release();

            SyncTikTokAnalyticsJob::dispatch(
                $integration->id, $syncMode,
                $since->toDateString(), $until->toDateString(), $userId
            );

            $dispatched++;
        }

        $this->info("Selesai. {$dispatched} sync di-dispatch, {$skipped} dilewati (masih berjalan).");

        return self::SUCCESS;
    }
}
