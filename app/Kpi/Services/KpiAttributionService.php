<?php

namespace App\Kpi\Services;

use App\Models\ContentPublication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Koreksi lanjutan KPI 2026-09-02 - "content outcome periode ini" SEKARANG
 * ditentukan dari PUBLICATION yang tanggal publikasinya (`published_at`)
 * benar-benar JATUH di periode yang dihitung, BUKAN dari assignment PIC
 * yang "dibuat sebelum akhir periode" (desain lama: `content_item_
 * assignments.created_at <= period_end` tanpa batas bawah - assignment
 * lama/konten lama ikut terhitung ulang SETIAP bulan berikutnya selama
 * baris assignment-nya masih ada, walau tidak ada aktivitas apa pun lagi
 * bulan itu).
 *
 * Atribusi SIAPA yang dapat kredit (Copywriter/Content Creator/Graphic
 * Designer/SMO/Manager/CEO) sekarang jadi tanggung jawab
 * `KpiRoleContextResolver` (berbasis aktivitas aktor per role) - service
 * ini HANYA menjawab "content item mana yang outcome-nya relevan untuk
 * periode ini", dipakai `KpiCalculationService::persistContentOutcomes()`.
 */
class KpiAttributionService
{
    /**
     * Distinct content_item_id yang punya MINIMAL SATU publication dengan
     * `published_at` di dalam [periodStart, periodEnd] - dasar "direct
     * content outcome" & company/client aggregate periode ini. Satu
     * content item dihitung SEKALI di sini terlepas dari berapa publication
     * (multi-platform) atau berapa PIC yang menempel padanya.
     *
     * @return Collection<int, int>
     */
    public function contentItemIdsPublishedInPeriod(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        return ContentPublication::whereBetween('published_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->pluck('content_item_id')
            ->unique()
            ->values();
    }
}
