<?php

namespace App\Enums;

/**
 * Peer group format untuk normalisasi analytics (ANALYTICS_NORMALIZATION.md)
 * - "Reels tidak boleh dibandingkan dengan Carousel", "Desain tidak boleh
 * dibandingkan langsung dengan Reels/TikTok pakai raw views". Diturunkan
 * dari ContentType (Video/Desain, arsitektur lama - TIDAK diubah) +
 * ContentItem.content_format (string bebas nullable, nilai kanonik saat ini
 * hanya "Single Feed"/"Carousel Feed" dari Excel Coverage Audit - lihat
 * migration 2026_08_27_000001).
 */
enum ContentFormatGroup: string
{
    case Video = 'video';
    case Carousel = 'carousel';
    case SingleFeed = 'single_feed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Video => 'Video (Reels/TikTok)',
            self::Carousel => 'Carousel',
            self::SingleFeed => 'Single Feed/Image',
            self::Unknown => 'Format Tidak Diketahui',
        };
    }

    /**
     * $contentTypeName = ContentItem->contentType->name ('Video'/'Desain').
     * $contentFormat = ContentItem->content_format (nullable string).
     * Desain tanpa content_format eksplisit DIANGGAP Single Feed (mayoritas
     * historis, bukan Carousel - Carousel harus eksplisit ditandai supaya
     * tidak salah dibandingkan dengan grup yang lebih besar populasinya).
     */
    public static function resolve(?string $contentTypeName, ?string $contentFormat): self
    {
        if ($contentTypeName === 'Video') {
            return self::Video;
        }

        if ($contentTypeName === 'Desain') {
            return match (true) {
                $contentFormat !== null && str_contains(strtolower($contentFormat), 'carousel') => self::Carousel,
                default => self::SingleFeed,
            };
        }

        return self::Unknown;
    }

    public function isVideoFamily(): bool
    {
        return $this === self::Video;
    }
}
