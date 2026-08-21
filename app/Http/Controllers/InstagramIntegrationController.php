<?php

namespace App\Http\Controllers;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\Platform;
use App\Services\InstagramAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth "Connect Instagram" per client - PIC 3, generalisasi dari integrasi
 * Instagram API tahap sebelumnya (1 akun test lewat token .env). Tujuannya
 * biar staff bisa hubungkan akun Instagram client baru dari UI (Client
 * Detail), tanpa developer perlu masuk server edit .env tiap kali onboarding
 * client baru - token disimpan terenkripsi di api_integrations.access_token.
 *
 * Token client TIDAK PERNAH disimpan di .env - satu-satunya source of truth
 * adalah api_integrations.access_token (encrypted cast), per client. .env
 * cuma nyimpen credential global Meta App (INSTAGRAM_CLIENT_ID/SECRET/
 * API_VERSION/REDIRECT_URI) yang dipakai buat flow OAuth ini sendiri.
 *
 * Catatan penting: ini nggak menghilangkan App Review Meta. Selama app
 * Instagram masih Development mode, cuma akun yang didaftarkan manual
 * sebagai "Instagram Tester" di App Dashboard yang bisa connect lewat sini.
 */
class InstagramIntegrationController extends Controller
{
    private const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';
    private const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';
    private const EXCHANGE_URL = 'https://graph.instagram.com/access_token';

    /**
     * Redirect ke layar consent Instagram. client_id dititipkan lewat
     * session (bukan di redirect_uri) karena Meta mewajibkan redirect_uri
     * yang didaftarkan di App Dashboard exact-match/statis, nggak boleh ada
     * segmen dinamis seperti {client}.
     */
    public function connect(Client $client)
    {
        if (! filled(config('services.instagram.client_id')) || ! filled(config('services.instagram.client_secret'))) {
            return back()->with('import_error', 'INSTAGRAM_CLIENT_ID/INSTAGRAM_CLIENT_SECRET belum diisi di .env - OAuth belum bisa dipakai.');
        }

        $state = Str::random(40);
        session([
            'instagram_oauth_state' => $state,
            'instagram_oauth_client_id' => $client->id,
        ]);

        $query = http_build_query([
            'client_id' => config('services.instagram.client_id'),
            'redirect_uri' => config('services.instagram.redirect'),
            'response_type' => 'code',
            'scope' => 'instagram_business_basic,instagram_business_manage_insights',
            'state' => $state,
        ]);

        return redirect(self::AUTHORIZE_URL.'?'.$query);
    }

    public function callback(Request $request)
    {
        $clientId = session('instagram_oauth_client_id');

        // User klik "Cancel"/tolak consent di layar Instagram - Meta
        // redirect balik dengan ?error=... bukan exception, jadi ditangani
        // sebagai alur normal, bukan kegagalan sistem.
        if ($request->has('error')) {
            session()->forget(['instagram_oauth_state', 'instagram_oauth_client_id']);
            Log::info('Instagram OAuth ditolak/dibatalkan user', ['error' => $request->input('error'), 'reason' => $request->input('error_reason')]);

            return redirect($clientId ? route('client-management.show', $clientId) : route('client-management.index'))
                ->with('import_error', 'Koneksi Instagram dibatalkan.');
        }

        if (! $clientId || $request->input('state') !== session('instagram_oauth_state')) {
            session()->forget(['instagram_oauth_state', 'instagram_oauth_client_id']);

            return redirect()->route('client-management.index')
                ->with('import_error', 'Sesi otorisasi Instagram kadaluarsa atau tidak valid, silakan coba connect lagi.');
        }

        session()->forget(['instagram_oauth_state', 'instagram_oauth_client_id']);

        $client = Client::find($clientId);
        if (! $client) {
            return redirect()->route('client-management.index')->with('import_error', 'Client tidak ditemukan.');
        }

        $platform = Platform::where('name', 'Instagram')->first();

        try {
            $shortLived = $this->exchangeCodeForToken($request->input('code'));
            $longLived = $this->exchangeForLongLivedToken($shortLived['access_token']);

            // Belum ada ApiIntegration tersimpan di titik ini (justru lagi
            // mau dibuat) - dipakai instance transient (nggak di-save) cuma
            // buat numpang lewat encrypted cast, biar getProfile() tetap
            // konsisten baca token dari $integration->access_token seperti
            // pemakaian service di tempat lain, bukan jalur token terpisah.
            $service = new InstagramAnalyticsService(new ApiIntegration(['access_token' => $longLived['access_token']]));
            $profile = $service->getProfile();

            ApiIntegration::updateOrCreate(
                ['client_id' => $client->id, 'platform_id' => $platform->id],
                [
                    'integration_name' => 'Instagram API (OAuth)',
                    'status' => 'active',
                    'access_token' => $longLived['access_token'],
                    'access_token_expires_at' => now()->addSeconds($longLived['expires_in'] ?? 5184000),
                    'external_account_id' => $profile['id'] ?? null,
                    'external_username' => $profile['username'] ?? null,
                    'last_error' => null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Instagram OAuth callback gagal', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            ApiIntegration::updateOrCreate(
                ['client_id' => $client->id, 'platform_id' => $platform->id],
                ['integration_name' => 'Instagram API (OAuth)', 'status' => 'inactive', 'last_error' => $e->getMessage()]
            );

            return redirect()->route('client-management.show', $client->id)
                ->with('import_error', 'Gagal menghubungkan Instagram: '.$e->getMessage());
        }

        return redirect()->route('client-management.show', $client->id)
            ->with('import_success', "Instagram berhasil terhubung sebagai @{$profile['username']}.");
    }

    /**
     * @return array{access_token: string, user_id?: int}
     */
    private function exchangeCodeForToken(?string $code): array
    {
        if (! $code) {
            throw new \RuntimeException('Kode otorisasi dari Instagram tidak ada.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.instagram.client_id'),
            'client_secret' => config('services.instagram.client_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.instagram.redirect'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            Log::error('Instagram token exchange (code) gagal', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Gagal menukar kode otorisasi Instagram jadi token.');
        }

        $body = $response->json();

        // Beberapa versi API Meta membungkus hasilnya di dalam array 'data',
        // yang lain flat - ditangani dua-duanya biar nggak gampang patah
        // kalau bentuk responsnya beda dari dugaan awal.
        $result = $body['data'][0] ?? $body;

        if (empty($result['access_token'])) {
            Log::error('Instagram token exchange (code): response tidak berisi access_token', ['body' => $body]);
            throw new \RuntimeException('Response Instagram tidak berisi access_token.');
        }

        return $result;
    }

    /**
     * @return array{access_token: string, expires_in?: int}
     */
    private function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::get(self::EXCHANGE_URL, [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => config('services.instagram.client_secret'),
            'access_token' => $shortLivedToken,
        ]);

        if ($response->failed()) {
            Log::error('Instagram long-lived token exchange gagal', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Gagal menukar token Instagram jadi long-lived token.');
        }

        $body = $response->json();

        if (empty($body['access_token'])) {
            Log::error('Instagram long-lived token exchange: response tidak berisi access_token', ['body' => $body]);
            throw new \RuntimeException('Response Instagram (long-lived token) tidak berisi access_token.');
        }

        return $body;
    }
}
