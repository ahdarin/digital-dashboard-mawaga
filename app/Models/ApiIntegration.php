<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIntegration extends Model
{
    protected $fillable = [
        'client_id', 'platform_id', 'integration_name',
        'access_token', 'refresh_token', 'status',
        'external_account_id', 'external_username', 'last_synced_at', 'last_error',
        'access_token_expires_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'access_token_expires_at' => 'datetime',
        // Belum ada kode manapun yang pernah nulis access_token/refresh_token
        // sebelum OAuth ini (dicek: grep kosong) - aman ditambah encrypted
        // cast tanpa perlu migrasi data lama.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
}