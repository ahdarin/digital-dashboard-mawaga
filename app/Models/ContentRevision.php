<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentRevision extends Model
{
    protected $fillable = [
        'content_item_id', 'requested_by', 'revision_round', 'revision_note', 'status',
    ];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function requestedBy() { return $this->belongsTo(User::class, 'requested_by'); }
}