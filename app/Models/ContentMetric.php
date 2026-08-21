<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentMetric extends Model
{
    protected $fillable = [
        'content_item_id', 'client_id', 'instagram_media_snapshot_id',
        'platform_id', 'sync_log_id', 'imported_by',
        'metric_date', 'views', 'engagement_rate',
        'watch_time_avg', 'completion_rate', 'shares', 'saves',
        'reach', 'impressions', 'likes', 'comments', 'profile_visit',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'engagement_rate' => 'decimal:2',
    ];

    // content_item_id NULLABLE (post Instagram real yang belum ke-link
    // manual/schedule-match) - relasi ini boleh balikin null, caller WAJIB
    // null-safe (?->) begitu baca contentItem dari baris yang mungkin
    // belum ke-link, jangan asumsikan selalu ada.
    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function client() { return $this->belongsTo(Client::class); }
    public function instagramMediaSnapshot() { return $this->belongsTo(InstagramMediaSnapshot::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
    public function syncLog() { return $this->belongsTo(AnalyticsSyncLog::class, 'sync_log_id'); }
    public function importedBy() { return $this->belongsTo(User::class, 'imported_by'); }

    /**
     * Identitas "1 post = 1 unit" buat hitung Content Published distinct -
     * content_item_id kalau sudah ke-link, kalau belum pakai
     * instagram_media_snapshot_id (post Instagram real tapi belum matched).
     * Baris CSV lama (content_item_id null-nya nggak mungkin, selalu
     * required) nggak kena skenario ini sama sekali.
     */
    public function getDistinctContentKeyAttribute(): string
    {
        if ($this->content_item_id) {
            return 'item-'.$this->content_item_id;
        }

        if ($this->instagram_media_snapshot_id) {
            return 'snapshot-'.$this->instagram_media_snapshot_id;
        }

        // Fallback ekstrem (CSV lama/data lain yang dua-duanya kosong) -
        // pakai id baris sendiri biar tetap ke-hitung sebagai unit
        // tersendiri, bukan collapse ke satu grup "null" bareng baris lain.
        return 'row-'.$this->id;
    }
}
