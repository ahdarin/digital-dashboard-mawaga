<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror 2026_08_23_000001_add_snapshot_source_to_content_metrics_table.php
 * (yang menambahkan instagram_media_snapshot_id) - TAPI kolom TERPISAH,
 * BUKAN dipakai bareng. content_metrics jadi source-of-truth tunggal
 * analytics buat TikTok juga, termasuk video yang belum ke-link ke
 * ContentItem internal (post TikTok real, apapun status matching-nya).
 *
 * Additive murni, tidak menyentuh instagram_media_snapshot_id/kolom
 * Instagram lain sama sekali - zero regression risk. Unique key TERPISAH
 * (tiktok_video_snapshot_id, metric_date) dari yang Instagram pakai,
 * konsisten dengan pola "1 platform = 1 snapshot key" yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_metrics', function (Blueprint $table) {
            $table->foreignId('tiktok_video_snapshot_id')->nullable()->after('instagram_media_snapshot_id')
                ->constrained('tiktok_video_snapshots')->nullOnDelete();

            $table->unique(['tiktok_video_snapshot_id', 'metric_date'], 'content_metrics_tiktok_snapshot_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('content_metrics', function (Blueprint $table) {
            $table->dropUnique('content_metrics_tiktok_snapshot_date_unique');
            $table->dropConstrainedForeignId('tiktok_video_snapshot_id');
        });
    }
};
