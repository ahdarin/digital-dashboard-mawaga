<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASS 1 MICRO-FIX - is_aigc (AI-generated-content flag), field resmi
 * TikTok Display API v2 Video Object (video.list/video.query, scope
 * video.list yang sudah dipakai app ini). Nullable - absen di response
 * (provider belum roll out field ini buat akun/video tertentu, ATAU app
 * ini belum genuinely diverifikasi punya akses ke field ini) TETAP null,
 * TIDAK PERNAH ditebak jadi false ("bukan AI-generated" adalah klaim yang
 * TIDAK BISA dibuktikan cuma dari ketiadaan field).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_video_snapshots', function (Blueprint $table) {
            $table->boolean('is_aigc')->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_video_snapshots', function (Blueprint $table) {
            $table->dropColumn('is_aigc');
        });
    }
};
