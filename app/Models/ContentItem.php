<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'content_plan_id',
        'provisional_code',
        'is_urgent',
        'client_id',
        'content_pillar_id',
        'content_type_id',
        'content_format',
        'content_format_id',
        'platform_id',
        'title',
        'brief',
        'reference_link',
        'caption_draft',
        'deadline_at',
        'upload_deadline_at',
        'footage_captured_at',
        'content_file_link',
        'scheduled_upload_at',
        'is_posted',
        'ai_strategy_insight_id',
        'estimated_duration_seconds',
        'estimated_slide_count',
        'import_source',
        'import_batch_id',
        'external_reference',
        'external_pic_name',
        'external_pic_email',
    ];

    protected $casts = [
        'deadline_at' => 'datetime',
        'upload_deadline_at' => 'datetime',
        'footage_captured_at' => 'datetime',
        'scheduled_upload_at' => 'datetime',
        'is_posted' => 'boolean',
        'is_urgent' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
    public function contentType()
    {
        return $this->belongsTo(ContentType::class);
    }
    /**
     * "Dalam format apa konten dipublikasikan?" - master baru, TERPISAH
     * dari contentType() ("bagaimana konten dikerjakan?" - lihat
     * App\Services\ContentFormatResolver buat prioritas sumber kebenaran).
     * Nullable - item lama/belum diklasifikasi TETAP valid.
     */
    public function contentFormat()
    {
        return $this->belongsTo(ContentFormat::class);
    }
    public function contentPillar()
    {
        return $this->belongsTo(ContentPillar::class);
    }
    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
    /**
     * Multi-platform (baru) - item lama masih baca `platform()` (scalar).
     * Item baru dari alur Content Plan pakai ini; `platform_id` tetap
     * disinkronkan ke platform pertama yang dipilih untuk kompatibilitas
     * mundur (laporan/analytics/import lama yang masih baca kolom scalar).
     */
    public function platforms()
    {
        return $this->belongsToMany(Platform::class, 'content_item_platforms');
    }
    public function workflow()
    {
        return $this->hasOne(ContentWorkflow::class);
    }
    public function statusLogs()
    {
        return $this->hasMany(ContentStatusLog::class);
    }
    public function assignments()
    {
        return $this->hasMany(ContentItemAssignment::class);
    }
    public function revisions()
    {
        return $this->hasMany(ContentRevision::class);
    }
    public function publications()
    {
        return $this->hasMany(ContentPublication::class);
    }
    public function metrics()
    {
        return $this->hasMany(ContentMetric::class);
    }
    public function contentPlan()
    {
        return $this->belongsTo(ContentPlan::class);
    }
    public function aiStrategyInsight()
    {
        return $this->belongsTo(AiStrategyInsight::class);
    }

    public function delayRiskScores()
    {
        return $this->hasMany(DelayRiskScore::class);
    }
    public function contentBriefDraft()
    {
        return $this->hasOne(ContentBriefDraft::class);
    }
    public function latestDelayRisk()
    {
        return $this->hasOne(DelayRiskScore::class)->latestOfMany();
    }

    /**
     * Dipakai gate "Ajukan Rencana" (ContentPlanController::submit()) dan
     * badge "Brief belum diisi" di halaman Content Plan - satu sumber
     * kebenaran, lihat ContentBriefDraft::isComplete().
     */
    public function hasCompleteBrief(): bool
    {
        return (bool) $this->contentBriefDraft?->isComplete();
    }
}