<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 1 baris = observasi cumulative state 1 content pada 1 tanggal SYNC (bukan
 * tanggal publish - itu tugas ContentMetric.metric_date, TIDAK diubah).
 * Dipakai ContentMetricPeriodService buat hitung delta antar snapshot per
 * periode filter (7/30/90 hari) - lihat docblock migration untuk root
 * cause lengkap kenapa tabel ini ada.
 */
class ContentMetricSnapshot extends Model
{
    protected $fillable = [
        'client_id', 'platform_id', 'content_item_id',
        'instagram_media_snapshot_id', 'tiktok_video_snapshot_id',
        'snapshot_date',
        'views', 'reach', 'impressions', 'likes', 'comments', 'shares', 'saves', 'profile_visit',
        'engagement_rate', 'watch_time_avg', 'completion_rate',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'engagement_rate' => 'decimal:2',
        'completion_rate' => 'decimal:2',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function instagramMediaSnapshot() { return $this->belongsTo(InstagramMediaSnapshot::class); }
    public function tiktokVideoSnapshot() { return $this->belongsTo(TikTokVideoSnapshot::class); }

    /**
     * Identitas "1 content = 1 unit" buat grouping delta per periode -
     * SAMA persis semantik ContentMetric::getDistinctContentKeyAttribute(),
     * disalin ulang (bukan direuse) karena dua model berbeda tanpa trait
     * bersama saat ini - lihat catatan di sana soal urutan prioritas.
     */
    public function getDistinctContentKeyAttribute(): string
    {
        if ($this->content_item_id) {
            return 'item-'.$this->content_item_id;
        }

        if ($this->instagram_media_snapshot_id) {
            return 'snapshot-'.$this->instagram_media_snapshot_id;
        }

        if ($this->tiktok_video_snapshot_id) {
            return 'tiktok-snapshot-'.$this->tiktok_video_snapshot_id;
        }

        return 'row-'.$this->id;
    }
}
