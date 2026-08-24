<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Exceptions\InstagramApiException;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\InstagramAnalyticsSyncService;
use Illuminate\Console\Command;

/**
 * KF3xx — Integrasi Instagram API multi-client (bagian dari Content
 * Analytics, domain PIC 3). Wrapper CLI tipis di atas
 * InstagramAnalyticsSyncService - orkestrasi sync sesungguhnya (matching,
 * upsert content_metrics/snapshot, dst) ada di service itu, dipakai bareng
 * sama SyncInstagramAnalyticsJob (dipicu tombol "Sync Now" di web) biar
 * business logic-nya cuma satu tempat.
 *
 * CLI di sini SELALU synchronous (1 kali percobaan, tidak ada retry
 * otomatis) - beda dari Job yang jalan lewat queue dengan retry policy.
 * Token SELALU dari api_integrations.access_token milik client yang
 * dipilih - TIDAK ADA fallback ke .env.
 *
 * Jalankan manual:
 *   php artisan analytics:sync-instagram --client=1
 *   php artisan analytics:sync-instagram --client=1 --month=2026-05
 */
class SyncInstagramAnalytics extends Command
{
    protected $signature = 'analytics:sync-instagram
        {--client= : ID client pemilik data hasil sync (wajib)}
        {--month= : Historical sync 1 bulan spesifik, format YYYY-MM (opsional - tanpa ini, sync default 2 bulan terakhir)}
        {--user= : ID user yang tercatat sebagai pelaku sync (opsional, default: user CEO pertama)}';

    protected $description = 'Sync profile, media, dan insights Instagram (per client, token dari api_integrations) ke content_metrics - default 2 bulan terakhir, atau 1 bulan spesifik lewat --month';

    public function handle(InstagramAnalyticsSyncService $service): int
    {
        $clientId = $this->option('client');

        if (! $clientId) {
            $this->error('Wajib isi --client=ID (sama seperti Import CSV, data hasil sync perlu tahu ini milik client mana).');
            return self::FAILURE;
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->error("Client dengan ID {$clientId} tidak ditemukan.");
            return self::FAILURE;
        }

        // Divalidasi SEBELUM ada API call apapun - format salah atau bulan
        // di masa depan harus ditolak di sini, bukan ketahuan setelah
        // token/API kepakai percuma.
        try {
            [$syncMode, $since, $until] = $service->resolveSyncWindow($this->option('month'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $platform = Platform::where('name', 'Instagram')->first();
        if (! $platform) {
            $this->error("Platform 'Instagram' tidak ditemukan di master data (tabel platforms).");
            return self::FAILURE;
        }

        $userId = $this->resolveUserId();
        if (! $userId) {
            $this->error('Nggak ada user sama sekali di database - analytics_sync_logs butuh imported_by yang valid.');
            return self::FAILURE;
        }

        $integration = ApiIntegration::where('client_id', $client->id)->where('platform_id', $platform->id)->first();

        if (! $integration || ! filled($integration->access_token)) {
            $this->error("Client {$client->name} belum connect Instagram (OAuth). Hubungkan dulu lewat tombol \"Connect Instagram\" di halaman Client Detail.");
            return self::FAILURE;
        }

        if (AnalyticsSyncLog::where('api_integration_id', $integration->id)->where('status', 'pending')->exists()) {
            $this->error("Client {$client->name} masih ada sync Instagram yang sedang berjalan. Tunggu sampai selesai sebelum sync lagi.");
            return self::FAILURE;
        }

        $syncLog = AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'api_integration_id' => $integration->id,
            'imported_by' => $userId,
            'source_type' => 'api_sync',
            'status' => 'pending',
            'sync_mode' => $syncMode,
            'range_from' => $since->toDateString(),
            'range_to' => $until->toDateString(),
        ]);

        $this->info($syncMode === 'historical'
            ? "Historical sync: {$since->translatedFormat('F Y')}"
            : "Default sync: {$since->toDateString()} s/d {$until->toDateString()}");

        try {
            $summary = $service->sync($integration, $syncLog, $since, $until, $userId);
        } catch (InstagramApiException $e) {
            // CLI nggak punya mekanisme retry (beda dari Job) - kegagalan
            // apapun di sini langsung final.
            $service->markFailed($integration, $syncLog, $e->getMessage(), $e->category);
            $this->error('Sync gagal: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info("Terhubung sebagai @{$summary['username']} ({$summary['account_media_count']} media di akun).");
        $this->info("{$summary['media_count']} media found");
        $this->info("{$summary['existing_matched']} already matched");
        $this->info("{$summary['newly_matched']} newly matched");
        $this->info("{$summary['unmatched']} unmatched");
        if ($summary['ambiguous'] > 0) {
            $this->warn("{$summary['ambiguous']} ambiguous (tidak auto-link, butuh manual link)");
        }
        $this->info("{$summary['metrics_saved']} metrics saved/updated");
        if ($summary['failed'] > 0) {
            $this->warn("{$summary['failed']} failed");
        }

        foreach ($summary['details'] as $detail) {
            $this->line("  - {$detail}");
        }

        return self::SUCCESS;
    }

    private function resolveUserId(): ?int
    {
        if ($optionUser = $this->option('user')) {
            return (int) $optionUser;
        }

        $ceo = User::whereHas('roles', fn ($q) => $q->where('name', UserRole::CEO->value))->first();

        return $ceo?->id ?? User::first()?->id;
    }
}
