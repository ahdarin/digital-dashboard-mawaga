<?php

namespace App\Policies;

use App\Models\ContentPlan;
use App\Models\User;

/**
 * ARCH-02 - lihat catatan di ContentItemPolicy. Route yang ada tetap
 * dijaga middleware client.scope + Rule AssignedClient, tidak diubah
 * memakai policy ini.
 */
class ContentPlanPolicy
{
    public function view(User $user, ContentPlan $contentPlan): bool
    {
        return $this->hasAccess($user, $contentPlan->client_id);
    }

    public function update(User $user, ContentPlan $contentPlan): bool
    {
        return $this->hasAccess($user, $contentPlan->client_id);
    }

    public function delete(User $user, ContentPlan $contentPlan): bool
    {
        return $this->hasAccess($user, $contentPlan->client_id);
    }

    private function hasAccess(User $user, ?int $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        return $user->canSeeAllClients() || $user->assignedClients()->whereKey($clientId)->exists();
    }
}
