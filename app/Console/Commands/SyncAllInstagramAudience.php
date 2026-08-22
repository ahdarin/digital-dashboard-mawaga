<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Jobs\SyncInstagramAudienceJob;
use App\Models\ApiIntegration;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Dijadwalkan HARIAN (routes/console.php) - dispatch SyncInstagramAudienceJob
 * (queue, bukan synchronous) buat semua ApiIntegration Instagram aktif.
 * TERPISAH dari analytics:sync-all-instagram (content) - lock key beda
 * prefix, jadi audience & content sync integration yang sama boleh jalan
 * bersamaan tanpa saling tunggu.
 *
 * Tidak ada historical month picker (Langkah 10/15) - daily sync SELALU
 * "hari ini" saja. Backfill reach 180 hari cuma sekali, manual, saat
 * integration baru connect (lihat --backfill di analytics:sync-instagram-audience).
 */
class SyncAllInstagramAudience extends Command
{
    protected $signature = 'analytics:sync-all-instagram-audience';
    protected $description = 'Dispatch sync Audience Insights harian untuk semua ApiIntegration Instagram yang aktif';

    public function handle(): int
    {
        $integrations = ApiIntegration::where('status', 'active')
            ->whereNotNull('access_token')
            ->whereHas('platform', fn ($q) => $q->where('name', 'Instagram'))
            ->get();

        if ($integrations->isEmpty()) {
            $this->info('Tidak ada integrasi Instagram aktif.');
            return self::SUCCESS;
        }

        $userId = User::whereHas('role', fn ($q) => $q->where('name', UserRole::CEO->value))->first()?->id
            ?? User::first()?->id;

        if (! $userId) {
            $this->error('Tidak ada user sama sekali - dibatalkan.');
            return self::FAILURE;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($integrations as $integration) {
            $lock = Cache::lock(SyncInstagramAudienceJob::cacheLockKey($integration->id), 10);
            if (! $lock->get()) {
                $this->line("Client {$integration->client_id}: audience sync masih berjalan, dilewati.");
                $skipped++;
                continue;
            }
            $lock->release();

            SyncInstagramAudienceJob::dispatch($integration->id, $userId);
            $dispatched++;
        }

        $this->info("Selesai. {$dispatched} audience sync di-dispatch, {$skipped} dilewati (masih berjalan).");

        return self::SUCCESS;
    }
}
