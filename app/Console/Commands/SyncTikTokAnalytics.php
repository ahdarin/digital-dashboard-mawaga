<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Exceptions\TikTokApiException;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Console\Command;

/**
 * MIRROR SyncInstagramAnalytics - wrapper CLI tipis di atas
 * TikTokAnalyticsSyncService, dipakai bareng SyncTikTokAnalyticsJob.
 *
 * Jalankan manual:
 *   php artisan analytics:sync-tiktok --client=1
 *   php artisan analytics:sync-tiktok --client=1 --month=2026-05
 */
class SyncTikTokAnalytics extends Command
{
    protected $signature = 'analytics:sync-tiktok
        {--client= : ID client pemilik data hasil sync (wajib)}
        {--month= : Historical sync 1 bulan spesifik, format YYYY-MM (opsional - tanpa ini, sync default 2 bulan terakhir)}
        {--user= : ID user yang tercatat sebagai pelaku sync (opsional, default: user CEO pertama)}';

    protected $description = 'Sync profile, video, dan metrik TikTok (per client, token dari api_integrations) ke content_metrics - default 2 bulan terakhir, atau 1 bulan spesifik lewat --month';

    public function handle(TikTokAnalyticsSyncService $service): int
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

        try {
            [$syncMode, $since, $until] = $service->resolveSyncWindow($this->option('month'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $platform = Platform::where('name', 'TikTok')->first();
        if (! $platform) {
            $this->error("Platform 'TikTok' tidak ditemukan di master data (tabel platforms).");
            return self::FAILURE;
        }

        $userId = $this->resolveUserId();
        if (! $userId) {
            $this->error('Nggak ada user sama sekali di database - analytics_sync_logs butuh imported_by yang valid.');
            return self::FAILURE;
        }

        $integration = ApiIntegration::where('client_id', $client->id)->where('platform_id', $platform->id)->first();

        if (! $integration || ! filled($integration->access_token)) {
            $this->error("Client {$client->name} belum connect TikTok (OAuth). Hubungkan dulu lewat tombol \"Connect TikTok\" di halaman Client Detail.");
            return self::FAILURE;
        }

        if (AnalyticsSyncLog::where('api_integration_id', $integration->id)->where('status', 'pending')->exists()) {
            $this->error("Client {$client->name} masih ada sync TikTok yang sedang berjalan. Tunggu sampai selesai sebelum sync lagi.");
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
            $summary = $service->sync($integration, $syncLog, $until, $userId);
        } catch (TikTokApiException $e) {
            $service->markFailed($integration, $syncLog, $e->getMessage(), $e->category);
            $this->error('Sync gagal: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info("Terhubung sebagai @{$summary['username']}.");
        $this->info("{$summary['video_count']} video found (has_more dari API: ".($summary['has_more'] ? 'ya' : 'tidak').", stopped_early: ".($summary['stopped_early'] ? 'ya' : 'tidak').")");
        if ($summary['oldest_fetched'] || $summary['newest_fetched']) {
            $this->info("Rentang video terambil: {$summary['oldest_fetched']} s/d {$summary['newest_fetched']}");
        }
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
