<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsSyncLog extends Model
{
    protected $fillable = [
        'client_id', 'platform_id', 'api_integration_id', 'imported_by',
        'source_type', 'status',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
    public function importedBy() { return $this->belongsTo(User::class, 'imported_by'); }
    public function metrics() { return $this->hasMany(ContentMetric::class, 'sync_log_id'); }
}