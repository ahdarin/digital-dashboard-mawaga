<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok for Developers OAuth 2.0 mengembalikan refresh_token dengan masa
 * berlaku SENDIRI (terpisah dari access_token, ~365 hari) dan daftar scope
 * yang benar-benar disetujui user - dua hal yang TIDAK ADA di kontrak
 * Instagram (Instagram cuma "long-lived token" yang di-refresh dengan
 * TOKEN ITU SENDIRI, bukan refresh_token terpisah). Additive, nullable -
 * kolom existing (access_token, refresh_token, access_token_expires_at,
 * dst dari migration Instagram sebelumnya) tetap dipakai apa adanya, TIDAK
 * disentuh sama sekali (zero regression risk buat integrasi Instagram).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->timestamp('refresh_token_expires_at')->nullable()->after('access_token_expires_at');
            // CSV daftar scope yang benar-benar disetujui user pas OAuth
            // (mis. "user.info.basic,video.list") - dipakai buat tahu scope
            // opsional (user.info.stats) granted atau tidak TANPA perlu
            // nebak dari hasil API call.
            $table->string('scopes')->nullable()->after('refresh_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropColumn(['refresh_token_expires_at', 'scopes']);
        });
    }
};
