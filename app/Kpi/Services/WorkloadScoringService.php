<?php

namespace App\Kpi\Services;

use App\Kpi\Support\RobustStats;
use App\Models\ContentItemAssignment;
use App\Support\WorkflowTransitions;
use Illuminate\Support\Collection;

/**
 * Beban kerja AKTIF (docs/kpi/PROCESS_METRICS.md "Aturan Process KPI"):
 * draft/uploaded/cancelled BUKAN active workload.
 *
 * Koreksi produk 2026-09-02: dihitung dari `content_item_assignments`
 * EXISTING (tabel `content_item_operational_assignments` beserta kolom
 * `planned_effort_points` sudah dihapus - "jangan membuat proses assignment
 * khusus KPI"). TIDAK ADA kolom bobot/effort di `content_item_assignments`,
 * jadi beban kerja dihitung sebagai JUMLAH content item aktif (unweighted) -
 * satu-satunya angka yang bisa diturunkan murni dari data existing tanpa
 * mengarang bobot baru.
 */
class WorkloadScoringService
{
    /**
     * Jumlah content item AKTIF (bukan draft/uploaded/cancelled) yang
     * user ini jadi PIC-nya.
     */
    public function activeWorkloadCount(int $userId): int
    {
        return ContentItemAssignment::where('user_id', $userId)
            ->whereHas('contentItem.workflow', fn ($q) => $q->whereNotIn('current_status', WorkflowTransitions::INACTIVE_STATUSES))
            ->count();
    }

    /**
     * Workload imbalance TIM - rasio beban user paling berat terhadap MEDIAN
     * beban tim (bukan mean, robust terhadap satu orang yang kebetulan
     * sangat ringan/berat). 1.0 = merata sempurna, semakin besar semakin
     * timpang.
     *
     * @param  Collection<int, int>  $userIds
     * @return array{ratio: ?float, workloads: array<int, int>, median: ?float}
     */
    public function teamImbalance(Collection $userIds): array
    {
        $workloads = $userIds->mapWithKeys(fn (int $userId) => [$userId => $this->activeWorkloadCount($userId)]);

        if ($workloads->isEmpty()) {
            return ['ratio' => null, 'workloads' => [], 'median' => null];
        }

        $median = RobustStats::median($workloads->values()->all());
        $max = $workloads->max();

        return [
            'ratio' => $median > 0 ? round($max / $median, 2) : ($max > 0 ? null : 1.0),
            'workloads' => $workloads->all(),
            'median' => $median,
        ];
    }
}
