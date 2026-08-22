<?php

namespace App\Http\Controllers;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\Platform;
use App\Services\TikTokAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth "Connect TikTok" per client - MIRROR InstagramIntegrationController
 * (satu app TikTok Developer GLOBAL, token per client di
 * api_integrations.access_token, encrypted). Login Kit + Display API v2
 * resmi (open.tiktokapis.com) - TIDAK ada scraping/unofficial API.
 *
 * PENTING: ini TIDAK menghilangkan App Review TikTok. Selama app TikTok
 * masih dalam mode development/audit, hanya akun yang didaftarkan sebagai
 * target-user-testing di TikTok Developer Portal yang bisa connect lewat
 * flow ini - sama pola dengan App Review Meta di Instagram integration.
 * Lihat docs/TIKTOK_INTEGRATION.md.
 */
class TikTokIntegrationController extends Controller
{
    private const AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';
    private const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    // Diminta SEMUA di sini (Langkah 3 - minimum + recommended) - TikTok
    // cuma benar-benar granting scope yang App-nya sudah disetujui untuk
    // produk itu, kelebihan permintaan scope yang belum disetujui TIDAK
    // membuat request gagal (TikTok mengabaikan scope yang app-nya belum
    // approved), tapi field yang bergantung padanya (follower_count dkk)
    // baru diminta ke API setelah dicek benar-benar granted lewat
    // $integration->scopes hasil callback (lihat TikTokAnalyticsService::hasScope()).
    private const SCOPES = ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.list'];

    public function connect(Client $client)
    {
        if (! filled(config('services.tiktok.client_key')) || ! filled(config('services.tiktok.client_secret'))) {
            return back()->with('import_error', 'TIKTOK_CLIENT_KEY/TIKTOK_CLIENT_SECRET belum diisi di .env - OAuth belum bisa dipakai.');
        }

        $state = Str::random(40);
        session([
            'tiktok_oauth_state' => $state,
            'tiktok_oauth_client_id' => $client->id,
        ]);

        // code_verifier/code_challenge (PKCE) - TikTok mewajibkan PKCE untuk
        // Login Kit sejak beberapa waktu terakhir per dokumentasi resmi.
        // Disimpan di session, dipakai lagi saat exchange code di callback().
        $codeVerifier = Str::random(64);
        session(['tiktok_oauth_code_verifier' => $codeVerifier]);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $query = http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'redirect_uri' => config('services.tiktok.redirect'),
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect(self::AUTHORIZE_URL.'?'.$query);
    }

    public function callback(Request $request)
    {
        $clientId = session('tiktok_oauth_client_id');

        if ($request->has('error')) {
            session()->forget(['tiktok_oauth_state', 'tiktok_oauth_client_id', 'tiktok_oauth_code_verifier']);
            Log::info('TikTok OAuth ditolak/dibatalkan user', ['error' => $request->input('error'), 'description' => $request->input('error_description')]);

            return redirect($clientId ? route('client-management.show', $clientId) : route('client-management.index'))
                ->with('import_error', 'Koneksi TikTok dibatalkan.');
        }

        if (! $clientId || $request->input('state') !== session('tiktok_oauth_state')) {
            session()->forget(['tiktok_oauth_state', 'tiktok_oauth_client_id', 'tiktok_oauth_code_verifier']);

            return redirect()->route('client-management.index')
                ->with('import_error', 'Sesi otorisasi TikTok kadaluarsa atau tidak valid, silakan coba connect lagi.');
        }

        $codeVerifier = session('tiktok_oauth_code_verifier');
        session()->forget(['tiktok_oauth_state', 'tiktok_oauth_client_id', 'tiktok_oauth_code_verifier']);

        $client = Client::find($clientId);
        if (! $client) {
            return redirect()->route('client-management.index')->with('import_error', 'Client tidak ditemukan.');
        }

        $platform = Platform::where('name', 'TikTok')->first();
        if (! $platform) {
            return redirect()->route('client-management.show', $client->id)
                ->with('import_error', "Platform 'TikTok' tidak ditemukan di master data.");
        }

        try {
            $token = $this->exchangeCodeForToken($request->input('code'), $codeVerifier);

            $service = new TikTokAnalyticsService(new ApiIntegration(['access_token' => $token['access_token'], 'scopes' => $token['scope'] ?? null]));
            $profile = $service->getUserInfo();

            ApiIntegration::updateOrCreate(
                ['client_id' => $client->id, 'platform_id' => $platform->id],
                [
                    'integration_name' => 'TikTok API (OAuth)',
                    'status' => 'active',
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? null,
                    'access_token_expires_at' => now()->addSeconds($token['expires_in'] ?? 86400),
                    'refresh_token_expires_at' => isset($token['refresh_expires_in']) ? now()->addSeconds($token['refresh_expires_in']) : null,
                    'scopes' => $token['scope'] ?? null,
                    'external_account_id' => $token['open_id'] ?? ($profile['open_id'] ?? null),
                    'external_username' => $profile['username'] ?? $profile['display_name'] ?? null,
                    'last_error' => null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('TikTok OAuth callback gagal', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            ApiIntegration::updateOrCreate(
                ['client_id' => $client->id, 'platform_id' => $platform->id],
                ['integration_name' => 'TikTok API (OAuth)', 'status' => 'inactive', 'last_error' => $e->getMessage()]
            );

            return redirect()->route('client-management.show', $client->id)
                ->with('import_error', 'Gagal menghubungkan TikTok: '.$e->getMessage());
        }

        $displayName = $profile['username'] ?? $profile['display_name'] ?? 'akun TikTok';

        return redirect()->route('client-management.show', $client->id)
            ->with('import_success', "TikTok berhasil terhubung sebagai @{$displayName}.");
    }

    /**
     * POST https://open.tiktokapis.com/v2/oauth/token/ - kontrak resmi
     * TikTok Login Kit (form-urlencoded, BUKAN JSON). Response berisi
     * access_token, expires_in, refresh_token, refresh_expires_in, open_id,
     * scope, token_type - SEMUA field ini beda nama & struktur dari
     * Instagram (Langkah 7, "Use official TikTok token endpoint/contract",
     * "Do not copy Instagram token endpoint logic").
     *
     * @return array{access_token: string, expires_in?: int, refresh_token?: string, refresh_expires_in?: int, open_id?: string, scope?: string}
     */
    private function exchangeCodeForToken(?string $code, ?string $codeVerifier): array
    {
        if (! $code) {
            throw new \RuntimeException('Kode otorisasi dari TikTok tidak ada.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.tiktok.redirect'),
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->failed()) {
            Log::error('TikTok token exchange gagal', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Gagal menukar kode otorisasi TikTok jadi token.');
        }

        $body = $response->json();

        if (! empty($body['error'])) {
            Log::error('TikTok token exchange: response berisi error', ['error' => $body['error'], 'description' => $body['error_description'] ?? null]);
            throw new \RuntimeException('TikTok menolak token exchange: '.($body['error_description'] ?? $body['error']));
        }

        if (empty($body['access_token'])) {
            Log::error('TikTok token exchange: response tidak berisi access_token', ['body' => $body]);
            throw new \RuntimeException('Response TikTok tidak berisi access_token.');
        }

        return $body;
    }
}
