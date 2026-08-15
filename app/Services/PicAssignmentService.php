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
        'Desain' => 'Graphic Designer',
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

    private function candidatesFor(Client $client, ?string $contentTypeName): \Illuminate\Support\Collection
    {
        $wantedRole = $this->roleByContentType[$contentTypeName ?? ''] ?? null;

        // Kandidat utama: tim yang di-assign ke client ini, role-nya cocok
        // jenis konten.
        $assignedUserIds = \App\Models\UserClientAssignment::where('client_id', $client->id)->pluck('user_id');

        $query = User::whereIn('id', $assignedUserIds)->where('status', 'active');
        if ($wantedRole) {
            $query->whereHas('role', fn ($q) => $q->where('name', $wantedRole));
        }
        $candidates = $query->get();

        if ($candidates->isNotEmpty()) {
            return $candidates;
        }

        // Cadangan: client belum punya tim ter-assign - semua internal aktif
        // dengan role yang cocok.
        $fallbackQuery = User::whereNull('client_id')->where('status', 'active');
        if ($wantedRole) {
            $fallbackQuery->whereHas('role', fn ($q) => $q->where('name', $wantedRole));
        }
        $fallback = $fallbackQuery->get();

        if ($fallback->isNotEmpty()) {
            return $fallback;
        }

        // Cadangan terakhir: tidak ada role yang cocok sama sekali - tetap
        // dibagi ke semua internal aktif, ditandai lewat usedFallbackRole().
        $this->usedFallbackRole = true;

        return User::whereNull('client_id')->where('status', 'active')->get();
    }

    private function baseLoad(User $user): int
    {
        return ContentItem::whereHas('assignments', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', ['uploaded', 'cancelled']))
            ->count();
    }
}
