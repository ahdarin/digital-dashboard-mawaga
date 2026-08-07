<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentBriefDraft extends Model
{
    protected $fillable = [
        'content_item_id', 'created_by',
        'hook_title', 'start_date', 'post_date', 'platform', 'reference_link',
        'take_by_user_id', 'copywriting_script', 'talent', 'properti',
        'estimated_duration_seconds', 'slide_count', 'talent_count',
        'location_count', 'complexity_level',
        'status', 'chat_history', 'finalized_at', 'previous_snapshot',
    ];

    protected $casts = [
        'start_date' => 'date',
        'post_date' => 'date',
        'chat_history' => 'array',
        'finalized_at' => 'datetime',
        'previous_snapshot' => 'array',
    ];

    public function contentItem()
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function takeByUser()
    {
        return $this->belongsTo(User::class, 'take_by_user_id');
    }

    public function isLocked(): bool
    {
        return $this->status === 'finalized';
    }

    public function canRevert(): bool
    {
        return ! $this->isLocked() && ! empty($this->previous_snapshot);
    }
}
