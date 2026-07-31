<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedReport extends Model
{
    protected $fillable = [
        'client_id', 'generated_by', 'report_type',
        'period_start', 'period_end', 'file_path',
    ];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date'];

    public function client() { return $this->belongsTo(Client::class); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by'); }
}