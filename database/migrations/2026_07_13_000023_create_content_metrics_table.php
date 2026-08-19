<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->onDelete('cascade');
            $table->foreignId('platform_id')->constrained('platforms');
            $table->foreignId('sync_log_id')->nullable()->constrained('analytics_sync_logs')->onDelete('set null');
            $table->foreignId('imported_by')->constrained('users');

            $table->date('metric_date');
            $table->integer('views')->default(0);
            $table->decimal('engagement_rate', 5, 2)->default(0.00);

            // Metrik tambahan khusus konten video (Reels & TikTok). Nullable,
            // karena konten Feed/foto nggak punya nilai ini.
            $table->integer('watch_time_avg')->nullable(); // rata-rata durasi ditonton, dalam detik
            $table->decimal('completion_rate', 5, 2)->nullable(); // % penonton yang nonton sampai habis
            $table->integer('shares')->nullable();
            $table->integer('saves')->nullable();
            $table->integer('reach')->nullable();
            $table->integer('impressions')->nullable();
            $table->integer('likes')->nullable();
            $table->integer('comments')->nullable();
            $table->integer('profile_visit')->nullable();

            $table->timestamps();

            $table->unique(['content_item_id', 'platform_id', 'metric_date'], 'content_metrics_composite_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('content_metrics');
    }
};
