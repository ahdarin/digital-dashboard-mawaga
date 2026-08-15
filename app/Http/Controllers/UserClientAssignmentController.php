<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserClientAssignment;
use Illuminate\Http\Request;

class UserClientAssignmentController extends Controller
{
    public function update(Request $request, User $user)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'client_ids' => 'nullable|array',
            'client_ids.*' => 'exists:clients,id',
        ]);

        $clientIds = $validated['client_ids'] ?? [];

        // Sync: hapus yang tidak dipilih lagi, tambah yang baru dipilih
        $existingIds = $user->assignedClients()->pluck('clients.id')->toArray();

        $toRemove = array_diff($existingIds, $clientIds);
        $toAdd = array_diff($clientIds, $existingIds);

        UserClientAssignment::where('user_id', $user->id)
            ->whereIn('client_id', $toRemove)
            ->delete();

        foreach ($toAdd as $clientId) {
            UserClientAssignment::create([
                'user_id' => $user->id,
                'client_id' => $clientId,
            ]);
        }

        return redirect()->route('user-management.index')
            ->with('status', "Client untuk {$user->name} berhasil diperbarui.");
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('user_management', 'manage'), 403);
    }
}