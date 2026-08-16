<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Guard buat request yang nerima client_id lewat query string/body
     * (bukan lewat route-model-binding, yang sudah dijaga middleware
     * client.scope) - abort 403 kalau user bukan CEO/Manager dan client
     * itu bukan bagian dari roster-nya (user_client_assignments).
     */
    protected function assertClientAccessible(int $clientId): void
    {
        $user = auth()->user();

        abort_unless(
            $user && ($user->canSeeAllClients() || $user->assignedClients()->whereKey($clientId)->exists()),
            403,
            'Anda tidak punya akses ke client ini.'
        );
    }
}
