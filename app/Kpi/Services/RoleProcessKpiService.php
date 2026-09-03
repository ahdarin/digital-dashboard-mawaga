<?php

namespace App\Kpi\Services;

use App\Enums\CoverageStatus;
use App\Kpi\Support\RobustStats;
use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Process KPI per role EXISTING (docs/kpi/PROCESS_METRICS.md) - dibangun
 * dari ContentStatusLog, ContentRevision, ContentBriefDraft, ContentPublication
 * (semua tabel EXISTING, tidak ada tabel assignment KPI khusus).
 *
 * Koreksi produk 2026-09-02 yang dijaga di sini:
 * - First production handoff = transisi in_progress -> waiting_review
 *   (BUKAN brief_ready -> in_progress - itu "mulai kerja", bukan "handoff").
 * - Internal revision DIBATASI periode KPI (revisi dibuat DI DALAM
 *   [periodStart, periodEnd], bukan seluruh histori content item).
 * - Analytics match rate dihitung PER PUBLICATION/PLATFORM, bukan per
 *   content item.
 * - `approval_type='correction'` SELALU dikecualikan.
 * - Median/percentile, bukan average, untuk semua metrik durasi.
 * - Client revision (requested_by_client_id) TIDAK menurunkan skor individu.
 * - Metrik BERBASIS RATE (0-100) menyusun composite process_score; metrik
 *   BERBASIS DURASI bersifat informasional (tidak ada target SLA eksplisit).
 */
class RoleProcessKpiService
{
    public function scoreCopywriter(int $userId, Carbon $periodStart, Carbon $periodEnd, int $minSampleSize): \App\Kpi\Dto\ProcessScoreBreakdown
    {
        $briefs = ContentBriefDraft::where('created_by', $userId)
            ->whereBetween('created_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->get();

        $finalized = $briefs->where('status', 'finalized');

        $durations = $finalized
            ->filter(fn (ContentBriefDraft $b) => $b->finalized_at !== null)
            ->map(fn (ContentBriefDraft $b) => $b->created_at->diffInHours($b->finalized_at));

        $medianDurationHours = $durations->isNotEmpty() ? RobustStats::median($durations->all()) : null;

        $firstPassEligible = $finalized->count();
        $firstPassAccepted = $finalized->where('returned_count', 0)->count();
        $firstPassRate = $firstPassEligible > 0 ? round(($firstPassAccepted / $firstPassEligible) * 100, 2) : null;

        $metrics = [
            'median_brief_duration_hours' => [
                'value' => $medianDurationHours, 'unit' => 'hours',
                'coverage' => $durations->isNotEmpty() ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => $durations->count(), 'notes' => 'Informasional - belum ada target SLA eksplisit untuk dikonversi jadi skor.',
            ],
            'first_pass_acceptance_rate' => [
                'value' => $firstPassRate, 'unit' => 'percent',
                'coverage' => $firstPassEligible > 0 ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => $firstPassEligible, 'notes' => null,
            ],
        ];

        return $this->buildBreakdown($userId, $metrics, sampleSize: $firstPassEligible, minSampleSize: $minSampleSize);
    }

    public function scoreProductionRole(int $userId, Carbon $periodStart, Carbon $periodEnd, Collection $contentItemIds, int $minSampleSize): \App\Kpi\Dto\ProcessScoreBreakdown
    {
        $statusLogs = ContentStatusLog::whereIn('content_item_id', $contentItemIds)
            ->whereNull('approval_type') // exclude koreksi Manager/CEO
            ->whereBetween('changed_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->orderBy('changed_at')
            ->get()
            ->groupBy('content_item_id');

        $items = ContentItem::whereIn('id', $contentItemIds)->get()->keyBy('id');

        $handoffOnTimeFlags = [];
        $activeDurationsHours = [];

        foreach ($statusLogs as $itemId => $logs) {
            $item = $items->get($itemId);

            // First production handoff = in_progress -> waiting_review
            // (staf MENYERAHKAN hasil kerja untuk direview) - BUKAN
            // brief_ready -> in_progress (itu "mulai kerja", bukan "handoff").
            $firstHandoff = $logs->first(fn (ContentStatusLog $l) => $l->from_status === 'in_progress' && $l->to_status === 'waiting_review');

            if ($firstHandoff && $item?->deadline_at) {
                $handoffOnTimeFlags[] = $firstHandoff->changed_at->lte($item->deadline_at) ? 1 : 0;
            }

            // Active production duration: JUMLAHKAN SEMUA segmen in_progress
            // -> waiting_review (bisa lebih dari satu kalau ada siklus
            // revisi). Waktu di status 'revision'/'waiting_review' itu
            // sendiri TIDAK PERNAH ikut terhitung.
            $inProgressStarts = $logs->filter(fn (ContentStatusLog $l) => $l->to_status === 'in_progress')->values();

            foreach ($inProgressStarts as $start) {
                $nextWaitingReview = $logs->first(fn (ContentStatusLog $l) => $l->to_status === 'waiting_review' && $l->changed_at->gt($start->changed_at));
                if ($nextWaitingReview) {
                    $activeDurationsHours[] = $start->changed_at->diffInHours($nextWaitingReview->changed_at);
                }
            }
        }

        $handoffOnTimeRate = ! empty($handoffOnTimeFlags) ? round((array_sum($handoffOnTimeFlags) / count($handoffOnTimeFlags)) * 100, 2) : null;
        $medianActiveDuration = ! empty($activeDurationsHours) ? RobustStats::median($activeDurationsHours) : null;

        // Internal revision rate DIBATASI periode KPI - revisi yang dibuat
        // DI LUAR [periodStart, periodEnd] tidak ikut terhitung untuk
        // periode ini (koreksi: sebelumnya tidak dibatasi periode sama sekali).
        $internalRevisionItemIds = ContentRevision::whereIn('content_item_id', $contentItemIds)
            ->whereNotNull('requested_by_user_id')
            ->whereBetween('created_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->distinct()
            ->pluck('content_item_id');
        $internalRevisionRate = $contentItemIds->isNotEmpty()
            ? round(($internalRevisionItemIds->count() / $contentItemIds->count()) * 100, 2)
            : null;

        $metrics = [
            'first_handoff_on_time_rate' => [
                'value' => $handoffOnTimeRate, 'unit' => 'percent',
                'coverage' => ! empty($handoffOnTimeFlags) ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => count($handoffOnTimeFlags), 'notes' => null,
            ],
            'median_active_production_hours' => [
                'value' => $medianActiveDuration, 'unit' => 'hours',
                'coverage' => ! empty($activeDurationsHours) ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => count($activeDurationsHours), 'notes' => 'Informasional - waktu tunggu client TIDAK termasuk.',
            ],
            'internal_revision_rate' => [
                'value' => $internalRevisionRate !== null ? round(100 - $internalRevisionRate, 2) : null,
                'unit' => 'percent_inverse_revision_rate',
                'coverage' => $contentItemIds->isNotEmpty() ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => $contentItemIds->count(), 'notes' => 'Ditampilkan sebagai (100 - rasio revisi internal DI DALAM periode ini) - semakin tinggi semakin baik.',
            ],
        ];

        return $this->buildBreakdown($userId, $metrics, sampleSize: $contentItemIds->count(), minSampleSize: $minSampleSize);
    }

    /**
     * Koreksi lanjutan 2026-09-02 - $publications adalah publication yang
     * BENAR-BENAR dipublikasikan user ini sendiri (`published_by=$userId`,
     * `recorded_via='manual'` - lihat KpiRoleContextResolver::
     * smoActivities()), BUKAN seluruh publication pada content item di
     * mana user ini kebetulan jadi PIC. PIC yang bukan publisher asli
     * TIDAK IKUT metrik ini; SMO yang publish tapi bukan PIC tetap dapat
     * kredit penuh.
     *
     * @param  Collection<int, ContentPublication>  $publications
     */
    public function scoreSmo(int $userId, Carbon $periodStart, Carbon $periodEnd, Collection $publications, int $minSampleSize): \App\Kpi\Dto\ProcessScoreBreakdown
    {
        $scheduleAdherenceFlags = [];
        foreach ($publications->groupBy('content_item_id') as $itemPublications) {
            $item = $itemPublications->first()->contentItem;
            if (! $item?->scheduled_upload_at) {
                continue;
            }
            // Kalau user ini publish >1 platform pada content yang sama,
            // pakai publication PALING AWAL milik DIA SENDIRI (bukan
            // publication siapa pun pada item itu).
            $earliest = $itemPublications->sortBy('published_at')->first();
            $diffHours = abs($item->scheduled_upload_at->diffInHours($earliest->published_at));
            $scheduleAdherenceFlags[] = $diffHours <= 24 ? 1 : 0;
        }
        $adherenceRate = ! empty($scheduleAdherenceFlags) ? round((array_sum($scheduleAdherenceFlags) / count($scheduleAdherenceFlags)) * 100, 2) : null;

        // Analytics match rate PER PUBLICATION/PLATFORM (koreksi - sebelumnya
        // per content item, kehilangan granularitas multi-platform: content
        // dengan Instagram matched + TikTok unmatched dulu dihitung "matched"
        // seluruhnya).
        $totalPublications = $publications->count();
        $matchedCount = $publications->filter(function (ContentPublication $pub) {
            return \App\Models\ContentMetric::where('content_item_id', $pub->content_item_id)
                ->where('platform_id', $pub->platform_id)
                ->exists();
        })->count();
        $matchedRate = $totalPublications > 0 ? round(($matchedCount / $totalPublications) * 100, 2) : null;

        $metrics = [
            'publication_schedule_adherence_rate' => [
                'value' => $adherenceRate, 'unit' => 'percent',
                'coverage' => ! empty($scheduleAdherenceFlags) ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => count($scheduleAdherenceFlags), 'notes' => null,
            ],
            'publication_analytics_match_rate' => [
                'value' => $matchedRate, 'unit' => 'percent',
                'coverage' => $totalPublications > 0 ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => $totalPublications, 'notes' => 'Dihitung per publication/platform yang BENAR-BENAR dipublikasikan user ini sendiri - proxy "unmatched publication/analytics rate", dibalik: tinggi = sedikit unmatched.',
            ],
        ];

        return $this->buildBreakdown($userId, $metrics, sampleSize: $publications->pluck('content_item_id')->unique()->count(), minSampleSize: $minSampleSize);
    }

    /**
     * Leadership Manager/CEO - decision turnaround HANYA dari approval/
     * decision yang BENAR-BENAR dilakukan user ini (content_status_logs,
     * changed_by_user_id = user). $contentItemIds sudah difilter caller ke
     * item yang benar-benar punya keputusan dari user ini.
     */
    public function scoreLeadership(int $userId, Carbon $periodStart, Carbon $periodEnd, Collection $contentItemIds, int $minSampleSize): \App\Kpi\Dto\ProcessScoreBreakdown
    {
        $decisionLogs = ContentStatusLog::whereIn('content_item_id', $contentItemIds)
            ->where('changed_by_user_id', $userId)
            ->whereIn('to_status', ['approved', 'revision'])
            ->whereNull('approval_type')
            ->whereBetween('changed_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->orderBy('changed_at')
            ->get()
            ->groupBy('content_item_id');

        $turnaroundHours = [];
        foreach ($decisionLogs as $itemId => $logs) {
            foreach ($logs as $decision) {
                $waitingReviewStart = ContentStatusLog::where('content_item_id', $itemId)
                    ->where('to_status', 'waiting_review')
                    ->where('changed_at', '<=', $decision->changed_at)
                    ->orderByDesc('changed_at')
                    ->first();

                if ($waitingReviewStart) {
                    $turnaroundHours[] = $waitingReviewStart->changed_at->diffInHours($decision->changed_at);
                }
            }
        }

        $medianTurnaround = ! empty($turnaroundHours) ? RobustStats::median($turnaroundHours) : null;

        // Item dilepas tanpa PIC - proxy dari content_item_assignments kosong
        // pada content yang sudah brief_ready ke atas.
        $itemsWithoutPic = ContentItem::whereIn('id', $contentItemIds)
            ->whereDoesntHave('assignments')
            ->whereHas('workflow', fn ($q) => $q->where('current_status', '!=', 'draft'))
            ->count();
        $noPicRate = $contentItemIds->isNotEmpty() ? round((1 - $itemsWithoutPic / $contentItemIds->count()) * 100, 2) : null;

        $metrics = [
            'median_decision_turnaround_hours' => [
                'value' => $medianTurnaround, 'unit' => 'hours',
                'coverage' => ! empty($turnaroundHours) ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => count($turnaroundHours), 'notes' => 'Informasional - belum ada target SLA eksplisit.',
            ],
            'pic_assignment_completeness_rate' => [
                'value' => $noPicRate, 'unit' => 'percent',
                'coverage' => $contentItemIds->isNotEmpty() ? CoverageStatus::Full : CoverageStatus::Unavailable,
                'sample_size' => $contentItemIds->count(), 'notes' => 'Persentase content TANPA PIC assignment kosong (semakin tinggi semakin baik).',
            ],
        ];

        return $this->buildBreakdown($userId, $metrics, sampleSize: $contentItemIds->count(), minSampleSize: $minSampleSize);
    }

    /**
     * @param  array<string, array{value: mixed, unit: ?string, coverage: CoverageStatus, sample_size: int, notes: ?string}>  $metrics
     */
    private function buildBreakdown(int $userId, array $metrics, int $sampleSize, int $minSampleSize): \App\Kpi\Dto\ProcessScoreBreakdown
    {
        $rateValues = array_filter(
            $metrics,
            fn ($m) => $m['unit'] !== null && str_contains($m['unit'], 'percent') && $m['value'] !== null
        );

        $overallCoverage = CoverageStatus::weakest(...array_column($metrics, 'coverage'));

        if ($sampleSize < $minSampleSize || empty($rateValues)) {
            return new \App\Kpi\Dto\ProcessScoreBreakdown($userId, $metrics, null, $overallCoverage, $sampleSize);
        }

        $processScore = RobustStats::clampScore(
            array_sum(array_column($rateValues, 'value')) / count($rateValues)
        );

        return new \App\Kpi\Dto\ProcessScoreBreakdown($userId, $metrics, $processScore, $overallCoverage, $sampleSize);
    }
}
