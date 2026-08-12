<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'content_plan_id',
        'client_id',
        'content_pillar_id',
        'content_type_id',
        'platform_id',
        'title',
        'brief',
        'caption_draft',
        'deadline_at',
        'footage_captured_at',
        'content_file_link',
        'scheduled_upload_at',
        'is_posted',
        'ai_strategy_insight_id',
        'estimated_duration_seconds',
        'estimated_slide_count',
    ];

    protected $casts = [
        'deadline_at' => 'datetime',
        'footage_captured_at' => 'datetime',
        'scheduled_upload_at' => 'datetime',
        'is_posted' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
    public function contentType()
    {
        return $this->belongsTo(ContentType::class);
    }
    public function contentPillar()
    {
        return $this->belongsTo(ContentPillar::class);
    }
    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
    public function workflow()
    {
        return $this->hasOne(ContentWorkflow::class);
    }
    public function statusLogs()
    {
        return $this->hasMany(ContentStatusLog::class);
    }
    public function assignments()
    {
        return $this->hasMany(ContentItemAssignment::class);
    }
    public function revisions()
    {
        return $this->hasMany(ContentRevision::class);
    }
    public function publications()
    {
        return $this->hasMany(ContentPublication::class);
    }
    public function metrics()
    {
        return $this->hasMany(ContentMetric::class);
    }
    public function contentPlan()
    {
        return $this->belongsTo(ContentPlan::class);
    }
    public function aiStrategyInsight()
    {
        return $this->belongsTo(AiStrategyInsight::class);
    }

    public function delayRiskScores()
    {
        return $this->hasMany(DelayRiskScore::class);
    }
    public function contentBriefDraft()
    {
        return $this->hasOne(ContentBriefDraft::class);
    }
    public function latestDelayRisk()
    {
        return $this->hasOne(DelayRiskScore::class)->latestOfMany();
    }
}