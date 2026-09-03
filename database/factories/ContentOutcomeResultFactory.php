<?php

namespace Database\Factories;

use App\Enums\ContentFormatGroup;
use App\Enums\CoverageStatus;
use App\Enums\MeasurementWindow;
use App\Models\ContentItem;
use App\Models\ContentOutcomeResult;
use App\Models\KpiCalculationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentOutcomeResult>
 */
class ContentOutcomeResultFactory extends Factory
{
    protected $model = ContentOutcomeResult::class;

    public function definition(): array
    {
        return [
            'kpi_calculation_run_id' => KpiCalculationRun::factory(),
            'content_item_id' => ContentItem::factory(),
            'format_group' => ContentFormatGroup::Video,
            'measurement_window' => MeasurementWindow::D7,
            'coverage_status' => CoverageStatus::Full,
            'peer_sample_size' => 10,
            'normalized_score' => 60.0,
            'computed_at' => now(),
        ];
    }
}
