<?php

namespace Database\Factories;

use App\Models\KpiCalculationRun;
use App\Models\KpiFormulaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiCalculationRun>
 */
class KpiCalculationRunFactory extends Factory
{
    protected $model = KpiCalculationRun::class;

    public function definition(): array
    {
        return [
            'kpi_formula_version_id' => KpiFormulaVersion::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => KpiCalculationRun::STATUS_COMPLETED,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
