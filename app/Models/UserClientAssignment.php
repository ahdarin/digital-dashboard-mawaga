<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserClientAssignment extends Model
{
    protected $fillable = ['user_id', 'client_id'];

    public function user() { return $this->belongsTo(User::class); }
    public function client() { return $this->belongsTo(Client::class); }
}