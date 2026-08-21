<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AudienceInsight extends Model
{
    public const SOURCE_API = 'instagram_api';
    public const SOURCE_CSV = 'csv_import';
    public const SOURCE_LEGACY = 'legacy';

    public const TYPE_SUMMARY = 'summary';
    public const TYPE_FOLLOWER = 'follower';
    public const TYPE_REACHED = 'reached';
    public const TYPE_ENGAGED = 'engaged';
    public const TYPE_GENERIC = 'generic';

    protected $fillable = [
        'client_id', 'platform_id', 'source', 'demographic_type', 'snapshot_date',
        'follower_count', 'reach',
        'gender_breakdown', 'age_breakdown', 'top_locations', 'top_countries', 'active_hours',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'gender_breakdown' => 'array',
        'age_breakdown' => 'array',
        'top_locations' => 'array',
        'top_countries' => 'array',
        'active_hours' => 'array',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }

    public function scopeApiSourced(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_API);
    }

    public function scopeCsvSourced(Builder $query): Builder
    {
        return $query->whereIn('source', [self::SOURCE_CSV, self::SOURCE_LEGACY]);
    }

    public function scopeSummary(Builder $query): Builder
    {
        return $query->where('demographic_type', self::TYPE_SUMMARY);
    }

    public function scopeDemographics(Builder $query, string $type): Builder
    {
        return $query->where('demographic_type', $type);
    }
}
