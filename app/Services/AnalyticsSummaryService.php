<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Agregasi "Overview"/Ringkasan analytics (stats, trend, platform breakdown,
 * top content) untuk 1 client + periode - dipakai bareng oleh
 * AnalyticsController (internal, tab Overview) dan Client\AnalyticsController
 * (client-side), biar query-nya nggak dobel dan otomatis sinkron kalau
 * logic-nya berubah.
 *
 * FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - "PUBLISH-DATE COHORT IS
 * PRIMARY". Angka HEADLINE (stats/topContent/content_count) SEKARANG datang
 * dari ContentCohortService - roster = content yang genuinely DIPUBLIKASIKAN
 * dalam periode terpilih (published_at, bukan lagi coverage/isUsable()),
 * angkanya = performa TERKINI genuine (ContentMetric.views/engagement_rate
 * dkk apa adanya, BUKAN delta). Root cause historis yang dikoreksi pass ini
 * (Phase 3's sendiri fix tidak cukup jauh): PeriodPerformanceService::
 * computeContentDelta() SENGAJA membatasi observasi "current"-nya ke
 * snapshot_date <= periodEnd (benar buat menghitung delta genuine) - tapi
 * consumer yang memakai isUsable() (coverageStatus !== 'unavailable') SEBAGAI
 * ROSTER GATE ikut mewarisi batas itu jadi filter roster TIDAK SENGAJA:
 * konten yang published DALAM periode tapi observasi pertamanya baru terjadi
 * SETELAH periode itu (mis. aplikasi/sync belum ada saat periode itu
 * berlangsung) hilang total dari halaman, padahal publish date & performa
 * TERKININYA genuine & diketahui. computeDailyGainSeries()/trend chart TETAP
 * PeriodPerformanceService apa adanya (Langkah 15 - itu genuinely "metric
 * movement selama periode", bukan cohort roster).
 */
class AnalyticsSummaryService
{
    public function __construct(
        private readonly PeriodPerformanceService $periodPerformanceService,
        private readonly ContentCohortService $contentCohortService,
        private readonly AnalyticsPeriodResolver $periodResolver,
        private readonly ContentFormatResolver $contentFormatResolver,
    ) {
    }

    /**
     * PASS 2 - $period SEKARANG AnalyticsPeriod (month/custom/legacy_days),
     * BUKAN lagi int hari mentah - SATU-SATUNYA tempat previous-period
     * dihitung (AnalyticsPeriodResolver::previousPeriod(), formula month
     * vs custom/legacy_days BEDA, lihat docblock method itu), method ini
     * TIDAK LAGI menghitung date math sendiri.
     */
    public function buildOverviewData(int|string $clientId, AnalyticsPeriod $period, ?int $platformId = null): array
    {
        $start = $period->dateFrom;
        $end = $period->effectiveDateTo;
        $previousPeriod = $this->periodResolver->previousPeriod($period);
        $prevStart = $previousPeriod->dateFrom;
        $prevEnd = $previousPeriod->effectiveDateTo;

        // PRIMARY (concept A+B) - roster + current performance, cohort-based.
        $current = $this->contentCohortService->computeClientCohort($clientId, $start, $end, $platformId);
        $previous = $this->contentCohortService->computeClientCohort($clientId, $prevStart, $prevEnd, $platformId);

        $totalViews = $current['totals']['views'];
        $avgEngagement = $current['totals']['engagement_rate'] ?? 0;
        $contentPublished = $current['totals']['content_count'];
        $platformsTracked = $current['totals']['platforms_tracked'];

        $prevTotalViews = $previous['totals']['views'];
        $prevAvgEngagement = $previous['totals']['engagement_rate'] ?? 0;
        $prevContentPublished = $previous['totals']['content_count'];
        $prevPlatformsTracked = $previous['totals']['platforms_tracked'];

        // UX ACCEPTANCE (Langkah 26) - context line ALWAYS shown (bukan
        // conditional warning) - cohort roster SELALU genuine/lengkap by
        // construction (published_at bukan observasi), jadi tidak ada lagi
        // "coverage belum penuh" buat angka HEADLINE ini.
        $cohortContextMessage = 'Menampilkan performa terkini konten yang dipublikasikan pada '.$period->label().'.';

        // SECONDARY ONLY (concept C) - apakah cohort ini PUNYA data
        // pertumbuhan/gain periode yang bisa dipercaya (period_result
        // attached tiap row oleh ContentCohortService) - TIDAK PERNAH
        // mempengaruhi $current/$totalViews/dst di atas, murni buat badge
        // opsional "Pertumbuhan periode: Riwayat belum cukup" kalau tim
        // butuh menampilkannya di level ringkasan juga.
        $cohortRows = collect($current['rows']);
        // "full" HARUS berarti SEMUA baris punya period-gain PENUH (bukan
        // cuma "usable" - partial JUGA usable tapi bukan lengkap) - lihat
        // PeriodPerformanceService::computeAggregate()'s own fullRows/
        // allRows pattern, dicerminkan di sini.
        $rowsWithFullPeriodMovement = $cohortRows->filter(fn ($row) => $row['period_result']?->coverageStatus === \App\Services\ContentPeriodResult::FULL);
        $rowsWithAnyPeriodMovement = $cohortRows->filter(fn ($row) => $row['period_result'] && $row['period_result']->isUsable());
        $coverageStatus = match (true) {
            $cohortRows->isEmpty() => \App\Services\ContentPeriodResult::UNAVAILABLE,
            $rowsWithFullPeriodMovement->count() === $cohortRows->count() => \App\Services\ContentPeriodResult::FULL,
            $rowsWithAnyPeriodMovement->isEmpty() => \App\Services\ContentPeriodResult::UNAVAILABLE,
            default => \App\Services\ContentPeriodResult::PARTIAL,
        };
        $coverageMessage = $coverageStatus !== \App\Services\ContentPeriodResult::FULL && $cohortRows->isNotEmpty()
            ? 'Data pertumbuhan performa selama periode ini belum tersedia untuk sebagian konten (riwayat observasi belum cukup) - angka performa terkini di atas tetap genuine dan lengkap.'
            : null;

        // Cohort roster SELALU genuine (published_at, bukan observasi) -
        // "previous" period selalu bisa dibandingkan, 0 konten published
        // adalah nol yang genuine, BUKAN "tidak diketahui" seperti dulu.
        $previousAvailable = true;

        $stats = [
            [
                'label' => 'Total Views',
                'value' => number_format($totalViews),
                'icon' => 'visibility',
                ...$this->percentChange($prevTotalViews, $totalViews, $previousAvailable),
            ],
            [
                'label' => 'Avg. Engagement Rate',
                'value' => number_format($avgEngagement, 2) . '%',
                'icon' => 'favorite',
                ...$this->percentChange($prevAvgEngagement, $avgEngagement, $previousAvailable),
            ],
            [
                'label' => 'Content Published',
                'value' => number_format($contentPublished),
                'icon' => 'grid_view',
                ...$this->percentChange($prevContentPublished, $contentPublished, $previousAvailable),
            ],
            [
                'label' => 'Platforms Tracked',
                'value' => number_format($platformsTracked),
                'icon' => 'hub',
                ...$this->percentChange($prevPlatformsTracked, $platformsTracked, $previousAvailable),
            ],
        ];

        $dailySeries = $this->periodPerformanceService->computeDailyGainSeries($clientId, $start, $end, $platformId);
        $trend = $this->buildTrend($dailySeries, $period->requestedLengthInDays());

        $platformBreakdown = collect($current['platform_breakdown']);

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION - roster SEKARANG
        // seluruh cohort (published_at, tidak ada lagi isUsable() filter di
        // sini), ranking by CURRENT performance (concept B) - "performa
        // terkini tertinggi di antara konten yang dipublikasikan periode
        // ini" (Langkah 12), BUKAN lagi diranking oleh gain periode yang
        // bisa jadi kosong/kecil semata karena riwayat observasi baru mulai.
        $topContent = $cohortRows
            ->map(fn ($row) => $this->presentTopContentRow($row))
            ->filter()
            ->sortByDesc(fn ($row) => $row['current_views'] ?? $row['views'] ?? 0)
            ->take(5)
            ->values();

        return compact('stats', 'trend', 'platformBreakdown', 'topContent', 'coverageStatus', 'coverageMessage', 'cohortContextMessage');
    }

    /**
     * Bangun 1 baris Top Content dari 1 baris cohort (ContentCohortService).
     * 'current_views'/'engagement_rate' PRIMARY (concept B, performa
     * genuine TERKINI, content_metrics apa adanya, TIDAK PERNAH null buat
     * content API - selalu ada begitu ContentMetric row-nya ada). 'views'
     * SEKARANG murni gain periode SEKUNDER (concept C, dari period_result
     * yang di-attach ContentCohortService) - NULLABLE kalau riwayat
     * observasi belum cukup, TIDAK PERNAH di-default 0 di sini (blade yang
     * menampilkan "Riwayat belum cukup" kalau null, bukan angka dikarang).
     */
    private function presentTopContentRow(array $row): ?array
    {
        $metric = $row['content_metric'];
        $periodResult = $row['period_result']; // null utk baris CSV/manual
        $isCsv = $row['source'] === 'csv';

        if ($metric->content_item_id) {
            $item = ContentItem::with(['client', 'contentType', 'contentFormat', 'platform'])->find($metric->content_item_id);
            if (! $item) {
                return null;
            }

            $linkedIgSnapshot = ! $isCsv && $metric->instagram_media_snapshot_id
                ? InstagramMediaSnapshot::find($metric->instagram_media_snapshot_id) : null;
            $linkedTtSnapshot = ! $isCsv && ! $linkedIgSnapshot && $metric->tiktok_video_snapshot_id
                ? \App\Models\TikTokVideoSnapshot::find($metric->tiktok_video_snapshot_id) : null;
            $linkedSnapshot = $linkedIgSnapshot ?? $linkedTtSnapshot;

            return [
                'id' => $item->id,
                'title' => $item->title,
                'client' => $item->client->name ?? '-',
                // SYSTEM CONSISTENCY PASS (Part B/C/H) - production_type
                // (Desain/Video) & content_format (Single Post/Carousel/
                // Video, master kalau sudah diisi, fallback provider kalau
                // belum) SEKARANG dua field terpisah - jangan campur lagi
                // jadi satu 'type'.
                'production_type' => $item->contentType->name ?? '-',
                'content_format' => $this->contentFormatResolver->labelForContentItem($item, $linkedIgSnapshot, $linkedTtSnapshot) ?? '-',
                'platform' => $item->platform->name ?? '-',
                'current_views' => $isCsv ? null : (int) $metric->views,
                'views' => $isCsv ? (int) $metric->views : $periodResult?->views(),
                'engagement_rate' => $isCsv
                    ? ($metric->engagement_rate !== null ? (float) $metric->engagement_rate : null)
                    : ($metric->engagement_rate !== null ? (float) $metric->engagement_rate : null),
                'coverage_status' => $isCsv ? \App\Services\ContentPeriodResult::PARTIAL : ($periodResult?->coverageStatus ?? \App\Services\ContentPeriodResult::UNAVAILABLE),
                'linked' => true,
                'permalink' => $linkedSnapshot?->permalink,
            ];
        }

        // Post API real TAPI belum ke-link - metadata dari
        // InstagramMediaSnapshot/TikTokVideoSnapshot (caption/permalink),
        // bukan ContentItem. TIDAK di-skip (tetap dihitung dalam Analytics),
        // cuma nggak ada link "Detail" internal. production_type TETAP
        // '-' (belum diketahui, TIDAK ditebak dari format) - content_format
        // murni normalisasi provider lewat ContentFormatResolver (Part C,
        // "unmatched -> provider normalization fallback").
        if ($metric->instagram_media_snapshot_id) {
            $snapshot = InstagramMediaSnapshot::find($metric->instagram_media_snapshot_id);
            $title = $snapshot?->caption ? Str::limit($snapshot->caption, 60) : 'Instagram Post (belum terhubung)';
            $format = $this->contentFormatResolver->labelForSnapshot($snapshot, null) ?? '-';
        } else {
            $snapshot = \App\Models\TikTokVideoSnapshot::find($metric->tiktok_video_snapshot_id);
            $title = $snapshot?->video_description ? Str::limit($snapshot->video_description, 60) : 'TikTok Video (belum terhubung)';
            $format = $this->contentFormatResolver->labelForSnapshot(null, $snapshot) ?? '-';
        }

        return [
            'id' => null,
            'title' => $title,
            'client' => '-',
            'production_type' => '-',
            'content_format' => $format,
            'platform' => $metric->platform->name ?? Platform::find($metric->platform_id)?->name ?? '-',
            'current_views' => (int) $metric->views,
            'views' => $periodResult?->views(),
            'engagement_rate' => $metric->engagement_rate !== null ? (float) $metric->engagement_rate : null,
            'coverage_status' => $periodResult?->coverageStatus ?? \App\Services\ContentPeriodResult::UNAVAILABLE,
            'linked' => false,
            'permalink' => $snapshot->permalink ?? $snapshot->share_url ?? null,
            'api_integration_id' => $snapshot?->api_integration_id,
            'external_post_id' => $snapshot?->external_post_id,
        ];
    }

    /**
     * @param  array<int, array{date: string, label: string, value: ?int, has_gap: bool}>  $dailySeries
     */
    public function buildTrend(array $dailySeries, int $period): array
    {
        return $period <= 30 ? $dailySeries : $this->periodPerformanceService->aggregateWeekly($dailySeries);
    }

    /**
     * PASS 2 (Langkah 6) - $previousAvailable membedakan "previous
     * genuinely 0" (coverage previous full/partial, angkanya memang nol)
     * dari "previous TIDAK DIKETAHUI" (coverage previous unavailable -
     * belum ada observasi/sync sama sekali buat periode itu) - dua kondisi
     * yang SEBELUMNYA collapse jadi angka 0 yang sama, menghasilkan pesan
     * menyesatkan "Naik dari 0" padahal kita genuinely tidak tahu apa-apa.
     */
    private function percentChange(int|float $previous, int|float $current, bool $previousAvailable = true): array
    {
        if (! $previousAvailable) {
            return ['change' => 'Tidak ada data pembanding periode sebelumnya', 'trend' => 'flat'];
        }

        if ($previous == 0) {
            return $current > 0
                ? ['change' => 'Naik dari 0 di periode sebelumnya', 'trend' => 'up']
                : ['change' => 'Belum ada data', 'trend' => 'flat'];
        }

        $percent = round((($current - $previous) / $previous) * 100, 1);

        if ($percent > 0) {
            return ['change' => "+{$percent}% dari periode sebelumnya", 'trend' => 'up'];
        }

        if ($percent < 0) {
            return ['change' => "{$percent}% dari periode sebelumnya", 'trend' => 'down'];
        }

        return ['change' => 'Sama seperti periode sebelumnya', 'trend' => 'flat'];
    }
}
