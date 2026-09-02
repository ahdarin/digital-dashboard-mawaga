<?php

namespace App\Services;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncInstagramAudienceJob;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ContentMetricSnapshot;
use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 - SATU-SATUNYA orchestrator buat tombol "Sinkronkan Data" global di
 * halaman Analytics/Performa. TIDAK menduplikasi business logic sync sama
 * sekali - cuma memutuskan job MANA yang relevan buat client+platform filter
 * yang dipilih, lalu dispatch job yang SUDAH ADA (SyncInstagramAnalyticsJob,
 * SyncInstagramAudienceJob, SyncTikTokAnalyticsJob) persis seperti
 * SettingsController::syncInstagram()/syncTiktok() &
 * ClientManagementController::syncInstagramAudience() sudah lakukan -
 * signature dispatch, resolveSyncWindow(null) (lookback default Phase 1,
 * BUKAN period display filter), dan lock-peek pattern SEMUA disalin apa
 * adanya dari situ, bukan ditulis ulang beda.
 *
 * Status resolution (queued/running/success/failed/dst) MENGGENERALISASI
 * pola yang SUDAH ADA di ClientManagementController::tiktokSyncStatus()
 * (lock peek + scan tabel `jobs` + AnalyticsSyncLog terakhir) supaya
 * Instagram Content, Instagram Audience, dan TikTok Content punya SATU
 * kontrak status yang identik - Client Detail TikTok polling yang sudah
 * dites TIDAK disentuh sama sekali (endpoint itu tetap ada, tetap pakai
 * logic sendiri, TIDAK di-refactor buat pakai class ini - Langkah 7,
 * "jangan merusak existing Client Detail polling yang sudah dites").
 */
class AnalyticsSyncOrchestrator
{
    public const SUBJOB_INSTAGRAM_CONTENT = 'instagram_content';

    public const SUBJOB_INSTAGRAM_AUDIENCE = 'instagram_audience';

    public const SUBJOB_TIKTOK_CONTENT = 'tiktok_content';

    /**
     * Subjob mana yang relevan buat platform filter GLOBAL ini (Langkah 3).
     * null $platformId = All Platforms.
     *
     * @return array<int, string>
     */
    public function relevantSubjobs(?int $platformId): array
    {
        $instagramId = Platform::where('name', 'Instagram')->value('id');
        $tiktokId = Platform::where('name', 'TikTok')->value('id');

        $wantInstagram = $platformId === null || $platformId === $instagramId;
        $wantTiktok = $platformId === null || $platformId === $tiktokId;

        $subjobs = [];
        if ($wantInstagram) {
            $subjobs[] = self::SUBJOB_INSTAGRAM_CONTENT;
            $subjobs[] = self::SUBJOB_INSTAGRAM_AUDIENCE;
        }
        if ($wantTiktok) {
            $subjobs[] = self::SUBJOB_TIKTOK_CONTENT;
        }

        return $subjobs;
    }

    /**
     * Dispatch sync buat 1 client, di-scope ke platform filter (Langkah
     * 1/3). SELALU client-scoped (Langkah 2 - caller WAJIB sudah
     * memvalidasi user berhak akses $client SEBELUM memanggil ini, method
     * ini sendiri tidak melakukan authorization check).
     *
     * HANYA subjob dengan integration ACTIVE yang benar-benar di-dispatch
     * (Langkah 4) - not_connected/needs_reconnect DI-SKIP diam-diam (bukan
     * error keras), tercermin di 'skipped'. Subjob yang KETAHUAN sudah
     * queued/running (Langkah 11/19 - lock ATAU baris di tabel `jobs`)
     * TIDAK di-dispatch ulang (server-side duplicate protection, bukan
     * cuma andalan tombol UI disabled).
     *
     * @return array{dispatched: array<int, string>, skipped: array<string, string>}
     */
    public function dispatch(Client $client, ?int $platformId, int $userId): array
    {
        $dispatched = [];
        $skipped = [];

        foreach ($this->relevantSubjobs($platformId) as $subjob) {
            $integration = $this->integrationFor($client, $subjob);

            if (! $integration) {
                $skipped[$subjob] = 'not_connected';
                continue;
            }

            if ($integration->status !== 'active') {
                $skipped[$subjob] = 'needs_reconnect';
                continue;
            }

            $jobClass = $this->jobClassFor($subjob);
            if ($this->isLockHeld($jobClass, $integration->id) || $this->hasQueuedJob($jobClass, $integration->id)) {
                // Sudah in-flight - anggap "dispatched" dari POV user (sync
                // memang sedang berjalan buat subjob ini), TAPI JANGAN
                // dispatch job kedua.
                $dispatched[] = $subjob;
                continue;
            }

            $this->dispatchOne($subjob, $integration, $userId);
            $dispatched[] = $subjob;
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    private function dispatchOne(string $subjob, ApiIntegration $integration, int $userId): void
    {
        match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT => $this->dispatchInstagramContent($integration, $userId),
            self::SUBJOB_INSTAGRAM_AUDIENCE => SyncInstagramAudienceJob::dispatch($integration->id, $userId),
            self::SUBJOB_TIKTOK_CONTENT => $this->dispatchTiktokContent($integration, $userId),
        };
    }

    /**
     * resolveSyncWindow(null) SENGAJA - ingestion SELALU pakai default
     * lookback Phase 1 (instagram_default_sync_days), BUKAN period display
     * filter (7/30/90) dari Analytics (Langkah 1, "Period adalah DISPLAY
     * FILTER, jangan jadikan sync mode").
     */
    private function dispatchInstagramContent(ApiIntegration $integration, int $userId): void
    {
        [$syncMode, $since, $until] = app(InstagramAnalyticsSyncService::class)->resolveSyncWindow(null);
        SyncInstagramAnalyticsJob::dispatch($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $userId);
    }

    private function dispatchTiktokContent(ApiIntegration $integration, int $userId): void
    {
        [$syncMode, $since, $until] = app(TikTokAnalyticsSyncService::class)->resolveSyncWindow(null);
        SyncTikTokAnalyticsJob::dispatch($integration->id, $syncMode, $since->toDateString(), $until->toDateString(), $userId);
    }

    /**
     * Status lengkap (overall + per-subjob) buat 1 client, di-scope ke
     * platform filter yang SAMA dengan dispatch() (Langkah 6/8/9/10).
     * Caller WAJIB sudah memvalidasi akses client sebelum memanggil ini.
     *
     * @return array{overall_status: string, subjobs: array<string, array>, last_observation_at: ?string}
     */
    public function statusForClient(Client $client, ?int $platformId): array
    {
        $subjobStatuses = [];
        foreach ($this->relevantSubjobs($platformId) as $subjob) {
            $subjobStatuses[$subjob] = $this->resolveSubjobStatus($client, $subjob);
        }

        return [
            'overall_status' => $this->computeOverallStatus($subjobStatuses),
            'subjobs' => $subjobStatuses,
            'last_observation_at' => $this->lastObservationAt($client, $platformId)?->toIso8601String(),
        ];
    }

    /**
     * @return array{status: string, message: string, synced_count: ?int, skipped_count: ?int, error_message: ?string, finished_at: ?string}
     */
    private function resolveSubjobStatus(Client $client, string $subjob): array
    {
        $integration = $this->integrationFor($client, $subjob);

        if (! $integration) {
            // Langkah 15/22 (Phase 4) - kalau platform ini TIDAK connected
            // TAPI client punya data manual/CSV buat platform itu, JANGAN
            // tampilkan seolah API sync tersedia - beri copy yang jujur
            // soal SUMBER data yang memang sudah ada. Status SEKARANG
            // 'manual_data' (Phase 4.1 Langkah 2) - status TERSENDIRI,
            // BUKAN lagi disamarkan sebagai 'not_connected' dengan pesan
            // beda, supaya konsumen (JS/test) bisa membedakan "tidak ada
            // data apapun" vs "ada data, tapi bukan dari API" secara
            // eksplisit. Dua-duanya tetap dikecualikan sama dari
            // success/failure judgment di computeOverallStatus(). Audience
            // (subjob instagram_audience) SENGAJA TIDAK dicek di sini -
            // audience independen dari content snapshot (Langkah 16), CSV
            // audience punya jalur impor sendiri (AudienceController::
            // importCsv), tidak dicampur di sini.
            if ($subjob !== self::SUBJOB_INSTAGRAM_AUDIENCE && $this->hasManualDataFor($client, $subjob)) {
                return $this->statusPayload('manual_data', $this->manualDataMessage($subjob), null);
            }

            return $this->statusPayload('not_connected', $this->notConnectedMessage($subjob), null);
        }

        if ($integration->status !== 'active') {
            return $this->statusPayload('needs_reconnect', $this->needsReconnectMessage($subjob), null);
        }

        $jobClass = $this->jobClassFor($subjob);
        $sourceType = $this->sourceTypeFor($subjob);

        $running = $this->isLockHeld($jobClass, $integration->id);
        $queued = ! $running && $this->hasQueuedJob($jobClass, $integration->id);
        $stale = false;

        $lastLog = AnalyticsSyncLog::where('api_integration_id', $integration->id)
            ->where('source_type', $sourceType)
            ->latest()
            ->first();

        if ($running) {
            $status = 'running';
        } elseif ($queued) {
            $status = 'queued';
        } else {
            $status = $this->mapLogStatus($subjob, $lastLog);
            if ($lastLog?->status === 'pending' && $status === 'failed') {
                $stale = true; // dipetakan failed KARENA stale, bukan kegagalan asli - lihat mapLogStatus()
            }
        }

        // Langkah 6/14 (Phase 4) - "sukses" tapi Phase 2 snapshot-history
        // gagal TIDAK BOLEH tetap dilaporkan sebagai success sempurna -
        // turunkan ke partial. Deteksi lewat SnapshotFailureMarker (Phase
        // 4.1 Langkah 4 - marker TERSENTRALISASI, dipakai bareng oleh
        // writer di InstagramAnalyticsSyncService/TikTokAnalyticsSyncService
        // supaya wording writer & detector di sini TIDAK BISA diam-diam
        // drift beda), TANPA menambah kolom baru ke analytics_sync_logs.
        if ($status === 'success' && SnapshotFailureMarker::detectedIn($lastLog?->error_message)) {
            $status = 'partial';
        }

        // Snapshot maintenance correction (Langkah 5) - sama alasan persis
        // seperti SnapshotFailureMarker di atas: refresh known-content yang
        // sebagian gagal TIDAK BOLEH tetap dilaporkan sukses sempurna.
        if ($status === 'success' && KnownContentRefreshFailureMarker::detectedIn($lastLog?->error_message)) {
            $status = 'partial';
        }

        $message = $stale ? $this->staleMessage($subjob) : $this->messageFor($subjob, $status);

        return $this->statusPayload($status, $message, $lastLog);
    }

    /**
     * Phase 4.1 Langkah 3 - PERBAIKAN dari Phase 4 (dulu 'pending' SELALU
     * dipetakan ke 'running' tanpa batas waktu, berpotensi poll selamanya
     * kalau worker crash sebelum sempat update status akhir). Live signal
     * (lock/jobs table, sudah dicek di resolveSubjobStatus() SEBELUM method
     * ini dipanggil) SELALU menang - method ini HANYA dipanggil begitu
     * KEDUANYA sudah pasti negatif (lock tidak dipegang, tidak ada baris di
     * `jobs`), yang berarti proses job (kalau pernah ada) SUDAH PASTI
     * berhenti dijalankan.
     *
     * 'pending' yang MASIH BARU (umur < staleThresholdSecondsFor()) diberi
     * grace window singkat sebagai 'running' (transisi wajar antara log
     * dibuat vs lock benar2 ke-acquire/ke-release - lihat komentar
     * threshold di staleThresholdSecondsFor()). 'pending' yang SUDAH lebih
     * tua dari itu berarti job itu PASTI crash tanpa sempat update status
     * akhir - dikembalikan sebagai 'failed' (pesan aman, BUKAN endless
     * 'running', BUKAN 'success' palsu, TIDAK expose exception/token).
     */
    private function mapLogStatus(string $subjob, ?AnalyticsSyncLog $lastLog): string
    {
        if ($lastLog?->status !== 'pending') {
            return match ($lastLog?->status) {
                'success' => 'success',
                'failed' => 'failed',
                default => 'idle',
            };
        }

        $ageSeconds = $lastLog->updated_at?->diffInSeconds(now()) ?? PHP_FLOAT_MAX;

        return $ageSeconds > $this->staleThresholdSecondsFor($subjob) ? 'failed' : 'running';
    }

    /**
     * Ambang stale DIDASARKAN pada lifecycle job yang SUNGGUHAN, bukan
     * angka dikarang: LOCK_EXPIRE_SECONDS masing-masing job class (600
     * detik buat SyncInstagramAnalyticsJob/SyncTikTokAnalyticsJob, 300
     * detik buat SyncInstagramAudienceJob - lihat konstanta di file job
     * masing-masing) + margin 60 detik (jeda wajar antara AnalyticsSyncLog
     * dibuat vs WithoutOverlapping lock benar2 ke-acquire/ke-release).
     * Kalau lock SUDAH TIDAK dipegang DAN tidak ada baris `jobs` TAPI log
     * masih 'pending' lebih lama dari ini, job itu PASTI sudah berhenti
     * (worker crash) - lock yang benar2 masih hidup TIDAK PERNAH sampai ke
     * method ini (sudah ketangkep sebagai 'running' oleh isLockHeld() di
     * resolveSubjobStatus() duluan).
     */
    private function staleThresholdSecondsFor(string $subjob): int
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_AUDIENCE => 360, // 300 (LOCK_EXPIRE_SECONDS) + 60
            default => 660, // 600 (LOCK_EXPIRE_SECONDS) + 60
        };
    }

    /**
     * @return array{status: string, message: string, synced_count: ?int, skipped_count: ?int, error_message: ?string, finished_at: ?string}
     */
    private function statusPayload(string $status, string $message, ?AnalyticsSyncLog $lastLog): array
    {
        $hasResult = $lastLog && in_array($status, ['success', 'partial', 'failed'], true);

        return [
            'status' => $status,
            'message' => $message,
            // synced_count/skipped_count APA ADANYA dari AnalyticsSyncLog -
            // Langkah 18, JANGAN diberi label "video ditemukan" dkk yang
            // tidak dijamin kontraknya (lihat catatan synced_count di
            // ClientManagementController::tiktokSyncStatus()).
            'synced_count' => $hasResult ? $lastLog->synced_count : null,
            'skipped_count' => $hasResult ? $lastLog->skipped_count : null,
            'error_message' => $hasResult ? $lastLog->error_message : null,
            'finished_at' => $hasResult ? $lastLog->updated_at?->toIso8601String() : null,
        ];
    }

    /**
     * Overall state machine (Phase 4.1 Langkah 2 - PERBAIKAN dari Phase 4).
     *
     * 'not_connected'/'manual_data' = TIDAK ADA ApiIntegration sama sekali
     * buat subjob itu ("belum bisa disync, tidak ada yang bisa direfresh")
     * - dikecualikan TOTAL dari success/failure judgment, PERSIS seperti
     * Phase 4 asli (manual-only platform TIDAK PERNAH bikin overall gagal).
     *
     * 'needs_reconnect' BEDA - integration-nya ADA tapi TIDAK VALID
     * (Langkah 2 audit: Phase 4 asli keliru menyamakan ini dengan
     * not_connected). Sekarang IKUT dihitung:
     * - SEMUA subjob yang actionable (integration ada) needs_reconnect ->
     *   overall = needs_reconnect (contoh F: semua integration inactive).
     * - SEBAGIAN needs_reconnect, sebagian lain sukses/gagal -> overall =
     *   partial (refresh yang diminta TIDAK LENGKAP - contoh C: IG sukses +
     *   TikTok butuh reconnect).
     * - TIDAK ADA yang needs_reconnect DAN semua actionable sukses ->
     *   success (contoh D).
     *
     * @param  array<string, array{status: string}>  $subjobStatuses
     */
    private function computeOverallStatus(array $subjobStatuses): string
    {
        $statuses = collect($subjobStatuses)->pluck('status');

        if ($statuses->isEmpty()) {
            return 'idle';
        }

        // Informational SAJA - tidak ada integration buat subjob ini sama
        // sekali (baik memang belum connect, ATAU cuma punya data manual).
        $actionable = $statuses->reject(fn ($s) => in_array($s, ['not_connected', 'manual_data'], true));

        if ($actionable->isEmpty()) {
            // SEMUA subjob relevan informational - tidak ada satupun yang
            // benar2 bisa di-refresh via API.
            return 'not_connected';
        }

        if ($actionable->contains('running')) {
            return 'running';
        }

        if ($actionable->contains('queued')) {
            return 'queued';
        }

        $attempted = $actionable->reject(fn ($s) => $s === 'idle');

        if ($attempted->isEmpty()) {
            // Cuma ada subjob 'idle' (integration active, TAPI belum
            // PERNAH disync sama sekali) - belum ada operasi buat dilacak.
            return 'idle';
        }

        $reconnect = $attempted->filter(fn ($s) => $s === 'needs_reconnect');

        if ($reconnect->count() === $attempted->count()) {
            // SEMUA integration yang relevan butuh reconnect (contoh F).
            return 'needs_reconnect';
        }

        if ($reconnect->isNotEmpty()) {
            // Campuran needs_reconnect + subjob lain (sukses/gagal) -
            // refresh yang diminta TIDAK LENGKAP walau sebagian sukses
            // (contoh C) - JANGAN overall=success.
            return 'partial';
        }

        if ($attempted->every(fn ($s) => $s === 'success')) {
            return 'success';
        }

        if ($attempted->every(fn ($s) => $s === 'failed')) {
            return 'failed';
        }

        // Campuran success/failed/partial (tanpa needs_reconnect) -
        // Langkah 20: JANGAN rollback yang sudah sukses, JANGAN klaim
        // overall failed kalau ada data valid yang masuk.
        return 'partial';
    }

    /**
     * Freshness (Langkah 14) - observasi genuine TERAKHIR yang benar2
     * tersimpan, BUKAN period coverage (itu urusan PeriodPerformanceService,
     * tidak disentuh di sini sama sekali - Langkah 6 kickoff "JANGAN
     * mengubah calculation semantics Phase 3/3.1").
     */
    public function lastObservationAt(Client $client, ?int $platformId): ?Carbon
    {
        $latest = ContentMetricSnapshot::where('client_id', $client->id)
            ->when($platformId, fn ($q) => $q->where('platform_id', $platformId))
            ->max('updated_at');

        return $latest ? Carbon::parse($latest) : null;
    }

    /**
     * Peek non-invasif ke WithoutOverlapping lock (pola PERSIS
     * ClientManagementController::tiktokSyncStatus()/syncInstagramAudience()
     * - get lalu langsung release kalau berhasil, cuma buat tahu APAKAH
     * sedang dipegang, bukan buat benar2 memegangnya).
     */
    private function isLockHeld(string $jobClass, int $integrationId): bool
    {
        $lock = Cache::lock($jobClass::cacheLockKey($integrationId), 10);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * Generalisasi ClientManagementController::hasQueuedTiktokJob() - scan
     * tabel `jobs` (queue driver 'database') buat instance job class
     * manapun yang property apiIntegrationId-nya cocok. Dipakai KEDUANYA:
     * status resolution (Langkah 6/11) DAN dispatch() (Langkah 19,
     * duplicate protection server-side).
     */
    private function hasQueuedJob(string $jobClass, int $integrationId): bool
    {
        $shortName = class_basename($jobClass);

        return DB::table('jobs')
            ->where('payload', 'like', "%{$shortName}%")
            ->get(['payload'])
            ->contains(function ($row) use ($jobClass, $integrationId) {
                $payload = json_decode($row->payload, true);
                $serializedCommand = $payload['data']['command'] ?? null;
                if (! is_string($serializedCommand)) {
                    return false;
                }

                $command = @unserialize($serializedCommand);

                return $command instanceof $jobClass && $command->apiIntegrationId === $integrationId;
            });
    }

    private function integrationFor(Client $client, string $subjob): ?ApiIntegration
    {
        $platformName = $subjob === self::SUBJOB_TIKTOK_CONTENT ? 'TikTok' : 'Instagram';

        return $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', $platformName))
            ->first();
    }

    private function jobClassFor(string $subjob): string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT => SyncInstagramAnalyticsJob::class,
            self::SUBJOB_INSTAGRAM_AUDIENCE => SyncInstagramAudienceJob::class,
            self::SUBJOB_TIKTOK_CONTENT => SyncTikTokAnalyticsJob::class,
        };
    }

    private function sourceTypeFor(string $subjob): string
    {
        return $subjob === self::SUBJOB_INSTAGRAM_AUDIENCE ? 'audience_api_sync' : 'api_sync';
    }

    private function notConnectedMessage(string $subjob): string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT, self::SUBJOB_INSTAGRAM_AUDIENCE => 'Instagram belum terhubung untuk client ini.',
            self::SUBJOB_TIKTOK_CONTENT => 'TikTok belum terhubung untuk client ini.',
        };
    }

    /**
     * CSV/manual = baris ContentMetric dengan KEDUA snapshot FK null (Phase
     * 3 docblock computeAggregate() - identitas persis yang dipakai
     * calculation engine buat memisahkan jalur CSV dari API, disalin
     * kriterianya di sini, BUKAN logic baru).
     */
    private function hasManualDataFor(Client $client, string $subjob): bool
    {
        $platformName = $subjob === self::SUBJOB_TIKTOK_CONTENT ? 'TikTok' : 'Instagram';
        $platformId = Platform::where('name', $platformName)->value('id');

        if (! $platformId) {
            return false;
        }

        return \App\Models\ContentMetric::where('client_id', $client->id)
            ->where('platform_id', $platformId)
            ->whereNull('instagram_media_snapshot_id')
            ->whereNull('tiktok_video_snapshot_id')
            ->exists();
    }

    private function manualDataMessage(string $subjob): string
    {
        $platform = $subjob === self::SUBJOB_TIKTOK_CONTENT ? 'TikTok' : 'Instagram';

        return "Data {$platform} untuk client ini berasal dari input manual.";
    }

    private function needsReconnectMessage(string $subjob): string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT, self::SUBJOB_INSTAGRAM_AUDIENCE => 'Koneksi Instagram butuh dihubungkan ulang.',
            self::SUBJOB_TIKTOK_CONTENT => 'Koneksi TikTok butuh dihubungkan ulang.',
        };
    }

    private function messageFor(string $subjob, string $status): string
    {
        $label = $this->labelFor($subjob);

        return match ($status) {
            'queued' => "{$label}: sedang antre...",
            'running' => "{$label}: sedang mengambil data...",
            'partial' => "{$label}: selesai sebagian.",
            'success' => "{$label}: berhasil disinkronkan.",
            'failed' => "{$label}: gagal disinkronkan.",
            default => "{$label}: belum pernah disinkronkan.",
        };
    }

    /**
     * Pesan KHUSUS buat kasus stale-pending (Phase 4.1 Langkah 3) - beda
     * dari kegagalan sync asli (yang biasanya punya error_message jelas
     * dari API), ini murni "kita tidak tahu apa yang terjadi, proses-nya
     * berhenti tanpa laporan akhir" - TIDAK menyebut kata teknis
     * (exception/worker/lock) ke user, cukup ajakan coba lagi.
     */
    private function staleMessage(string $subjob): string
    {
        $label = $this->labelFor($subjob);

        return "{$label}: sinkronisasi sebelumnya terhenti tanpa status akhir yang jelas. Coba sinkronkan ulang.";
    }

    private function labelFor(string $subjob): string
    {
        return match ($subjob) {
            self::SUBJOB_INSTAGRAM_CONTENT => 'Analitik Konten Instagram',
            self::SUBJOB_INSTAGRAM_AUDIENCE => 'Audience Insights Instagram',
            self::SUBJOB_TIKTOK_CONTENT => 'Analitik TikTok',
        };
    }
}
