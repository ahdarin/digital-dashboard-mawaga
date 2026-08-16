<?php

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\User;

/**
 * ARCH-02 - "bolehkah user ini menyentuh record ini" sebelumnya tidak
 * punya rumah (ditulis ulang ad hoc per controller, sering tidak ditulis
 * sama sekali - lihat SEC-01/04/05/06 di audit). Policy ini adalah rumah
 * itu, dan bisa dipanggil eksplisit lewat $this->authorize(...) di
 * controller baru ke depannya.
 *
 * Route yang sudah ada TIDAK diubah untuk memakai policy ini - proteksinya
 * saat ini berjalan lewat middleware client.scope + Rule AssignedClient
 * (sudah diverifikasi lewat pengujian IDOR langsung di setiap route), dan
 * logikanya di sini sengaja dibuat identik supaya kedua mekanisme selalu
 * sepakat.
 */
class ContentItemPolicy
{
    public function view(User $user, ContentItem $contentItem): bool
    {
        return $this->hasAccess($user, $contentItem->client_id);
    }

    public function update(User $user, ContentItem $contentItem): bool
    {
        return $this->hasAccess($user, $contentItem->client_id);
    }

    public function delete(User $user, ContentItem $contentItem): bool
    {
        return $this->hasAccess($user, $contentItem->client_id);
    }

    private function hasAccess(User $user, ?int $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        return $user->canSeeAllClients() || $user->assignedClients()->whereKey($clientId)->exists();
    }
}
