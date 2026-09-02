<?php

namespace App\Services;

use App\Models\ContentMetricSnapshot;
use Illuminate\Support\Carbon;

/**
 * Hasil 1 kali kalkulasi period performance buat SATU content unit (identitas
 * instagram_media_snapshot_id ATAU tiktok_video_snapshot_id) - dipakai
 * PeriodPerformanceService::computeContentDelta(). Lihat docblock kelas itu
 * buat penjelasan lengkap semantik boundary/coverage (CASE A/B/C/D).
 *
 * coverage_status:
 * - full        -> delta VALID untuk periode penuh (baseline tepat di
 *                  boundary ideal, current mencapai period_end).
 * - partial     -> ada angka observed yang bisa ditampilkan, TAPI bukan
 *                  exact gain periode penuh (baseline lebih tua dari ideal,
 *                  atau current belum mencapai period_end, atau riwayat baru
 *                  mulai di tengah periode).
 * - unavailable -> TIDAK ADA angka yang bisa dipertanggungjawabkan sama
 *                  sekali (belum ada observasi, atau terdeteksi metric
 *                  reset/correction yang membuat delta tidak bisa dipercaya).
 */
final class ContentPeriodResult
{
    public const FULL = 'full';

    public const PARTIAL = 'partial';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, int|null>  $delta  keys: views, likes, comments, shares, saves, reach, impressions - null berarti komponen ini TIDAK BISA dihitung (bukan 0)
     */
    public function __construct(
        public readonly string $coverageStatus,
        public readonly ?Carbon $coverageFrom,
        public readonly ?Carbon $coverageTo,
        public readonly ?string $reason,
        public readonly array $delta = [],
        public readonly ?float $engagementRate = null,
        public readonly ?ContentMetricSnapshot $baselineSnapshot = null,
        public readonly ?ContentMetricSnapshot $currentSnapshot = null,
    ) {
    }

    public static function unavailable(string $reason): self
    {
        return new self(self::UNAVAILABLE, null, null, $reason);
    }

    public static function full(Carbon $from, Carbon $to, array $delta, ?float $engagementRate, ?ContentMetricSnapshot $baseline, ?ContentMetricSnapshot $current): self
    {
        return new self(self::FULL, $from, $to, null, $delta, $engagementRate, $baseline, $current);
    }

    public static function partial(Carbon $from, Carbon $to, string $reason, array $delta, ?float $engagementRate, ?ContentMetricSnapshot $baseline, ?ContentMetricSnapshot $current): self
    {
        return new self(self::PARTIAL, $from, $to, $reason, $delta, $engagementRate, $baseline, $current);
    }

    public function isUsable(): bool
    {
        return $this->coverageStatus !== self::UNAVAILABLE;
    }

    public function views(): ?int
    {
        return $this->delta['views'] ?? null;
    }

    /**
     * Pesan siap-tampil buat UI (Langkah 11) - JANGAN expose internal
     * exception/reason mentah ke user, selalu lewat method ini.
     */
    public function coverageMessage(): ?string
    {
        return match ($this->reason) {
            'missing_baseline' => 'Belum ada observasi sebelum periode ini dimulai.',
            'missing_current' => 'Belum ada data performa yang teramati untuk konten ini.',
            'baseline_too_old' => 'Data yang teramati mencakup rentang lebih lama dari periode terpilih (baseline lebih tua dari awal periode).',
            'current_before_period_end' => 'Observasi terakhir belum mencapai akhir periode terpilih.',
            'metric_reset_or_correction' => 'Terdeteksi penurunan metrik kumulatif (kemungkinan koreksi data API) - gain periode ini tidak dapat dipercaya.',
            'history_started_mid_period' => 'Riwayat data untuk konten ini baru mulai di tengah periode - angka yang tampil adalah gain yang benar-benar teramati, bukan gain periode penuh.',
            'manual_recorded' => 'Data dari input manual/CSV - mencakup baris yang tersedia, bukan diverifikasi mencakup setiap hari dalam periode.',
            default => null,
        };
    }

    /**
     * PASS 2 (Langkah "DATA AVAILABILITY REASONS") - metadata TAMBAHAN
     * (BUKAN pengganti coverageStatus/reason existing yang sudah dites
     * ekstensif) buat Pass 3 UI, memetakan reason internal ke taksonomi
     * bersama yang SAMA persis stringnya dengan
     * AnalyticsFailureCategory (Pass 1) - "available", "unsupported",
     * "provider_unavailable", "insufficient_history", "sync_failed",
     * "no_activity" - biar Pass 3 bisa treat sinyal availability dari
     * layer sync MAUPUN layer period-engine secara seragam.
     *
     * Pemetaan approximate buat beberapa reason (didokumentasikan, BUKAN
     * 1:1 sempurna - reason internal di sini didesain buat precision
     * kalkulasi, bukan buat taksonomi UI):
     * - metric_reset_or_correction -> sync_failed (bukan literally
     *   kegagalan sync, tapi closest bucket: angka tidak bisa dipercaya
     *   karena provider correction, bukan threshold/unsupported).
     * - manual_recorded -> available (datanya SENDIRI genuine tersedia,
     *   cuma completeness-nya beda axis dari availability).
     */
    public function availabilityCategory(): string
    {
        return match ($this->reason) {
            null => 'available',
            'manual_recorded' => 'available',
            'missing_baseline', 'baseline_too_old', 'current_before_period_end', 'history_started_mid_period' => 'insufficient_history',
            'missing_current' => 'insufficient_history',
            'metric_reset_or_correction' => 'sync_failed',
            default => 'insufficient_history',
        };
    }
}
