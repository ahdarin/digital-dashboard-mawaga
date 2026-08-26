<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPlanStatusLog extends Model
{
    protected $fillable = [
        'content_plan_id', 'changed_by_user_id', 'from_status', 'to_status', 'notes', 'changed_at',
    ];

    protected $casts = ['changed_at' => 'datetime'];

    public function contentPlan() { return $this->belongsTo(ContentPlan::class); }
    public function changedByUser() { return $this->belongsTo(User::class, 'changed_by_user_id'); }
}
