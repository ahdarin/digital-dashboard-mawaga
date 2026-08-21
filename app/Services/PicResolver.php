<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\TeamMember;
use Illuminate\Support\Collection;

/**
 * Satu titik resolusi tampilan PIC (Langkah "TeamMember <-> Legacy PIC
 * Operational UI Integration") - dipakai Content Plan/Content Item detail/
 * Production Workflow biar konsisten, bukan tiap view nulis fallback-nya
 * sendiri-sendiri.
 *
 * READ-ONLY MURNI - tidak pernah menulis current_pic_id/ContentItemAssignment.
 * TeamMember cuma DISPLAY fallback, bukan assignment beneran (Langkah 8).
 *
 * Prioritas:
 * 1. ContentWorkflow.currentPic (User real, sudah authenticated assignee)
 * 2. external_pic_email -> TeamMember.email exact match (case-insensitive,
 *    BUKAN fuzzy)
 * 3. external_pic_name mentah (kalau email tidak match TeamMember manapun,
 *    atau memang tidak ada email tapi ada nama)
 * 4. null -> caller tampilkan "Belum ditugaskan"/"Belum ada Penanggung
 *    Jawab" (wording existing, tidak diubah)
 *
 * TeamMember di-preload SEKALI per instance (bukan per-item) - instance ini
 * dibuat sekali per request controller lalu dipakai berulang dalam loop
 * item, jadi cuma 1 query `team_members` per halaman, bukan N+1.
 */
class PicResolver
{
    private ?Collection $teamMembersByEmail = null;

    public function resolve(ContentItem $item): array
    {
        $currentPic = $item->workflow?->currentPic;

        if ($currentPic) {
            return [
                'name' => $currentPic->name,
                'email' => $currentPic->email,
                'has_account' => true,
                'user_id' => $currentPic->id,
                'source' => 'user',
            ];
        }

        if ($item->external_pic_email) {
            $teamMember = $this->teamMembersByEmail()->get(strtolower(trim($item->external_pic_email)));
            if ($teamMember) {
                return [
                    'name' => $teamMember->name,
                    'email' => $teamMember->email,
                    'has_account' => $teamMember->hasAccount(),
                    'user_id' => $teamMember->user_id,
                    'source' => 'team_member',
                ];
            }
        }

        if ($item->external_pic_name) {
            return [
                'name' => $item->external_pic_name,
                'email' => $item->external_pic_email,
                'has_account' => false,
                'user_id' => null,
                'source' => 'external_name',
            ];
        }

        return [
            'name' => null,
            'email' => null,
            'has_account' => false,
            'user_id' => null,
            'source' => 'none',
        ];
    }

    private function teamMembersByEmail(): Collection
    {
        return $this->teamMembersByEmail ??= TeamMember::whereNotNull('email')
            ->get()
            ->keyBy(fn (TeamMember $tm) => strtolower(trim($tm->email)));
    }
}
