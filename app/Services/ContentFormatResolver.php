<?php

namespace App\Services;

use App\Models\ContentFormat;
use App\Models\ContentItem;
use App\Models\InstagramMediaSnapshot;
use App\Models\TikTokVideoSnapshot;
use Illuminate\Support\Collection;

/**
 * SYSTEM CONSISTENCY PASS (Part D) - SATU-SATUNYA tempat raw provider
 * media type (Instagram IMAGE/CAROUSEL_ALBUM/VIDEO, TikTok video) dipetakan
 * ke Content Format kanonis (Single Post/Carousel/Video). Controller/
 * Service/Blade lain TIDAK BOLEH bikin mapping sendiri - selalu panggil
 * lewat sini (mirror pola AnalyticsPeriod::label() sebagai "satu sumber
 * kebenaran" buat domain lain).
 *
 * Prioritas sumber kebenaran (Part C):
 * 1. ContentItem.contentFormat (master internal, kalau sudah di-link DAN
 *    formatnya sudah diisi) - OTORITATIF, TIDAK PERNAH ditimpa raw media
 *    type provider walau provider bilang beda.
 * 2. Normalisasi provider (media_type/media_product_type Instagram, atau
 *    kehadiran video TikTok) - dipakai HANYA buat konten yang belum
 *    ke-link, atau konten yang sudah ke-link tapi content_format_id-nya
 *    masih kosong (belum pernah diklasifikasi manual).
 * 3. null ("belum diketahui") - TIDAK PERNAH ditebak kalau kombinasi raw
 *    value belum terbukti/belum dikenal (Part J, "unknown remains
 *    unknown").
 */
class ContentFormatResolver
{
    public const SLUG_SINGLE_POST = 'single-post';

    public const SLUG_CAROUSEL = 'carousel';

    public const SLUG_VIDEO = 'video';

    private ?Collection $formatsBySlug = null;

    /**
     * Label kanonis (mis. "Single Post") buat 1 ContentItem yang SUDAH
     * ke-link, mengikuti prioritas sumber kebenaran di docblock kelas.
     * $instagramSnapshot/$tiktokSnapshot opsional - dipakai buat fallback
     * SAJA kalau master content_format_id item ini belum diisi.
     */
    public function labelForContentItem(
        ContentItem $item,
        ?InstagramMediaSnapshot $instagramSnapshot = null,
        ?TikTokVideoSnapshot $tiktokSnapshot = null,
    ): ?string {
        if ($item->contentFormat) {
            return $item->contentFormat->name;
        }

        return $this->labelForSnapshot($instagramSnapshot, $tiktokSnapshot);
    }

    /**
     * Label kanonis buat konten yang BELUM ke-link (unmatched) - murni
     * normalisasi provider, tidak ada ContentItem master sama sekali.
     */
    public function labelForSnapshot(
        ?InstagramMediaSnapshot $instagramSnapshot = null,
        ?TikTokVideoSnapshot $tiktokSnapshot = null,
    ): ?string {
        if ($instagramSnapshot) {
            return $this->labelForSlug($this->slugForInstagram($instagramSnapshot->media_type, $instagramSnapshot->media_product_type));
        }

        if ($tiktokSnapshot) {
            return $this->labelForSlug($this->slugForTikTok($tiktokSnapshot->external_post_id));
        }

        return null;
    }

    /**
     * Instagram: IMAGE -> Single Post, CAROUSEL_ALBUM -> Carousel,
     * VIDEO/Reel -> Video. Kombinasi lain (belum terbukti ada di data
     * real) SENGAJA balikin null, bukan ditebak - caller wajib fallback
     * ke '-'/"belum diketahui".
     */
    public function slugForInstagram(?string $mediaType, ?string $mediaProductType = null): ?string
    {
        if ($mediaProductType === 'REELS') {
            return self::SLUG_VIDEO;
        }

        return match ($mediaType) {
            'IMAGE' => self::SLUG_SINGLE_POST,
            'CAROUSEL_ALBUM' => self::SLUG_CAROUSEL,
            'VIDEO' => self::SLUG_VIDEO,
            default => null,
        };
    }

    /**
     * TikTok API ini cuma pernah mengembalikan 1 bentuk konten (video) -
     * null kalau baris snapshot-nya sendiri tidak lengkap (tidak ada
     * external_post_id), mirror kondisi lama di
     * TikTokVideoSnapshot::getDisplayFormatAttribute().
     */
    public function slugForTikTok(?string $externalPostId): ?string
    {
        return $externalPostId ? self::SLUG_VIDEO : null;
    }

    public function labelForSlug(?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }

        return $this->formatsBySlug()->get($slug)?->name;
    }

    /**
     * 3 baris SAJA - cache in-process per request cukup, tidak perlu
     * Cache facade/TTL.
     */
    private function formatsBySlug(): Collection
    {
        return $this->formatsBySlug ??= ContentFormat::all()->keyBy('slug');
    }
}
