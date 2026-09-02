<?php

namespace App\Jobs;

use App\Exceptions\TikTokApiException;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Services\TikTokAnalyticsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * MIRROR SyncInstagramAnalyticsJob - background job buat tombol "Sync
 * Content" TikTok di Settings, TIDAK menahan HTTP request user. Payload
 * SENGAJA cuma ID + primitif, TIDAK PERNAH access_token/ApiIntegration
 * instance utuh (token dibaca ulang dari DB via encrypted cast begitu job
 * jalan) - sama alasan persis dengan Instagram.
 *
 * AnalyticsSyncLog dibuat DI DALAM handle(), SETELAH WithoutOverlapping
 * meloloskan job ini - sama fix "stale pending log" yang sudah diterapkan
 * di Instagram.
 */
class SyncTikTokAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    // TikTok video/list dibatasi MAX_PAGES=10 x 20/halaman = 200 video per
    // sync, jauh lebih kecil dari kasus Instagram terukur (27 media/window)
    // - 600 detik tetap dipertahankan sebagai lock ceiling yang sama biar
    // konsisten & aman kalau worker crash di tengah jalan, bukan dikalibrasi
    // dari data TikTok real (belum ada, lihat catatan LOCK_EXPIRE_SECONDS
    // Instagram - alasan sama persis di sini).
    private const LOCK_EXPIRE_SECONDS = 600;

    public function __construct(
        public readonly int $apiIntegrationId,
        public readonly string $syncMode,
        public readonly string $rangeFrom,
        public readonly string $rangeTo,
        public readonly int $userId,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    private static function lockKeySuffix(int $apiIntegrationId): string
    {
        return "tiktok-sync-{$apiIntegrationId}";
    }

    public static function cacheLockKey(int $apiIntegrationId): string
    {
        return 'laravel-queue-overlap:'.self::class.':'.self::lockKeySuffix($apiIntegrationId);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::lockKeySuffix($this->apiIntegrationId)))
                ->expireAfter(self::LOCK_EXPIRE_SECONDS)
                ->dontRelease(),
        ];
    }

    public function handle(TikTokAnalyticsSyncService $service): void
    {
        $integration = ApiIntegration::findOrFail($this->apiIntegrationId);

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

        // TikTok video/list tidak punya filter "since" server-side (lihat
        // TikTokAnalyticsService::getVideoList()) - early-stop pagination
        // dilakukan client-side pakai $cutoff = LOWER BOUND ($rangeFrom).
        // TikTok mengurutkan video newest-first, jadi begitu 1 video
        // create_time-nya < $cutoff, sisa halaman itu & seterusnya pasti
        // lebih lama juga - aman berhenti di situ.
        //
        // BUG SEBELUMNYA (fixed): cutoff sempat dibaca dari $rangeTo (upper
        // bound, biasanya "hari ini" buat default sync). Karena HAMPIR
        // SEMUA video create_time-nya < hari ini, pagination berhenti di
        // video pertama halaman pertama - video_count jadi 0 padahal akun
        // punya video, dan sync tetap dianggap 'success' karena kode
        // menganggap videos=[] sama dengan "API asli memang kosong".
        $cutoff = Carbon::parse($this->rangeFrom)->startOfDay();

        try {
            $service->sync($integration, $syncLog, $cutoff, $this->userId);
        } catch (TikTokApiException $e) {
            if (! $e->isRetryable()) {
                $service->markFailed($integration, $syncLog, $e->getMessage(), $e->category);
                $this->fail($e);
                return;
            }

            throw $e;
        }

        // Audit sync horizon (Langkah 3) - refresh known/tracked video di
        // luar discovery window normal, HANYA buat default sync. DIBUNGKUS
        // try/catch TERPISAH - kegagalan di sini TIDAK PERNAH boleh
        // menggagalkan/retry job yang sync utamanya sudah sukses di atas.
        if ($this->syncMode === 'default') {
            try {
                $service->refreshKnownVideos($integration, $syncLog, $this->userId);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SyncTikTokAnalyticsJob: refreshKnownVideos gagal (sync utama tetap sukses)', [
                    'api_integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

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

        $category = $e instanceof TikTokApiException ? $e->category : TikTokApiException::UNKNOWN;

        app(TikTokAnalyticsSyncService::class)->markFailed($integration, $syncLog, $e->getMessage(), $category);
    }
}
