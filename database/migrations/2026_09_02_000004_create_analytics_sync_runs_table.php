<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics V2 Phase B - structured sync run foundation. SATU baris = SATU
 * operasi refresh (klik "Perbarui Data", atau 1 tick scheduler harian) buat
 * 1 client, BISA mencakup >1 task (Instagram Content + Audience + TikTok
 * Content sekaligus kalau "All Platforms"). Murni ADDITIVE - TIDAK
 * menggantikan analytics_sync_logs (itu tetap satu-satunya sumber
 * kebenaran final success/partial/failed per subjob, dipakai luas oleh
 * AnalyticsSyncOrchestrator yang sudah dites ekstensif - lihat
 * AnalyticsSyncTask.reconciled/progress_counts di migration berikutnya buat
 * detail progress yang analytics_sync_logs TIDAK punya).
 *
 * TIDAK PERNAH menyimpan token/secret/raw API payload - kolom di sini murni
 * angka/status/timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            // scheduled | manual | retry | initial - lihat AnalyticsSyncRun::TRIGGER_*.
            $table->string('trigger', 20);

            // null buat trigger=scheduled (system-initiated, bukan user).
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            // queued | running | success | partial | failed | needs_reconnect -
            // ROLLUP dari seluruh analytics_sync_tasks di run ini (lihat
            // AnalyticsSyncRun::recomputeStatus()), BUKAN ditulis manual.
            $table->string('status', 20)->default('queued');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'status'], 'sync_runs_client_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sync_runs');
    }
};
