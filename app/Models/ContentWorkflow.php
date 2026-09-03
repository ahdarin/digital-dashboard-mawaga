<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentWorkflow extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_item_id', 'current_pic_id', 'current_status', 'is_overdue',
        'client_reviewed_at', 'client_reviewed_by_client_id', 'client_review_result',
    ];
    protected $casts = ['is_overdue' => 'boolean', 'client_reviewed_at' => 'datetime'];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function currentPic() { return $this->belongsTo(User::class, 'current_pic_id'); }

    // Review SELALU dari Client Portal (tidak pernah dari User) - lihat
    // Client\ApprovalController::approve().
    public function clientReviewedByClient() { return $this->belongsTo(Client::class, 'client_reviewed_by_client_id'); }
}
