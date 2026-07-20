<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentItemAssignment extends Model
{
    protected $fillable = [
        'content_item_id', 'user_id', 'assignment_role',
    ];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function user() { return $this->belongsTo(User::class); }
}