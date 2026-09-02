<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASS 1B - TikTok user/info/ stats scope (user.info.stats, SUDAH
 * di-request app ini - lihat TikTokAnalyticsService::USER_INFO_FIELDS_STATS)
 * balikin following_count/likes_count/video_count SELAIN follower_count
 * yang sudah dipersist - dua-duanya sebelumnya diminta lalu DIBUANG
 * (tidak pernah ditulis ke audience_insights). Kolom TikTok-only (Instagram
 * tidak punya field setara lewat scope yang dipakai app ini) - SELALU NULL
 * buat baris Instagram, TIDAK PERNAH fabricated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_insights', function (Blueprint $table) {
            $table->unsignedInteger('following_count')->nullable()->after('follower_count');
            $table->unsignedBigInteger('likes_count')->nullable()->after('following_count');
            $table->unsignedInteger('video_count')->nullable()->after('likes_count');
        });
    }

    public function down(): void
    {
        Schema::table('audience_insights', function (Blueprint $table) {
            $table->dropColumn(['following_count', 'likes_count', 'video_count']);
        });
    }
};
