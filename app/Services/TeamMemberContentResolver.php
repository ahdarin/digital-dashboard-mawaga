<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\TeamMember;
use Illuminate\Support\Collection;

/**
 * Attribution AGREGAT ContentItem per TeamMember (Langkah "Performa Tim -
 * migrasi domain User ke TeamMember") - beda tujuan dari PicResolver
 * (PicResolver = display identity SATU ContentItem; ini = kumpulan
 * ContentItem MILIK banyak TeamMember sekaligus, buat listing/summary).
 *
 * Sumber (union, distinct per ContentItem.id, TIDAK fuzzy):
 * A. ContentItemAssignment.user_id == TeamMember.user_id (kalau TeamMember
 *    punya akun)
 * B. content_items.external_pic_email == TeamMember.email (exact,
 *    case-insensitive)
 *
 * workflow.current_pic_id SENGAJA TIDAK dipakai sebagai sumber ketiga -
 * formula Performa Tim yang sudah ada (TeamPerformanceController) dari dulu
 * cuma pakai ContentItemAssignment, bukan current_pic_id, jadi attribution
 * ini mengikuti sumber yang SAMA, cuma nambah fallback email buat yang
 * belum punya User.
 *
 * 2 query total (bukan per-TeamMember) - flat terhadap jumlah TeamMember.
 */
class TeamMemberContentResolver
{
    /**
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return Collection<int, Collection<int, ContentItem>> keyed by TeamMember->id
     */
    public function resolveContentItems(Collection $teamMembers): Collection
    {
        $userIds = $teamMembers->pluck('user_id')->filter()->unique()->values();

        $itemsByUser = ContentItemAssignment::whereIn('user_id', $userIds)
            ->with('contentItem.workflow')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('contentItem')->filter()->unique('id')->values());

        $emails = $teamMembers->pluck('email')->filter()
            ->map(fn ($e) => strtolower(trim($e)))->unique()->values();

        $itemsByEmail = ContentItem::whereNotNull('external_pic_email')
            ->with('workflow')
            ->get()
            ->filter(fn (ContentItem $item) => $emails->contains(strtolower(trim($item->external_pic_email))))
            ->groupBy(fn (ContentItem $item) => strtolower(trim($item->external_pic_email)));

        return $teamMembers->mapWithKeys(function (TeamMember $tm) use ($itemsByUser, $itemsByEmail) {
            $fromUser = $tm->user_id ? ($itemsByUser->get($tm->user_id) ?? collect()) : collect();
            $fromEmail = $tm->email ? ($itemsByEmail->get(strtolower(trim($tm->email))) ?? collect()) : collect();

            return [$tm->id => $fromUser->merge($fromEmail)->unique('id')->values()];
        });
    }
}
