<?php

namespace App\Services;

use App\Models\ContentItemAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Attribution AGREGAT ContentItem per User - beda tujuan dari PicResolver
 * (PicResolver = display identity SATU ContentItem; ini = kumpulan
 * ContentItem MILIK banyak User sekaligus, buat listing/summary seperti
 * Performa Tim & kandidat reassign PIC).
 *
 * Sumber: ContentItemAssignment.user_id - satu-satunya sumber assignment
 * PIC sekarang ("satu orang = satu record", tidak ada TeamMember/
 * external_pic_email fallback lagi di sini; ContentItem yang PIC-nya
 * belum ter-resolve ke User manapun sengaja tidak ikut dihitung ke
 * siapa pun - lihat PicResolver untuk display fallback-nya).
 *
 * 1 query total (bukan per-User) - flat terhadap jumlah User.
 */
class UserContentResolver
{
    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, Collection<int, \App\Models\ContentItem>> keyed by User->id
     */
    public function resolveContentItems(Collection $users): Collection
    {
        $userIds = $users->pluck('id')->unique()->values();

        $itemsByUser = ContentItemAssignment::whereIn('user_id', $userIds)
            ->with('contentItem.workflow')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('contentItem')->filter()->unique('id')->values());

        return $users->mapWithKeys(fn (User $user) => [
            $user->id => $itemsByUser->get($user->id) ?? collect(),
        ]);
    }
}
