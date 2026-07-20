<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentStatusLog extends Model
{
    protected $fillable = [
        'content_item_id', 'changed_by', 'from_status',
        'to_status', 'approval_type', 'notes', 'changed_at',
    ];
    protected $casts = ['changed_at' => 'datetime'];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
