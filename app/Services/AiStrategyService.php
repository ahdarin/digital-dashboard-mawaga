<?php

namespace App\Services;

use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentType;
use App\Models\PerformanceAnomaly;
use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AiStrategyService — AI beneran (Google Gemini API, free tier), bukan
 * teks statis.
 *
 * Alurnya:
 * 1. Ambil data performa konten 1 client untuk 1 CALENDAR MONTH yang
 *    dipilih user (YYYY-MM) + platform filter global Analytics (spesifik/
 *    All) - dari content_metric_snapshots lewat PeriodPerformanceService,
 *    angka asli bukan dummy
 * 2. Ringkes jadi JSON kecil (total views, engagement, top 5 konten,
 *    breakdown per platform & pillar, coverage status)
 * 3. Kirim ke Gemini API, minta balikan terstruktur: narasi strategi,
 *    action items, suggested content split
 * 4. Simpan hasilnya ke tabel ai_strategy_insights, terikat ke context
 *    (client_id, platform_id, period_start, period_end) yang dianalisis -
 *    period_start/period_end APA ADANYA menyimpan batas bulan kalender
 *    yang dipilih (bukan lagi rolling window)
 *
 * Phase 4.1 (v2, "AI Strategy Month Selection") - histori semantik jendela
 * waktu di service ini:
 * - Awalnya SELALU 1 bulan kalender PENUH sebelumnya, independen dari
 *   filter Analytics.
 * - Diubah jadi rolling window (7/30/90 hari) mengikuti filter period
 *   Analytics global, plus previous-equal-length-window comparison.
 * - SEKARANG (final): balik ke calendar month, TAPI kali ini eksplisit
 *   DIPILIH user via input <input type="month"> khusus AI Strategy
 *   (bukan lagi terpaku ke "bulan lalu" otomatis, dan BUKAN lagi
 *   mengikuti filter period 7/30/90 Analytics - dua konsep waktu yang
 *   sengaja terpisah: Overview/Table/Audience tetap rolling window,
 *   AI Strategy py month-picker sendiri). TIDAK ADA previous-period
 *   comparison lagi - "previous_month" BUKAN requirement, dihapus total
 *   (lihat instruksi eksplisit "JANGAN otomatis membandingkan dengan
 *   bulan sebelumnya"). "Terapkan ke Content Plan"
 *   (AnalyticsController::applyAiStrategy()) TETAP menargetkan
 *   ContentPlan bulan KALENDER BERJALAN (report/production cadence,
 *   beda konsep dari bulan yang DIANALISIS AI - sengaja tidak disatukan).
 *
 * Butuh GEMINI_API_KEY di .env - ambil gratis di
 * https://aistudio.google.com/app/apikey (nggak perlu kartu kredit).
 */
class AiStrategyService
{
    // Model gratis di free tier Gemini. Kalau nanti error "model not
    // found", cek daftar model terbaru di https://aistudio.google.com
    // dan ganti nilai ini.
    private const MODEL = 'gemini-flash-lite-latest';

    public function __construct(
        private readonly PeriodPerformanceService $periodPerformanceService,
        private readonly ContentCohortService $contentCohortService,
        private readonly AnalyticsPeriodResolver $periodResolver,
        private readonly ContentFormatResolver $contentFormatResolver,
    ) {
    }

    /**
     * Resolusi batas 1 calendar month yang dipilih user ($month, format
     * "Y-m", mis. "2026-08") - period_start SELALU tanggal 1 bulan itu.
     * period_end:
     * - Bulan yang SUDAH LEWAT (fully in the past) -> akhir bulan itu
     *   (tanggal terakhir, mis. 31 Agustus).
     * - Bulan BERJALAN (current month) -> HARI INI, BUKAN endOfMonth -
     *   jangan pernah menganggap sisa hari bulan itu (yang belum terjadi)
     *   sudah py data (Langkah 5, "Jangan menunggu endOfMonth. Jangan
     *   membuat 3-30 Sep sebagai zero").
     *
     * Caller (controller) WAJIB validasi $month sebelum manggil ini
     * (format YYYY-MM valid, bukan bulan di masa depan) - method ini
     * sendiri percaya $month sudah valid, tidak defensive-check ulang di
     * sini (Langkah 10, validasi di boundary request/controller).
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function resolveMonthWindow(string $month): array
    {
        // PASS 2 - delegasi ke AnalyticsPeriodResolver (SATU-SATUNYA
        // tempat kalkulasi month-window boleh ada sekarang, lihat
        // docblock class itu) - method ini dipertahankan sebagai thin
        // wrapper APA ADANYA (return shape ['start'=>Carbon,'end'=>Carbon]
        // TIDAK berubah) biar caller/test existing (AiStrategyMonthSelectionTest
        // dkk) tetap jalan tanpa perubahan. effectiveDateTo dipakai
        // sebagai 'end' (SAMA PERSIS semantik lama - "end" method ini
        // SELALU sudah capped ke hari ini buat bulan berjalan).
        $period = $this->periodResolver->buildMonth($month);

        return [
            'start' => $period->dateFrom,
            'end' => $period->effectiveDateTo->copy()->endOfDay(),
        ];
    }

    /**
     * @param  string  $month  Format "Y-m" (mis. "2026-08") - calendar month yang dipilih user, DIVALIDASI di controller.
     * @param  ?int  $platformId  null = All Platforms (gabungan), atau ID platform spesifik.
     */
    public function buildPerformanceSummary(Client $client, string $month, ?int $platformId = null): array
    {
        $window = $this->resolveMonthWindow($month);
        $start = $window['start'];
        $end = $window['end'];
        $days = $start->diffInDays($end) + 1;

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 10/11) -
        // roster SEKARANG cohort publikasi (ContentCohortService,
        // published_at), angka analisis (total_views/performance_by_pillar/
        // performance_by_platform/top_5_content) SEKARANG performa TERKINI
        // genuine (ContentMetric apa adanya), BUKAN delta periode. AI HARUS
        // bisa menganalisis top-performing content/format/production-type
        // dalam cohort ini WALAU riwayat period-gain belum cukup (mis. sync
        // baru mulai setelah bulan itu berlalu) - period_views_gain/
        // coverage_status TETAP di-attach per-content sebagai info
        // SEKUNDER (concept C), TIDAK PERNAH lagi jadi alasan content
        // dikecualikan dari roster/ranking. $platformId diteruskan apa
        // adanya - AI Strategy TIDAK BOLEH diam-diam menggabungkan semua
        // platform kalau user memilih platform spesifik.
        $aggregate = $this->contentCohortService->computeClientCohort($client->id, $start, $end, $platformId);
        $cohortRows = collect($aggregate['rows']);

        $totalViews = $aggregate['totals']['views'];

        $byPillar = $cohortRows->groupBy(fn ($row) => $row['content_metric']->contentItem?->contentPillar?->name ?? 'Tanpa Pilar')
            ->map(fn ($rows) => [
                'total_views' => (int) $rows->sum(fn ($row) => $row['content_metric']->views ?? 0),
                'avg_engagement' => $this->avgCurrentEngagementFromRows($rows),
                'content_count' => $rows->count(),
            ]);

        $byPlatform = $cohortRows->groupBy(fn ($row) => $row['content_metric']->platform->name ?? '-')
            ->map(fn ($rows) => ['total_views' => (int) $rows->sum(fn ($row) => $row['content_metric']->views ?? 0)]);

        // SYSTEM CONSISTENCY PASS (Part J/K) - AI HARUS menerima klasifikasi
        // kanonis, dua dimensi TERPISAH (production_type/content_format),
        // BUKAN raw provider enum ATAU satu field yang mencampur keduanya.
        // production_type null (bukan ditebak) kalau content belum ke-link
        // - "unknown remains unknown". content_format lewat
        // ContentFormatResolver yang sama dipakai Analytics/Report, supaya
        // AI, dashboard, dan export semuanya "satu semantik". Ranking
        // SEKARANG by CURRENT views (performa terkini dalam cohort), BUKAN
        // gain periode - lihat docblock class ini/ContentCohortService.
        $topContent = $cohortRows
            ->sortByDesc(fn ($row) => $row['content_metric']->views ?? 0)
            ->take(5)
            ->map(function ($row) {
                $item = $row['content_metric']->contentItem;
                $igSnapshot = $row['content_metric']->instagramMediaSnapshot;
                $ttSnapshot = $row['content_metric']->tiktokVideoSnapshot;
                $periodResult = $row['period_result'];

                return [
                    'title' => $item?->title ?? '-',
                    'pillar' => $item?->contentPillar?->name ?? '-',
                    'production_type' => $item?->contentType?->name,
                    'content_format' => $item
                        ? $this->contentFormatResolver->labelForContentItem($item, $igSnapshot, $ttSnapshot)
                        : $this->contentFormatResolver->labelForSnapshot($igSnapshot, $ttSnapshot),
                    'platform' => $row['content_metric']->platform->name ?? '-',
                    'published_at' => $row['published_at']?->toDateString(),
                    // PRIMARY (concept B) - performa TERKINI genuine.
                    'current_views' => (int) ($row['content_metric']->views ?? 0),
                    'engagement_rate' => $row['content_metric']->engagement_rate !== null ? (float) $row['content_metric']->engagement_rate : 0,
                    // SECONDARY ONLY (concept C) - null/insufficient_history
                    // TIDAK PERNAH berarti "current_views di atas salah/nol".
                    'period_views_gain' => $periodResult?->views(),
                    'period_coverage' => $periodResult?->availabilityCategory() ?? 'insufficient_history',
                ];
            })
            ->values();

        // Data audience (demografi, top lokasi, jam aktif, tren follower) -
        // Phase 4.2 FIX: SEBELUMNYA dibatasin ke platform yang beneran ada
        // performance_by_platform-nya periode ini ($byPlatform derived dari
        // content performance) - salah, terlalu coupled. Contoh nyata:
        // TikTok dipilih, ada follower data TikTok, TAPI content performance
        // TikTok periode ini unavailable/tidak ada baris usable -> audience
        // TikTok yang genuinely ada ikut hilang, padahal tidak ada
        // hubungannya sama sekali dengan ketersediaan audience.
        // requested context ($platformId) SEKARANG yang menentukan platform
        // mana yang di-resolve, independen dari performance_by_platform:
        // - platform spesifik dipilih -> resolve platform itu SAJA, terlepas
        //   dia muncul di performance_by_platform atau tidak (resolveAudienceForPlatform()
        //   sendiri sudah honest return null kalau memang tidak ada data).
        // - All Platforms -> resolve tiap platform yang BENERAN punya baris
        //   AudienceInsight buat client ini (bukan asal semua platform yang
        //   exists di sistem) - tetap platform-separated di bawah, TIDAK
        //   PERNAH merge demografi lintas platform.
        $audienceByPlatform = [];
        $audiencePlatforms = $platformId
            ? Platform::whereKey($platformId)->get()
            : Platform::whereHas('audienceInsights', fn ($q) => $q->where('client_id', $client->id))->get();

        foreach ($audiencePlatforms as $platformModel) {
            $audienceRow = $this->resolveAudienceForPlatform($client, $platformModel, $start);

            if (! $audienceRow) {
                continue;
            }

            $peakHour = null;
            if (! empty($audienceRow['active_hours'])) {
                $peakHourKey = collect($audienceRow['active_hours'])->sortDesc()->keys()->first();
                $peakHour = str_pad((string) $peakHourKey, 2, '0', STR_PAD_LEFT).':00';
            }

            $audienceByPlatform[$platformModel->name] = [
                'follower_count' => $audienceRow['follower_count'],
                'follower_growth_percent_this_period' => $audienceRow['growth_percent'],
                'gender_breakdown' => $audienceRow['gender_breakdown'],
                'age_breakdown' => $audienceRow['age_breakdown'],
                'top_locations' => $audienceRow['top_locations'],
                'peak_active_hour' => $peakHour,
            ];
        }

        // Berapa banyak ide konten yang AI harus generate - ngikutin kuota
        // bulanan paket client (content + design), bukan angka tetap. Ini
        // dipakai applyAiStrategy() buat nentuin jumlah draft ContentItem,
        // jadi jumlah ide yang diminta ke sini WAJIB nyamain itu - kalau
        // enggak, draft yang kebanyakan bakal jatuh ke placeholder generik
        // tanpa judul/brief/format asli dari AI.
        $activePackage = $client->activePackage;
        $targetItemCount = (($activePackage->monthly_content_quota ?? 0) + ($activePackage->monthly_design_quota ?? 0)) ?: 10;

        // Anomali performa (spike/drop) yang kedeteksi analytics:detect-anomalies
        // SELAMA periode yang dianalisis - kasih AI konteks kejadian konkret,
        // bukan cuma angka agregat pillar/platform. Diurut dari yang paling
        // signifikan, dibatasin 10 biar prompt-nya nggak membengkak kalau
        // client-nya banyak konten yang fluktuatif.
        // Phase 4.2 FIX (audit "notable anomaly platform scoping") - SEBELUMNYA
        // pakai ContentItem.platform_id, scalar LEGACY yang cuma disinkronkan
        // ke platform PERTAMA yang dipilih (lihat docblock ContentItem::
        // platforms()) - salah kalau content item genuinely multi-platform.
        // PerformanceAnomaly sendiri TIDAK menyimpan platform_id (audit
        // DetectPerformanceAnomalies - anomaly direkam per content_item_id+
        // detected_date, TIDAK menyimpan identity metric/snapshot spesifik
        // yang memicunya, dan dedup check di command itu PER CONTENT ITEM
        // PER HARI, bukan per platform - kalau 1 content item genuinely
        // punya anomaly di 2 platform hari yang sama, cuma yang pertama
        // diproses yang kerekam). Jadi platform SATU anomaly TIDAK BISA
        // ditentukan dengan pasti dari PerformanceAnomaly sendiri.
        //
        // Source-of-truth yang BENAR & deterministic: ContentMetric.platform_id
        // (kolom asli per-baris-metric, BUKAN scalar ContentItem, selalu
        // exact - lihat migration create_content_metrics_table). Kalau
        // SEMUA ContentMetric milik content item itu platform_id-nya SAMA
        // (kasus normal - 1 content, 1 platform), atribusinya unambiguous.
        // Kalau content item itu genuinely py ContentMetric di >1 platform
        // (multi-platform), TIDAK ADA cara membuktikan anomaly SPESIFIK
        // yang mana - dikecualikan TOTAL dari filter platform manapun
        // (lebih baik anomaly itu hilang dari konteks AI daripada bocor ke
        // platform yang salah/ditebak).
        $notableAnomalies = PerformanceAnomaly::whereHas('contentItem', fn ($q) => $q->where('client_id', $client->id))
            ->whereBetween('detected_date', [$start, $end])
            ->with(['contentItem.contentPillar', 'contentItem.metrics:id,content_item_id,platform_id'])
            ->get()
            ->filter(function ($anomaly) use ($platformId) {
                if ($platformId === null) {
                    return true; // All Platforms - semua anomaly client ini relevan.
                }
                $distinctPlatformIds = $anomaly->contentItem?->metrics->pluck('platform_id')->unique() ?? collect();

                return $distinctPlatformIds->count() === 1 && $distinctPlatformIds->first() === $platformId;
            })
            ->sortByDesc(fn ($a) => abs($a->percent_change))
            ->take(10)
            ->map(fn ($a) => [
                'content_title' => $a->contentItem->title ?? '-',
                'pillar' => $a->contentItem?->contentPillar?->name ?? '-',
                'type' => $a->type,
                'percent_change' => $a->percent_change,
                'date' => $a->detected_date->format('d M'),
            ])
            ->values();

        // FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 10) -
        // coverage_status SEKARANG murni tentang concept C (SECONDARY
        // period-movement completeness across the cohort) - TIDAK PERNAH
        // lagi berarti "tidak ada data performa sama sekali" (roster/total_
        // views/top_5_content di atas SUDAH genuine & lengkap, terlepas
        // dari status ini). coverage_from/to = rentang observasi period-gain
        // yang genuinely ada di antara baris cohort (bisa kosong kalau
        // TIDAK SATUPUN baris punya period-gain genuine).
        // "full" HARUS berarti SEMUA baris punya period-gain PENUH (bukan
        // cuma "usable" - partial JUGA usable tapi bukan lengkap).
        $rowsWithFullPeriodMovement = $cohortRows->filter(fn ($row) => $row['period_result']?->coverageStatus === ContentPeriodResult::FULL);
        $rowsWithAnyPeriodMovement = $cohortRows->filter(fn ($row) => $row['period_result'] && $row['period_result']->isUsable());
        $coverageStatus = match (true) {
            $cohortRows->isEmpty() => ContentPeriodResult::UNAVAILABLE,
            $rowsWithFullPeriodMovement->count() === $cohortRows->count() => ContentPeriodResult::FULL,
            $rowsWithAnyPeriodMovement->isEmpty() => ContentPeriodResult::UNAVAILABLE,
            default => ContentPeriodResult::PARTIAL,
        };
        $coverageFrom = $rowsWithAnyPeriodMovement->map(fn ($row) => $row['period_result']->coverageFrom)->filter()->max();
        $coverageTo = $rowsWithAnyPeriodMovement->map(fn ($row) => $row['period_result']->coverageTo)->filter()->min();

        // "as_of" (Langkah 10) - observasi/sync TERAKHIR genuine yang
        // menyusun angka current_views di atas, biar AI (dan user yang baca
        // JSON mentah kalau perlu) tahu persis "performa terkini" ini
        // per-kapan, bukan klaim real-time yang tidak genuine.
        $asOf = $cohortRows
            ->map(fn ($row) => $row['content_metric']->instagramMediaSnapshot?->last_fetched_at ?? $row['content_metric']->tiktokVideoSnapshot?->last_fetched_at)
            ->filter()
            ->max();

        // Bulan BERJALAN (belum selesai) - dipakai buat label UI/AI yang
        // jujur ("hingga 2 September 2026", bukan klaim performa bulan
        // penuh) - Langkah 5/8.
        $isCurrentMonthInProgress = Carbon::now()->format('Y-m') === $month;

        return [
            // Langkah 10 - "AI input should mean: analysis_scope: content_
            // published_in_selected_period" - field eksplisit biar kontrak
            // ini terlihat langsung di JSON, bukan cuma tersirat.
            'cohort' => 'content_published_in_period',
            'as_of' => ($asOf ?? Carbon::now())->toIso8601String(),
            'client_name' => $client->name,
            'selected_month' => $month,
            'period' => "{$start->format('d M Y')} - {$end->format('d M Y')}",
            'is_current_month_in_progress' => $isCurrentMonthInProgress,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'platform_id' => $platformId,
            'platform_label' => $platformId ? (Platform::find($platformId)?->name ?? '-') : 'Semua Platform',
            // SECONDARY ONLY (concept C) - lihat catatan di atas.
            'coverage_status' => $coverageStatus,
            'coverage_from' => $coverageFrom?->toDateString(),
            'coverage_to' => $coverageTo?->toDateString(),
            'total_views' => $totalViews,
            'avg_engagement_rate' => $this->avgCurrentEngagementFromRows($cohortRows),
            'content_published_count' => $cohortRows->count(),
            'tracked_days' => $this->countTrackedDays($client, $start, $end),
            'period_days' => $days,
            'performance_by_pillar' => $byPillar,
            'performance_by_platform' => $byPlatform,
            'top_5_content' => $topContent,
            'audience_by_platform' => $audienceByPlatform,
            'notable_anomalies' => $notableAnomalies,
            'target_content_count' => $targetItemCount,
        ];
    }

    /**
     * Engagement TERKINI (concept B) - rata-rata ContentMetric.engagement_rate
     * apa adanya (sudah cumulative/current sejak ditulis saat sync), BUKAN
     * lagi rata-rata delta periode ($result->engagementRate).
     */
    private function avgCurrentEngagementFromRows(Collection $rows): float
    {
        $values = $rows->map(fn ($row) => $row['content_metric']->engagement_rate)->filter(fn ($v) => $v !== null);

        return $values->isNotEmpty() ? round($values->avg(), 2) : 0.0;
    }

    /**
     * "tracked_days" Phase 3 = jumlah hari yang BENAR2 punya observasi
     * genuine dalam periode (union snapshot_date content_metric_snapshots
     * buat content API + metric_date buat CSV/manual) - dipakai
     * AnalyticsController buat dataCompleteness (tracked_days/period_days).
     * BUKAN lagi distinct ContentMetric.metric_date (yang buat content API
     * dikunci ke tanggal publish, jadi cuma menghitung publish dates, bukan
     * hari performa benar2 di-observasi).
     */
    private function countTrackedDays(Client $client, Carbon $start, Carbon $end): int
    {
        $apiDates = ContentMetricSnapshot::where('client_id', $client->id)
            ->whereBetween('snapshot_date', [$start->toDateString(), $end->toDateString()])
            ->distinct()
            ->pluck('snapshot_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString());

        $csvDates = ContentMetric::where('client_id', $client->id)
            ->whereNull('instagram_media_snapshot_id')
            ->whereNull('tiktok_video_snapshot_id')
            ->whereBetween('metric_date', [$start, $end])
            ->distinct()
            ->pluck('metric_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString());

        return $apiDates->merge($csvDates)->unique()->count();
    }

    /**
     * Precedence API/CSV konsisten sama AnalyticsController::buildAudienceTabData()
     * (Langkah 22, "Instagram Audience Insights") - kalau client+platform
     * sudah punya row source=instagram_api, AI HANYA baca API (summary buat
     * follower_count/active_hours/growth, demographic_type=follower buat
     * gender/age/top_locations) - TIDAK digabung sama CSV/legacy. Kalau
     * belum ada API sama sekali, fallback ke CSV/legacy seperti sebelumnya.
     * Prompt Gemini & shape performance_data TIDAK diubah - method ini cuma
     * benerin SUMBER datanya, karena "latest()" polos sudah tidak aman
     * sejak 1 tanggal bisa punya banyak row paralel (summary+follower+
     * reached+engaged).
     *
     * @return array{follower_count: ?int, growth_percent: ?float, gender_breakdown: ?array, age_breakdown: ?array, top_locations: ?array, active_hours: ?array}|null
     */
    private function resolveAudienceForPlatform(Client $client, Platform $platform, Carbon $periodStart): ?array
    {
        $hasApiData = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->apiSourced()
            ->exists();

        if (! $hasApiData) {
            $latestSnapshot = AudienceInsight::where('client_id', $client->id)
                ->where('platform_id', $platform->id)
                ->csvSourced()
                ->latest('snapshot_date')
                ->first();

            if (! $latestSnapshot) {
                return null;
            }

            $previous = AudienceInsight::where('client_id', $client->id)
                ->where('platform_id', $platform->id)
                ->csvSourced()
                ->where('snapshot_date', '<=', $periodStart)
                ->latest('snapshot_date')
                ->first();

            return [
                'follower_count' => $latestSnapshot->follower_count,
                'growth_percent' => ($previous && $previous->follower_count > 0)
                    ? round((($latestSnapshot->follower_count - $previous->follower_count) / $previous->follower_count) * 100, 1)
                    : null,
                'gender_breakdown' => $latestSnapshot->gender_breakdown,
                'age_breakdown' => $latestSnapshot->age_breakdown,
                'top_locations' => $latestSnapshot->top_locations,
                'active_hours' => $latestSnapshot->active_hours,
            ];
        }

        $summary = AudienceInsight::where('client_id', $client->id)->where('platform_id', $platform->id)
            ->apiSourced()->summary()->latest('snapshot_date')->first();
        $followerDemo = AudienceInsight::where('client_id', $client->id)->where('platform_id', $platform->id)
            ->apiSourced()->demographics(AudienceInsight::TYPE_FOLLOWER)->latest('snapshot_date')->first();

        if (! $summary && ! $followerDemo) {
            return null; // integration ada tapi belum pernah sync sukses sama sekali
        }

        $previousSummary = AudienceInsight::where('client_id', $client->id)->where('platform_id', $platform->id)
            ->apiSourced()->summary()->whereNotNull('follower_count')
            ->where('snapshot_date', '<=', $periodStart)
            ->latest('snapshot_date')->first();

        $followerCount = $summary?->follower_count;

        return [
            'follower_count' => $followerCount,
            'growth_percent' => ($previousSummary && $followerCount && $previousSummary->follower_count > 0)
                ? round((($followerCount - $previousSummary->follower_count) / $previousSummary->follower_count) * 100, 1)
                : null,
            'gender_breakdown' => $followerDemo?->gender_breakdown,
            'age_breakdown' => $followerDemo?->age_breakdown,
            'top_locations' => $followerDemo?->top_locations,
            'active_hours' => $summary?->active_hours,
        ];
    }

    /**
     * @return array{summary: string, action_items: array, suggested_split: array}
     * @throws \RuntimeException kalau API call gagal
     */
    public function generateStrategy(array $performanceSummary): array
    {
        $prompt = $this->buildPrompt($performanceSummary);
        $text = $this->callGemini($prompt, $this->outputTokenBudget($performanceSummary['target_content_count'] ?? 10));

        $parsed = $this->extractJson($text);

        if (! $parsed || ! isset($parsed['summary'], $parsed['action_items'])) {
            throw new \RuntimeException('Gagal parsing hasil AI jadi format terstruktur.');
        }

        return [
            'summary' => $parsed['summary'],
            'action_items' => $parsed['action_items'],
            'suggested_split' => $parsed['suggested_split'] ?? [],
            'top_pillars' => $parsed['top_pillars'] ?? [],
            'content_ideas' => $parsed['content_ideas'] ?? [],
        ];
    }

    /**
     * Balesan diskusi/chat - AI jawab pertanyaan/masukan user tentang
     * insight yang udah digenerate, TETAP ngerujuk ke data performa asli
     * ($insight->performance_data) biar jawabannya nggak ngarang.
     *
     * @param array $conversationHistory [['role' => 'user'|'assistant', 'message' => '...'], ...]
     */
    public function chat(array $performanceData, array $previousResult, array $conversationHistory, string $userMessage): string
    {
        $dataJson = json_encode($performanceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $resultJson = json_encode($previousResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $historyText = collect($conversationHistory)
            ->map(fn ($m) => ($m['role'] === 'user' ? 'User' : 'AI').': '.$m['message'])
            ->implode("\n");

        $prompt = <<<PROMPT
Kamu adalah social media strategist yang lagi diskusi sama tim agensi
soal analisis performa konten yang udah kamu buat sebelumnya.

DATA PERFORMA ASLI (jadi dasar analisis awal):
{$dataJson}

ANALISIS AWAL YANG UDAH KAMU BERIKAN:
{$resultJson}

RIWAYAT DISKUSI SEJAUH INI:
{$historyText}

Pesan terbaru dari user:
{$userMessage}

Jawab pesan user itu secara natural, dalam Bahasa Indonesia, singkat aja
(maksimal 4-5 kalimat, kayak chat biasa bukan laporan formal). Tulis
dalam KALIMAT MENGALIR BIASA - JANGAN pakai markdown sama sekali (jangan
pakai **bold**, jangan pakai bullet point/list bernomor, jangan pakai
heading #). Kalau perlu nyebut beberapa poin, gabung jadi 1-2 kalimat
aja, bukan list. Kalau user ngasih info baru yang nggak ada di data
(misal "sebenarnya bulan itu kita ganti strategi tanggal 15"), akui itu
terus jelasin gimana itu bisa ngubah interpretasi analisisnya. Kalau
user nanya sesuatu yang datanya nggak ada di DATA PERFORMA ASLI di atas,
bilang terus terang datanya nggak tersedia, jangan ngarang angka. JANGAN
balas dalam format JSON, cukup teks biasa.

PENTING - batasan chat ini: kamu CUMA bisa NGOBROL di sini, balesan kamu
TIDAK PERNAH otomatis mengubah suggested_split/content_ideas/summary yang
tersimpan. Kalau user setuju/minta suatu perubahan (misal "boleh
sesuaikan", "oke ganti aja", "iya lanjutkan"), JANGAN pernah jawab seolah
perubahan itu udah/lagi kamu terapkan (contoh yang SALAH: "oke, udah saya
sesuaikan", "baik, saya update sekarang"). Sebagai gantinya, akui idenya
masuk akal (kalau memang masuk akal) lalu WAJIB arahkan user buat klik
tombol "Perbarui Analisis dari Diskusi Ini" di bawah kolom chat ini biar
perubahannya beneran ke-generate ulang - itu satu-satunya cara analisis
ini ke-update, chat doang nggak cukup.
PROMPT;

        $text = $this->callGemini($prompt, 512);

        // Jaga-jaga kalau Gemini tetep ngasih markdown walau udah dilarang -
        // bersihin simbolnya biar nggak keliatan mentah di UI chat
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text); // **bold** -> bold
        $text = preg_replace('/^[\*\-]\s+/m', '', $text);      // bullet list -> teks biasa
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);      // # heading -> teks biasa

        return trim($text);
    }

    /**
     * Generate ULANG analisis terstruktur (summary/action_items/dst),
     * kali ini mempertimbangkan seluruh diskusi yang udah terjadi -
     * dipanggil pas user klik "Perbarui Analisis dari Diskusi Ini".
     */
    public function refineFromDiscussion(array $performanceData, array $previousResult, array $conversationHistory): array
    {
        $dataJson = json_encode($performanceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $resultJson = json_encode($previousResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $targetCount = $performanceData['target_content_count'] ?? 10;
        $platformNames = collect($performanceData['performance_by_platform'] ?? [])->keys();
        $platformOptions = $platformNames->isNotEmpty() ? $platformNames->implode(', ') : 'Instagram, TikTok';

        $historyText = collect($conversationHistory)
            ->map(fn ($m) => ($m['role'] === 'user' ? 'User' : 'AI').': '.$m['message'])
            ->implode("\n");

        $previousPillars = collect($previousResult['suggested_split'] ?? [])->pluck('label')->filter()->implode(', ');
        $dataPillars = collect($performanceData['performance_by_pillar'] ?? [])->keys()->implode(', ');

        $prompt = <<<PROMPT
Kamu sebelumnya udah bikin analisis strategi konten berikut, berdasarkan
data performa ini:

DATA PERFORMA ASLI:
{$dataJson}

ANALISIS SEBELUMNYA:
{$resultJson}

Pillar yang ada di ANALISIS SEBELUMNYA (suggested_split): {$previousPillars}
Pillar yang beneran ada datanya di performance_by_pillar: {$dataPillars}

Tim udah ngasih diskusi/masukan tambahan soal analisis ini:
{$historyText}

Sekarang PERBARUI analisisnya dengan mempertimbangkan masukan dari diskusi
di atas, tapi TETAP berdasarkan data performa asli (jangan ngarang angka
yang nggak ada). Kalau masukan dari user itu berupa konteks tambahan
(bukan angka), pakai itu buat nyempurnain rekomendasi strategi, bukan
buat ngubah angka performa yang udah ada.

Balas HANYA dalam format JSON valid, tanpa teks lain, struktur PERSIS
sama kayak sebelumnya:

{
  "summary": "2-3 kalimat ringkasan yang UDAH mempertimbangkan diskusi",
  "action_items": ["action item 1", "action item 2", "action item 3"],
  "suggested_split": [{"label": "nama pillar", "value": persentase_angka}],
  "top_pillars": [{"name": "...", "reasoning": "..."}],
  "content_ideas": [{"pillar": "nama pillar (harus match salah satu label di suggested_split)", "title": "...", "brief": "...", "type": "salah satu dari: {$this->contentTypeOptions()}", "platform": "salah satu dari: {$platformOptions}"}]
}

Aturan tambahan soal pillar di suggested_split (PENTING, sering salah kalau
dilanggar):
- Default-nya PERTAHANKAN semua pillar yang ada di ANALISIS SEBELUMNYA
  ({$previousPillars}), cuma GESER persentase (value) antar pillar sesuai
  diskusi. JANGAN hapus sebuah pillar sampai persentasenya 0/hilang dari
  daftar kecuali diskusi di atas eksplisit minta pillar itu DIHAPUS/DIGANTI
  TOTAL - kalau diskusinya cuma minta "sebagian"/"beberapa" konten
  dipindah/divariasikan ke pillar lain, pillar asalnya WAJIB tetap muncul
  di suggested_split dengan persentase yang dikurangi (bukan hilang sama
  sekali)
- Pillar baru cuma boleh ditambahkan ke suggested_split kalau namanya
  eksplisit disebut di diskusi di atas (User atau AI). JANGAN nambahin
  pillar lain yang nggak ada di ANALISIS SEBELUMNYA, nggak ada di
  performance_by_pillar, DAN nggak disebut di diskusi - misal jangan
  tiba-tiba nambahin "Product Highlight"/"Entertainment"/"Education" kalau
  itu nggak pernah dibahas
- suggested_split tetap harus total 100

content_ideas WAJIB tetap {$targetCount} ide total (sama kayak sebelumnya,
ngikutin target_content_count), kecuali diskusi di atas eksplisit minta
jumlahnya diubah. Field "type" WAJIB diisi salah satu dari: {$this->contentTypeOptions()}.
Field "platform" WAJIB diisi salah satu dari: {$platformOptions}.
PROMPT;

        $text = $this->callGemini($prompt, $this->outputTokenBudget($targetCount));
        $parsed = $this->extractJson($text);

        if (! $parsed || ! isset($parsed['summary'], $parsed['action_items'])) {
            throw new \RuntimeException('Gagal parsing hasil pembaruan analisis.');
        }

        return [
            'summary' => $parsed['summary'],
            'action_items' => $parsed['action_items'],
            'suggested_split' => $parsed['suggested_split'] ?? [],
            'top_pillars' => $parsed['top_pillars'] ?? [],
            'content_ideas' => $parsed['content_ideas'] ?? [],
        ];
    }

    /**
     * Generate SATU ide konten alternatif buat 1 pillar tertentu - dipakai
     * pas user klik "Regenerate" di modal detail ide (bisa buat cari
     * alternatif di pillar yang sama, atau setelah user ganti kategori
     * pillar-nya). AI dikasih SEMUA ide lain yang udah ada (bukan cuma
     * pillar yang sama) biar hasilnya nggak duplikat/mirip dan tetap
     * mempertimbangkan komposisi type/platform keseluruhan.
     *
     * @param array $performanceData performance_data mentah dari insight
     * @param array $otherIdeas seluruh content_ideas SELAIN yang lagi diganti
     * @throws \RuntimeException kalau API call/parsing gagal
     */
    public function regenerateIdea(array $performanceData, array $otherIdeas, string $pillar): array
    {
        $dataJson = json_encode($performanceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $otherIdeasJson = json_encode(array_values($otherIdeas), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $platformNames = collect($performanceData['performance_by_platform'] ?? [])->keys();
        $platformOptions = $platformNames->isNotEmpty() ? $platformNames->implode(', ') : 'Instagram, TikTok';

        $prompt = <<<PROMPT
Kamu sebelumnya udah bikin daftar ide konten berikut buat 1 client,
berdasarkan data performa ini:

DATA PERFORMA ASLI:
{$dataJson}

IDE KONTEN LAIN YANG UDAH ADA DI DAFTAR INI (jangan diulang - lihat juga
sebaran type/platform-nya biar beban kerja tim produksi tetap seimbang):
{$otherIdeasJson}

Tim mau GANTI salah satu ide di daftar itu dengan alternatif baru buat
pillar "{$pillar}". Buatkan SATU ide konten baru, beda dari semua ide yang
udah ada di atas (jangan mirip judul maupun sudut pandangnya), dan
perhatikan sebaran type/platform yang udah ada supaya nggak numpuk beban
ke 1 role produksi doang.

PENTING soal karakter pillar "{$pillar}": judul & brief WAJIB beneran
mencerminkan pendekatan/nada pillar itu, bukan cuma nempelin nama pillar
ke field "pillar" doang. Contoh: kalau pillar-nya "Hard Selling", boleh
langsung dorong jualan/CTA beli/promo eksplisit. Kalau pillar-nya "Soft
Selling", JANGAN pakai hook/CTA jualan yang keras/langsung - pendekatannya
harus lebih santai (storytelling, relatable, edukatif, atau problem-solving
ringan) yang cuma nyerempet ke produk/jasa secara halus. Kalau ide lain di
atas (IDE KONTEN LAIN YANG UDAH ADA DI DAFTAR INI) ada yang beda pillar
dari "{$pillar}", JANGAN niru nada/sudut pandang ide-ide itu - sesuaikan
sepenuhnya ke karakter pillar "{$pillar}" yang diminta sekarang.

Balas HANYA dalam format JSON valid, tanpa teks lain, tanpa markdown code
block, struktur PERSIS seperti ini:

{
  "pillar": "harus PERSIS \"{$pillar}\", jangan diubah/dipilih pillar lain",
  "title": "judul konten yang siap pakai, spesifik, bukan generik, dan BEDA dari ide yang udah ada",
  "brief": "2-3 kalimat brief: sudut pandang/hook/poin utama yang harus disampaikan",
  "type": "salah satu dari: {$this->contentTypeOptions()}",
  "platform": "salah satu dari: {$platformOptions}"
}
PROMPT;

        $text = $this->callGemini($prompt, 512);
        $parsed = $this->extractJson($text);

        if (! $parsed || ! isset($parsed['title'], $parsed['brief'])) {
            throw new \RuntimeException('Gagal parsing hasil regenerate ide.');
        }

        // Guard: AI diminta echo balik pillar yang dia pakai - kalau beda
        // dari yang diminta, berarti dia nyasar/salah paham pillar lain
        // (misal kepengaruh nada ide-ide lain di daftar). Mending gagal
        // eksplisit di sini daripada diam-diam nyimpen ide yang pillar-nya
        // ketuker.
        if (isset($parsed['pillar']) && mb_strtolower(trim((string) $parsed['pillar'])) !== mb_strtolower(trim($pillar))) {
            throw new \RuntimeException("AI ngasih hasil buat pillar \"{$parsed['pillar']}\" alih-alih \"{$pillar}\" yang diminta - coba regenerate ulang.");
        }

        // Guard: type & platform juga harus dari daftar yang valid, bukan
        // hasil ngarang Gemini - biar nggak lolos ke ContentItem dengan
        // format/platform yang nggak dikenal sistem.
        $validTypes = collect(explode(',', $this->contentTypeOptions()))->map(fn ($t) => trim($t));
        if (isset($parsed['type']) && ! $validTypes->contains(fn ($t) => mb_strtolower($t) === mb_strtolower(trim((string) $parsed['type'])))) {
            throw new \RuntimeException("AI ngasih format \"{$parsed['type']}\" yang nggak dikenal sistem - coba regenerate ulang.");
        }

        $validPlatforms = $platformNames->isNotEmpty() ? $platformNames : collect(['Instagram', 'TikTok']);
        if (isset($parsed['platform']) && ! $validPlatforms->contains(fn ($p) => mb_strtolower($p) === mb_strtolower(trim((string) $parsed['platform'])))) {
            throw new \RuntimeException("AI ngasih platform \"{$parsed['platform']}\" yang nggak dikenal sistem - coba regenerate ulang.");
        }

        return [
            'pillar' => $pillar,
            'title' => $parsed['title'],
            'brief' => $parsed['brief'],
            'type' => $parsed['type'] ?? null,
            'platform' => $parsed['platform'] ?? null,
        ];
    }

    /**
     * Helper mentah - kirim prompt ke Gemini, balikin teks jawabannya.
     * Dipakai bareng sama generateStrategy(), chat(), dan refineFromDiscussion()
     * biar nggak duplikasi kode manggil API.
     */
    private function callGemini(string $prompt, int $maxOutputTokens): string
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY belum di-set di .env');
        }

        $response = Http::timeout(60)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.':generateContent?key='.$apiKey,
            [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'maxOutputTokens' => $maxOutputTokens,
                ],
            ]
        );

        if ($response->failed()) {
            Log::error('AiStrategyService: Gemini API gagal', ['body' => $response->body()]);
            throw new \RuntimeException('Gagal menghubungi Gemini API: '.$response->status().' - '.($response->json('error.message') ?? $response->body()));
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! $text) {
            $finishReason = $response->json('candidates.0.finishReason');
            throw new \RuntimeException('Response API kosong (finishReason: '.($finishReason ?? 'unknown').').');
        }

        return $text;
    }

    /**
     * Instruksi eksplisit ke Gemini soal coverage historis (Phase 4.1
     * Langkah 12) - kalau snapshot history belum selengkap periode yang
     * diminta, Gemini WAJIB tahu itu secara eksplisit di prompt (bukan
     * cuma badge di UI), supaya dia TIDAK menulis observed partial gain
     * seolah itu angka full-period yang lengkap.
     */
    /**
     * FINAL ANALYTICS PRODUCT SEMANTICS CORRECTION (Langkah 10/11/12) -
     * coverage_status di sini SEKARANG murni tentang concept C (SECONDARY,
     * "how much did metrics move during the period" - period-gain history
     * completeness), TIDAK PERNAH lagi berarti "tidak ada data performa
     * sama sekali". content_published_count/total_views/top_5_content
     * SUDAH genuine & lengkap regardless of this status (roster-nya cohort
     * publikasi, dihitung ContentCohortService SEBELUM method ini bahkan
     * dipanggil) - jadi instruksi di sini HARUS eksplisit mengizinkan (WAJIB,
     * bukan cuma boleh) analisis performa terkini/top-performing/format/
     * production-type tetap jalan penuh, HANYA melarang KLAIM PERTUMBUHAN
     * ("naik/turun selama bulan X") yang genuinely tidak bisa dibuktikan.
     */
    private function coverageNoticeFor(array $data): string
    {
        $status = $data['coverage_status'] ?? null;
        $monthLabel = $this->monthLabel($data);

        if ($status === ContentPeriodResult::FULL || $status === null) {
            return '';
        }

        if ($status === ContentPeriodResult::UNAVAILABLE) {
            return <<<TEXT

PENTING - COVERAGE DATA (HANYA soal PERTUMBUHAN, BUKAN soal ketersediaan data):
Riwayat observasi pertumbuhan metrik SELAMA {$monthLabel} belum tersedia sama
sekali (coverage_status="unavailable") - kemungkinan besar sinkronisasi baru
mulai SETELAH bulan ini berlalu. INI TIDAK BERARTI data performa kosong -
total_views/avg_engagement_rate/top_5_content/performance_by_pillar/
performance_by_platform di atas SEMUA genuine dan LENGKAP (performa TERKINI
konten yang dipublikasikan {$monthLabel}, current_views tiap content di
top_5_content nyata, BUKAN nol/dikarang). WAJIB tetap analisis top-performing
content, performa per pillar/platform/format/production-type, dan volume
konten seperti biasa berdasarkan angka-angka itu. YANG TIDAK BOLEH: klaim
pertumbuhan/perubahan SELAMA {$monthLabel} (contoh: "views naik X% selama
bulan ini") - itu genuinely tidak bisa dibuktikan tanpa riwayat observasi,
field period_views_gain per-content akan null/coverage "insufficient_history"
kalau ini terjadi.
TEXT;
        }

        $from = $data['coverage_from'] ?? null;
        $to = $data['coverage_to'] ?? null;
        $range = ($from && $to) ? "{$from} sampai {$to}" : 'sebagian bulan yang diminta';

        return <<<TEXT

PENTING - COVERAGE DATA (HANYA soal PERTUMBUHAN, BUKAN soal ketersediaan data):
total_views/top_5_content/performance_by_pillar/performance_by_platform di
atas SUDAH genuine & lengkap (performa TERKINI konten yang dipublikasikan
{$monthLabel}) - TIDAK PERLU qualifier apapun buat angka-angka itu. HANYA
riwayat PERTUMBUHAN metrik (period_views_gain per-content, coverage_
status="partial") yang baru mencakup {$range} sebagian konten - kalau mau
klaim pertumbuhan/perubahan SELAMA {$monthLabel} secara umum, WAJIB akui
keterbatasan itu eksplisit (contoh: "berdasarkan konten yang punya riwayat
sejak {$from}..."), JANGAN generalisasi ke seluruh cohort tanpa qualifier.
TEXT;
    }

    /**
     * Versi RINGKAS coverageNoticeFor() - diulang PERSIS sebelum instruksi
     * format output JSON di akhir prompt (bukan cuma sekali di dekat data
     * mentah). Reinforcement di 2 titik dalam prompt yang sama, bukan
     * hanya 1x - satu penyisipan di awal terbukti tidak cukup mencegah
     * struktur "Performa {bulan} = X" muncul di output kalau instruksinya
     * cuma sekali dibaca lalu "hilang" di tengah prompt yang panjang.
     */
    private function coverageReminderFor(array $data): string
    {
        $status = $data['coverage_status'] ?? null;
        $monthLabel = $this->monthLabel($data);

        return match ($status) {
            ContentPeriodResult::UNAVAILABLE => "\nINGAT SEKALI LAGI: coverage_status=\"unavailable\" HANYA berarti riwayat PERTUMBUHAN metrik selama {$monthLabel} tidak tersedia - JANGAN tulis kalimat berbentuk \"Views naik/bertambah selama {$monthLabel}...\". total_views/top_5_content/performance_by_pillar TETAP genuine, WAJIB tetap dianalisis penuh (top-performing content, format, production-type, volume).",
            ContentPeriodResult::PARTIAL => "\nINGAT SEKALI LAGI: coverage_status=\"partial\" HANYA membatasi klaim PERTUMBUHAN metrik (coverage_from s/d coverage_to) - total_views/top_5_content/performance_by_pillar TETAP genuine & lengkap, JANGAN batasi analisis performa terkini karena ini.",
            default => '',
        };
    }

    /**
     * "Agustus 2026" dari selected_month ("2026-08") - dipakai
     * coverageNoticeFor()/coverageReminderFor() supaya bahasa prompt
     * konsisten dengan label bulan yang sebenarnya dipilih, bukan lagi
     * angka hari generik.
     */
    private function monthLabel(array $data): string
    {
        $month = $data['selected_month'] ?? null;

        if (! $month) {
            return 'bulan yang diminta';
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $month.'-01')->translatedFormat('F Y');
        } catch (\Throwable) {
            return $month;
        }
    }

    private function buildPrompt(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $targetCount = $data['target_content_count'] ?? 10;
        // performance_by_platform bisa Collection (baru dibangun di
        // buildPerformanceSummary(), belum lewat cast array dari DB) atau
        // array biasa (udah lewat AiStrategyInsight->performance_data) -
        // collect() aman buat dua-duanya.
        $platformNames = collect($data['performance_by_platform'] ?? [])->keys();
        $platformOptions = $platformNames->isNotEmpty() ? $platformNames->implode(', ') : 'Instagram, TikTok';
        // Phase 4.2 (Langkah 5, "coverage prompt must be structurally
        // honest") - $coverageNotice sekarang ditaruh SEBELUM JSON (model
        // baca batasannya DULU, baru angkanya - bukan kebalik), DAN
        // $coverageReminder (versi ringkas) diulang lagi PERSIS sebelum
        // instruksi format output - reinforcement di 2 titik (awal & akhir
        // prompt) supaya batasan coverage tidak "hilang" di tengah prompt
        // yang panjang. 1 penyisipan tunggal terbukti tidak cukup buat
        // structural honesty - lihat juga bullet eksplisit di "Aturan
        // tambahan" di bawah yang meng-override instruksi "WAJIB sebut
        // angka asli" buat kasus unavailable.
        $coverageNotice = $this->coverageNoticeFor($data);
        $coverageReminder = $this->coverageReminderFor($data);
        $monthLabel = $this->monthLabel($data);
        $inProgressNote = ! empty($data['is_current_month_in_progress'])
            ? " Bulan ini MASIH BERJALAN (belum selesai) - data di atas HANYA mencakup {$data['period']}, sisa hari bulan ini belum terjadi dan TIDAK PERNAH diasumsikan nol/kosong, cukup tidak termasuk sama sekali dalam angka ini."
            : '';

        return <<<PROMPT
Kamu adalah social media strategist untuk agensi kreatif. Di bawah ini data
performa konten asli 1 client untuk bulan analisis yang dipilih:
{$monthLabel} ({$data['period']}).{$inProgressNote}
Data ini dari database, bukan contoh:
{$coverageNotice}

{$json}

Analisis data ini dan berikan rekomendasi strategi konten untuk bulan
berikutnya, KHUSUS berdasarkan bulan {$monthLabel} ini. Dasarkan rekomendasi
HANYA pada angka yang diberikan (pillar mana yang performanya terbaik,
platform mana yang paling efektif) - jangan mengarang data yang tidak ada
di JSON di atas. JANGAN membandingkan dengan bulan sebelumnya atau bulan
manapun di luar {$monthLabel} - tidak ada data perbandingan yang diberikan
di JSON di atas, jadi klaim "naik/turun dari bulan lalu" TIDAK PERNAH boleh
muncul kecuali data perbandingan itu benar-benar eksplisit ada di JSON.
Kalau ada field "audience_by_platform", pertimbangkan juga demografi (usia/gender
dominan), top lokasi, dan peak_active_hour di sana buat mempertajam
rekomendasi (sudut pandang konten yang relevan buat demografi itu, dan jam
posting yang disaranin) - tapi kalau audience_by_platform kosong atau nggak
ada buat suatu platform, JANGAN ngarang asumsi audience-nya, cukup dasarkan
rekomendasi ke data performa konten aja. Kalau ada field "notable_anomalies",
itu konten yang performanya melonjak ("spike") atau anjlok ("drop") secara
signifikan dibanding rata-rata historisnya sendiri selama periode ini,
terdeteksi otomatis dari sistem - manfaatin buat memperkuat top_pillars
(sebut kalau ada spike yang mendukung/drop yang melemahkan reasoning suatu
pillar) dan content_ideas (kalau beberapa spike sama-sama dari 1
pillar/type/platform tertentu, itu sinyal kuat buat direplikasi). Kalau
field-nya kosong, berarti nggak ada anomali terdeteksi periode ini - jangan
disebut atau dikarang. Kalau data yang tersedia terlalu sedikit untuk suatu
kesimpulan, katakan itu secara eksplisit di summary, jangan dipaksakan.
{$coverageReminder}

Balas HANYA dalam format JSON valid, tanpa teks lain di luar JSON, tanpa
markdown code block, dengan struktur persis seperti ini:

{
  "summary": "2-3 kalimat ringkasan kondisi & rekomendasi strategi utama, dalam Bahasa Indonesia",
  "action_items": ["action item 1 yang spesifik & bisa dieksekusi", "action item 2", "action item 3"],
  "suggested_split": [{"label": "nama pillar/format", "value": persentase_angka}],
  "top_pillars": [
    {"name": "nama pillar dari performance_by_pillar di atas", "reasoning": "1 kalimat kenapa pillar ini masuk ranking, sebut angka konkretnya"}
  ],
  "content_ideas": [
    {"pillar": "nama pillar (harus match salah satu label di suggested_split)", "title": "judul konten yang siap pakai, spesifik, bukan generik", "brief": "2-3 kalimat brief: sudut pandang/hook/poin utama yang harus disampaikan", "type": "salah satu dari: {$this->contentTypeOptions()}", "platform": "salah satu dari: {$platformOptions}"}
  ]
}

Aturan tambahan:
- coverage_status di JSON di atas HANYA soal riwayat PERTUMBUHAN metrik
  (period_views_gain), BUKAN soal ketersediaan performance_by_pillar/
  total_views/top_5_content - field-field itu genuine & lengkap TERLEPAS
  dari coverage_status. top_pillars dan suggested_split WAJIB tetap diisi
  dari performance_by_pillar seperti biasa walau coverage_status=
  "unavailable" - HANYA JANGAN klaim pertumbuhan/perubahan selama bulan
  ini di reasoning-nya kalau coverage_status bukan "full" (pakai angka
  performa TERKINI di data, bukan klaim naik/turun).
- suggested_split harus total 100, isinya pillar YANG ADA di
  performance_by_pillar (jangan bikin pillar baru yang nggak ada di data)
- top_pillars maksimal 3, diurutkan dari performa terbaik, WAJIB nyebut
  angka asli (views/engagement TERKINI) dari data di atas di bagian
  reasoning, jangan cuma bilang "bagus" tanpa angka - ini SELALU berlaku,
  TIDAK ADA pengecualian coverage_status (lihat bullet pertama)
- Kalau performance_by_pillar di data cuma punya 1 atau 2 pillar,
  top_pillars ya isi sejumlah yang ada aja, jangan dipaksa jadi 3
- content_ideas: WAJIB buatkan TEPAT {$targetCount} ide konten total (ini
  target_content_count di data di atas, ngikutin kuota konten+desain
  bulanan client - JANGAN dibulatkan ke bawah atau dikira-kira 8-10 kayak
  kebiasaan biasanya). Sebarannya ngikutin persentase di suggested_split
  (misal kalau Edukasi 40% dari {$targetCount} ide, bikin ~{$targetCount}
  x 40% ide buat pillar Edukasi, dibulatkan). Judul & brief harus konkret
  dan siap dipakai tim produksi, bukan placeholder generik kayak "Konten
  Edukasi #1". Kalau {$targetCount} kerasa banyak, tetap usahakan
  se-konkret mungkin per ide - boleh lebih ringkas per brief asal
  jumlahnya tetap {$targetCount}
- PENTING soal beban kerja tim produksi: field "type" di tiap content_ideas
  WAJIB diisi salah satu dari daftar ini, PERSIS namanya (case-sensitive):
  {$this->contentTypeOptions()}. Variasikan tipenya - JANGAN semua ide
  dikasih type yang sama (misal semua "Carousel"), karena itu numpuk beban
  ke 1 role produksi doang. Sebutkan juga formatnya di title/brief biar
  jelas, dan usahakan sebarannya proporsional lintas type
- field "platform" di tiap content_ideas WAJIB diisi salah satu dari
  daftar ini, PERSIS namanya: {$platformOptions} - pilih platform yang
  paling relevan buat ide itu berdasarkan performance_by_platform di data
- kalau audience_by_platform ada datanya buat platform tertentu, manfaatin
  demografi & peak_active_hour-nya di brief ide (mis. sudut pandang yang
  relevan buat usia/gender dominan, atau saran jam upload) - tapi JANGAN
  dipaksakan nyebut audience kalau datanya emang nggak ada
PROMPT;
    }

    /**
     * Daftar nama ContentType yang beneran ada di sistem, buat dikasih ke
     * Gemini sebagai pilihan valid field "type" pada content_ideas - biar
     * pas di-apply ke Content Plan (applyAiStrategy()), tiap draft bisa
     * di-mapping ke content_type_id yang benar (termasuk kuota Design vs
     * Content per client_packages.monthly_design_quota).
     */
    private function contentTypeOptions(): string
    {
        $names = ContentType::pluck('name');

        return $names->isNotEmpty() ? $names->implode(', ') : 'Video, Desain';
    }

    /**
     * maxOutputTokens buat generate/refine - dulu fix 2048 (cukup buat
     * ~8-10 ide), sekarang jumlah ide ngikutin target_content_count yang
     * bisa jauh lebih banyak (kuota bulanan client), jadi budget token-nya
     * ikut discale biar nggak kepotong (finishReason: MAX_TOKENS) pas
     * client-nya punya kuota besar.
     */
    private function outputTokenBudget(int $targetItemCount): int
    {
        return min(8192, 1024 + ($targetItemCount * 140));
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // Gemini sering bungkus JSON dalam ```json ... ``` walaupun udah
        // diminta jangan - kadang malah didahului teks pembuka kayak
        // "Berikut analisisnya:" sebelum blok fence-nya. Makanya dicari di
        // mana pun posisinya (bukan cuma di awal/akhir string).
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches)) {
            $text = $matches[1];
        }

        $decoded = json_decode($text, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Fallback terakhir: nggak ada fence sama sekali tapi masih ada
        // teks nyasar sebelum/sesudah objek JSON-nya - ambil dari '{'
        // pertama sampai '}' terakhir.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}