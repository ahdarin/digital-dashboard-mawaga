<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PROGRESSIVE 90-DAY SYNC ENGINE - durable per-item chunk ledger. See
 * migration 2026_09_04_000003_create_analytics_sync_task_items_table for
 * the full rationale.
 */
class AnalyticsSyncTaskItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_SKIPPED = 'skipped';

    public const SOURCE_DISCOVERY = 'discovery';

    public const SOURCE_KNOWN_REFRESH = 'known_refresh';

    protected $fillable = [
        'analytics_sync_task_id', 'external_item_id', 'media_type', 'published_at',
        'stage', 'source', 'chunk_index', 'status', 'payload',
        'core_completed_at', 'optional_status', 'last_error',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'core_completed_at' => 'datetime',
        'payload' => 'array',
    ];

    public function task()
    {
        return $this->belongsTo(AnalyticsSyncTask::class, 'analytics_sync_task_id');
    }

    public function isTerminal(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }
}
