<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit KPI Fase 0: sistem belum punya penanda organic vs paid/promoted sama
 * sekali di manapun (content_publications, content_metrics, atau chain sync
 * Instagram/TikTok). Struktur minimal ditambahkan di sini - paid content
 * TIDAK BOLEH masuk peer group/baseline organic yang sama (lihat
 * ContentOutcomeScoringService, Fase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publications', function (Blueprint $table) {
            $table->boolean('is_paid')->default(false)->after('caption_final');
            $table->string('promotion_type')->nullable()->after('is_paid');
            $table->decimal('ad_spend', 12, 2)->nullable()->after('promotion_type');
            $table->string('campaign_reference')->nullable()->after('ad_spend');
        });
    }

    public function down(): void
    {
        Schema::table('content_publications', function (Blueprint $table) {
            $table->dropColumn(['is_paid', 'promotion_type', 'ad_spend', 'campaign_reference']);
        });
    }
};
