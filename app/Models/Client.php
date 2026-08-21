<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'client_category_id', 'name', 'brand_name', 'logo_path', 'status', 'color', 'asset_link'
    ];

    public function category() { return $this->belongsTo(ClientCategory::class, 'client_category_id'); }
    public function packages() { return $this->hasMany(ClientPackage::class); }
    public function contentPlans() { return $this->hasMany(ContentPlan::class); }
    public function contentItems() { return $this->hasMany(ContentItem::class); }

    public function users() { return $this->hasMany(User::class); }
    public function owner() { return $this->hasOne(User::class)->whereHas('roles', fn ($q) => $q->where('name', 'Client Owner')); }
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