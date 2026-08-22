<?php

namespace App\Exceptions;

use Exception;

/**
 * Mirror InstagramApiException - dilempar TikTokAnalyticsService kalau
 * panggilan API gagal, bawa kategori kesalahan biar SyncTikTokAnalyticsJob
 * bisa memutuskan retry atau tidak TANPA menebak-nebak dari isi pesan.
 * Kategori diambil dari response nyata TikTok (HTTP status/error.code resmi,
 * lihat https://developers.tiktok.com/doc/tiktok-api-v2-error-code) atau
 * tipe exception HTTP client Laravel.
 *
 * $message aman ditampilkan ke user (nggak ada token/credential di
 * dalamnya) - detail lengkap tetap di-log terpisah oleh pemanggil.
 */
class TikTokApiException extends Exception
{
    public const AUTHENTICATION = 'authentication';   // 401 / access_token_invalid / access_token_expired / scope_not_authorized
    public const RATE_LIMIT = 'rate_limit';            // 429 / rate_limit_exceeded
    public const NETWORK = 'network';                  // timeout/connection error (Http\Client\ConnectionException)
    public const SERVER_ERROR = 'server_error';         // 5xx dari TikTok / internal_error
    public const MALFORMED_RESPONSE = 'malformed_response'; // response 200 tapi struktur tidak sesuai dugaan
    public const UNKNOWN = 'unknown';

    public function __construct(
        string $message,
        public readonly string $category,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Sama persis kebijakan Instagram (lihat InstagramApiException) - cuma
     * transient failure yang di-retry otomatis.
     */
    public function isRetryable(): bool
    {
        return in_array($this->category, [self::NETWORK, self::RATE_LIMIT, self::SERVER_ERROR], true);
    }
}
