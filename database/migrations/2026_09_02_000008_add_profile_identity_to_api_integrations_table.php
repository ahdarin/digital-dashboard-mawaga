<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASS 1B - "COMPLETE INSTAGRAM/TIKTOK COVERAGE GAP". Identitas profil
 * eksternal yang genuinely stabil (bukan analytics/metrik harian) - level
 * ApiIntegration (1 baris per client+platform), BUKAN time-series, karena
 * ini fakta "identitas akun saat ini", bukan observasi bertanggal.
 *
 * Semua kolom nullable, ditulis best-effort tiap sync/connect sukses -
 * platform yang tidak menyediakan field tertentu (Instagram tidak expose
 * bio/verified/profile_deep_link lewat scope yang dipakai app ini) SELALU
 * NULL, TIDAK PERNAH fabricated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->string('external_display_name')->nullable()->after('external_username');
            $table->text('external_avatar_url')->nullable()->after('external_display_name');
            $table->text('external_bio')->nullable()->after('external_avatar_url');
            $table->boolean('external_verified')->nullable()->after('external_bio');
            $table->text('external_profile_url')->nullable()->after('external_verified');
        });
    }

    public function down(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropColumn(['external_display_name', 'external_avatar_url', 'external_bio', 'external_verified', 'external_profile_url']);
        });
    }
};
