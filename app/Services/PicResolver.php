<?php

namespace App\Services;

use App\Models\ContentItem;

/**
 * Satu titik resolusi tampilan PIC - dipakai Content Plan/Content Item
 * detail/Production Workflow biar konsisten, bukan tiap view nulis
 * fallback-nya sendiri-sendiri.
 *
 * READ-ONLY MURNI - tidak pernah menulis current_pic_id/ContentItemAssignment.
 *
 * Prioritas:
 * 1. ContentWorkflow.currentPic (User real, canonical person entity -
 *    "satu orang = satu record")
 * 2. external_pic_name mentah - sisa historis dari import Content Planner
 *    yang tidak deterministik ter-resolve ke User manapun (mis. email
 *    malformed tanpa "@"), sengaja TIDAK di-fuzzy-match ke User apapun
 * 3. null -> caller tampilkan "Belum ditugaskan"/"Belum ada Penanggung
 *    Jawab" (wording existing, tidak diubah)
 */
class PicResolver
{
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
}
