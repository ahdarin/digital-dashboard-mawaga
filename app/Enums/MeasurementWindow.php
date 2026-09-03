<?php

namespace App\Enums;

/**
 * Jendela pengukuran performa publication - D+7 untuk early performance,
 * D+30 untuk sustained performance (lihat ANALYTICS_NORMALIZATION.md).
 * Publication yang usianya belum mencapai jumlah hari ini diberi status
 * provisional (bukan diproses seolah datanya lengkap).
 */
enum MeasurementWindow: string
{
    case D7 = 'd7';
    case D30 = 'd30';

    public function days(): int
    {
        return match ($this) {
            self::D7 => 7,
            self::D30 => 30,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::D7 => 'D+7 (Early Performance)',
            self::D30 => 'D+30 (Sustained Performance)',
        };
    }
}
