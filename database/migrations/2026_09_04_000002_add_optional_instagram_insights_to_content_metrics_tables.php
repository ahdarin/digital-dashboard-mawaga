<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL INSTAGRAM OPTIONAL INSIGHTS COMPLETENESS GATE - 4 metric baru
 * diverifikasi resmi tersedia (Meta reference v25.0, dicek 2026-09-03,
 * scope instagram_business_manage_insights yang SUDAH dipakai app ini,
 * TIDAK BUTUH scope baru):
 *
 * - watch_time_total (ig_reels_video_view_total_time, REELS only) - TOTAL
 *   agregat waktu tonton, DETIK (dikonversi dari ms Meta) - metric
 *   TERPISAH dari watch_time_avg (rata-rata) yang sudah ada, BUKAN
 *   overload kolom yang sama dengan makna beda (Part 5/7).
 * - skip_rate (reels_skip_rate, REELS only) - rasio/persentase, CURRENT_RATE,
 *   TIDAK PERNAH di-delta (sama seperti engagement_rate SENDIRI tidak
 *   pernah jadi input delta, itu HASIL kalkulasi periode, bukan raw
 *   stored rate).
 * - profile_activity (FEED only) - sum breakdown aktivitas profil dari
 *   post ini, CURRENT_CUMULATIVE.
 * - attributed_follows (follows metric, FEED only) - follow baru yang
 *   diatribusikan ke POST SPESIFIK ini (BUKAN total follower akun - itu
 *   domain AudienceInsight/ApiIntegration, nama kolom SENGAJA
 *   "attributed_follows" bukan cuma "follows" biar tidak rancu dengan
 *   follower_count akun), CURRENT_CUMULATIVE.
 *
 * profile_visits (metric Meta) SENGAJA TIDAK dapat kolom baru - reuse
 * kolom `profile_visit` yang SUDAH ADA sejak migration awal (2026_07_13,
 * selama ini cuma diisi lewat CSV import manual, tidak pernah lewat sync
 * API) - makna PERSIS sama, sumber beda, bukan overload (Part 5/7).
 *
 * Additive, nullable semua, di KEDUA tabel (content_metrics = current/
 * latest, content_metric_snapshots = observasi harian) - konsisten
 * dengan pola profile_visit/watch_time_avg yang sudah ada di keduanya.
 * TIDAK ada kolom baru buat facebook_views/crossposted_views
 * (CONDITIONAL_NOT_APPLICABLE - produk ini tidak punya konsep platform
 * Facebook sama sekali) atau clips_replays_count/
 * ig_reels_aggregated_all_plays_count (DEPRECATED, dipensiunkan Meta
 * Jan-Apr 2025, digantikan metric `views` terpadu yang sudah dipakai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_metrics', function (Blueprint $table) {
            $table->integer('watch_time_total')->nullable()->after('watch_time_avg');
            $table->decimal('skip_rate', 5, 2)->nullable()->after('completion_rate');
            $table->integer('profile_activity')->nullable()->after('profile_visit');
            $table->integer('attributed_follows')->nullable()->after('profile_activity');
        });

        Schema::table('content_metric_snapshots', function (Blueprint $table) {
            $table->unsignedInteger('watch_time_total')->nullable()->after('watch_time_avg');
            $table->decimal('skip_rate', 5, 2)->nullable()->after('completion_rate');
            $table->unsignedBigInteger('profile_activity')->nullable()->after('profile_visit');
            $table->unsignedBigInteger('attributed_follows')->nullable()->after('profile_activity');
        });
    }

    public function down(): void
    {
        Schema::table('content_metrics', function (Blueprint $table) {
            $table->dropColumn(['watch_time_total', 'skip_rate', 'profile_activity', 'attributed_follows']);
        });

        Schema::table('content_metric_snapshots', function (Blueprint $table) {
            $table->dropColumn(['watch_time_total', 'skip_rate', 'profile_activity', 'attributed_follows']);
        });
    }
};
