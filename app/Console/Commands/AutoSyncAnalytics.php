<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\AnalyticsSyncOrchestrator;
use Illuminate\Console\Command;

/**
 * Analytics V2 Phase B - "AUTO SYNC, ONCE PER 24 HOURS". SATU command
 * terjadwal harian yang menggantikan 3 command lama (analytics:sync-all-
 * instagram, analytics:sync-all-instagram-audience, analytics:sync-all-
 * tiktok - MASIH ADA, TIDAK dihapus, tetap bisa dijalankan manual buat
 * debugging, TAPI JADWAL OTOMATISNYA dinonaktifkan di routes/console.php
 * supaya tidak dispatch dobel).
 *
 * Kenapa dikonsolidasi: "Scheduled and manual refresh MUST use the same
 * orchestration pipeline" - command lama masing-masing dispatch job
 * LANGSUNG (bypass AnalyticsSyncOrchestrator sama sekali), jadi TIDAK
 * PERNAH menghasilkan AnalyticsSyncRun/Task (tidak ada progress/
 * reconciliation tracking), dan protokol duplicate-protection-nya beda
 * sendiri dari tombol manual "Perbarui Data" (2 sumber kebenaran yang bisa
 * drift). Command ini SATU-SATUNYA jalur - manggil
 * AnalyticsSyncOrchestrator::dispatch() PERSIS seperti tombol manual,
 * cuma $trigger beda ('scheduled' vs 'manual') - duplicate-protection
 * (lock/queued-job check) OTOMATIS ikut, karena itu logic yang SAMA.
 *
 * Loop per CLIENT (bukan per ApiIntegration platform) - dispatch(client,
 * null, ...) sendiri yang menentukan subjob mana yang relevan (Instagram
 * Content + Audience + TikTok Content sekaligus kalau connected), SATU
 * panggilan per client cukup - jangan loop 3x seperti command lama.
 *
 * Auto sync SELALU pakai default lookback (incremental/regular) - TIDAK
 * PERNAH historical import (--month) atau reach backfill 180 hari (itu
 * one-time saat connect pertama, lihat InstagramIntegrationController).
 */
class AutoSyncAnalytics extends Command
{
    protected $signature = 'analytics:auto-sync';

    protected $description = 'Refresh analytics harian otomatis (scheduled trigger) untuk semua client dengan integrasi Instagram/TikTok aktif - pipeline SAMA dengan tombol manual "Perbarui Data".';

    public function handle(AnalyticsSyncOrchestrator $orchestrator): int
    {
        $userId = User::whereHas('roles', fn ($q) => $q->where('name', UserRole::CEO->value))->first()?->id
            ?? User::first()?->id;

        if (! $userId) {
            $this->error('Tidak ada user sama sekali - dibatalkan.');

            return self::FAILURE;
        }

        $clients = Client::whereHas('apiIntegrations', fn ($q) => $q->where('status', 'active')
            ->whereHas('platform', fn ($q2) => $q2->whereIn('name', ['Instagram', 'TikTok'])))
            ->get();

        if ($clients->isEmpty()) {
            $this->info('Tidak ada client dengan integrasi aktif.');

            return self::SUCCESS;
        }

        $dispatchedClients = 0;
        $totalDispatchedSubjobs = 0;
        $totalSkipped = 0;

        foreach ($clients as $client) {
            $result = $orchestrator->dispatch($client, null, $userId, \App\Models\AnalyticsSyncRun::TRIGGER_SCHEDULED);

            if (! empty($result['dispatched'])) {
                $dispatchedClients++;
                $totalDispatchedSubjobs += count($result['dispatched']);
            }
            $totalSkipped += count($result['skipped']);
        }

        $this->info("Selesai. {$dispatchedClients} client di-dispatch ({$totalDispatchedSubjobs} subjob), {$totalSkipped} subjob dilewati (not_connected/needs_reconnect).");

        return self::SUCCESS;
    }
}
