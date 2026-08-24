<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\InstagramAudienceInsightsService;
use Illuminate\Console\Command;

/**
 * Sync manual Audience Insights 1 client - dipanggil langsung buat testing/
 * troubleshooting (mirror analytics:sync-instagram, tapi TERPISAH sama
 * sekali, tidak menyentuh content_metrics/InstagramMediaSnapshot).
 *
 *   php artisan analytics:sync-instagram-audience --client=1
 *   php artisan analytics:sync-instagram-audience --client=1 --backfill
 */
class SyncInstagramAudience extends Command
{
    protected $signature = 'analytics:sync-instagram-audience
        {--client= : ID client pemilik data hasil sync (wajib)}
        {--backfill : One-time backfill reach historis (s/d 180 hari) - bukan sync harian biasa}
        {--user= : ID user yang tercatat sebagai pelaku sync (opsional, default: user CEO pertama)}';

    protected $description = 'Sync account-level Audience Insights (followers/reach/active_hours/demographics) untuk 1 client - synchronous, langsung pakai service (bukan queue), buat CLI/testing';

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
            $this->error("Platform 'Instagram' tidak ditemukan di master data.");
            return self::FAILURE;
        }

        $integration = ApiIntegration::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->first();

        if (! $integration || ! $integration->access_token) {
            $this->error("Client {$client->name} belum connect Instagram (tidak ada token).");
            return self::FAILURE;
        }

        $userId = $this->resolveUserId();
        if (! $userId) {
            $this->error('Nggak ada user sama sekali di database.');
            return self::FAILURE;
        }

        $service = new InstagramAudienceInsightsService($integration);

        if ($this->option('backfill')) {
            $days = $service->backfillReachHistory();
            $this->info("Backfill reach selesai: {$days} hari tersimpan.");
            return self::SUCCESS;
        }

        if (\App\Models\AnalyticsSyncLog::where('api_integration_id', $integration->id)->where('source_type', 'audience_api_sync')->where('status', 'pending')->exists()) {
            $this->error("Client {$client->name} masih ada sync audience yang sedang berjalan. Tunggu sampai selesai sebelum sync lagi.");
            return self::FAILURE;
        }

        $syncLog = \App\Models\AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'api_integration_id' => $integration->id,
            'imported_by' => $userId,
            'source_type' => 'audience_api_sync',
            'sync_mode' => 'default',
            'range_from' => now()->toDateString(),
            'range_to' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $result = $service->sync($syncLog);

        $this->info('Summary row: '.($result['summary_saved'] ? 'tersimpan' : 'gagal'));
        $this->info('Demografi tersimpan: '.(empty($result['demographics_saved']) ? '(tidak ada)' : implode(', ', $result['demographics_saved'])));
        $this->info('Demografi unavailable: '.(empty($result['demographics_unavailable']) ? '(tidak ada)' : implode(', ', $result['demographics_unavailable'])));

        return self::SUCCESS;
    }

    private function resolveUserId(): ?int
    {
        if ($optionUser = $this->option('user')) {
            return (int) $optionUser;
        }

        return User::whereHas('roles', fn ($q) => $q->where('name', UserRole::CEO->value))->first()?->id
            ?? User::first()?->id;
    }
}
