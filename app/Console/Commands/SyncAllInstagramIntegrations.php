<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Jobs\SyncInstagramAnalyticsJob;
use App\Models\ApiIntegration;
use App\Models\User;
use App\Services\InstagramAnalyticsSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Dijadwalkan HARIAN (routes/console.php) - dispatch sync default (rolling
 * 2 bulan terakhir) buat SEMUA ApiIntegration Instagram yang statusnya
 * active. HANYA default window - historical sync per bulan SELALU manual,
 * tidak pernah dijadwalkan otomatis (per keputusan produk).
 *
 * Cadence: Daily. Alasan (didiskusikan & disetujui sebelum implementasi):
 * metric Instagram berakumulasi bertahap (bukan real-time), volume API
 * call per sync sudah kecil berkat since/until server-side filtering,
 * dan konsisten dengan command terjadwal lain di app ini yang sudah daily.
 */
class SyncAllInstagramIntegrations extends Command
{
    protected $signature = 'analytics:sync-all-instagram';
    protected $description = 'Dispatch sync default (2 bulan terakhir) untuk semua ApiIntegration Instagram yang aktif - dijadwalkan harian';

    public function handle(InstagramAnalyticsSyncService $service): int
    {
        $integrations = ApiIntegration::where('status', 'active')
            ->whereNotNull('access_token')
            ->whereHas('platform', fn ($q) => $q->where('name', 'Instagram'))
            ->get();

        if ($integrations->isEmpty()) {
            $this->info('Tidak ada integrasi Instagram aktif.');
            return self::SUCCESS;
        }

        $userId = User::whereHas('roles', fn ($q) => $q->where('name', UserRole::CEO->value))->first()?->id
            ?? User::first()?->id;

        if (! $userId) {
            $this->error('Tidak ada user sama sekali - dibatalkan.');
            return self::FAILURE;
        }

        [$syncMode, $since, $until] = $service->resolveSyncWindow(null);

        $dispatched = 0;
        $skipped = 0;

        foreach ($integrations as $integration) {
            // Sama seperti tombol manual - peek non-invasif ke lock yang
            // sama dipakai WithoutOverlapping (bukan cek AnalyticsSyncLog -
            // log sekarang cuma dibuat DI DALAM Job setelah lock didapat,
            // lihat docblock SyncInstagramAnalyticsJob). Ini optimisasi
            // biar log CLI kelihatan jelas mana yang dilewati; kalaupun
            // dilewatkan cek ini karena race, WithoutOverlapping di Job
            // tetap jadi pengaman akhir yang sesungguhnya.
            $lock = Cache::lock(SyncInstagramAnalyticsJob::cacheLockKey($integration->id), 10);
            if (! $lock->get()) {
                $this->line("Client {$integration->client_id}: sync masih berjalan, dilewati.");
                $skipped++;
                continue;
            }
            $lock->release();

            SyncInstagramAnalyticsJob::dispatch(
                $integration->id, $syncMode,
                $since->toDateString(), $until->toDateString(), $userId
            );

            $dispatched++;
        }

        $this->info("Selesai. {$dispatched} sync di-dispatch, {$skipped} dilewati (masih berjalan).");

        return self::SUCCESS;
    }
}
