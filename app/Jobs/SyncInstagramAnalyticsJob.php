<?php

namespace App\Jobs;

use App\Exceptions\InstagramApiException;
use App\Models\AnalyticsSyncLog;
use App\Models\AnalyticsSyncTask;
use App\Models\ApiIntegration;
use App\Services\InstagramAnalyticsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Background job buat tombol "Sync Now"/"Sync Selected Month" di web -
 * dipicu SettingsController::syncInstagram(), TIDAK menahan HTTP request
 * user (dispatch lalu redirect langsung, sync-nya jalan di queue worker).
 *
 * Payload SENGAJA cuma ID + primitif (api_integration_id, sync_mode,
 * range_from/to, user_id) - TIDAK PERNAH access_token atau ApiIntegration
 * instance utuh, biar token nggak ikut ke-serialize ke tabel jobs (yang
 * isinya plaintext JSON). Token dibaca ulang dari DB (encrypted cast,
 * decrypt otomatis) begitu job jalan.
 *
 * PENTING (fix stale pending log): AnalyticsSyncLog SENGAJA dibuat DI
 * DALAM handle(), SETELAH WithoutOverlapping meloloskan job ini - job
 * yang dibuang middleware (overlap) nggak akan pernah sampai baris
 * pembuatan log itu, jadi TIDAK MUNGKIN ninggalin log 'pending' basi.
 * Dulu log dibuat di controller SEBELUM dispatch - itu yang jadi sumber
 * bug stale pending, sudah diperbaiki di sini.
 *
 * Business logic sync SAMA PERSIS dengan yang dipakai Artisan command
 * (analytics:sync-instagram) - lihat InstagramAnalyticsSyncService,
 * satu-satunya tempat orkestrasinya, di sini cuma resolve+retry policy.
 */
class SyncInstagramAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Retry cuma buat transient failure (network/rate-limit/server_error -
    // lihat InstagramApiException::isRetryable()). Non-retryable (token
    // invalid, dst) langsung fail() di handle(), nggak nyampe sini.
    public $tries = 3;

    // Lock nggak boleh nyangkut selamanya kalau worker crash di tengah
    // sync. 600 detik (10 menit) dipilih dari durasi NYATA yang sudah
    // diukur (akun besar Top Scorer Arena: 27 media/window 2 bulan = 31
    // detik) + margin besar buat akun jauh lebih aktif - bukan angka
    // dikarang, tinjau ulang kalau ada akun yang beneran mendekati ini.
    private const LOCK_EXPIRE_SECONDS = 600;

    public function __construct(
        public readonly int $apiIntegrationId,
        public readonly string $syncMode,
        public readonly string $rangeFrom,
        public readonly string $rangeTo,
        public readonly int $userId,
        // Analytics V2 Phase B - nullable, default null (jalur lama tanpa
        // AnalyticsSyncTask, mis. SettingsController::syncInstagram() yang
        // dispatch job ini langsung tanpa lewat AnalyticsSyncOrchestrator,
        // TETAP jalan identik apa adanya - Task murni ADDITIVE).
        public readonly ?int $syncTaskId = null,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120, 300]; // 30 detik, 2 menit, 5 menit
    }

    /**
     * Suffix key TANPA prefix Laravel - dipakai WithoutOverlapping di
     * middleware() (dia yang nambahin prefix+nama job sendiri).
     */
    private static function lockKeySuffix(int $apiIntegrationId): string
    {
        return "instagram-sync-{$apiIntegrationId}";
    }

    /**
     * Key CACHE LENGKAP (dengan prefix) - dipakai controller buat
     * "peek" non-invasif (coba ambil lock, langsung dilepas lagi kalau
     * berhasil) SEBELUM dispatch, biar user dapat pesan "sedang berjalan"
     * instan tanpa perlu bikin/baca AnalyticsSyncLog.
     *
     * HARUS selalu sinkron dengan format internal WithoutOverlapping
     * ('laravel-queue-overlap:' . nama job class . ':' . key) - Laravel
     * tidak expose cara resmi baca key computed itu dari luar tanpa
     * benar-benar menjalankan job asli. Sudah diverifikasi cocok lewat
     * pengetesan nyata (lock manual dengan key ini berhasil memblokir
     * job asli, dan sebaliknya).
     */
    public static function cacheLockKey(int $apiIntegrationId): string
    {
        return 'laravel-queue-overlap:'.self::class.':'.self::lockKeySuffix($apiIntegrationId);
    }

    /**
     * Satu ApiIntegration cuma boleh punya 1 sync Instagram jalan
     * bersamaan - lock key berbasis api_integration_id (BUKAN client_id,
     * biar siap kalau nanti 1 client punya >1 akun Instagram). dontRelease()
     * = kalau ketahuan overlap, job kedua langsung dibuang (bukan diulang
     * nanti) - controller sudah cek duluan sebelum dispatch (poin di atas),
     * ini pengaman defense-in-depth buat race condition di celah sempit.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::lockKeySuffix($this->apiIntegrationId)))
                ->expireAfter(self::LOCK_EXPIRE_SECONDS)
                ->dontRelease(),
        ];
    }

    public function handle(InstagramAnalyticsSyncService $service): void
    {
        $integration = ApiIntegration::findOrFail($this->apiIntegrationId);

        // firstOrCreate (bukan create polos) - kunci ke kombinasi yang
        // sama persis tiap retry (Laravel re-run handle() dari awal tiap
        // percobaan), biar retry ke-2/3 nemuin log 'pending' yang SAMA,
        // bukan bikin baris baru tiap kali dicoba ulang.
        $syncLog = AnalyticsSyncLog::firstOrCreate(
            [
                'api_integration_id' => $integration->id,
                'status' => 'pending',
                'sync_mode' => $this->syncMode,
                'range_from' => $this->rangeFrom,
                'range_to' => $this->rangeTo,
            ],
            [
                'client_id' => $integration->client_id,
                'platform_id' => $integration->platform_id,
                'imported_by' => $this->userId,
                'source_type' => 'api_sync',
            ]
        );

        $since = Carbon::parse($this->rangeFrom)->startOfDay();
        $until = Carbon::parse($this->rangeTo)->endOfDay();

        $task = $this->syncTaskId ? AnalyticsSyncTask::find($this->syncTaskId) : null;
        $syncResult = null;

        try {
            $syncResult = $service->sync($integration, $syncLog, $since, $until, $this->userId, $task);
        } catch (InstagramApiException $e) {
            if (! $e->isRetryable()) {
                $service->markFailed($integration, $syncLog, $e->getMessage(), $e->category);
                $task?->finish($e->category === InstagramApiException::AUTHENTICATION ? 'needs_reconnect' : 'failed');
                $this->fail($e);
                return;
            }

            throw $e; // retryable - biar Laravel jadwalkan ulang sesuai $tries/backoff()
        }

        // Audit sync horizon (Langkah 3) - refresh known/tracked media di
        // luar discovery window normal, HANYA buat default sync (historical
        // sync 1 bulan spesifik tidak perlu efek samping ini). DIBUNGKUS
        // try/catch TERPISAH - kegagalan di sini TIDAK PERNAH boleh
        // menggagalkan/retry job yang sync utamanya sudah sukses di atas.
        // $task yang SAMA diteruskan (bukan Task baru) - satu Task Instagram
        // Content mencakup kedua fase (Langkah "TASK" di spec: task
        // TIDAK dipecah per-metode internal), lihat AnalyticsSyncTask::finish()
        // buat kenapa 2 finish() berurutan aman (severity tertinggi menang).
        if ($this->syncMode === 'default') {
            try {
                // PASS 4.1 - run_started_at dari sync() barusan diteruskan
                // sebagai exclude boundary, biar refreshKnownMedia() TIDAK
                // memilih ulang media yang baru saja disentuh sync() di
                // atas dalam RUN yang sama (lihat docblock refreshKnownMedia()).
                $service->refreshKnownMedia($integration, $syncLog, $this->userId, $task, $syncResult['run_started_at'] ?? null);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SyncInstagramAnalyticsJob: refreshKnownMedia gagal (sync utama tetap sukses)', [
                    'api_integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
                $task?->finish('partial');
            }
        }
    }

    /**
     * Dipanggil Laravel otomatis setelah SEMUA retry habis untuk kegagalan
     * yang retryable (network/rate-limit/server_error abis dicoba 3x tetap
     * gagal) - satu-satunya jalur exhausted-retry sampai ke titik final.
     * Kegagalan non-retryable sudah ditandai duluan di handle() lewat
     * markFailed()+fail() manual, jadi di sini di-skip biar nggak ketiban
     * update dobel/salah timpa (dicek dari status syncLog masih 'pending').
     */
    public function failed(\Throwable $e): void
    {
        $integration = ApiIntegration::find($this->apiIntegrationId);
        $syncLog = AnalyticsSyncLog::where('api_integration_id', $this->apiIntegrationId)
            ->where('status', 'pending')
            ->where('sync_mode', $this->syncMode)
            ->where('range_from', $this->rangeFrom)
            ->where('range_to', $this->rangeTo)
            ->latest()
            ->first();

        if (! $integration || ! $syncLog) {
            return;
        }

        $category = $e instanceof InstagramApiException ? $e->category : InstagramApiException::UNKNOWN;

        app(InstagramAnalyticsSyncService::class)->markFailed($integration, $syncLog, $e->getMessage(), $category);

        $this->syncTaskId && AnalyticsSyncTask::find($this->syncTaskId)
            ?->finish($category === InstagramApiException::AUTHENTICATION ? 'needs_reconnect' : 'failed');
    }
}
