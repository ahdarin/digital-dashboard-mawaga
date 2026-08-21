<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentRevision extends Model
{
    protected $fillable = [
        'content_item_id', 'requested_by_user_id', 'requested_by_client_id',
        'revision_round', 'revision_note', 'status',
    ];

    public function contentItem() { return $this->belongsTo(ContentItem::class); }

    // Actor eksplisit: SALAH SATU dari dua ini selalu null - internal user
    // (revisi dibuat lewat halaman detail konten/kanban) ATAU client
    // (request revisi lewat Client Portal), tidak pernah dua-duanya.
    public function requestedByUser() { return $this->belongsTo(User::class, 'requested_by_user_id'); }
    public function requestedByClient() { return $this->belongsTo(Client::class, 'requested_by_client_id'); }

    /**
     * Label tampilan siapa yang minta revisi - dipakai view internal (revision
     * log, detail konten) supaya tidak perlu tahu actor-nya User atau Client.
     */
    public function requestedByLabel(): string
    {
        return $this->requestedByClient?->brand_name
            ?? $this->requestedByUser?->name
            ?? '-';
    }
}
