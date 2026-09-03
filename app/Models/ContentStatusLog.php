<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentStatusLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_item_id', 'changed_by_user_id', 'changed_by_client_id', 'from_status',
        'to_status', 'approval_type', 'notes', 'changed_at',
    ];
    protected $casts = ['changed_at' => 'datetime'];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }

    // Actor eksplisit: SALAH SATU dari dua ini selalu null - lihat
    // ContentRevision::requestedByUser()/requestedByClient() untuk pola yang sama.
    public function changedByUser() { return $this->belongsTo(User::class, 'changed_by_user_id'); }
    public function changedByClient() { return $this->belongsTo(Client::class, 'changed_by_client_id'); }

    public function changedByLabel(): string
    {
        return $this->changedByClient?->name
            ?? $this->changedByUser?->name
            ?? '-';
    }
}
