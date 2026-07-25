<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentMetric extends Model
{
    protected $fillable = [
        'content_item_id', 'platform_id', 'sync_log_id', 'imported_by',
        'metric_date', 'views', 'engagement_rate',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'engagement_rate' => 'decimal:2',
    ];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
    public function syncLog() { return $this->belongsTo(AnalyticsSyncLog::class, 'sync_log_id'); }
    public function importedBy() { return $this->belongsTo(User::class, 'imported_by'); }
}