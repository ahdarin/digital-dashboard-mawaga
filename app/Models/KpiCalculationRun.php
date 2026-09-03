<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jejak eksekusi kalkulasi KPI - lihat docblock migration
 * `create_kpi_calculation_runs_table`.
 */
class KpiCalculationRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'kpi_formula_version_id', 'period_start', 'period_end',
        'status', 'started_at', 'finished_at', 'triggered_by', 'error_message',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function formulaVersion()
    {
        return $this->belongsTo(KpiFormulaVersion::class, 'kpi_formula_version_id');
    }

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function contentOutcomeResults(): HasMany
    {
        return $this->hasMany(ContentOutcomeResult::class);
    }

    public function userKpiResults(): HasMany
    {
        return $this->hasMany(UserKpiResult::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForPeriod(Builder $query, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): Builder
    {
        return $query
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd);
    }
}
