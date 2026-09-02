<?php

namespace App\Models;

use App\Services\AnalyticsFailureCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Analytics V2 Phase B - 1 item (media/video) yang gagal diproses dalam 1
 * AnalyticsSyncTask, cukup terstruktur buat targeted retry. TIDAK PERNAH
 * menyimpan token/Authorization header/raw API payload - "message" HARUS
 * sudah melalui sanitasi caller (getMessage() dari InstagramApiException/
 * TikTokApiException), never raw response body.
 */
class AnalyticsSyncFailure extends Model
{
    protected $fillable = [
        'analytics_sync_task_id', 'external_item_id', 'content_item_id',
        'operation', 'category', 'message', 'retryable', 'attempts', 'resolved_at',
    ];

    protected $casts = [
        'retryable' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function task() { return $this->belongsTo(AnalyticsSyncTask::class, 'analytics_sync_task_id'); }
    public function contentItem() { return $this->belongsTo(ContentItem::class); }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeRetryable($query)
    {
        return $query->unresolved()->where('retryable', true);
    }

    public function markResolved(): void
    {
        $this->update(['resolved_at' => now()]);
    }

    public function markAttemptFailedAgain(): void
    {
        $this->increment('attempts');
    }

    public static function record(
        AnalyticsSyncTask $task,
        string $operation,
        string $category,
        ?string $message,
        ?string $externalItemId = null,
        ?int $contentItemId = null,
    ): self {
        return self::create([
            'analytics_sync_task_id' => $task->id,
            'external_item_id' => $externalItemId,
            'content_item_id' => $contentItemId,
            'operation' => $operation,
            'category' => $category,
            'message' => $message,
            'retryable' => AnalyticsFailureCategory::isRetryable($category),
            'attempts' => 1,
        ]);
    }
}
