<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL API COVERAGE GATE (Part 3, "IMPLEMENT USEFUL GAPS") -
 * account_type & media_count SUDAH SELALU ada di response Instagram
 * getProfile() (fields=id,username,name,account_type,media_count,
 * profile_picture_url) SEJAK Pass 1B - field ini di-fetch dengan
 * PANGGILAN API YANG SAMA PERSIS (nol biaya API tambahan, nol scope
 * tambahan), TAPI tidak pernah dipersist, cuma dibuang. Ini genuinely
 * "silently ignored" bukan karena tidak diminta, tapi karena tidak
 * disimpan setelah diminta - gap paling aman untuk ditutup (field paling
 * stabil di Graph API, sudah bertahun-tahun tidak berubah namanya).
 *
 * account_type berguna sebagai diagnostik (BUSINESS vs MEDIA_CREATOR -
 * sebagian insight metric Meta berbeda ketersediaannya per tipe akun).
 * media_count murni informational ("berapa post di akun ini secara
 * total") - snapshot-in-time, disegarkan ulang tiap sync (bukan
 * dianggap sebagai metric performa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->string('external_account_type')->nullable()->after('external_verified');
            $table->unsignedInteger('external_media_count')->nullable()->after('external_account_type');
        });
    }

    public function down(): void
    {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropColumn(['external_account_type', 'external_media_count']);
        });
    }
};
