<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIntegration extends Model
{
    protected $fillable = [
        'client_id', 'platform_id', 'integration_name',
        'access_token', 'refresh_token', 'status',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
}