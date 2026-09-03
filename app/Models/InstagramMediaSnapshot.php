<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramMediaSnapshot extends Model
{
    protected $fillable = [
        'api_integration_id', 'external_post_id', 'permalink', 'caption',
        'media_type', 'media_product_type', 'published_at', 'thumbnail_url',
        'match_status', 'content_publication_id', 'last_fetched_at', 'shortcode',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'last_fetched_at' => 'datetime',
    ];

    public function apiIntegration() { return $this->belongsTo(ApiIntegration::class); }
    public function contentPublication() { return $this->belongsTo(ContentPublication::class); }
    public function contentMetric() { return $this->hasOne(ContentMetric::class); }

    /**
     * Label format kanonis buat display SAJA (Performance Table/Top
     * Content/halaman Hubungkan Konten) - BUKAN ContentType internal,
     * TIDAK pernah dipakai buat isi content_type_id. SYSTEM CONSISTENCY
     * PASS (Part D) - normalisasi SEKARANG lewat SATU tempat terpusat
     * (App\Services\ContentFormatResolver), bukan mapping lokal di sini -
     * IMAGE jadi "Single Post" (BUKAN "Image", istilah teknis provider
     * tidak boleh bocor ke user), Reel/VIDEO jadi "Video" (Reels tidak
     * lagi label terpisah - tetap konten video secara kanonis).
     */
    public function getDisplayFormatAttribute(): ?string
    {
        $resolver = app(\App\Services\ContentFormatResolver::class);

        return $resolver->labelForSlug($resolver->slugForInstagram($this->media_type, $this->media_product_type));
    }
}
