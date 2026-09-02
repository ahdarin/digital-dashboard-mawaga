<?php

namespace App\Services;

/**
 * Analytics V2 Phase B - kategori kegagalan item-level yang dipakai
 * AnalyticsSyncFailure.category, DIPETAKAN dari kategori exception yang
 * SUDAH ADA (InstagramApiException::AUTHENTICATION/RATE_LIMIT/NETWORK/
 * SERVER_ERROR/MALFORMED_RESPONSE/UNKNOWN, TikTokApiException sama) via
 * fromApiExceptionCategory() - BUKAN taksonomi baru yang independen, biar
 * "authentication" di sini SELALU berarti persis yang sama dengan
 * markNeedsReconnect() yang sudah dipakai di seluruh pipeline sync.
 *
 * UNSUPPORTED/PROVIDER_UNAVAILABLE sengaja TIDAK bisa datang dari
 * ApiException manapun (keduanya bukan error API - itu jawaban VALID dari
 * provider yang cuma berarti "metric ini memang tidak ada untuk akun/media
 * ini") - caller yang tahu konteksnya (mis. media_product_type=STORY,
 * demografi threshold Meta) yang menandai secara eksplisit, retryable()
 * SELALU false buat keduanya (retry tidak akan pernah mengubah hasil).
 */
final class AnalyticsFailureCategory
{
    public const AUTHENTICATION = 'authentication';

    public const TRANSIENT = 'transient';

    public const UNSUPPORTED = 'unsupported';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const UNKNOWN = 'unknown';

    /**
     * Auth TIDAK PERNAH retryable (token rusak, percobaan ulang pasti gagal
     * identik - reconnect manual satu-satunya jalan). Unsupported/
     * provider_unavailable TIDAK PERNAH retryable (bukan technical failure,
     * lihat docblock kelas). Transient/unknown DIANGGAP retryable dengan
     * batas (bounded automatic retry, lihat AnalyticsSyncFailure model) -
     * unknown defensif diperlakukan sama seperti transient supaya tidak
     * diam-diam permanen gagal cuma karena kategori tidak dikenali.
     */
    public static function isRetryable(string $category): bool
    {
        return in_array($category, [self::TRANSIENT, self::UNKNOWN], true);
    }

    /**
     * Reuse SATU-SATUNYA sumber kebenaran kategori exception yang sudah
     * ada (InstagramApiException::AUTHENTICATION/RATE_LIMIT/NETWORK/
     * SERVER_ERROR/MALFORMED_RESPONSE/UNKNOWN, TikTokApiException identik) -
     * JANGAN hand-roll pemetaan string lain di tempat lain, itu persis
     * kelas bug drift yang mau dicegah pola marker terpusat lain di
     * codebase ini (SnapshotFailureMarker/KnownContentRefreshFailureMarker).
     */
    public static function fromApiExceptionCategory(string $exceptionCategory): string
    {
        return match ($exceptionCategory) {
            'authentication' => self::AUTHENTICATION,
            'rate_limit', 'network', 'server_error' => self::TRANSIENT,
            default => self::UNKNOWN,
        };
    }
}
