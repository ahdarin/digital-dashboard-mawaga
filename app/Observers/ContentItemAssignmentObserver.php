<?php

namespace App\Observers;

use App\Models\ContentItemAssignment;
use App\Models\UserClientAssignment;

/**
 * Begitu seseorang ditugaskan ke sebuah content item, otomatis daftarkan
 * dia ke roster client-nya juga (user_client_assignments) - biar papan
 * Produksi dkk yang di-scope per-client selalu ikut client yang benar-benar
 * dia kerjakan, tanpa Manager harus isi Assign Klien manual tiap kali ada
 * penugasan baru.
 */
class ContentItemAssignmentObserver
{
    public function created(ContentItemAssignment $assignment): void
    {
        $this->syncRoster($assignment);
    }

    public function updated(ContentItemAssignment $assignment): void
    {
        if ($assignment->wasChanged('user_id')) {
            $this->syncRoster($assignment);
        }
    }

    private function syncRoster(ContentItemAssignment $assignment): void
    {
        $clientId = $assignment->contentItem?->client_id;

        if (! $clientId) {
            return;
        }

        UserClientAssignment::firstOrCreate([
            'user_id' => $assignment->user_id,
            'client_id' => $clientId,
        ]);
    }
}
