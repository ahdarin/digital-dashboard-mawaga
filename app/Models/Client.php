<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'client_category_id', 'name', 'brand_name', 'status',
    ];

    public function category() { return $this->belongsTo(ClientCategory::class, 'client_category_id'); }
    public function packages() { return $this->hasMany(ClientPackage::class); }
    public function contentPlans() { return $this->hasMany(ContentPlan::class); }
    public function contentItems() { return $this->hasMany(ContentItem::class); }

    // --- Tambahan untuk Client Management ---
    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function owner()
    {
        return $this->hasOne(User::class)->whereHas('role', fn ($q) => $q->where('name', 'Client Owner'));
    }

    public function activePackage()
    {
        return $this->hasOne(ClientPackage::class)->where('status', 'active')->latestOfMany('start_date');
    }

    public function assignedUsers()
    {
        return $this->belongsToMany(User::class, 'user_client_assignments');
    }
}