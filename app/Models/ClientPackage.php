<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientPackage extends Model
{
    protected $fillable = [
        'client_id', 'package_template_id', 'package_name_snapshot',
        'monthly_content_quota', 'monthly_design_quota',
        'start_date', 'end_date', 'status',
    ];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function client() { return $this->belongsTo(Client::class); }
}