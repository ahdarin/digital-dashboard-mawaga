<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    protected $fillable = [
        'user_id', 'name', 'operational_role', 'email', 'source', 'external_reference',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'team_member_client');
    }

    public function hasAccount(): bool
    {
        return $this->user_id !== null;
    }
}
