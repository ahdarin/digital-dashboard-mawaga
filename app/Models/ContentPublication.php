<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPublication extends Model
{
    protected $fillable = [
        'content_item_id', 'platform_id', 'published_by',
        'published_at', 'post_url', 'thumbnail_url', 'caption_final',
        'external_post_id', 'api_integration_id',
    ];
    protected $casts = ['published_at' => 'datetime'];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
    public function publishedBy() { return $this->belongsTo(User::class, 'published_by'); }
    public function apiIntegration() { return $this->belongsTo(ApiIntegration::class); }
}