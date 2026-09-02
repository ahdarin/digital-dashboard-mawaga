<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics V2 Phase B - 1 TASK = 1 subjob teknis di dalam 1 run
 * (instagram_content / instagram_audience / tiktok_content - reuse string
 * AnalyticsSyncOrchestrator::SUBJOB_* apa adanya, JANGAN bikin enum
 * terpisah yang bisa drift). Nama-nama ini TIDAK PERNAH ditampilkan
 * langsung ke user - UI selalu lewat label manusiawi (lihat
 * AnalyticsSyncOrchestrator::labelFor()).
 *
 * Counter discovered/processed/success/unavailable/skipped/failed dipakai
 * reconciliation invariant: discovered = success + unavailable + skipped +
 * failed (lihat AnalyticsSyncTask::isReconciled()). last_progress_at
 * (BEDA dari updated_at biasa - hanya di-touch saat progress genuine
 * terjadi) dipakai UI membedakan "masih jalan wajar" vs "macet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sync_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analytics_sync_run_id')->constrained('analytics_sync_runs')->cascadeOnDelete();
            $table->foreignId('api_integration_id')->constrained('api_integrations')->cascadeOnDelete();

            $table->string('subjob', 40); // instagram_content | instagram_audience | tiktok_content

            // queued | running | success | partial | failed | needs_reconnect
            $table->string('status', 20)->default('queued');

            // Label internal tahap saat ini (mis. "discovering_media",
            // "fetching_insights", "reconciling") - dipakai stage/indeterminate
            // progress SEBELUM discovered_count diketahui (Langkah "Progress
            // Semantics"), TIDAK PERNAH dipakai buat menghitung persentase
            // dari elapsed time.
            $table->string('stage', 60)->nullable();

            $table->unsignedInteger('discovered_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('unavailable_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            // null = belum dihitung (task belum selesai). true/false SETELAH
            // task selesai - lihat AnalyticsSyncTask::isReconciled().
            $table->boolean('reconciled')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('attempt')->default(1);

            $table->timestamps();

            $table->index(['api_integration_id', 'subjob', 'status'], 'sync_tasks_integration_subjob_status_index');
            $table->index(['analytics_sync_run_id'], 'sync_tasks_run_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sync_tasks');
    }
};
