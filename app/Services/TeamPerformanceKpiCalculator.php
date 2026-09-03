<?php

namespace App\Services;

use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\UserMonthlyKpiResult;
use App\Jobs\RecalculateMonthlyKpi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Satu-satunya tempat KPI Team Performance dihitung. Baca data existing apa
 * adanya (assignment, brief, status log, revision, publication, analytics
 * snapshot) TANPA mengubah cara kerja pengguna atau menulis relasi baru -
 * lihat docs/KPI_TEAM_PERFORMANCE.md untuk formula & aturan atribusi
 * lengkap.
 *
 * Dipanggil dari App\Jobs\RecalculateMonthlyKpi (satu-satunya jalur otomatis,
 * lihat docblock job itu) - method ini sendiri aman dipanggil berulang untuk
 * periode yang sama (selalu updateOrCreate, tidak pernah insert duplikat).
 */
class TeamPerformanceKpiCalculator
{
    private const TOLERANCE_HOURS = 24;
    private const MAX_BONUS = 10.0;
    private const MIN_BASELINE_SAMPLE = 3;

    /**
     * Pemicu kedua KPI (selain jadwal harian di routes/console.php): dipanggil
     * dari titik manapun yang menampilkan KPI (Team Performance, Profile)
     * saat halaman dibuka, supaya bulan berjalan yang belum pernah dihitung
     * atau hasil terakhirnya bukan dari hari ini langsung diperbarui.
     * ShouldBeUnique pada job membuat dispatch berulang dari banyak halaman
     * sekaligus aman (tidak pernah dobel).
     */
    public function ensureCalculated(Carbon $periodStart): void
    {
        $latestCalculatedAt = UserMonthlyKpiResult::where('period_start', $periodStart->toDateString())
            ->max('calculated_at');

        $stale = $latestCalculatedAt === null || Carbon::parse($latestCalculatedAt)->lt(now()->startOfDay());

        if ($stale) {
            RecalculateMonthlyKpi::dispatch($periodStart->toDateString());
        }
    }

    public function calculateForPeriod(Carbon $periodStart): void
    {
        $periodStart = $periodStart->copy()->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $contentItems = $this->contentItemsForPeriod($periodStart, $periodEnd);

        if ($contentItems->isEmpty()) {
            return;
        }

        $itemIds = $contentItems->pluck('id');

        $attributionByUser = $this->attributionByUser($itemIds);
        $revisionFlagByItem = $this->internalRevisionFlagByItem($itemIds);

        $timelinessByItem = $contentItems->mapWithKeys(
            fn (ContentItem $item) => [$item->id => $this->timelinessForItem($item)]
        );

        $analyticsByItem = $contentItems->mapWithKeys(
            fn (ContentItem $item) => [$item->id => $this->analyticsBonusForItem($item)]
        );

        $itemsById = $contentItems->keyBy('id');
        $calculatedAt = now();

        foreach ($attributionByUser as $userId => $userItemIds) {
            $this->persistUserResult(
                (int) $userId,
                $periodStart,
                collect($userItemIds)->unique()->values(),
                $itemsById,
                $revisionFlagByItem,
                $timelinessByItem,
                $analyticsByItem,
                $calculatedAt
            );
        }
    }

    /**
     * Content masuk ke periode bulan berdasarkan publication PERTAMANYA
     * (tanggal publication paling awal lintas platform) - bukan
     * deadline_at/created_at, sesuai keputusan produk.
     */
    private function contentItemsForPeriod(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $firstPublishedByItem = ContentPublication::query()
            ->selectRaw('content_item_id, MIN(published_at) as first_published_at')
            ->groupBy('content_item_id')
            ->get()
            ->keyBy('content_item_id');

        $itemIds = $firstPublishedByItem
            ->filter(fn ($row) => Carbon::parse($row->first_published_at)->between($periodStart, $periodEnd))
            ->keys();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        return ContentItem::whereIn('id', $itemIds)
            ->with(['client', 'publications', 'statusLogs'])
            ->get()
            ->map(function (ContentItem $item) use ($firstPublishedByItem) {
                $item->setAttribute(
                    'first_published_at',
                    Carbon::parse($firstPublishedByItem[$item->id]->first_published_at)
                );

                return $item;
            });
    }

    /**
     * user_id => [content_item_id, ...] - satu content boleh menyumbang ke
     * banyak user (semua PIC yang tercatat), satu user tidak pernah dapat
     * content_item_id yang sama dua kali (dedup lewat key array asosiatif
     * per item sebelum dibalik ke per-user).
     */
    private function attributionByUser(Collection $itemIds): array
    {
        $byItem = [];

        ContentItemAssignment::whereIn('content_item_id', $itemIds)
            ->get(['content_item_id', 'user_id'])
            ->each(function ($row) use (&$byItem) {
                $byItem[$row->content_item_id][$row->user_id] = true;
            });

        ContentBriefDraft::whereIn('content_item_id', $itemIds)
            ->whereNotNull('created_by')
            ->get(['content_item_id', 'created_by'])
            ->each(function ($row) use (&$byItem) {
                $byItem[$row->content_item_id][$row->created_by] = true;
            });

        // Cuma transisi ke scheduled/uploaded yang dihitung sebagai kontribusi
        // "menayangkan" - approval (waiting_review->approved) SENGAJA tidak
        // masuk sini, lihat docs/KPI_TEAM_PERFORMANCE.md.
        ContentStatusLog::whereIn('content_item_id', $itemIds)
            ->whereIn('to_status', ['scheduled', 'uploaded'])
            ->whereNotNull('changed_by_user_id')
            ->get(['content_item_id', 'changed_by_user_id'])
            ->each(function ($row) use (&$byItem) {
                $byItem[$row->content_item_id][$row->changed_by_user_id] = true;
            });

        $byUser = [];
        foreach ($byItem as $itemId => $userIds) {
            foreach (array_keys($userIds) as $userId) {
                $byUser[$userId][] = (int) $itemId;
            }
        }

        return $byUser;
    }

    /** content_item_id => true untuk item yang punya >=1 revisi internal. */
    private function internalRevisionFlagByItem(Collection $itemIds): Collection
    {
        return ContentRevision::whereIn('content_item_id', $itemIds)
            ->whereNotNull('requested_by_user_id')
            ->distinct()
            ->pluck('content_item_id')
            ->mapWithKeys(fn ($id) => [$id => true]);
    }

    /**
     * Urutan sumber data ketepatan waktu sesuai spesifikasi: (1) jadwal
     * upload vs publication aktual, toleransi 24 jam; (2) handoff pertama
     * in_progress->waiting_review vs deadline; (3) tidak bisa dinilai.
     */
    private function timelinessForItem(ContentItem $item): array
    {
        if ($item->scheduled_upload_at && $item->publications->isNotEmpty()) {
            $publishedAt = Carbon::parse($item->publications->min('published_at'));
            $onTime = $publishedAt->lte($item->scheduled_upload_at->copy()->addHours(self::TOLERANCE_HOURS));

            return ['verdict' => $onTime, 'basis' => 'scheduled_vs_published', 'reason' => null];
        }

        $handoff = $item->statusLogs
            ->where('from_status', 'in_progress')
            ->where('to_status', 'waiting_review')
            ->sortBy('changed_at')
            ->first();

        if ($handoff && $item->deadline_at) {
            $onTime = Carbon::parse($handoff->changed_at)->lte($item->deadline_at);

            return ['verdict' => $onTime, 'basis' => 'handoff_vs_deadline', 'reason' => null];
        }

        return [
            'verdict' => null,
            'basis' => null,
            'reason' => 'Data waktu tidak lengkap (tidak ada jadwal upload + publication, maupun handoff produksi ke review dengan deadline).',
        ];
    }

    /**
     * Bonus 0-10 dari perbandingan performa publication dengan baseline
     * minimal 3 publication sebelumnya (klien+platform+format sama). Null
     * kalau baseline/analytics belum cukup - TIDAK PERNAH jadi 0 dalam
     * kondisi ini (0 hanya kalau performa <= baseline sungguhan).
     */
    private function analyticsBonusForItem(ContentItem $item): array
    {
        $publication = $item->publications->sortBy('published_at')->first();

        if (! $publication) {
            return ['bonus' => null, 'available' => false, 'reason' => 'Belum ada publication.'];
        }

        $formatKey = $this->formatKey($item);

        if ($formatKey === null) {
            return ['bonus' => null, 'available' => false, 'reason' => 'Format konten belum diklasifikasikan.'];
        }

        $ownMetrics = $this->metricsAroundD7($item->id, Carbon::parse($publication->published_at));

        if (! $ownMetrics) {
            return ['bonus' => null, 'available' => false, 'reason' => 'Belum ada snapshot analytics sekitar D+7.'];
        }

        $baseline = $this->baselineMetrics($item, $publication, $formatKey);

        if (count($baseline) < self::MIN_BASELINE_SAMPLE) {
            return ['bonus' => null, 'available' => false, 'reason' => 'Belum ada minimal 3 publication pembanding sejenis.'];
        }

        $baselineValue = collect($baseline)->pluck('value')->filter(fn ($v) => $v !== null)->avg();
        $baselineEngagement = collect($baseline)->pluck('engagement')->filter(fn ($v) => $v !== null)->avg();

        $valueBonus = $baselineValue > 0
            ? $this->bonusFromPct((($ownMetrics['value'] - $baselineValue) / $baselineValue) * 100)
            : null;

        $engagementBonus = ($baselineEngagement !== null && $baselineEngagement > 0 && $ownMetrics['engagement'] !== null)
            ? $this->bonusFromPct((($ownMetrics['engagement'] - $baselineEngagement) / $baselineEngagement) * 100)
            : null;

        $bonuses = collect([$valueBonus, $engagementBonus])->filter(fn ($v) => $v !== null);

        if ($bonuses->isEmpty()) {
            return ['bonus' => null, 'available' => false, 'reason' => 'Baseline tidak punya data reach/views/engagement yang valid.'];
        }

        return ['bonus' => round($bonuses->avg(), 2), 'available' => true, 'reason' => null];
    }

    /**
     * Kunci "format sejenis" - content_format_id kalau sudah diklasifikasi,
     * fallback content_type_id (Video/Desain) untuk item lama. Video/Reels/
     * TikTok/desain feed/carousel TIDAK PERNAH dibandingkan silang karena
     * kuncinya beda persis kalau content_format_id/content_type_id beda.
     */
    private function formatKey(ContentItem $item): ?string
    {
        if ($item->content_format_id) {
            return 'format:'.$item->content_format_id;
        }

        if ($item->content_type_id) {
            return 'type:'.$item->content_type_id;
        }

        return null;
    }

    private function baselineMetrics(ContentItem $item, ContentPublication $publication, string $formatKey): array
    {
        $candidates = ContentItem::query()
            ->where('client_id', $item->client_id)
            ->where('id', '!=', $item->id)
            ->whereHas('publications', fn ($q) => $q->where('platform_id', $publication->platform_id))
            ->with(['publications' => fn ($q) => $q->where('platform_id', $publication->platform_id)->orderBy('published_at')])
            ->get()
            ->filter(fn (ContentItem $candidate) => $this->formatKey($candidate) === $formatKey);

        $baseline = [];

        foreach ($candidates as $candidate) {
            $candidatePublication = $candidate->publications->first();

            if (! $candidatePublication || Carbon::parse($candidatePublication->published_at)->gte($publication->published_at)) {
                continue;
            }

            $metrics = $this->metricsAroundD7($candidate->id, Carbon::parse($candidatePublication->published_at));

            if ($metrics) {
                $baseline[] = $metrics;
            }
        }

        return $baseline;
    }

    /**
     * Snapshot cumulative terdekat pada window D+7 s.d. D+10 (toleransi
     * jadwal sync harian) - lihat ContentMetricSnapshot docblock kenapa
     * tabel ini (bukan content_metrics.metric_date) yang dipakai untuk
     * observasi "pada N hari setelah tayang".
     */
    private function metricsAroundD7(int $contentItemId, Carbon $publishedAt): ?array
    {
        $snapshot = ContentMetricSnapshot::where('content_item_id', $contentItemId)
            ->whereDate('snapshot_date', '>=', $publishedAt->copy()->addDays(7))
            ->whereDate('snapshot_date', '<=', $publishedAt->copy()->addDays(10))
            ->orderBy('snapshot_date')
            ->first();

        if (! $snapshot) {
            return null;
        }

        $value = $snapshot->views ?? $snapshot->reach;

        if ($value === null) {
            return null;
        }

        $engagement = $snapshot->engagement_rate !== null ? (float) $snapshot->engagement_rate : null;

        if ($engagement === null && $value > 0) {
            $components = array_filter([$snapshot->likes, $snapshot->comments, $snapshot->shares, $snapshot->saves], fn ($v) => $v !== null);

            if (! empty($components)) {
                $engagement = (array_sum($components) / $value) * 100;
            }
        }

        return ['value' => (float) $value, 'engagement' => $engagement];
    }

    private function bonusFromPct(float $pct): float
    {
        if ($pct <= 0) {
            return 0.0;
        }

        if ($pct >= 50) {
            return self::MAX_BONUS;
        }

        if ($pct <= 25) {
            return round($pct / 25 * 5, 2);
        }

        return round(5 + (($pct - 25) / 25) * 5, 2);
    }

    private function persistUserResult(
        int $userId,
        Carbon $periodStart,
        Collection $itemIds,
        Collection $itemsById,
        Collection $revisionFlagByItem,
        Collection $timelinessByItem,
        Collection $analyticsByItem,
        Carbon $calculatedAt
    ): void {
        if ($itemIds->isEmpty()) {
            return;
        }

        $sampleSize = $itemIds->count();

        $timelinessEntries = $itemIds->map(fn ($id) => $timelinessByItem->get($id));
        $assessable = $timelinessEntries->filter(fn ($t) => $t['verdict'] !== null);
        $onTimeCount = $assessable->filter(fn ($t) => $t['verdict'] === true)->count();
        $timelyAssessableCount = $assessable->count();
        $timelinessScore = $timelyAssessableCount > 0 ? round($onTimeCount / $timelyAssessableCount * 100, 2) : null;

        $itemsWithoutRevision = $itemIds->filter(fn ($id) => ! $revisionFlagByItem->has($id))->count();
        $qualityScore = round($itemsWithoutRevision / $sampleSize * 100, 2);

        $baseScore = $timelinessScore !== null
            ? ($timelinessScore * 0.6) + ($qualityScore * 0.4)
            : $qualityScore;

        $analyticsEntries = $itemIds->map(fn ($id) => $analyticsByItem->get($id));
        $availableBonuses = $analyticsEntries->filter(fn ($a) => $a['available'])->pluck('bonus');
        $analyticsAvailable = $availableBonuses->isNotEmpty();
        $analyticsBonus = $analyticsAvailable ? round($availableBonuses->avg(), 2) : null;

        $finalScore = round(min(100, $baseScore + ($analyticsBonus ?? 0)), 2);

        $isSufficient = $sampleSize >= 3 && $timelyAssessableCount >= 3;
        $status = $isSufficient
            ? UserMonthlyKpiResult::statusFromScore($finalScore)
            : UserMonthlyKpiResult::STATUS_SEMENTARA;

        $breakdown = $itemIds->map(function ($id) use ($itemsById, $revisionFlagByItem, $timelinessByItem, $analyticsByItem) {
            $item = $itemsById->get($id);
            $timeliness = $timelinessByItem->get($id);
            $analytics = $analyticsByItem->get($id);

            return [
                'content_item_id' => $id,
                'title' => $item?->title,
                'client_name' => $item?->client?->name,
                'published_at' => $item?->first_published_at?->toDateTimeString(),
                'on_time' => $timeliness['verdict'],
                'timeliness_reason' => $timeliness['reason'],
                'has_internal_revision' => $revisionFlagByItem->has($id),
                'analytics_bonus' => $analytics['bonus'],
                'analytics_reason' => $analytics['reason'],
            ];
        })->values()->all();

        UserMonthlyKpiResult::updateOrCreate(
            ['user_id' => $userId, 'period_start' => $periodStart->toDateString()],
            [
                'timeliness_score' => $timelinessScore,
                'quality_score' => $qualityScore,
                'analytics_bonus' => $analyticsBonus,
                'analytics_available' => $analyticsAvailable,
                'final_score' => $finalScore,
                'sample_size' => $sampleSize,
                'status' => $status,
                'breakdown' => $breakdown,
                'calculated_at' => $calculatedAt,
            ]
        );
    }
}
