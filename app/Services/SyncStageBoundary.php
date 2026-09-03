<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * PROGRESSIVE 90-DAY SYNC ENGINE - deterministic, timezone-safe rolling age
 * bucket computation, shared by Instagram AND TikTok chunk planning so the
 * boundary math exists in exactly ONE place (Langkah 4, "the exact boundary
 * implementation must be deterministic and timezone-safe").
 *
 * Buckets are ROLLING DAY AGE relative to a single $now snapshot taken once
 * per planning run (never per-item now() calls, which could let two items
 * published on the exact same instant fall into different buckets purely
 * because of clock drift between two calls). Uses startOfDay() on both ends
 * so "age in days" is a whole-day integer, immune to time-of-day jitter.
 */
class SyncStageBoundary
{
    public const STAGE_RECENT = 1;

    public const STAGE_MID = 2;

    public const STAGE_OLDER = 3;

    /**
     * STAGE 1 (0-29 hari), STAGE 2 (30-59 hari), STAGE 3 (60-89 hari, sampai
     * batas discovery window - item lebih tua dari itu TIDAK PERNAH sampai
     * ke sini karena sudah difilter di discovery/known-refresh eligibility
     * query, lihat Part 8/9 pass sebelumnya).
     */
    public static function stageFor(Carbon $publishedAt, Carbon $now): int
    {
        $ageDays = $publishedAt->copy()->startOfDay()->diffInDays($now->copy()->startOfDay(), false);
        $ageDays = max(0, $ageDays);

        $stage1Max = (int) config('analytics.sync_stage_1_max_age_days');
        $stage2Max = (int) config('analytics.sync_stage_2_max_age_days');

        return match (true) {
            $ageDays < $stage1Max => self::STAGE_RECENT,
            $ageDays < $stage2Max => self::STAGE_MID,
            default => self::STAGE_OLDER,
        };
    }
}
