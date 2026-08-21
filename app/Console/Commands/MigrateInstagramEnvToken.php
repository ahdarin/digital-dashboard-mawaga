<?php

namespace App\Console\Commands;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\Platform;
use App\Services\InstagramAnalyticsService;
use Illuminate\Console\Command;

/**
 * One-off: migrasi token Instagram 523 Studio dari INSTAGRAM_ACCESS_TOKEN
 * (.env, sisa arsitektur token-tunggal sebelum multi-client OAuth) ke
 * api_integrations.access_token milik client itu. HANYA command ini yang
 * boleh baca env('INSTAGRAM_ACCESS_TOKEN') langsung - begitu migrasi
 * sukses, baris itu dihapus dari .env dan config/services.php nggak lagi
 * punya key 'access_token' sama sekali.
 *
 * Command ini boleh dihapus setelah dipakai sekali - didokumentasikan di
 * laporan migrasi, bukan bagian dari arsitektur permanen.
 *
 * Jalankan: php artisan instagram:migrate-env-token --client=6
 */
class MigrateInstagramEnvToken extends Command
{
    protected $signature = 'instagram:migrate-env-token {--client= : ID client tujuan (wajib)}';
    protected $description = 'One-off: pindahkan INSTAGRAM_ACCESS_TOKEN dari .env ke api_integrations.access_token client tertentu';

    public function handle(): int
    {
        $clientId = $this->option('client');
        if (! $clientId) {
            $this->error('Wajib isi --client=ID.');
            return self::FAILURE;
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->error("Client dengan ID {$clientId} tidak ditemukan.");
            return self::FAILURE;
        }

        $platform = Platform::where('name', 'Instagram')->first();
        if (! $platform) {
            $this->error("Platform 'Instagram' tidak ditemukan.");
            return self::FAILURE;
        }

        $envToken = env('INSTAGRAM_ACCESS_TOKEN');
        if (! filled($envToken)) {
            $this->error('INSTAGRAM_ACCESS_TOKEN kosong/tidak ada di .env - tidak ada yang perlu dimigrasi.');
            return self::FAILURE;
        }

        $this->info("Memvalidasi token untuk client {$client->name} lewat getProfile()...");

        try {
            $profile = (new InstagramAnalyticsService(new ApiIntegration(['access_token' => $envToken])))->getProfile();
        } catch (\Throwable $e) {
            $this->error('Token di .env gagal divalidasi ke Instagram API: '.$e->getMessage());
            return self::FAILURE;
        }

        $username = $profile['username'] ?? null;
        if (! $username) {
            $this->error('Response profil Instagram tidak berisi username - migrasi dibatalkan.');
            return self::FAILURE;
        }

        $integration = ApiIntegration::updateOrCreate(
            ['client_id' => $client->id, 'platform_id' => $platform->id],
            [
                'access_token' => $envToken,
                'status' => 'active',
                'external_account_id' => $profile['id'] ?? null,
                'external_username' => $username,
                'last_error' => null,
            ]
        );

        $this->info("Berhasil. Token tersimpan terenkripsi di api_integrations (id={$integration->id}) untuk client {$client->name}, terverifikasi sebagai @{$username}.");
        $this->warn('Sekarang aman hapus INSTAGRAM_ACCESS_TOKEN dari .env - command ini tidak lagi dibutuhkan setelah itu.');

        return self::SUCCESS;
    }
}
