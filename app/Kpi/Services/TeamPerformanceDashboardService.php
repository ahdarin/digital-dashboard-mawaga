<?php

namespace App\Kpi\Services;

use App\Enums\KpiStatusLabel;
use App\Kpi\Support\RobustStats;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentOutcomeResult;
use App\Models\ContentPlan;
use App\Models\ContentStatusLog;
use App\Models\KpiCalculationRun;
use App\Models\Role;
use App\Models\User;
use App\Models\UserKpiResult;
use App\Support\WorkflowTransitions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Application service KHUSUS untuk kebutuhan tampilan Team Performance -
 * TIDAK melakukan kalkulasi KPI apa pun sendiri (itu tanggung jawab
 * KpiCalculationService, dipicu otomatis di latar belakang - lihat
 * docs/kpi/JOBS_AND_OPERATIONS.md). Service ini murni MEMBACA hasil yang
 * sudah dipersist (`user_kpi_results`/`content_outcome_results`) dan
 * menyusunnya untuk kebutuhan controller/view.
 *
 * Koreksi produk 2026-09-02: `role_id` FK ke `roles.id` EXISTING (bukan
 * tabel role baru) - lihat docs/kpi/ATTRIBUTION_RULES.md. Koreksi lanjutan
 * (#4): `client_id` SEKARANG selalu terisi untuk baris operasional
 * (Copywriter/Content Creator/Graphic Designer/SMO), bukan cuma leadership -
 * filter klien menampilkan seluruh role, bukan cuma leadership.
 */
class TeamPerformanceDashboardService
{

    /**
     * Run TERBARU yang completed untuk periode ini - "current" run per
     * definisi KpiCalculationRun (lihat docblock model).
     */
    public function latestCompletedRun(Carbon $periodStart, Carbon $periodEnd): ?KpiCalculationRun
    {
        return KpiCalculationRun::completed()
            ->forPeriod($periodStart, $periodEnd)
            ->latest('finished_at')
            ->first();
    }

    /**
     * Run completed TERBARU untuk PERIODE APA PUN - dipakai sebagai fallback
     * "snapshot sebelumnya" (Fase 4: "tampilkan snapshot sebelumnya jika ada")
     * kalau periode yang diminta belum pernah dihitung sama sekali.
     */
    public function latestCompletedRunAnyPeriod(): ?KpiCalculationRun
    {
        return KpiCalculationRun::completed()->latest('finished_at')->first();
    }

    /**
     * Run dianggap stale kalau lebih tua dari $freshnessMinutes - dipakai
     * controller untuk memutuskan apakah perlu men-dispatch kalkulasi ulang
     * saat halaman dibuka (Fase 4: TIDAK PERNAH mensyaratkan user menjalankan
     * command manual - background job yang dipicu otomatis).
     */
    public function isStale(?KpiCalculationRun $run, int $freshnessMinutes = 30): bool
    {
        return $run === null || $run->finished_at === null || $run->finished_at->lt(now()->subMinutes($freshnessMinutes));
    }

    /**
     * Baris per (user, role). SATU baris per kombinasi, TIDAK PERNAH satu
     * overall score lintas role (larangan eksplisit UI).
     *
     * @return Collection<int, UserKpiResult>
     */
    public function memberRows(KpiCalculationRun $run, array $filters = []): Collection
    {
        return UserKpiResult::where('kpi_calculation_run_id', $run->id)
            ->when($filters['role_id'] ?? null, fn ($q, $v) => $q->where('role_id', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['coverage_status'] ?? null, fn ($q, $v) => $q->where('coverage_status', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->with(['user', 'role', 'client'])
            ->orderBy('user_id')
            ->get();
    }

    /**
     * Semua baris (lintas role/client) untuk SATU user - dasar tab "Anggota"
     * detail per user (mendukung satu user beberapa role, beberapa klien).
     *
     * @return Collection<int, UserKpiResult>
     */
    public function resultsForUser(KpiCalculationRun $run, User $user): Collection
    {
        return UserKpiResult::where('kpi_calculation_run_id', $run->id)
            ->where('user_id', $user->id)
            ->with(['role', 'client'])
            ->get();
    }

    /**
     * Detail content outcome yang berkontribusi pada satu baris UserKpiResult
     * tertentu - dibaca LANGSUNG dari `component_breakdown` yang sudah
     * dipersist saat kalkulasi (`contributing_content_item_ids`/
     * `leadership_decided_content_item_ids`), BUKAN diturunkan ulang secara
     * live dari assignment/role saat ini (koreksi lanjutan 2026-09-02: lebih
     * akurat untuk audit - breakdown ini snapshot APA ADANYA saat kalkulasi
     * berjalan, tidak berubah walau assignment/role user berubah belakangan).
     *
     * @return Collection<int, ContentOutcomeResult>
     */
    public function contentOutcomesForResult(UserKpiResult $result): Collection
    {
        $breakdown = $result->component_breakdown ?? [];
        $contentItemIds = collect($breakdown['contributing_content_item_ids'] ?? [])
            ->merge($breakdown['leadership_decided_content_item_ids'] ?? [])
            ->unique();

        if ($contentItemIds->isEmpty()) {
            return collect();
        }

        return ContentOutcomeResult::where('kpi_calculation_run_id', $result->kpi_calculation_run_id)
            ->whereIn('content_item_id', $contentItemIds)
            ->with('contentItem.client')
            ->get();
    }

    /**
     * Daftar role yang PUNYA hasil di run ini - dipakai filter dropdown,
     * supaya tidak menampilkan role yang memang tidak relevan pada periode ini.
     */
    public function rolesWithResults(KpiCalculationRun $run): Collection
    {
        $roleIds = UserKpiResult::where('kpi_calculation_run_id', $run->id)
            ->whereNotNull('role_id')
            ->distinct()
            ->pluck('role_id');

        return Role::whereIn('id', $roleIds)->orderBy('name')->get();
    }

    /**
     * Ringkasan tim (tab "Ringkasan Tim") - HANYA angka yang benar-benar bisa
     * diturunkan dari user_kpi_results/content_outcome_results run ini,
     * tanpa mengarang. Coverage banner ikut disertakan supaya UI tidak perlu
     * menghitung ulang.
     */
    public function teamSummary(KpiCalculationRun $run, Collection $memberRows): array
    {
        $withScore = $memberRows->filter(fn (UserKpiResult $r) => $r->composite_score !== null && $r->status_label !== KpiStatusLabel::DataBelumCukup);
        $withProcess = $memberRows->filter(fn (UserKpiResult $r) => $r->process_score !== null);

        return [
            'run' => $run,
            'formula_version' => $run->formulaVersion->version,
            'total_rows' => $memberRows->count(),
            'rows_with_composite_score' => $withScore->count(),
            'rows_data_belum_cukup' => $memberRows->where('status_label', KpiStatusLabel::DataBelumCukup)->count(),
            'rows_sementara' => $memberRows->where('status_label', KpiStatusLabel::Sementara)->count(),
            'rows_perlu_perhatian' => $memberRows->where('status_label', KpiStatusLabel::PerluPerhatian)->count(),
            'rows_sehat' => $memberRows->where('status_label', KpiStatusLabel::Sehat)->count(),
            'avg_process_score' => $withProcess->isNotEmpty() ? round($withProcess->avg('process_score'), 1) : null,
            'period_start' => $run->period_start,
            'period_end' => $run->period_end,
            'quota_fulfillment' => $this->quotaFulfillment($run->period_start, $run->period_end),
            'handoff_on_time_rate' => $this->companyRateFromBreakdown($memberRows, 'first_handoff_on_time_rate'),
            'publication_adherence_rate' => $this->companyRateFromBreakdown($memberRows, 'publication_schedule_adherence_rate'),
            'workload' => $this->workloadSummary(),
            'bottleneck' => $this->bottleneckStage($run->period_start, $run->period_end),
            'active_blockers' => $this->activeBlockerCount(),
        ];
    }

    /**
     * Pemenuhan kuota paket - dari ContentPlan bulan ini + ClientPackage
     * quota (fitur existing, tidak diubah) vs content item yang SUDAH
     * dilepas ke produksi (bukan draft).
     */
    private function quotaFulfillment(Carbon $periodStart, Carbon $periodEnd): array
    {
        $plans = ContentPlan::where('year', $periodStart->year)
            ->where('month', $periodStart->month)
            ->with('clientPackage')
            ->get();

        $quota = $plans->sum(fn (ContentPlan $p) => ($p->clientPackage->monthly_content_quota ?? 0) + ($p->clientPackage->monthly_design_quota ?? 0));

        if ($quota <= 0) {
            return ['quota' => 0, 'released' => 0, 'rate' => null];
        }

        $released = ContentItem::whereIn('content_plan_id', $plans->pluck('id'))
            ->whereHas('workflow', fn ($q) => $q->where('current_status', '!=', 'draft'))
            ->count();

        return ['quota' => $quota, 'released' => $released, 'rate' => round(min($released / $quota, 1) * 100, 1)];
    }

    /**
     * Rata-rata satu metrik rate (mis. first_handoff_on_time_rate) lintas
     * SELURUH baris user_kpi_results run ini - dibaca dari component_breakdown
     * yang sudah dipersist (bukan query ulang), jadi tidak menambah beban
     * query untuk ringkasan tim.
     */
    private function companyRateFromBreakdown(Collection $memberRows, string $metricKey): ?float
    {
        $values = $memberRows
            ->map(fn (UserKpiResult $r) => $r->component_breakdown['process'][$metricKey]['value'] ?? null)
            ->filter(fn ($v) => $v !== null);

        return $values->isNotEmpty() ? round($values->avg(), 1) : null;
    }

    private function workloadSummary(): array
    {
        $userIds = ContentItemAssignment::whereHas('contentItem.workflow', fn ($q) => $q->whereNotIn('current_status', WorkflowTransitions::INACTIVE_STATUSES))
            ->distinct()
            ->pluck('user_id');

        return app(WorkloadScoringService::class)->teamImbalance($userIds);
    }

    /**
     * Tahap dengan median durasi TERLAMA (dari ContentStatusLog transisi
     * yang tercatat di periode ini, exclude koreksi) - proxy "bottleneck".
     */
    private function bottleneckStage(Carbon $periodStart, Carbon $periodEnd): ?array
    {
        $logs = ContentStatusLog::whereNull('approval_type')
            ->whereBetween('changed_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->orderBy('content_item_id')
            ->orderBy('changed_at')
            ->get(['content_item_id', 'from_status', 'to_status', 'changed_at'])
            ->groupBy('content_item_id');

        $durationsByStage = [];
        foreach ($logs as $itemLogs) {
            $sorted = $itemLogs->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $prev = $sorted[$i - 1];
                $curr = $sorted[$i];
                $stageKey = "{$prev->to_status} -> {$curr->to_status}";
                $durationsByStage[$stageKey][] = $prev->changed_at->diffInHours($curr->changed_at);
            }
        }

        if (empty($durationsByStage)) {
            return null;
        }

        $medians = [];
        foreach ($durationsByStage as $stage => $durations) {
            $medians[$stage] = RobustStats::median($durations);
        }

        arsort($medians);
        $slowestStage = array_key_first($medians);

        return ['stage' => $slowestStage, 'median_hours' => $medians[$slowestStage]];
    }

    /**
     * Proxy "active blocker" - content item aktif yang sudah ditandai
     * overdue (is_overdue, kolom derived existing).
     */
    private function activeBlockerCount(): int
    {
        return ContentItem::whereHas('workflow', fn ($q) => $q->where('is_overdue', true)
            ->whereNotIn('current_status', WorkflowTransitions::INACTIVE_STATUSES))
            ->count();
    }
}
