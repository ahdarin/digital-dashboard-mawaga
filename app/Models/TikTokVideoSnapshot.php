<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokVideoSnapshot extends Model
{
    // WAJIB eksplisit - Laravel menebak nama tabel dari class name via
    // Str::snake(), dan "TikTokVideoSnapshot" kena split jadi "tik_tok_..."
    // (capital T di "Tok" dianggap word boundary baru), padahal migration
    // membuat tabel "tiktok_video_snapshots" (tanpa underscore tik/tok).
    protected $table = 'tiktok_video_snapshots';

    protected $fillable = [
        'api_integration_id', 'external_post_id', 'share_url', 'title',
        'video_description', 'duration', 'cover_image_url', 'height', 'width', 'is_aigc',
        'match_status', 'content_publication_id', 'published_at', 'last_fetched_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'last_fetched_at' => 'datetime',
        // Nullable boolean - null HARUS tetap null lewat cast ini (Eloquent
        // 'boolean' cast membiarkan null apa adanya, cuma non-null value
        // yang dikonversi ke true/false - JANGAN diasumsikan "not AI-
        // generated" cuma karena provider tidak mengirim field ini).
        'is_aigc' => 'boolean',
    ];

    public function apiIntegration() { return $this->belongsTo(ApiIntegration::class); }
    public function contentPublication() { return $this->belongsTo(ContentPublication::class); }
    // FK eksplisit - guess default Laravel juga kena masalah "tik_tok_..."
    // yang sama seperti nama tabel di atas.
    public function contentMetric() { return $this->hasOne(ContentMetric::class, 'tiktok_video_snapshot_id'); }

    /**
     * Label format kanonis buat display SAJA (Performance Table/Top
     * Content), BUKAN ContentType internal - mirror
     * InstagramMediaSnapshot::display_format, SEKARANG lewat resolver
     * terpusat yang sama (App\Services\ContentFormatResolver) biar TIDAK
     * ADA mapping ganda - TikTok cuma punya 1 bentuk video lewat API ini
     * (tidak ada varian carousel/story), jadi selalu "Video" begitu ada
     * data - null kalau baris ini entah kenapa tidak lengkap.
     */
    public function getDisplayFormatAttribute(): ?string
    {
        $resolver = app(\App\Services\ContentFormatResolver::class);

        return $resolver->labelForSlug($resolver->slugForTikTok($this->external_post_id));
    }
}
