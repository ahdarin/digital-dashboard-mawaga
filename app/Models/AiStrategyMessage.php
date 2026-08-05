<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiStrategyMessage extends Model
{
    protected $fillable = ['ai_strategy_insight_id', 'user_id', 'role', 'message'];

    public function insight() { return $this->belongsTo(AiStrategyInsight::class, 'ai_strategy_insight_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
