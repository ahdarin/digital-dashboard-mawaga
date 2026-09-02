<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics V2 Phase B - 1 baris = 1 item (media/video/task) yang gagal
 * diproses dalam 1 analytics_sync_tasks, cukup terstruktur buat targeted
 * retry (Langkah "TARGETED RETRY" - retry HANYA item yang gagal, bukan
 * ulang seluruh task).
 *
 * TIDAK PERNAH menyimpan token/Authorization header/client secret/
 * refresh_token/code_verifier/raw API payload - "message" HARUS sudah
 * melalui sanitasi yang sama dengan InstagramApiException/TikTokApiException
 * (pesan aman ditampilkan ke user, bukan raw response body).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sync_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analytics_sync_task_id')->constrained('analytics_sync_tasks')->cascadeOnDelete();

            // ID media/video dari provider (instagram_media_snapshot_id/
            // tiktok_video_snapshot_id EKSTERNAL, bukan FK internal - item
            // yang gagal biasanya belum pernah tersimpan sebagai snapshot
            // sama sekali, jadi tidak selalu punya FK internal buat dirujuk).
            $table->string('external_item_id', 191)->nullable();
            $table->foreignId('content_item_id')->nullable()->constrained('content_items')->nullOnDelete();

            // discover_media | fetch_insights | fetch_video_batch |
            // fetch_profile | fetch_audience - operasi spesifik yang gagal,
            // dipakai retry buat tahu HARUS memanggil ulang apa.
            $table->string('operation', 60);

            // authentication | transient | unsupported | provider_unavailable
            // | unknown - lihat App\Services\AnalyticsFailureCategory. HARUS
            // dipetakan dari kategori exception yang SUDAH ADA
            // (InstagramApiException/TikTokApiException), bukan ditebak baru.
            $table->string('category', 30);

            // Pesan aman - SUDAH melalui sanitasi caller (getMessage() dari
            // InstagramApiException/TikTokApiException, bukan raw body).
            $table->string('message', 500)->nullable();

            $table->boolean('retryable')->default(false);
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['analytics_sync_task_id', 'resolved_at'], 'sync_failures_task_resolved_index');
            $table->index(['analytics_sync_task_id', 'external_item_id'], 'sync_failures_task_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sync_failures');
    }
};
