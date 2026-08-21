<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom buat OAuth "Connect Instagram" per client (menggantikan kebutuhan
 * developer edit .env tiap onboarding client baru). access_token/refresh_token
 * kolomnya sendiri sudah ada dari migration awal api_integrations - di sini
 * cuma nambah tanggal expired-nya biar bisa dijadwalin refresh otomatis
 * sebelum long-lived token Instagram (60 hari) kadaluarsa.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->timestamp('access_token_expires_at')->nullable()->after('last_error');
        });
    }

    public function down(): void {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropColumn('access_token_expires_at');
        });
    }
};
