<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentBriefDraft extends Model
{
    protected $fillable = [
        'content_item_id', 'created_by',
        'hook_title', 'start_date', 'post_date', 'platform', 'reference_link',
        'take_by_user_id', 'copywriting_script', 'scenes', 'talent', 'properti',
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
        'scenes' => 'array',
    ];

    /**
     * Daftar scene/slide/adegan terstruktur, siap dipakai tampilan/edit.
     * Fallback ke copywriting_script lama (satu blob markdown) kalau brief
     * ini dibuat sebelum field scenes ada, supaya PIC tetap bisa mengedit
     * dan memecahnya jadi field terpisah lewat form edit manual.
     */
    public function getScenesForDisplayAttribute(): array
    {
        if (! empty($this->scenes)) {
            return $this->scenes;
        }

        if (empty($this->copywriting_script)) {
            return [];
        }

        return [[
            'label' => null,
            'visual' => $this->copywriting_script,
            'talent_script' => null,
        ]];
    }

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
