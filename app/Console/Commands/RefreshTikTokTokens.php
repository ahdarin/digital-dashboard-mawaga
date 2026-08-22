<?php

namespace App\Console\Commands;

use App\Models\ApiIntegration;
use App\Models\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MIRROR RefreshInstagramTokens - TAPI kontrak refresh TikTok BEDA TOTAL
 * dari Instagram (Langkah 7, "Do not copy Instagram token endpoint logic. Use
 * official TikTok token endpoint/contract"):
 *
 * - Instagram: 1 access_token long-lived (~60 hari) di-refresh DENGAN
 *   TOKEN ITU SENDIRI (GET /refresh_access_token?access_token=...), tidak
 *   ada refresh_token terpisah.
 * - TikTok: access_token PENDEK (~24 jam) + refresh_token TERPISAH (~365
 *   hari) yang dipakai buat menukar access_token baru lewat endpoint OAuth
 *   yang SAMA dengan token exchange awal (grant_type=refresh_token). TikTok
 *   JUGA merotasi refresh_token setiap kali dipakai - refresh_token LAMA
 *   tidak bisa dipakai dua kali, jadi refresh_token BARU wajib disimpan
 *   ulang tiap refresh berhasil.
 *
 * Dijadwalkan HARIAN (lebih sering perlu dibanding Instagram karena
 * access_token TikTok jauh lebih pendek umurnya) - refresh kalau
 * access_token_expires_at dalam 2 hari ke depan. Kalau refresh_token
 * SENDIRI sudah/akan kadaluarsa, TIDAK dicoba refresh sama sekali (pasti
 * gagal) - langsung ditandai butuh reconnect manual.
 */
class RefreshTikTokTokens extends Command
{
    private const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    protected $signature = 'analytics:refresh-tiktok-tokens';
    protected $description = 'Refresh access token TikTok yang mendekati expired (per client, hasil OAuth connect)';

    public function handle(): int
    {
        $platform = Platform::where('name', 'TikTok')->first();
        if (! $platform) {
            $this->error("Platform 'TikTok' tidak ditemukan.");
            return self::FAILURE;
        }

        $dueForRefresh = ApiIntegration::where('platform_id', $platform->id)
            ->where('status', 'active')
            ->whereNotNull('access_token')
            ->whereNotNull('refresh_token')
            ->whereNotNull('access_token_expires_at')
            ->where('access_token_expires_at', '<=', now()->addDays(2))
            ->get();

        if ($dueForRefresh->isEmpty()) {
            $this->info('0 token perlu di-refresh.');
            return self::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;
        $needsReconnect = 0;

        foreach ($dueForRefresh as $integration) {
            // refresh_token TikTok py masa berlaku sendiri (~365 hari) -
            // kalau itu sendiri sudah/mau kadaluarsa, refresh PASTI ditolak
            // API. Jangan buang API call, langsung minta reconnect manual.
            if ($integration->refresh_token_expires_at && $integration->refresh_token_expires_at->isPast()) {
                $integration->update([
                    'status' => 'inactive',
                    'last_error' => 'Refresh token TikTok sudah kadaluarsa, perlu connect ulang.',
                ]);
                $needsReconnect++;
                continue;
            }

            try {
                $response = Http::asForm()->post(self::TOKEN_URL, [
                    'client_key' => config('services.tiktok.client_key'),
                    'client_secret' => config('services.tiktok.client_secret'),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $integration->refresh_token,
                ]);

                $body = $response->json();

                if ($response->failed() || ! empty($body['error']) || empty($body['access_token'])) {
                    throw new \RuntimeException($body['error_description'] ?? $body['error'] ?? "HTTP {$response->status()}");
                }

                $integration->update([
                    'access_token' => $body['access_token'],
                    // refresh_token TikTok DIROTASI setiap refresh berhasil -
                    // refresh_token lama tidak lagi valid, WAJIB simpan yang baru.
                    'refresh_token' => $body['refresh_token'] ?? $integration->refresh_token,
                    'access_token_expires_at' => now()->addSeconds($body['expires_in'] ?? 86400),
                    'refresh_token_expires_at' => isset($body['refresh_expires_in']) ? now()->addSeconds($body['refresh_expires_in']) : $integration->refresh_token_expires_at,
                    'scopes' => $body['scope'] ?? $integration->scopes,
                    'last_error' => null,
                ]);

                $refreshed++;
            } catch (\Throwable $e) {
                Log::error('Refresh token TikTok gagal - butuh reconnect manual', [
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

        $this->info("Selesai. {$refreshed} token berhasil di-refresh, {$failed} gagal, {$needsReconnect} langsung butuh reconnect (refresh_token sudah kadaluarsa).");

        return self::SUCCESS;
    }
}
