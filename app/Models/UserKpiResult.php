<?php

namespace App\Models;

use App\Enums\CoverageStatus;
use App\Enums\KpiStatusLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Hasil composite KPI per user per run - lihat docblock migration
 * `create_user_kpi_results_table` (koreksi 2026-09-02: `role_id` FK ke
 * `roles.id` EXISTING - bukan tabel operational role terpisah, lihat
 * docs/kpi/ATTRIBUTION_RULES.md).
 */
class UserKpiResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_calculation_run_id', 'user_id', 'role_id', 'client_id',
        'period_start', 'period_end', 'process_score', 'direct_outcome_score',
        'portfolio_outcome_score', 'composite_score', 'coverage_status',
        'sample_size', 'status_label', 'component_breakdown',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'process_score' => 'decimal:2',
        'direct_outcome_score' => 'decimal:2',
        'portfolio_outcome_score' => 'decimal:2',
        'composite_score' => 'decimal:2',
        'coverage_status' => CoverageStatus::class,
        'status_label' => KpiStatusLabel::class,
        'component_breakdown' => 'array',
    ];

    public function calculationRun()
    {
        return $this->belongsTo(KpiCalculationRun::class, 'kpi_calculation_run_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Access role EXISTING (`roles` table) dipakai sebagai label konteks KPI - bukan authorization. */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeForRole(Builder $query, int $roleId): Builder
    {
        return $query->where('role_id', $roleId);
    }

    public function scopeLeadershipSummary(Builder $query): Builder
    {
        return $query->whereNull('client_id');
    }
}
