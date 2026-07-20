<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPlan extends Model
{
    protected $fillable = [
        'client_id', 'client_package_id', 'created_by',
        'approved_by', 'month', 'year', 'status',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function clientPackage() { return $this->belongsTo(ClientPackage::class); }
    public function contentItems() { return $this->hasMany(ContentItem::class); }
}