<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelayRiskScore extends Model
{
    protected $fillable = [
        'content_item_id',
        'risk_score',
        'risk_level',
        'top_factor',
        'features_snapshot',
    ];
    protected $casts = ['features_snapshot' => 'array'];

    public function contentItem()
    {
        return $this->belongsTo(ContentItem::class);
    }
}