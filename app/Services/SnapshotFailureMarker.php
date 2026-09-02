<?php

namespace App\Services;

/**
 * Phase 4.1 (Langkah 4) - kontrak SATU-SATUNYA buat menandai & mendeteksi
 * "content_metrics tersimpan TAPI content_metric_snapshots (Phase 2) gagal"
 * di dalam AnalyticsSyncLog.error_message, TANPA kolom/skema baru.
 *
 * SEBELUM ini: InstagramAnalyticsSyncService::saveMetricSafely() &
 * TikTokAnalyticsSyncService::saveMetricSafely() masing-masing menulis
 * string literalnya sendiri, dan AnalyticsSyncOrchestrator punya CONST
 * TERPISAH buat mendeteksinya - 3 tempat berbeda yang kebetulan harus
 * cocok persis, gampang diam-diam drift kalau salah satu diubah tanpa yang
 * lain. SEKARANG cuma class ini yang tahu bentuk teks-nya - writer (2 sync
 * service) dan detector (orchestrator) SAMA-SAMA panggil method di sini.
 *
 * TIDAK PERNAH menyertakan raw exception detail sensitif/token - $detail
 * yang dioper caller HARUS sudah berupa pesan aman (persis seperti
 * $e->getMessage() dari InstagramApiException/TikTokApiException, yang
 * sudah didesain aman ditampilkan - lihat throwApiError() di service
 * masing-masing).
 *
 * Phase 4.1 (Langkah 5) - marker DIUBAH dari frasa bahasa Indonesia
 * ('content_metric_snapshots gagal') jadi PREFIX terkontrol berbentuk
 * bracket ([SNAPSHOT_HISTORY_PARTIAL]) - bukan karena frasa lama pernah
 * kebukti false-positive, tapi supaya kontraknya TIDAK BERGANTUNG pada
 * kata-kata natural yang bisa (di masa depan, tanpa sengaja) dipakai
 * ulang developer lain di Log message/error text lain manapun. Prefix
 * bracket JELAS bukan kalimat manusia biasa - jauh lebih kecil
 * kemungkinan collision dibanding scan kata generik semacam "snapshot"/
 * "warning"/"failed" (yang MEMANG rentan, itu kenapa marker ini bukan
 * cuma salah satu dari kata itu).
 */
final class SnapshotFailureMarker
{
    private const PREFIX = '[SNAPSHOT_HISTORY_PARTIAL]';

    /**
     * Bungkus 1 baris detail kegagalan snapshot-history buat ditambahkan ke
     * $summary['details'] (dipakai InstagramAnalyticsSyncService &
     * TikTokAnalyticsSyncService::saveMetricSafely()). Marker SELALU di
     * depan - pesan manusia (aman ditampilkan) menyusul setelahnya.
     */
    public static function wrap(string $itemLabel, string $safeDetail): string
    {
        return self::PREFIX." {$itemLabel}: content_metrics tersimpan, TAPI content_metric_snapshots gagal - {$safeDetail}";
    }

    /**
     * Cek apakah error_message (AnalyticsSyncLog, bisa gabungan beberapa
     * detail lewat implode(' | ', ...)) mengandung marker ini - dipakai
     * AnalyticsSyncOrchestrator buat menurunkan status 'success' jadi
     * 'partial'. Cek exact prefix string, BUKAN kata generik apapun.
     */
    public static function detectedIn(?string $errorMessage): bool
    {
        return $errorMessage !== null && str_contains($errorMessage, self::PREFIX);
    }
}
