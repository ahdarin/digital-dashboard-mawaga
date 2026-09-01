<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiStrategyInsight extends Model
{
    protected $fillable = [
        'client_id', 'generated_by', 'period_start', 'period_end', 'performance_data',
        'summary', 'action_items', 'suggested_split', 'top_pillars', 'content_ideas',
        'data_completeness_percent',
        'status', 'error_message', 'applied_at', 'applied_by', 'applied_idea_indexes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'performance_data' => 'array',
        'action_items' => 'array',
        'suggested_split' => 'array',
        'top_pillars' => 'array',
        'content_ideas' => 'array',
        'applied_at' => 'datetime',
        'applied_idea_indexes' => 'array',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by'); }
    public function appliedBy() { return $this->belongsTo(User::class, 'applied_by'); }
    public function messages() { return $this->hasMany(AiStrategyMessage::class)->orderBy('created_at'); }
}