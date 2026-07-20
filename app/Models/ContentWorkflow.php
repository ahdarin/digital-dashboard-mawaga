<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentWorkflow extends Model
{
    protected $fillable = ['content_item_id', 'current_pic_id', 'current_status', 'is_overdue'];
    protected $casts = ['is_overdue' => 'boolean'];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function currentPic() { return $this->belongsTo(User::class, 'current_pic_id'); }
}
