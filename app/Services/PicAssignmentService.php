<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\User;

/**
 * Pembagian PIC otomatis buat konten yang dibuat massal oleh AI (lihat
 * AnalyticsController::applyAiStrategy) - giliran berputar berbasis beban
 * kerja (round-robin, BUKAN acak), supaya setiap konten pasti punya
 * penanggung jawab tanpa numpuk ke satu orang.
 */
class PicAssignmentService
{
    private array $roleByContentType = [
        'Video' => 'Content Creator',
        'Desain' => 'Desain Grafis',
    ];

    private array $loadCounts = [];
    private bool $usedFallbackRole = false;

    /**
     * Pilih PIC paling ringan bebannya buat satu content item, lalu naikkan
     * hitungan bebannya di memori supaya item berikutnya dalam batch yang
     * sama tidak jatuh ke orang yang sama terus.
     */
    public function assign(Client $client, ?string $contentTypeName): ?User
    {
        $candidates = $this->candidatesFor($client, $contentTypeName);

        if ($candidates->isEmpty()) {
            return null;
        }

        $picked = $candidates->sortBy(fn (User $u) => $this->loadCounts[$u->id] ?? $this->baseLoad($u))->first();

        $this->loadCounts[$picked->id] = ($this->loadCounts[$picked->id] ?? $this->baseLoad($picked)) + 1;

        return $picked;
    }

    public function usedFallbackRole(): bool
    {
        return $this->usedFallbackRole;
    }

    /**
     * Kandidat SELALU dibatasi ke tim yang sudah di-assign ke client ini
     * (lewat "Assign Klien" di Kelola Pengguna) - tidak ada fallback ke
     * seluruh staff internal lagi. Kalau tim yang ditugaskan belum cukup
     * (kosong sama sekali, atau tidak ada yang role-nya cocok), itu harus
     * diselesaikan dengan menugaskan orang yang tepat ke client-nya dulu -
     * bukan dibuka diam-diam ke siapa saja oleh sistem.
     */
    private function candidatesFor(Client $client, ?string $contentTypeName): \Illuminate\Support\Collection
    {
        $wantedRole = $this->roleByContentType[$contentTypeName ?? ''] ?? null;

        $assignedUserIds = \App\Models\UserClientAssignment::where('client_id', $client->id)->pluck('user_id');

        $query = User::whereIn('id', $assignedUserIds)->where('status', 'active');

        if (! $wantedRole) {
            return $query->get();
        }

        $withMatchingRole = (clone $query)->whereHas('roles', fn ($q) => $q->where('name', $wantedRole))->get();

        if ($withMatchingRole->isNotEmpty()) {
            return $withMatchingRole;
        }

        // Nggak ada yang role-nya persis cocok di antara tim yang
        // ditugaskan - tetap dibatasi ke tim itu saja (bukan dibuka ke
        // semua staff), cuma ditandai biar caller tahu ini kurang ideal.
        $this->usedFallbackRole = true;

        return $query->get();
    }

    private function baseLoad(User $user): int
    {
        return ContentItem::whereHas('assignments', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', ['uploaded', 'cancelled']))
            ->count();
    }
}
