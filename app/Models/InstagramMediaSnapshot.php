<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramMediaSnapshot extends Model
{
    protected $fillable = [
        'api_integration_id', 'external_post_id', 'permalink', 'caption',
        'media_type', 'media_product_type', 'published_at', 'thumbnail_url',
        'match_status', 'content_publication_id', 'last_fetched_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'last_fetched_at' => 'datetime',
    ];

    public function apiIntegration() { return $this->belongsTo(ApiIntegration::class); }
    public function contentPublication() { return $this->belongsTo(ContentPublication::class); }
    public function contentMetric() { return $this->hasOne(ContentMetric::class); }
}
