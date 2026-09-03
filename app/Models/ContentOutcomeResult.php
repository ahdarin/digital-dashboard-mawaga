<?php

namespace App\Models;

use App\Enums\ContentFormatGroup;
use App\Enums\CoverageStatus;
use App\Enums\MeasurementWindow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Hasil scoring outcome per content item per measurement window per run -
 * lihat docblock migration `create_content_outcome_results_table`.
 */
class ContentOutcomeResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_calculation_run_id', 'content_item_id', 'format_group',
        'measurement_window', 'coverage_status', 'peer_sample_size',
        'peer_group_key', 'normalized_score', 'component_scores',
        'raw_metrics', 'exclusion_reason', 'computed_at',
    ];

    protected $casts = [
        'format_group' => ContentFormatGroup::class,
        'measurement_window' => MeasurementWindow::class,
        'coverage_status' => CoverageStatus::class,
        'normalized_score' => 'decimal:2',
        'component_scores' => 'array',
        'raw_metrics' => 'array',
        'computed_at' => 'datetime',
    ];

    public function calculationRun()
    {
        return $this->belongsTo(KpiCalculationRun::class, 'kpi_calculation_run_id');
    }

    public function contentItem()
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->coverage_status, [CoverageStatus::Full, CoverageStatus::Partial], true)
            && $this->normalized_score !== null;
    }
}
