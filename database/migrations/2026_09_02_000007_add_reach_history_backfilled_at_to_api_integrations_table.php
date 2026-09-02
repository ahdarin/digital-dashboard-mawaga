<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics V2 Phase B - "INSTAGRAM REACH HISTORY" - idempotent marker biar
 * InstagramAudienceInsightsService::backfillReachHistory() (genuine
 * historical reach s/d 180 hari, sudah ada & terbukti sebelumnya) HANYA
 * dijalankan SEKALI per integration secara otomatis saat baru connect
 * (bukan tombol manual yang di-expose ke user - lihat
 * InstagramIntegrationController::callback()). Nullable + additive murni -
 * integration lama (belum pernah backfill) otomatis NULL, TIDAK
 * mengasumsikan/fabricate kapan backfill "seharusnya" sudah terjadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->timestamp('reach_history_backfilled_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropColumn('reach_history_backfilled_at');
        });
    }
};
