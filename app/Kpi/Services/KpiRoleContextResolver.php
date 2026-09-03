<?php

namespace App\Kpi\Services;

use App\Models\ContentBriefDraft;
use App\Models\ContentItemAssignment;
use App\Models\ContentPublication;
use App\Models\ContentStatusLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Koreksi lanjutan KPI 2026-09-02 - atribusi role KPI SEKARANG berbasis
 * AKTIVITAS AKTOR yang benar-benar tercatat di periode ini, BUKAN memilih
 * satu role "utama" dengan priority fallback dari kepemilikan role +
 * status PIC semata (desain lama). Role KPI SELALU role EXISTING
 * (`roles`/`user_roles`) - TIDAK ADA tabel/role baru.
 *
 * Setiap method di bawah mengembalikan Collection of
 * array{user_id, content_item_id, client_id, role_name?} - SATU baris per
 * (aktor, content_item) yang BENAR-BENAR terbukti dari data existing untuk
 * periode [periodStart, periodEnd]. Satu user boleh muncul di lebih dari
 * satu method (multi-role) dan lebih dari satu client (breakdown per
 * client) - tidak pernah dipaksa jadi satu bucket tunggal.
 */
class KpiRoleContextResolver
{
    /**
     * Fallback role produksi generik - HANYA dipakai saat content type
     * tidak match Content Creator/Graphic Designer (lihat
     * resolveRoleForAssignment()). Copywriter & SMO SENGAJA TIDAK ada di
     * sini lagi (koreksi 2026-09-02) - keduanya sekarang punya jalur
     * atribusi sendiri berbasis aktivitas nyata (authorship brief /
     * publishing), BUKAN dari sekadar jadi PIC content_item_assignments.
     * Sisa fallback ini murni untuk Manager/CEO yang JUGA mengerjakan
     * produksi langsung (assigned sebagai PIC) - tetap role EXISTING
     * mereka sendiri, bukan role baru.
     */
    private const PRODUCTION_ROLE_PRIORITY = ['Manager', 'CEO'];

    /**
     * Copywriter: dari `content_brief_drafts.created_by` - brief yang
     * DIBUAT pada periode ini (`created_at` di dalam periode). TIDAK
     * disyaratkan jadi PIC content item sama sekali (koreksi: dulu
     * copywriter cuma dapat KPI kalau kebetulan juga PIC assignment).
     *
     * @return Collection<int, array{user_id: int, content_item_id: int, client_id: ?int}>
     */
    public function copywriterActivities(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        return ContentBriefDraft::whereNotNull('created_by')
            ->whereBetween('created_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->with('contentItem')
            ->get()
            ->filter(fn (ContentBriefDraft $b) => $b->contentItem !== null)
            ->map(fn (ContentBriefDraft $b) => [
                'user_id' => $b->created_by,
                'content_item_id' => $b->content_item_id,
                'client_id' => $b->contentItem->client_id,
            ])
            ->values();
    }

    /**
     * Content Creator / Graphic Designer / Manager-CEO-sebagai-PIC: dari
     * `content_item_assignments` (PIC EXISTING) - TAPI HANYA untuk content
     * item yang MEMANG punya aktivitas produksi tercatat (`content_status_logs`,
     * exclude koreksi) DI DALAM periode ini (koreksi #1: assignment lama
     * yang kontennya sudah tidak aktif tidak boleh ikut terhitung ulang
     * tiap bulan hanya karena baris assignment-nya masih ada).
     *
     * Role ditentukan dari tipe konten (Video -> Content Creator, Desain ->
     * Graphic Designer) - `assignment_role` TIDAK dipakai sebagai sinyal
     * (diverifikasi: SETIAP baris `content_item_assignments` di seluruh
     * codebase selalu ditulis `'primary'`, tidak pernah nilai lain - bukan
     * fallback langka, itu satu-satunya nilai yang pernah ada). Kalau tipe
     * konten tidak match role manapun yang dimiliki user, fallback ke
     * Manager/CEO (PRODUCTION_ROLE_PRIORITY) - kalau tetap tidak match,
     * item ini TIDAK dihitung untuk siapa pun lewat jalur ini (bukan
     * mengarang role).
     *
     * @return Collection<int, array{user_id: int, role_name: string, content_item_id: int, client_id: ?int}>
     */
    public function productionActivities(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $activeItemIds = ContentStatusLog::whereNull('approval_type')
            ->whereBetween('changed_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->distinct()
            ->pluck('content_item_id');

        if ($activeItemIds->isEmpty()) {
            return collect();
        }

        return ContentItemAssignment::whereIn('content_item_id', $activeItemIds)
            ->with(['contentItem.contentType', 'user.roles'])
            ->get()
            ->filter(fn (ContentItemAssignment $a) => $a->user !== null && $a->contentItem !== null)
            ->map(function (ContentItemAssignment $a) {
                $roleName = $this->resolveRoleForAssignment(
                    $a->user->roles->pluck('name')->all(),
                    $a->contentItem->contentType?->name
                );

                if ($roleName === null) {
                    return null;
                }

                return [
                    'user_id' => $a->user_id,
                    'role_name' => $roleName,
                    'content_item_id' => $a->content_item_id,
                    'client_id' => $a->contentItem->client_id,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * SMO: dari `content_publications.published_by` - HANYA publication
     * yang `recorded_via='manual'` (aktor terpercaya - lihat docblock
     * migration `add_recorded_via_to_content_publications_table` dan
     * `ContentPublication::isReliablyAttributedToPublisher()`), dengan
     * `published_at` di dalam periode ini. SMO TIDAK disyaratkan jadi PIC
     * content item sama sekali - dan SEBALIKNYA, PIC yang BUKAN publisher
     * asli TIDAK mendapat kredit publishing (koreksi: dulu kredit publish
     * SELALU jatuh ke PIC "SMO" content, terlepas siapa yang benar-benar
     * mempublikasikannya).
     *
     * @return Collection<int, array{user_id: int, content_item_id: int, client_id: ?int, publication_id: int}>
     */
    public function smoActivities(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        return ContentPublication::where('recorded_via', ContentPublication::RECORDED_VIA_MANUAL)
            ->whereNotNull('published_by')
            ->whereBetween('published_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->with('contentItem')
            ->get()
            ->filter(fn (ContentPublication $p) => $p->contentItem !== null)
            ->map(fn (ContentPublication $p) => [
                'user_id' => $p->published_by,
                'content_item_id' => $p->content_item_id,
                'client_id' => $p->contentItem->client_id,
                'publication_id' => $p->id,
            ])
            ->values();
    }

    /**
     * @param  array<int, string>  $userRoleNames
     */
    private function resolveRoleForAssignment(array $userRoleNames, ?string $contentTypeName): ?string
    {
        if ($contentTypeName === 'Video' && in_array('Content Creator', $userRoleNames, true)) {
            return 'Content Creator';
        }

        if ($contentTypeName === 'Desain' && in_array('Graphic Designer', $userRoleNames, true)) {
            return 'Graphic Designer';
        }

        foreach (self::PRODUCTION_ROLE_PRIORITY as $roleName) {
            if (in_array($roleName, $userRoleNames, true)) {
                return $roleName;
            }
        }

        // User tidak punya role produksi yang cocok sama sekali (mis. PIC
        // dengan role cuma Copywriter/SMO/Admin, atau content type tidak
        // dikenal) - item ini TIDAK dihitung untuk siapa pun lewat jalur
        // produksi (bukan mengarang role).
        return null;
    }
}
