<?php

namespace App\Exceptions;

use Exception;

/**
 * Dilempar InstagramAnalyticsService kalau panggilan API gagal - bawa
 * kategori kesalahan biar SyncInstagramAnalyticsJob bisa memutuskan retry
 * atau tidak TANPA menebak-nebak dari isi pesan. Kategori diambil dari
 * response nyata (HTTP status/error code Meta) atau tipe exception HTTP
 * client Laravel - TIDAK ada kode error Instagram yang dikarang.
 *
 * $message aman ditampilkan ke user (nggak ada token/credential di
 * dalamnya) - detail lengkap tetap di-log terpisah oleh pemanggil.
 */
class InstagramApiException extends Exception
{
    public const AUTHENTICATION = 'authentication';   // 401 / code 190 - token invalid/expired
    public const RATE_LIMIT = 'rate_limit';            // 429
    public const NETWORK = 'network';                  // timeout/connection error (Http\Client\ConnectionException)
    public const SERVER_ERROR = 'server_error';         // 5xx dari Instagram
    public const MALFORMED_RESPONSE = 'malformed_response'; // response 200 tapi struktur nggak sesuai dugaan
    public const UNKNOWN = 'unknown';

    public function __construct(
        string $message,
        public readonly string $category,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Cuma transient failure yang boleh di-retry otomatis (network/rate
     * limit/server error 5xx) - auth invalid/permission/malformed request
     * TIDAK di-retry karena percobaan ulang pasti gagal lagi dengan cara
     * yang sama, cuma buang-buang waktu & kuota API (Langkah 10).
     */
    public function isRetryable(): bool
    {
        return in_array($this->category, [self::NETWORK, self::RATE_LIMIT, self::SERVER_ERROR], true);
    }
}
