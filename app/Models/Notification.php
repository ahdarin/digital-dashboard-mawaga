<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'user_id', 'title', 'type', 'body',
        'related_type', 'related_id', 'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function user() { return $this->belongsTo(User::class); }

    public function related() { return $this->morphTo(); }
}