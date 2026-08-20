<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Platform extends Model
{
    protected $fillable = ['name'];

    public function contentItems() { return $this->hasMany(ContentItem::class); }
    public function publications() { return $this->hasMany(ContentPublication::class); }
    public function audienceInsights() { return $this->hasMany(AudienceInsight::class); }
    public function apiIntegrations() { return $this->hasMany(ApiIntegration::class); }
    public function analyticsSyncLogs() { return $this->hasMany(AnalyticsSyncLog::class); }
    public function contentMetrics() { return $this->hasMany(ContentMetric::class); }
}