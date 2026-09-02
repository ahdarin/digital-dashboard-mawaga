<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASS 1B - height/width Video Object TikTok Display API v2 (field
 * dokumentasi resmi, scope video.list - field ID/create_time/dst yang
 * sudah dipakai app ini juga di bawah scope yang sama). Nilai stabil per
 * video (tidak pernah berubah setelah upload) - beda dari cover_image_url
 * yang TTL-nya terbatas, aman disimpan permanen begitu didapat sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_video_snapshots', function (Blueprint $table) {
            $table->unsignedInteger('height')->nullable()->after('duration');
            $table->unsignedInteger('width')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_video_snapshots', function (Blueprint $table) {
            $table->dropColumn(['height', 'width']);
        });
    }
};
