<?php

namespace App\Services;

use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use App\Models\TikTokVideoSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SATU-SATUNYA source-of-truth buat "berapa performa yang DIPEROLEH selama
 * periode X" (Phase 3, menggantikan bug lama: content_metrics.metric_date
 * dikunci ke tanggal PUBLISH, jadi whereBetween('metric_date', period) dulu
 * sebenarnya memfilter "diterbitkan dalam periode", bukan "performa
 * diperoleh dalam periode"). content_metrics TIDAK diubah/direpurpose sama
 * sekali (tetap current/latest + kompatibilitas CSV, lihat docblock
 * ContentMetric) - service ini MEMBACA content_metric_snapshots (histori
 * observasi cumulative harian, Phase 2) buat konten API, dan tetap memakai
 * semantik ContentMetric.metric_date APA ADANYA buat baris CSV/manual
 * (metric_date CSV adalah nilai per-periode yang user ketik sendiri, BUKAN
 * placeholder tanggal publish - lihat SettingsController::importPerformance()).
 *
 * ==================================================================
 * ATURAN BOUNDARY COVERAGE (CRITICAL CORRECTION, Phase 3 kickoff)
 * ==================================================================
 * "snapshot terakhir sebelum period_start" TIDAK OTOMATIS berarti coverage
 * penuh. Dengan model snapshot harian, baseline yang IDEAL persis 1 hari
 * sebelum period_start (period_start - 1 day). Kalau baseline yang ADA lebih
 * tua dari itu (ada gap hari yang tidak pernah ke-sync), delta yang dihitung
 * SECARA JUJUR mencakup lebih dari periode yang diminta - coverage jadi
 * 'partial', BUKAN 'full'. Sama halnya di ujung periode: kalau period_end
 * = hari ini tapi observasi terakhir bukan hari ini, coverage juga bukan
 * full. TIDAK PERNAH fabricate observasi yang hilang di boundary manapun.
 *
 * ==================================================================
 * CASE A/B/C/D per content (lihat computeContentDelta())
 * ==================================================================
 * A - full boundary tersedia (baseline tepat di boundary ideal + current
 *     mencapai period_end) -> delta exact, coverage full.
 * B - published_at >= period_start -> baseline=0 LEGITIMATE (konten belum
 *     ada sebelum publish, bukan "baseline tidak diketahui").
 * C - published sebelum periode TAPI tidak ada snapshot valid sebelum
 *     period_start (riwayat snapshot baru mulai di tengah periode) -> BUKAN
 *     baseline=0. Kalau ada >=2 observasi genuine sejak riwayat mulai,
 *     hitung OBSERVED PARTIAL GAIN (delta antara observasi pertama &
 *     terakhir yang benar-benar ada), coverage_status=partial,
 *     coverage_from=tanggal observasi pertama itu. TIDAK PERNAH dilabeli
 *     sebagai gain "periode penuh".
 * D - tidak ada observasi current sama sekali -> unavailable.
 *
 * ==================================================================
 * NEGATIVE DELTA / METRIC RESET (section 4, DIPERBAIKI Phase 3.1)
 * ==================================================================
 * Cumulative metric yang TURUN antar observasi (API correction/counter
 * reset/data inconsistency) TIDAK PERNAH di-clamp diam-diam ke 0 lalu
 * diklaim "zero gain". TAPI deteksinya di-scope SEMPIT, dua sumbu:
 * - PERIOD-RELEVANT SAJA: cuma observasi di dalam interval yang benar2
 *   dipakai buat delta ($coverageFrom s/d $current) yang dicek - koreksi
 *   histori SEBELUM baseline (mis. koreksi bulan Juni yang tidak relevan
 *   buat periode September) TIDAK membuat periode lain jadi unavailable
 *   (dulu: scan SELURUH histori sampai period_end, over-invalidating).
 * - PER-METRIC, BUKAN PER-CONTENT: 1 metric yang correction (mis. likes)
 *   TIDAK menghapus metric LAIN yang masih valid (mis. views) - cuma
 *   metric yang benar2 kena reset yang jadi NULL di $delta, metric lain
 *   tetap dihitung normal (dulu: 1 metric turun -> SELURUH content
 *   unavailable, termasuk views yang sebenarnya valid).
 * Content yang punya >=1 metric reset TETAP usable (coverage_status jadi
 * partial, reason 'metric_reset_or_correction'), bukan otomatis
 * unavailable - lihat fieldHasResetInChain()/diffMetricsWithResetDetection().
 *
 * ==================================================================
 * ENGAGEMENT RATE (section 6, DIPERBAIKI Phase 3.1)
 * ==================================================================
 * TIDAK PERNAH subtract persentase engagement_rate antar snapshot, TIDAK
 * PERNAH average cumulative engagement_rate. Dihitung ULANG dari RAW DELTA
 * components. NULL != 0 berlaku di NUMERATOR juga (bukan cuma denominator) -
 * 3 kondisi per komponen interaksi:
 * A. Metric TIDAK didukung platform (TikTok `saves`) -> tidak masuk formula
 *    SAMA SEKALI.
 * B. Metric didukung, delta genuinely 0 -> dihitung sebagai 0 (observed
 *    zero yang sah).
 * C. Metric didukung TAPI delta NULL (belum ada observasi/kena reset) ->
 *    SELURUH engagement_rate periode ini jadi NULL, BUKAN treat sebagai 0.
 * Denominator: reach delta fallback views delta buat Instagram, views delta
 * SAJA buat TikTok (integration ini tidak punya reach) - NULL denominator ->
 * engagement period NULL, BUKAN 0 palsu. Lihat computeDeltaEngagementRate().
 *
 * ==================================================================
 * CSV/MANUAL COVERAGE (section 3, Phase 3.1)
 * ==================================================================
 * Baris CSV/manual TIDAK PERNAH otomatis coverage_status='full' lagi.
 * Angkanya genuine (metric_date = tanggal yang user ketik sendiri, TIDAK
 * diubah sama sekali secara numerik), TAPI kehadiran baris dalam periode
 * TIDAK MEMBUKTIKAN periode itu comprehensively recorded (1 baris CSV di
 * tanggal 29 Agustus pada periode 30 hari BUKAN bukti "30 hari penuh
 * tercatat" - sistem tidak bisa verifikasi user input SETIAP hari).
 * Ditandai coverage_status='partial', reason='manual_recorded' - lihat
 * computeAggregate().
 */
class PeriodPerformanceService
{
    /**
     * Hitung delta 1 content unit (identitas instagram_media_snapshot_id
     * ATAU tiktok_video_snapshot_id) untuk 1 periode. Ini building block
     * ATOMIC yang dipakai computeClientPeriod() untuk semua consumer -
     * JANGAN duplikasi logic boundary/coverage di luar method ini.
     */
    public function computeContentDelta(
        string $platformType, // 'instagram' | 'tiktok'
        string $identityColumn, // 'instagram_media_snapshot_id' | 'tiktok_video_snapshot_id'
        int $identityId,
        ?Carbon $publishedAt,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): ContentPeriodResult {
        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->startOfDay();

        $snapshots = ContentMetricSnapshot::where($identityColumn, $identityId)
            ->whereDate('snapshot_date', '<=', $periodEnd->toDateString())
            ->orderBy('snapshot_date')
            ->get();

        if ($snapshots->isEmpty()) {
            return ContentPeriodResult::unavailable('missing_current');
        }

        $current = $snapshots->last();
        $currentDate = Carbon::parse($current->snapshot_date)->startOfDay();

        $publishedInsidePeriod = $publishedAt && $publishedAt->copy()->startOfDay()->gte($periodStart);

        if ($publishedInsidePeriod) {
            // CASE B - baseline 0 legitimate, konten belum ada sebelum publish.
            $baseline = null;
            $coverageFrom = $publishedAt->copy()->startOfDay();
            $baselineIssue = null; // baseline=0 selalu "exact", tidak pernah "too old"
        } else {
            $baseline = $snapshots->last(fn (ContentMetricSnapshot $s) => Carbon::parse($s->snapshot_date)->lt($periodStart));

            if (! $baseline) {
                // CASE C atau D - tidak ada baseline valid sebelum period_start.
                return $this->computeObservedPartialGain($platformType, $snapshots, $periodStart);
            }

            $idealBaselineDate = $periodStart->copy()->subDay();
            $coverageFrom = Carbon::parse($baseline->snapshot_date)->startOfDay();
            $baselineIssue = $coverageFrom->equalTo($idealBaselineDate) ? null : 'baseline_too_old';
        }

        // PHASE 3.1 FIX - negative-delta/reset detection SEKARANG di-scope
        // ke INTERVAL YANG RELEVAN buat periode ini ($coverageFrom s/d
        // $currentDate), BUKAN lagi seluruh histori snapshot sampai
        // period_end. Observasi SEBELUM baseline (mis. koreksi metrik bulan
        // Juni yang tidak relevan buat periode September) TIDAK BOLEH
        // membuat periode ini unavailable - lihat docblock kelas di atas.
        $relevantSnapshots = $snapshots->filter(fn (ContentMetricSnapshot $s) => Carbon::parse($s->snapshot_date)->gte($coverageFrom));

        ['delta' => $delta, 'reset_metrics' => $resetMetrics] = $baseline
            ? $this->diffMetricsWithResetDetection($relevantSnapshots, $baseline, $current)
            : $this->diffFromZeroWithResetDetection($relevantSnapshots, $current);

        $engagementRate = $this->computeDeltaEngagementRate($platformType, $delta);

        $currentIssue = $currentDate->equalTo($periodEnd) ? null : 'current_before_period_end';

        // PHASE 3.1 FIX - metric reset TIDAK PERNAH membuat SELURUH content
        // unavailable lagi (dulu 1 metric yang correction bikin semua metric
        // lain, termasuk views yang masih valid, ikut hilang). Kalau metric
        // yang kena reset itu sendiri yang jadi delta null, itu SUDAH cukup
        // (lihat diffMetricsWithResetDetection - field yang reset otomatis
        // null di $delta) - $resetMetrics di sini CUMA dipakai buat
        // menurunkan coverage_status jadi partial (bukti eksplisit "ada
        // koreksi data", walau metric lain tetap valid & usable).
        $reason = $baselineIssue ?? $currentIssue ?? (! empty($resetMetrics) ? 'metric_reset_or_correction' : null);

        if ($reason === null) {
            return ContentPeriodResult::full($coverageFrom, $currentDate, $delta, $engagementRate, $baseline, $current);
        }

        return ContentPeriodResult::partial($coverageFrom, $currentDate, $reason, $delta, $engagementRate, $baseline, $current);
    }

    /**
     * CASE C - riwayat snapshot baru mulai di tengah periode (atau setelah
     * periode dimulai sama sekali). Kalau ada >=2 observasi genuine, delta
     * dihitung dari observasi PERTAMA yang benar2 ada s/d yang terakhir -
     * itu gain yang BENAR2 teramati, bukan gain periode penuh. Kalau cuma
     * ada 1 observasi (atau 0, ditangani caller sebelum sampai sini) ->
     * unavailable (missing_baseline), tidak ada delta yang bisa dihitung.
     */
    private function computeObservedPartialGain(string $platformType, Collection $snapshots, Carbon $periodStart): ContentPeriodResult
    {
        // Semua snapshot yang ada (di dalam ATAU melebihi periode - kasus
        // "history baru mulai di tengah periode" berarti snapshot pertama
        // ada DI DALAM periode) dipakai sebagai kandidat first-observation.
        // $snapshots di sini SUDAH otomatis = interval yang relevan (semua
        // snapshot_date >= periodStart, karena baseline search di caller
        // gagal menemukan apapun < periodStart) - tidak perlu filter lagi.
        if ($snapshots->count() < 2) {
            return ContentPeriodResult::unavailable('missing_baseline');
        }

        $first = $snapshots->first();
        $current = $snapshots->last();

        ['delta' => $delta] = $this->diffMetricsWithResetDetection($snapshots, $first, $current);
        $engagementRate = $this->computeDeltaEngagementRate($platformType, $delta);

        return ContentPeriodResult::partial(
            Carbon::parse($first->snapshot_date)->startOfDay(),
            Carbon::parse($current->snapshot_date)->startOfDay(),
            'history_started_mid_period',
            $delta,
            $engagementRate,
            $first,
            $current
        );
    }

    /**
     * Deteksi penurunan SATU field kumulatif tertentu, di-scope ke koleksi
     * snapshot yang SUDAH difilter caller ke interval yang relevan (Phase
     * 3.1 fix - dulu method ini/pendahulunya scan SELURUH histori lalu
     * invalidate SELURUH content, sekarang per-field DAN per-interval).
     * Titik yang null buat field ini dilewati (bukan dianggap "reset ke
     * null"), biar 1 observasi yang kebetulan tidak punya field ini tidak
     * salah kedeteksi sebagai turun.
     */
    private function fieldHasResetInChain(Collection $relevantSnapshots, string $field): bool
    {
        $previous = null;

        foreach ($relevantSnapshots as $snapshot) {
            $value = $snapshot->{$field};
            if ($value === null) {
                continue;
            }
            if ($previous !== null && $value < $previous) {
                return true;
            }
            $previous = $value;
        }

        return false;
    }

    /**
     * @return array{delta: array<string, int|null>, reset_metrics: array<int, string>}
     */
    private function diffMetricsWithResetDetection(Collection $relevantSnapshots, ContentMetricSnapshot $baseline, ContentMetricSnapshot $current): array
    {
        $fields = ['views', 'likes', 'comments', 'shares', 'saves', 'reach', 'impressions'];
        $delta = [];
        $resetMetrics = [];

        foreach ($fields as $field) {
            if ($baseline->{$field} === null || $current->{$field} === null) {
                $delta[$field] = null; // salah satu titik NULL -> delta TIDAK DIKETAHUI, bukan 0
                continue;
            }

            if ($this->fieldHasResetInChain($relevantSnapshots, $field)) {
                // PHASE 3.1 FIX - PER-METRIC, bukan lagi seluruh content.
                // Metric LAIN yang tidak kena reset tetap dihitung normal
                // di iterasi field berikutnya (mis. views tetap valid walau
                // likes correction).
                $delta[$field] = null;
                $resetMetrics[] = $field;
                continue;
            }

            $delta[$field] = (int) $current->{$field} - (int) $baseline->{$field};
        }

        return ['delta' => $delta, 'reset_metrics' => $resetMetrics];
    }

    /**
     * @return array{delta: array<string, int|null>, reset_metrics: array<int, string>}
     */
    private function diffFromZeroWithResetDetection(Collection $relevantSnapshots, ContentMetricSnapshot $current): array
    {
        $fields = ['views', 'likes', 'comments', 'shares', 'saves', 'reach', 'impressions'];
        $delta = [];
        $resetMetrics = [];

        foreach ($fields as $field) {
            if ($current->{$field} === null) {
                $delta[$field] = null;
                continue;
            }

            if ($this->fieldHasResetInChain($relevantSnapshots, $field)) {
                $delta[$field] = null;
                $resetMetrics[] = $field;
                continue;
            }

            $delta[$field] = (int) $current->{$field};
        }

        return ['delta' => $delta, 'reset_metrics' => $resetMetrics];
    }

    /**
     * PHASE 3.1 FIX - engagement rate SEKARANG membedakan 3 kondisi numerator
     * per komponen interaksi (Langkah 2):
     * A. Metric TIDAK didukung platform (mis. TikTok `saves`) -> TIDAK
     *    dimasukkan ke numerator SAMA SEKALI (bukan skip-as-zero, memang
     *    bukan bagian formula platform ini).
     * B. Metric didukung, delta-nya genuinely 0 -> ikut dihitung sebagai 0
     *    (itu observed zero yang sah).
     * C. Metric didukung TAPI delta-nya NULL (tidak diketahui/kena reset) ->
     *    TIDAK diubah jadi 0 - seluruh engagement_rate periode ini jadi
     *    NULL, karena komponen wajib formula tidak bisa dipercaya.
     *
     * @param  array<string, int|null>  $delta
     */
    private function computeDeltaEngagementRate(string $platformType, array $delta): ?float
    {
        if ($platformType === 'tiktok') {
            // TikTok - saves TIDAK PERNAH jadi bagian formula (integration
            // ini tidak punya field saves TikTok sama sekali, beda dari
            // "didukung tapi kebetulan null").
            $denominator = $delta['views'];
            $requiredComponents = ['likes', 'comments', 'shares'];
        } else {
            // Instagram - reach delta diprioritaskan, fallback views delta,
            // sama urutan dengan InstagramAnalyticsSyncService::computeEngagementRate().
            $denominator = $delta['reach'] ?? $delta['views'];
            $requiredComponents = ['likes', 'comments', 'shares', 'saves'];
        }

        if ($denominator === null) {
            return null;
        }
        if ($denominator <= 0) {
            return 0.0;
        }

        $interactions = 0;
        foreach ($requiredComponents as $component) {
            if ($delta[$component] === null) {
                // Komponen wajib formula ini TIDAK diketahui (belum ada
                // observasi/kena reset) - engagement TIDAK BISA dipercaya,
                // NULL bukan fabricated 0 (Langkah 2, "NULL != 0 berlaku
                // pada numerator juga").
                return null;
            }
            $interactions += $delta[$component];
        }

        return round(min($interactions / $denominator * 100, 999.99), 2);
    }

    /**
     * Aggregate period performance buat 1 client (+ optional platform
     * filter), pakai roster STANDAR: client_id LANGSUNG di ContentMetric
     * (Langkah 9A/9B/9C/9F/9G - Overview/Table/Export/Report/AI Strategy) -
     * mencakup post API yang BELUM di-link ke ContentItem juga, sama seperti
     * konvensi existing AnalyticsSummaryService/AnalyticsController.
     *
     * Consumer dengan roster BEDA (mis. Executive Dashboard yang cuma
     * menghitung content yang SUDAH ke-link via whereHas('contentItem', ...)
     * - preserved dari behavior lama, bukan scope Phase 3) pakai
     * computeAggregate() langsung dengan roster query sendiri - MATH-nya
     * (delta/coverage/engagement) tetap SAMA PERSIS, cuma roster-nya beda.
     *
     * @return array{coverage: array, totals: array, platform_breakdown: array, rows: \Illuminate\Support\Collection}
     */
    public function computeClientPeriod(int|string $clientId, Carbon $periodStart, Carbon $periodEnd, ?int $platformId = null): array
    {
        $apiMetrics = ContentMetric::where('client_id', $clientId)
            ->when($platformId, fn ($q) => $q->where('platform_id', $platformId))
            ->where(fn ($q) => $q->whereNotNull('instagram_media_snapshot_id')->orWhereNotNull('tiktok_video_snapshot_id'))
            ->with(['contentItem.contentPillar', 'contentItem.contentType', 'contentItem.workflow', 'platform', 'instagramMediaSnapshot', 'tiktokVideoSnapshot'])
            ->get();

        $csvMetrics = ContentMetric::where('client_id', $clientId)
            ->when($platformId, fn ($q) => $q->where('platform_id', $platformId))
            ->whereNull('instagram_media_snapshot_id')
            ->whereNull('tiktok_video_snapshot_id')
            ->whereBetween('metric_date', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->with(['contentItem.contentPillar', 'contentItem.contentType', 'contentItem.workflow', 'platform'])
            ->get();

        return $this->computeAggregate($apiMetrics, $csvMetrics, $periodStart, $periodEnd);
    }

    /**
     * Core aggregate math (Langkah 9) - dipakai computeClientPeriod() di
     * atas MAUPUN consumer dengan roster kustom (Executive Dashboard).
     * $apiMetrics = ContentMetric rows dengan instagram_media_snapshot_id
     * ATAU tiktok_video_snapshot_id terisi. $csvMetrics = ContentMetric rows
     * CSV/manual (dua-duanya snapshot FK null) yang metric_date-nya SUDAH
     * di dalam window yang diminta caller (caller yang filter, method ini
     * cuma menjumlahkan APA ADANYA - metric_date CSV = nilai per-periode
     * ASLI, bukan cumulative snapshot, TIDAK dipaksa lewat delta engine).
     *
     * @return array{coverage: array, totals: array, platform_breakdown: array, rows: \Illuminate\Support\Collection}
     */
    public function computeAggregate(Collection $apiMetrics, Collection $csvMetrics, Carbon $periodStart, Carbon $periodEnd): array
    {
        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->startOfDay();

        // toBase() - $apiMetrics/$csvMetrics adalah Eloquent Collection,
        // ->map() TETAP mengembalikan Eloquent Collection walau isinya
        // sudah diubah jadi array biasa - ->merge() versi Eloquent
        // mengharuskan item berupa Model (manggil getKey()), bukan array.
        // Konversi ke base Collection dulu biar merge() di bawah aman.
        $rows = $apiMetrics->toBase()->map(function (ContentMetric $metric) use ($periodStart, $periodEnd) {
            if ($metric->instagram_media_snapshot_id) {
                $platformType = 'instagram';
                $identityColumn = 'instagram_media_snapshot_id';
                $identityId = $metric->instagram_media_snapshot_id;
                $publishedAt = $metric->instagramMediaSnapshot?->published_at;
            } else {
                $platformType = 'tiktok';
                $identityColumn = 'tiktok_video_snapshot_id';
                $identityId = $metric->tiktok_video_snapshot_id;
                $publishedAt = $metric->tiktokVideoSnapshot?->published_at;
            }

            $result = $this->computeContentDelta($platformType, $identityColumn, $identityId, $publishedAt, $periodStart, $periodEnd);

            return ['content_metric' => $metric, 'result' => $result, 'source' => 'api'];
        });

        // PHASE 3.1 FIX (Langkah 3) - CSV/manual TIDAK PERNAH otomatis
        // 'full'. Barisnya sendiri genuine (metric_date = tanggal asli yang
        // user ketik, angkanya TIDAK diubah), TAPI kehadiran 1/beberapa
        // baris dalam periode TIDAK MEMBUKTIKAN periode itu comprehensively
        // recorded (contoh spec: 1 baris tanggal 29 Agustus di periode 30
        // hari BUKAN bukti "30 hari penuh") - sistem tidak punya cara
        // memverifikasi user benar2 input SETIAP hari. Ditandai 'partial'
        // dengan reason 'manual_recorded' (bukan 'metric_reset_or_correction'
        // atau reason lain yang menyiratkan ada masalah data - ini cuma
        // batasan structural CSV, bukan kesalahan). Kalkulasi NUMERIK-nya
        // TETAP SAMA PERSIS (sum apa adanya, tidak disentuh sama sekali).
        $csvRows = $csvMetrics->toBase()->map(fn (ContentMetric $metric) => [
            'content_metric' => $metric,
            'result' => ContentPeriodResult::partial(
                Carbon::parse($metric->metric_date)->startOfDay(),
                Carbon::parse($metric->metric_date)->startOfDay(),
                'manual_recorded',
                [
                    'views' => $metric->views,
                    'likes' => $metric->likes,
                    'comments' => $metric->comments,
                    'shares' => $metric->shares,
                    'saves' => $metric->saves,
                    'reach' => $metric->reach,
                    'impressions' => $metric->impressions,
                ],
                $metric->engagement_rate !== null ? (float) $metric->engagement_rate : null,
                null,
                null // CSV tidak punya ContentMetricSnapshot genuine - metric_date-nya SUDAH per-periode
            ),
            'source' => 'csv',
        ]);

        $allRows = $rows->merge($csvRows)->values();

        $usableRows = $allRows->filter(fn ($row) => $row['result']->isUsable());
        $fullRows = $allRows->filter(fn ($row) => $row['result']->coverageStatus === ContentPeriodResult::FULL);

        // PHASE 3.1 FIX (Langkah 1, "jangan diam-diam membuang valid views")
        // - baris usable yang views delta-nya NULL (metric itu sendiri kena
        // reset/correction, atau belum ada observasi valid) DIKECUALIKAN
        // dari SUM, BUKAN dihitung sebagai 0 kontribusi. "?? 0" di sini akan
        // mengubah "tidak diketahui" jadi "diketahui nol" - persis
        // pelanggaran yang coverage_status sudah capek-capek dibangun buat
        // dicegah.
        $totalViews = (int) $usableRows
            ->map(fn ($row) => $row['result']->views())
            ->filter(fn ($v) => $v !== null)
            ->sum();
        $engagementValues = $usableRows->map(fn ($row) => $row['result']->engagementRate)->filter(fn ($v) => $v !== null);
        $avgEngagement = $engagementValues->isNotEmpty() ? round($engagementValues->avg(), 2) : null;

        $coverageStatus = match (true) {
            $allRows->isEmpty() => ContentPeriodResult::UNAVAILABLE,
            $usableRows->isEmpty() => ContentPeriodResult::UNAVAILABLE,
            $fullRows->count() === $allRows->count() => ContentPeriodResult::FULL,
            default => ContentPeriodResult::PARTIAL,
        };

        $coverageFrom = $usableRows->map(fn ($row) => $row['result']->coverageFrom)->filter()->max();
        $coverageTo = $usableRows->map(fn ($row) => $row['result']->coverageTo)->filter()->min();

        $platformBreakdown = $usableRows
            ->groupBy(fn ($row) => $row['content_metric']->platform_id)
            ->map(function ($groupRows, $platformId) {
                $platform = Platform::find($platformId);

                // Sama disiplin dengan $totalViews di atas - kecualikan
                // baris yang views delta-nya NULL, jangan dihitung 0.
                $value = $groupRows
                    ->map(fn ($row) => $row['result']->views())
                    ->filter(fn ($v) => $v !== null)
                    ->sum();

                return [
                    'label' => $platform->name ?? '-',
                    'value' => (int) $value,
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'coverage' => [
                'status' => $coverageStatus,
                'from' => $coverageFrom,
                'to' => $coverageTo,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_content' => $allRows->count(),
                'usable_content' => $usableRows->count(),
            ],
            'totals' => [
                'views' => $totalViews,
                'engagement_rate' => $avgEngagement,
                'content_count' => $usableRows->count(),
                'platforms_tracked' => $usableRows->pluck('content_metric.platform_id')->unique()->count(),
            ],
            'platform_breakdown' => $platformBreakdown,
            'rows' => $allRows,
        ];
    }

    /**
     * GAIN trend harian (section 7) - BUKAN lifetime cumulative chart. Buat
     * tiap tanggal di [periodStart, periodEnd], jumlahkan (snapshot(D) -
     * snapshot(D-1)) SEMUA content client(+platform) yang PUNYA dua-duanya
     * (D dan D-1). Content yang bolong di salah satu hari itu di-SKIP buat
     * hari itu (bukan dianggap 0) - hari yang tidak ada SATUPUN content
     * dengan pasangan valid dilaporkan sebagai gap eksplisit (value null),
     * TIDAK PERNAH membagi rata delta multi-hari secara artifisial.
     *
     * @return array<int, array{date: string, label: string, value: ?int, has_gap: bool}>
     */
    public function computeDailyGainSeries(int|string $clientId, Carbon $periodStart, Carbon $periodEnd, ?int $platformId = null): array
    {
        $rangeStart = $periodStart->copy()->startOfDay()->subDay();

        $snapshots = ContentMetricSnapshot::where('client_id', $clientId)
            ->when($platformId, fn ($q) => $q->where('platform_id', $platformId))
            ->whereBetween('snapshot_date', [$rangeStart->toDateString(), $periodEnd->copy()->startOfDay()->toDateString()])
            ->orderBy('snapshot_date')
            ->get(['instagram_media_snapshot_id', 'tiktok_video_snapshot_id', 'snapshot_date', 'views']);

        $csvMetrics = ContentMetric::where('client_id', $clientId)
            ->when($platformId, fn ($q) => $q->where('platform_id', $platformId))
            ->whereNull('instagram_media_snapshot_id')
            ->whereNull('tiktok_video_snapshot_id')
            ->whereBetween('metric_date', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->get(['metric_date', 'views']);

        return $this->computeDailyGainSeriesFromSnapshots($snapshots, $periodStart, $periodEnd, $csvMetrics);
    }

    /**
     * Core gain-series math (Langkah 7/9) - dipakai computeDailyGainSeries()
     * di atas MAUPUN consumer dengan roster kustom (Executive Dashboard,
     * yang cuma menghitung content ter-link - lihat computeAggregate()).
     *
     * $csvMetrics (opsional) - baris ContentMetric CSV/manual yang
     * metric_date-nya sudah di dalam window; nilainya DITAMBAHKAN ke titik
     * hari itu APA ADANYA (metric_date CSV = nilai per-periode asli dari
     * user, konsisten dengan semantik lama - lihat computeAggregate()) -
     * bukan bagian dari perhitungan delta cumulative API.
     *
     * @return array<int, array{date: string, label: string, value: ?int, has_gap: bool}>
     */
    public function computeDailyGainSeriesFromSnapshots(Collection $snapshots, Carbon $periodStart, Carbon $periodEnd, ?Collection $csvMetrics = null): array
    {
        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->startOfDay();

        $byContent = $snapshots->groupBy(fn (ContentMetricSnapshot $s) => $s->instagram_media_snapshot_id
            ? 'ig-'.$s->instagram_media_snapshot_id
            : 'tt-'.$s->tiktok_video_snapshot_id);

        // dailyGains[Y-m-d] = jumlah delta valid hari itu (akumulasi lintas content)
        $dailyGains = [];
        $dailyHasData = [];

        foreach ($byContent as $contentSnapshots) {
            $byDate = $contentSnapshots->keyBy(fn (ContentMetricSnapshot $s) => Carbon::parse($s->snapshot_date)->toDateString());
            $sortedDates = $byDate->keys()->sort()->values();

            for ($i = 1; $i < $sortedDates->count(); $i++) {
                $prevDate = $sortedDates[$i - 1];
                $currDate = $sortedDates[$i];

                // Cuma pasangan HARI BERURUTAN (D-1 -> D) yang dipakai -
                // pasangan yang bolong (mis. Senin lalu Rabu) TIDAK dibagi
                // rata, cukup dilewati (tidak menyumbang gain harian apapun,
                // baik utk Selasa maupun Rabu, karena kita genuinely tidak
                // tahu distribusinya - Langkah 7 "Jangan fabricate daily
                // activity"). addDay()->isSameDay() dipakai, BUKAN
                // diffInDays() !== 1 - Carbon::diffInDays() di versi ini
                // mengembalikan float (mis. 1.0), sementara "!==" strict
                // comparison ke int 1 SELALU gagal walau selisihnya
                // memang persis 1 hari (dulu bikin SEMUA pasangan hari
                // berurutan salah kedeteksi sebagai "bolong").
                if (! Carbon::parse($prevDate)->addDay()->isSameDay(Carbon::parse($currDate))) {
                    continue;
                }

                $prevViews = $byDate[$prevDate]->views;
                $currViews = $byDate[$currDate]->views;

                if ($prevViews === null || $currViews === null) {
                    continue;
                }

                $gain = (int) $currViews - (int) $prevViews;
                if ($gain < 0) {
                    continue; // metric reset/correction - dilewati, bukan diklaim negative gain
                }

                $dailyGains[$currDate] = ($dailyGains[$currDate] ?? 0) + $gain;
                $dailyHasData[$currDate] = true;
            }
        }

        if ($csvMetrics) {
            foreach ($csvMetrics as $csvMetric) {
                $date = Carbon::parse($csvMetric->metric_date)->toDateString();
                if ($csvMetric->views === null) {
                    continue;
                }
                $dailyGains[$date] = ($dailyGains[$date] ?? 0) + (int) $csvMetric->views;
                $dailyHasData[$date] = true;
            }
        }

        $points = [];
        $cursor = $periodStart->copy();
        while ($cursor->lte($periodEnd)) {
            $key = $cursor->toDateString();
            $points[] = [
                'date' => $key,
                'label' => $cursor->translatedFormat('d/m'),
                'value' => $dailyHasData[$key] ?? false ? $dailyGains[$key] : null,
                'has_gap' => ! ($dailyHasData[$key] ?? false),
            ];
            $cursor->addDay();
        }

        return $points;
    }

    /**
     * Gain harian 1 content unit (Langkah 9H, DetectPerformanceAnomalies) -
     * MIRROR logic computeDailyGainSeriesFromSnapshots() tapi per-content
     * (bukan aggregate lintas content client). Cuma pasangan hari
     * BERURUTAN dengan views non-null di dua-duanya yang dihitung, delta
     * negatif (metric reset/correction) dilewati (bukan negative gain
     * palsu) - key array = tanggal (Y-m-d) HARI SETELAH pasangan, value =
     * gain hari itu. Tanggal tanpa pasangan valid TIDAK ADA di array
     * (bukan 0) - caller HARUS cek isset(), bukan asumsi default 0.
     *
     * @return array<string, int>
     */
    public function computeContentDailyGains(string $identityColumn, int $identityId, Carbon $from, Carbon $to): array
    {
        $snapshots = ContentMetricSnapshot::where($identityColumn, $identityId)
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'views']);

        $byDate = $snapshots->keyBy(fn (ContentMetricSnapshot $s) => Carbon::parse($s->snapshot_date)->toDateString());
        $dates = $byDate->keys()->sort()->values();

        $gains = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $prevDate = $dates[$i - 1];
            $currDate = $dates[$i];

            // addDay()->isSameDay() (bukan diffInDays() !== 1) - lihat
            // catatan di computeDailyGainSeriesFromSnapshots().
            if (! Carbon::parse($prevDate)->addDay()->isSameDay(Carbon::parse($currDate))) {
                continue;
            }

            $prevViews = $byDate[$prevDate]->views;
            $currViews = $byDate[$currDate]->views;

            if ($prevViews === null || $currViews === null) {
                continue;
            }

            $gain = (int) $currViews - (int) $prevViews;
            if ($gain < 0) {
                continue;
            }

            $gains[$currDate] = $gain;
        }

        return $gains;
    }

    /**
     * Pesan coverage siap-tampil (Langkah 11) - JANGAN tampilkan "7/30/90
     * Hari: X views" tanpa qualifier kalau datanya belum full coverage.
     * Dipakai SEMUA consumer performa konten (Overview/Table/dst) - satu
     * tempat, bukan diulang tiap controller. Audience coverage TETAP
     * TERPISAH (method ini cuma buat performa konten, lihat
     * AnalyticsController::buildAudienceTabData(), tidak disentuh Phase 3).
     */
    public function coverageMessage(array $coverage, int $period): ?string
    {
        if ($coverage['status'] === ContentPeriodResult::FULL) {
            return null;
        }

        if ($coverage['status'] === ContentPeriodResult::UNAVAILABLE) {
            return 'Data performa periode ini belum tersedia.';
        }

        $from = $coverage['from']?->translatedFormat('d M Y');

        return $from
            ? "Data {$period} hari belum tersedia penuh. Menampilkan performa yang teramati sejak {$from}."
            : "Data {$period} hari belum tersedia penuh.";
    }

    /**
     * Kelompokkan daily gain series (dari computeDailyGainSeries[FromSnapshots])
     * jadi mingguan - buat periode 90 hari (Langkah 7, "weekly aggregation
     * boleh, tapi boundary/coverage rule tetap berlaku"). 1 minggu ditandai
     * gap HANYA kalau SEMUA harinya gap - kalau minimal 1 hari punya angka
     * valid, minggu itu dijumlahkan dari hari-hari yang valid saja (hari
     * yang gap TIDAK menyumbang 0, cukup dilewati dari penjumlahan).
     *
     * @param  array<int, array{date: string, label: string, value: ?int, has_gap: bool}>  $dailySeries
     * @return array<int, array{label: string, value: ?int, has_gap: bool}>
     */
    public function aggregateWeekly(array $dailySeries): array
    {
        return collect($dailySeries)
            ->groupBy(fn (array $point) => Carbon::parse($point['date'])->startOfWeek()->toDateString())
            ->map(function (Collection $weekPoints, string $weekStart) {
                $knownValues = $weekPoints->pluck('value')->filter(fn ($v) => $v !== null);

                return [
                    'label' => Carbon::parse($weekStart)->translatedFormat('d M'),
                    'value' => $knownValues->isNotEmpty() ? (int) $knownValues->sum() : null,
                    'has_gap' => $knownValues->isEmpty(),
                ];
            })
            ->values()
            ->all();
    }
}
