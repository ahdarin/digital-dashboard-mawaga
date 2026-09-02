<?php

namespace App\Services;

/**
 * PASS 3 - "DATA HEALTH UX" (Langkah J). SATU-SATUNYA tempat kategori
 * availability (dari ContentPeriodResult::availabilityCategory() ATAU
 * ditandai manual oleh caller yang tahu konteks provider - mis. TikTok
 * Display API memang tidak pernah menyediakan reach/demographics/active
 * hours, Instagram threshold "Not enough users") diterjemahkan jadi copy
 * user-facing Indonesia yang KONSISTEN - TIDAK ADA view manapun yang boleh
 * hand-roll pesan sendiri buat kategori yang sama (persis alasan pola
 * marker terpusat lain di codebase ini - SnapshotFailureMarker dkk).
 *
 * BUKAN kalkulasi/kategorisasi baru - murni presentation layer di atas
 * taksonomi yang SUDAH ADA sejak Pass 1 (availability-reason metadata) dan
 * Pass 2 (ContentPeriodResult::availabilityCategory()). Genuine zero TIDAK
 * PERNAH lewat kelas ini - render 0 apa adanya, jangan pernah panggil
 * label() buat nilai yang genuinely nol.
 */
final class AvailabilityPresenter
{
    public const AVAILABLE = 'available';

    public const UNSUPPORTED = 'unsupported';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const INSUFFICIENT_HISTORY = 'insufficient_history';

    public const SYNC_FAILED = 'sync_failed';

    public const NO_ACTIVITY = 'no_activity';

    /**
     * Label singkat siap-tampil (badge/tooltip) - null buat AVAILABLE/
     * NO_ACTIVITY (keduanya ditampilkan sebagai NILAI APA ADANYA, TIDAK
     * butuh qualifier tambahan - "never treat genuine zero as missing").
     */
    public static function label(string $category): ?string
    {
        return match ($category) {
            self::UNSUPPORTED => 'Tidak tersedia melalui API',
            self::PROVIDER_UNAVAILABLE => 'Belum tersedia dari provider untuk akun/periode ini',
            self::INSUFFICIENT_HISTORY => 'Riwayat data belum cukup',
            self::SYNC_FAILED => 'Belum berhasil diperbarui',
            default => null, // available, no_activity
        };
    }

    /**
     * Varian label yang menyebut platform eksplisit (Langkah J contoh:
     * "Tidak tersedia melalui TikTok/Instagram API") - dipakai tempat yang
     * memang tahu platform-nya (mis. Audience tab, sudah $platform->name).
     */
    public static function labelForPlatform(string $category, string $platformName): ?string
    {
        if ($category === self::UNSUPPORTED) {
            return "Tidak tersedia melalui {$platformName} API";
        }

        if ($category === self::PROVIDER_UNAVAILABLE) {
            return "Belum tersedia dari {$platformName} untuk akun/periode ini";
        }

        return self::label($category);
    }

    public static function icon(string $category): string
    {
        return match ($category) {
            self::SYNC_FAILED => 'warning',
            self::UNSUPPORTED, self::PROVIDER_UNAVAILABLE, self::INSUFFICIENT_HISTORY => 'info',
            default => 'check_circle',
        };
    }
}
