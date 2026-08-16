<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validasi client_id dari request body (bukan route-model-binding, yang
 * sudah dijaga middleware client.scope) - CEO/Manager lolos selalu, role
 * lain harus punya client itu di roster (user_client_assignments).
 */
class AssignedClient implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = auth()->user();

        if (! $user || $user->canSeeAllClients()) {
            return;
        }

        if (! $user->assignedClients()->whereKey($value)->exists()) {
            $fail('Anda tidak punya akses ke client ini.');
        }
    }
}
