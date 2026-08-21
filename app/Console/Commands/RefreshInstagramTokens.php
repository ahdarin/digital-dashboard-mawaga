<?php

namespace App\Console\Commands;

use App\Models\ApiIntegration;
use App\Models\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Long-lived token Instagram cuma berlaku ~60 hari - command ini refresh
 * token yang mendekati expired (dalam 7 hari) biar client yang sudah
 * connect via OAuth (InstagramIntegrationController) nggak perlu reconnect
 * manual tiap 2 bulan. Dijadwalkan harian lewat routes/console.php.
 */
class RefreshInstagramTokens extends Command
{
    protected $signature = 'analytics:refresh-instagram-tokens';
    protected $description = 'Refresh long-lived access token Instagram yang mendekati expired (per client, hasil OAuth connect)';

    public function handle(): int
    {
        $platform = Platform::where('name', 'Instagram')->first();
        if (! $platform) {
            $this->error("Platform 'Instagram' tidak ditemukan.");
            return self::FAILURE;
        }

        $dueForRefresh = ApiIntegration::where('platform_id', $platform->id)
            ->where('status', 'active')
            ->whereNotNull('access_token')
            ->whereNotNull('access_token_expires_at')
            ->where('access_token_expires_at', '<=', now()->addDays(7))
            ->get();

        if ($dueForRefresh->isEmpty()) {
            $this->info('0 token perlu di-refresh.');
            return self::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;

        foreach ($dueForRefresh as $integration) {
            try {
                $response = Http::get('https://graph.instagram.com/refresh_access_token', [
                    'grant_type' => 'ig_refresh_token',
                    'access_token' => $integration->access_token,
                ]);

                if ($response->failed() || empty($response->json('access_token'))) {
                    throw new \RuntimeException($response->json('error.message') ?? "HTTP {$response->status()}");
                }

                $integration->update([
                    'access_token' => $response->json('access_token'),
                    'access_token_expires_at' => now()->addSeconds($response->json('expires_in') ?? 5184000),
                    'last_error' => null,
                ]);

                $refreshed++;
            } catch (\Throwable $e) {
                Log::error('Refresh token Instagram gagal - butuh reconnect manual', [
                    'client_id' => $integration->client_id,
                    'error' => $e->getMessage(),
                ]);

                $integration->update([
                    'status' => 'inactive',
                    'last_error' => 'Refresh token gagal, perlu connect ulang: '.$e->getMessage(),
                ]);

                $failed++;
            }
        }

        $this->info("Selesai. {$refreshed} token berhasil di-refresh, {$failed} gagal (butuh reconnect manual).");

        return self::SUCCESS;
    }
}
