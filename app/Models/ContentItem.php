<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'content_plan_id', 'client_id', 'content_pillar_id',
        'content_type_id', 'platform_id', 'title', 'brief',
        'caption_draft', 'deadline_at', 'is_posted',
    ];

    protected $casts = [
        'deadline_at' => 'datetime',
        'is_posted' => 'boolean',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function contentType() { return $this->belongsTo(ContentType::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
    public function workflow() { return $this->hasOne(ContentWorkflow::class); }
    public function statusLogs() { return $this->hasMany(ContentStatusLog::class); }
    public function assignments() { return $this->hasMany(ContentItemAssignment::class); }
    public function revisions() { return $this->hasMany(ContentRevision::class); }
    public function publications() { return $this->hasMany(ContentPublication::class); }
    public function metrics(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContentMetric::class);
    }
}
