<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentPublication extends Model
{
    use HasFactory;

    public const RECORDED_VIA_MANUAL = 'manual';

    public const RECORDED_VIA_AUTO_SYNC = 'auto_sync';

    protected $fillable = [
        'content_item_id', 'platform_id', 'published_by', 'recorded_via',
        'published_at', 'post_url', 'thumbnail_url', 'caption_final',
        'external_post_id', 'api_integration_id',
        'is_paid', 'promotion_type', 'ad_spend', 'campaign_reference',
    ];
    protected $casts = [
        'published_at' => 'datetime',
        'is_paid' => 'boolean',
        'ad_spend' => 'decimal:2',
    ];

    /**
     * KPI outcome scoring (Fase 2) mengecualikan publication paid dari peer
     * group organic - lihat docs/kpi/ANALYTICS_NORMALIZATION.md.
     */
    public function isOrganic(): bool
    {
        return ! $this->is_paid;
    }

    /**
     * Koreksi lanjutan KPI 2026-09-02 - `published_by` HANYA aktor
     * terpercaya kalau baris ini dicatat lewat aksi manusia langsung
     * (Record Publication / link media unmatched). Baris yang dibuat
     * otomatis saat sync (`recorded_via=auto_sync`) - `published_by` cuma
     * user yang KEBETULAN memicu sync, BUKAN publisher asli - TIDAK
     * dipakai untuk atribusi KPI SMO (lihat RoleProcessKpiService::
     * scoreSmo(), KpiRoleContextResolver, docs/kpi/ATTRIBUTION_RULES.md).
     */
    public function isReliablyAttributedToPublisher(): bool
    {
        return $this->recorded_via === self::RECORDED_VIA_MANUAL;
    }

    public function contentItem() { return $this->belongsTo(ContentItem::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
    public function publishedBy() { return $this->belongsTo(User::class, 'published_by'); }
    public function apiIntegration() { return $this->belongsTo(ApiIntegration::class); }
}