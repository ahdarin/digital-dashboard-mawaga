<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rekaman terstruktur tiap anomali performa (spike/drop) yang terdeteksi
 * DetectPerformanceAnomalies - terpisah dari Notification (yang cuma buat
 * ditampilkan ke user & bisa ditandai dibaca/dihapus). Dipakai
 * AiStrategyService::buildPerformanceSummary() buat kasih AI Strategy
 * konteks "apa yang beneran terjadi" selama periode yang dianalisis, bukan
 * cuma angka agregat pillar/platform.
 */
class PerformanceAnomaly extends Model
{
    protected $fillable = [
        'content_item_id', 'type', 'percent_change', 'views_on_date', 'baseline_avg_views', 'detected_date',
    ];

    protected $casts = [
        'detected_date' => 'date',
    ];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
}
