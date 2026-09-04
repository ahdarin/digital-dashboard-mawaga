<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Platform extends Model
{
    protected $fillable = ['name'];

    public function contentItems() { return $this->hasMany(ContentItem::class); }
    public function publications() { return $this->hasMany(ContentPublication::class); }
    public function audienceInsights() { return $this->hasMany(AudienceInsight::class); }
    public function apiIntegrations() { return $this->hasMany(ApiIntegration::class); }
    public function analyticsSyncLogs() { return $this->hasMany(AnalyticsSyncLog::class); }
    public function contentMetrics() { return $this->hasMany(ContentMetric::class); }
    public function contentMetricSnapshots() { return $this->hasMany(ContentMetricSnapshot::class); }
    public function aiStrategyInsights() { return $this->hasMany(AiStrategyInsight::class); }

    /**
     * SYSTEM CONSISTENCY PASS (Part L) - SATU-SATUNYA tempat nama route
     * "Hubungkan Konten"/unmatched-management dipetakan dari nama platform
     * baris data (BUKAN filter global/asumsi/hardcode Instagram) - dulu
     * SEMUA link "Hubungkan Konten" di Analytics hardcode ke
     * publishing-tracker.instagram.unmatched, jadi baris TikTok 404
     * (ContentPublicationController::unmatchedInstagram() abort_unless
     * platform-nya Instagram). $platformName ambil dari kolom platform
     * BARIS ITU SENDIRI (mis. $row->platform / $content['platform']),
     * bukan dari filter platform_id yang sedang aktif di halaman.
     */
    public static function unmatchedTrackerRouteName(?string $platformName): string
    {
        return $platformName === 'TikTok'
            ? 'publishing-tracker.tiktok.unmatched'
            : 'publishing-tracker.instagram.unmatched';
    }
}
