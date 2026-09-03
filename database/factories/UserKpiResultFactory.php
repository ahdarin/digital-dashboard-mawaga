<?php

namespace Database\Factories;

use App\Enums\CoverageStatus;
use App\Enums\KpiStatusLabel;
use App\Models\KpiCalculationRun;
use App\Models\User;
use App\Models\UserKpiResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserKpiResult>
 */
class UserKpiResultFactory extends Factory
{
    protected $model = UserKpiResult::class;

    public function definition(): array
    {
        return [
            'kpi_calculation_run_id' => KpiCalculationRun::factory(),
            'user_id' => User::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'coverage_status' => CoverageStatus::Full,
            'sample_size' => 10,
            'status_label' => KpiStatusLabel::Sehat,
        ];
    }
}
