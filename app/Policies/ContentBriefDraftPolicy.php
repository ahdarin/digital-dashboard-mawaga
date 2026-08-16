<?php

namespace App\Policies;

use App\Models\ContentBriefDraft;
use App\Models\User;

/**
 * ARCH-02 - lihat catatan di ContentItemPolicy. ContentBriefDraft tidak
 * punya kolom client_id sendiri, jadi di-resolve lewat relasi contentItem
 * (sama seperti middleware client.scope:contentBrief,contentItem.client_id
 * yang sudah dipasang di route). Route yang ada tetap dijaga middleware
 * itu, tidak diubah memakai policy ini.
 */
class ContentBriefDraftPolicy
{
    public function view(User $user, ContentBriefDraft $draft): bool
    {
        return $this->hasAccess($user, $draft->contentItem?->client_id);
    }

    public function update(User $user, ContentBriefDraft $draft): bool
    {
        return $this->hasAccess($user, $draft->contentItem?->client_id);
    }

    private function hasAccess(User $user, ?int $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        return $user->canSeeAllClients() || $user->assignedClients()->whereKey($clientId)->exists();
    }
}
