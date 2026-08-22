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
        'video_description', 'duration', 'cover_image_url',
        'match_status', 'content_publication_id', 'published_at', 'last_fetched_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'last_fetched_at' => 'datetime',
    ];

    public function apiIntegration() { return $this->belongsTo(ApiIntegration::class); }
    public function contentPublication() { return $this->belongsTo(ContentPublication::class); }
    // FK eksplisit - guess default Laravel juga kena masalah "tik_tok_..."
    // yang sama seperti nama tabel di atas.
    public function contentMetric() { return $this->hasOne(ContentMetric::class, 'tiktok_video_snapshot_id'); }

    /**
     * Label format buat display SAJA (Performance Table/Top Content), BUKAN
     * ContentType internal - mirror InstagramMediaSnapshot::display_format,
     * tapi TikTok cuma py 1 bentuk video lewat API ini (tidak ada varian
     * carousel/story), jadi selalu "Video" begitu ada data - null kalau
     * baris ini entah kenapa tidak lengkap.
     */
    public function getDisplayFormatAttribute(): ?string
    {
        return $this->external_post_id ? 'Video' : null;
    }
}
