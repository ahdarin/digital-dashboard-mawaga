<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudienceInsight extends Model
{
    protected $fillable = [
        'client_id', 'platform_id', 'snapshot_date', 'follower_count',
        'gender_breakdown', 'age_breakdown', 'top_locations', 'active_hours',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'gender_breakdown' => 'array',
        'age_breakdown' => 'array',
        'top_locations' => 'array',
        'active_hours' => 'array',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
}