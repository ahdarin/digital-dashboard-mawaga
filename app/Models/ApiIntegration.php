<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIntegration extends Model
{
    protected $fillable = [
        'client_id', 'platform_id', 'integration_name',
        'access_token', 'refresh_token', 'status',
        'external_account_id', 'external_username', 'last_synced_at', 'last_error',
        'access_token_expires_at', 'refresh_token_expires_at', 'scopes',
        'reach_history_backfilled_at',
        'external_display_name', 'external_avatar_url', 'external_bio',
        'external_verified', 'external_profile_url',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'reach_history_backfilled_at' => 'datetime',
        'external_verified' => 'boolean',
        // Belum ada kode manapun yang pernah nulis access_token/refresh_token
        // sebelum OAuth ini (dicek: grep kosong) - aman ditambah encrypted
        // cast tanpa perlu migrasi data lama.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }

    /**
     * PASS 1B - "FIX INSTAGRAM GRANTED-SCOPE PERSISTENCE". Tri-state
     * SENGAJA (bukan boolean) - null berarti "belum pernah diketahui"
     * (integration lama sebelum fix ini, ATAU response provider yang
     * terakhir tidak menyertakan info scope), BUKAN "pasti tidak
     * di-grant". App TIDAK PERNAH boleh menyamakan unknown dengan false -
     * itu klaim yang tidak terbukti persis sama seperti masalah yang mau
     * diperbaiki (lihat InstagramIntegrationController::callback()).
     */
    public function hasKnownScope(string $scope): ?bool
    {
        if ($this->scopes === null || trim($this->scopes) === '') {
            return null;
        }

        return in_array($scope, array_map('trim', explode(',', $this->scopes)), true);
    }
}