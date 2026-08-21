<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramMediaSnapshot extends Model
{
    protected $fillable = [
        'api_integration_id', 'external_post_id', 'permalink', 'caption',
        'media_type', 'media_product_type', 'published_at', 'thumbnail_url',
        'match_status', 'content_publication_id', 'last_fetched_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'last_fetched_at' => 'datetime',
    ];

    public function apiIntegration() { return $this->belongsTo(ApiIntegration::class); }
    public function contentPublication() { return $this->belongsTo(ContentPublication::class); }
    public function contentMetric() { return $this->hasOne(ContentMetric::class); }

    /**
     * Label format post buat display SAJA (Performance Table/Top Content) -
     * BUKAN ContentType internal, TIDAK pernah dipakai buat isi
     * content_type_id. Cuma menangani combo media_type+media_product_type
     * yang TERBUKTI ada di data real (diaudit langsung dari DB, bukan
     * dokumentasi Meta): CAROUSEL_ALBUM+FEED, VIDEO+REELS, IMAGE+FEED.
     * Combo lain (belum pernah muncul) SENGAJA balikin null, bukan ditebak -
     * caller wajib fallback ke '-'.
     */
    public function getDisplayFormatAttribute(): ?string
    {
        if ($this->media_product_type === 'REELS') {
            return 'Reels';
        }

        return match ($this->media_type) {
            'CAROUSEL_ALBUM' => 'Carousel',
            'IMAGE' => 'Image',
            'VIDEO' => 'Video',
            default => null,
        };
    }
}
