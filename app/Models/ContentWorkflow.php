<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentWorkflow extends Model
{
    protected $fillable = [
        'content_item_id', 'current_pic_id', 'current_status', 'is_overdue',
        'client_reviewed_at', 'client_reviewed_by', 'client_review_result',
    ];
    protected $casts = ['is_overdue' => 'boolean', 'client_reviewed_at' => 'datetime'];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function currentPic() { return $this->belongsTo(User::class, 'current_pic_id'); }
    public function clientReviewedBy() { return $this->belongsTo(User::class, 'client_reviewed_by'); }
}
