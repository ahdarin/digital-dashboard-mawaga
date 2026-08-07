<?php

namespace App\Support;

class ContentComplexityCalculator
{
    // Batas ini diambil persis dari tertile data training (lihat notebook)
    public static function fromContentItem(?int $durationSeconds, ?int $slideCount, string $jenis): int
    {
        if ($jenis === 'Video' || $jenis === 'video') {
            if ($durationSeconds === null)
                return 2; // fallback netral kalau belum diisi
            if ($durationSeconds <= 30)
                return 1;
            if ($durationSeconds <= 40)
                return 2;
            return 3;
        }

        // Feed/Carousel
        if ($slideCount === null)
            return 1;
        if ($slideCount <= 1)
            return 1;
        if ($slideCount <= 4)
            return 2;
        return 3;
    }
}