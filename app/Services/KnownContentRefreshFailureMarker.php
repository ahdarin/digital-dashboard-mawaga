<?php

namespace App\Services;

/**
 * Sibling dari SnapshotFailureMarker - kontrak SATU-SATUNYA buat menandai
 * & mendeteksi "sync utama sukses TAPI known-content refresh (observation
 * rotation di luar discovery window) sebagian/seluruhnya gagal" di dalam
 * AnalyticsSyncLog.error_message, TANPA kolom/skema baru. Writer
 * (InstagramAnalyticsSyncService::refreshKnownMedia()/
 * TikTokAnalyticsSyncService::refreshKnownVideos()) dan detector
 * (AnalyticsSyncOrchestrator) SAMA-SAMA panggil method di sini, persis
 * pola SnapshotFailureMarker - jangan hand-roll string di tempat lain.
 *
 * TIDAK PERNAH menyertakan raw exception detail/token/auth header/client
 * secret/refresh token - $failed/$total di sini murni angka hitungan,
 * tidak ada teks bebas dari caller yang masuk ke pesan ini sama sekali
 * (beda dari SnapshotFailureMarker::wrap() yang menerima $safeDetail dari
 * caller - marker ini sengaja TIDAK menerima detail bebas apapun, jadi
 * tidak ada permukaan buat kebocoran lewat sini).
 */
final class KnownContentRefreshFailureMarker
{
    private const PREFIX = '[KNOWN_CONTENT_REFRESH_PARTIAL]';

    public static function wrap(int $failed, int $total): string
    {
        return self::PREFIX." {$failed}/{$total} konten tersimpan gagal diperbarui.";
    }

    /**
     * Cek apakah error_message (AnalyticsSyncLog, bisa gabungan beberapa
     * detail lewat implode(' | ', ...) bareng marker lain seperti
     * SnapshotFailureMarker) mengandung marker ini - dipakai
     * AnalyticsSyncOrchestrator buat menurunkan status 'success' jadi
     * 'partial'. Cek exact prefix string, BUKAN kata generik apapun.
     */
    public static function detectedIn(?string $errorMessage): bool
    {
        return $errorMessage !== null && str_contains($errorMessage, self::PREFIX);
    }
}
