<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageTemplate extends Model
{
    protected $fillable = [
        'name',
        'monthly_content_quota',
        'monthly_design_quota',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function clientPackages(): HasMany
    {
        return $this->hasMany(ClientPackage::class);
    }
}