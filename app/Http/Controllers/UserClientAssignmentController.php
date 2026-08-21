<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Models\UserClientAssignment;
use App\Services\PicReassignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserClientAssignmentController extends Controller
{
    public function update(Request $request, User $user)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'client_ids' => 'nullable|array',
            'client_ids.*' => 'exists:clients,id',
        ]);

        $this->sync(['user_id' => $user->id], 'client_id', $validated['client_ids'] ?? []);

        return redirect()->route('user-management.index')
            ->with('status', "Client untuk {$user->name} berhasil diperbarui.");
    }

    /**
     * Kebalikan dari update() - dari sisi client, pilih staff mana saja
     * yang jadi PIC-nya. Dipakai tombol "PIC Ditugaskan" di halaman detail
     * client, biar assign-nya nggak cuma bisa dari Kelola Pengguna.
     *
     * Kalau ada staff yang dikeluarkan (di-uncheck) padahal masih PIC di
     * konten aktif client ini, seluruh update ditolak - harus lewat
     * removeFromClient() satu-satu yang mewajibkan pilih pengganti dulu,
     * biar konten itu nggak nyangkut ke PIC yang sudah dilepas dari roster.
     */
    public function updateForClient(Request $request, Client $client, PicReassignmentService $picReassignmentService)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $userIds = $validated['user_ids'] ?? [];
        $existingIds = $client->assignedUsers()->pluck('users.id')->toArray();
        $toRemove = array_diff($existingIds, $userIds);

        if (! empty($toRemove)) {
            $blocked = [];
            foreach (User::whereIn('id', $toRemove)->get() as $staff) {
                $count = $picReassignmentService->countActive($staff, $client->id);
                if ($count > 0) {
                    $blocked[] = "{$staff->name} ({$count} konten aktif)";
                }
            }

            if (! empty($blocked)) {
                throw ValidationException::withMessages([
                    'user_ids' => 'Tidak bisa dikeluarkan lewat sini karena masih PIC di konten aktif: '.implode(', ', $blocked).'. Gunakan tombol "Keluarkan" di kartu PIC untuk memindahkan tugasnya dulu.',
                ]);
            }
        }

        $this->sync(['client_id' => $client->id], 'user_id', $userIds);

        return redirect()->route('client-management.show', $client)
            ->with('status', "PIC untuk {$client->brand_name} berhasil diperbarui.");
    }

    /**
     * Keluarkan SATU staff dari roster PIC client ini - dipakai saat yang
     * bersangkutan masih PIC di konten aktif (mis. resign), jadi wajib
     * pilih pengganti buat mindahin semua tugas aktifnya dulu sebelum
     * benar-benar dilepas dari roster.
     */
    public function removeFromClient(Request $request, Client $client, User $user, PicReassignmentService $picReassignmentService)
    {
        $this->authorizeManage();

        $activeCount = $picReassignmentService->countActive($user, $client->id);
        $replacement = null;

        if ($activeCount > 0) {
            $validated = $request->validate([
                'replacement_user_id' => [
                    'required',
                    Rule::exists('user_client_assignments', 'user_id')->where(fn ($q) => $q->where('client_id', $client->id)),
                    Rule::notIn([$user->id]),
                ],
            ]);

            $replacement = User::findOrFail($validated['replacement_user_id']);
            $picReassignmentService->transferAll($user, $replacement, $client->id);
        }

        UserClientAssignment::where('client_id', $client->id)->where('user_id', $user->id)->delete();

        $message = $activeCount > 0
            ? "{$user->name} dikeluarkan dari PIC {$client->brand_name}. {$activeCount} tugas aktif dipindahkan ke {$replacement->name}."
            : "{$user->name} dikeluarkan dari PIC {$client->brand_name}.";

        return redirect()->route('client-management.show', $client)->with('status', $message);
    }

    /**
     * Sync generik user_client_assignments dari salah satu sisi - $fixed
     * kunci yang tetap (mis. ['user_id' => X] atau ['client_id' => X]),
     * $varyColumn kolom yang divariasikan, $varyIds daftar ID baru.
     */
    private function sync(array $fixed, string $varyColumn, array $varyIds): void
    {
        $existingIds = UserClientAssignment::where($fixed)->pluck($varyColumn)->toArray();

        $toRemove = array_diff($existingIds, $varyIds);
        $toAdd = array_diff($varyIds, $existingIds);

        UserClientAssignment::where($fixed)->whereIn($varyColumn, $toRemove)->delete();

        foreach ($toAdd as $id) {
            UserClientAssignment::create([...$fixed, $varyColumn => $id]);
        }
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('user_management', 'manage'), 403);
    }
}
