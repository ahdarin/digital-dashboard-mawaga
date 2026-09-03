<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_category_id', 'name', 'logo_path', 'status', 'color', 'asset_link',
        'portal_token', 'portal_access_enabled',
    ];

    protected $casts = [
        'portal_access_enabled' => 'boolean',
    ];

    /**
     * Client BUKAN User - portal_token adalah SATU-SATUNYA credential akses
     * Client Portal (permanent capability URL, tidak pernah expired). Token
     * SELALU dibuat di sini (bukan di controller/form) supaya berlaku sama
     * dari jalur manapun Client dibuat: controller, factory, seeder, test,
     * atau CLI - lihat ClientManagementController::store() yang sekarang
     * cuma Client::create() polos tanpa logic token sama sekali.
     */
    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            if (blank($client->portal_token)) {
                $client->portal_token = self::generateUniquePortalToken();
            }
        });
    }

    /**
     * bin2hex(random_bytes(32)) = 256 bit entropy, 64 karakter hex - tabrakan
     * praktis mustahil, tapi tetap dicek eksplisit (bukan diasumsikan unique)
     * sebelum dipakai, sesuai permintaan "pastikan collision ditangani".
     */
    public static function generateUniquePortalToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (self::where('portal_token', $token)->exists());

        return $token;
    }

    public function regeneratePortalToken(): string
    {
        $token = self::generateUniquePortalToken();
        $this->update(['portal_token' => $token]);

        return $token;
    }

    public function category() { return $this->belongsTo(ClientCategory::class, 'client_category_id'); }
    public function packages() { return $this->hasMany(ClientPackage::class); }
    public function contentPlans() { return $this->hasMany(ContentPlan::class); }
    public function contentItems() { return $this->hasMany(ContentItem::class); }

    public function activePackage() { return $this->hasOne(ClientPackage::class)->where('status', 'active')->latestOfMany('start_date'); }
    public function assignedUsers() { return $this->belongsToMany(User::class, 'user_client_assignments'); }
    public function apiIntegrations() { return $this->hasMany(ApiIntegration::class); }

    // Accessor: $client->logo_url -> URL logo kalau ada, null kalau nggak
    // (biar view tinggal fallback ke placeholder inisial huruf)
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? \Storage::disk('public')->url($this->logo_path) : null;
    }
}
