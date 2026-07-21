<?php
// app/Services/PhoneNumberNormalizer.php
namespace App\Services;

class PhoneNumberNormalizer
{
    /**
     * Normalisasi nomor ke format internasional Indonesia tanpa "+".
     * Contoh input yang diterima: 08123456789, 8123456789, +628123456789, 628123456789
     * Output: 628123456789
     */
    public static function normalize(string $phoneNumber): string
    {
        // Hapus semua karakter selain angka
        $digits = preg_replace('/\D/', '', $phoneNumber);

        // Kalau diawali '0' -> ganti jadi '62'
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }

        // Kalau belum diawali '62' sama sekali (misal user cuma isi 8123456789)
        if (!str_starts_with($digits, '62')) {
            $digits = '62' . $digits;
        }

        return $digits;
    }
}