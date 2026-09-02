<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix arsitektur: filter Performa 7/30/90 SEHARUSNYA berarti "performa yang
 * DIPEROLEH selama N hari terakhir" (delta cumulative API metric), bukan
 * "konten yang tanggal publish-nya ada di N hari terakhir" (bug lama -
 * content_metrics.metric_date dikunci ke tanggal publish, bukan tanggal
 * sync, jadi whereBetween('metric_date', [...]) sebenarnya memfilter
 * berdasarkan publish date).
 *
 * content_metrics TIDAK diubah sama sekali (tetap latest/current aggregate,
 * existing features - Dashboard/AI Strategy/Report/CSV export/Content
 * Detail - semua tetap baca dari sana apa adanya, zero regression risk).
 * Tabel ini PURELY ADDITIVE: 1 baris per (content, tanggal SYNC/observasi),
 * ditulis DI SAMPING content_metrics oleh sync service yang sama, dipakai
 * ContentMetricPeriodService buat hitung delta antar snapshot per periode.
 *
 * Unique key TERPISAH per platform (instagram_media_snapshot_id vs
 * tiktok_video_snapshot_id, sama pola dengan content_metrics existing) -
 * upsert per (snapshot_id, snapshot_date) mencegah duplicate kalau manual
 * sync dijalankan berkali-kali di hari yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms');
            $table->foreignId('content_item_id')->nullable()->constrained('content_items')->nullOnDelete();
            $table->foreignId('instagram_media_snapshot_id')->nullable()
                ->constrained('instagram_media_snapshots')->cascadeOnDelete();
            $table->foreignId('tiktok_video_snapshot_id')->nullable()
                ->constrained('tiktok_video_snapshots')->cascadeOnDelete();

            $table->date('snapshot_date');

            // Semua metric NULLABLE - NULL berarti "API ini tidak
            // menyediakan metric ini", BUKAN 0 (sama disiplin dengan
            // content_metrics existing - lihat TikTokAnalyticsSyncService::
            // saveMetric() & InstagramAnalyticsSyncService::saveMetric()).
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('profile_visit')->nullable();
            $table->decimal('engagement_rate', 6, 2)->nullable();
            $table->unsignedInteger('watch_time_avg')->nullable();
            $table->decimal('completion_rate', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['instagram_media_snapshot_id', 'snapshot_date'], 'cms_instagram_snapshot_date_unique');
            $table->unique(['tiktok_video_snapshot_id', 'snapshot_date'], 'cms_tiktok_snapshot_date_unique');
            $table->index(['client_id', 'platform_id', 'snapshot_date'], 'cms_client_platform_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_metric_snapshots');
    }
};
