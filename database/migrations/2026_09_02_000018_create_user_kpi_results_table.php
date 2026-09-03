<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil composite KPI per user, terikat ke satu kpi_calculation_run.
 *
 * Koreksi produk 2026-09-02: `role_id` adalah FK ke `roles.id` EXISTING
 * (access role - dipakai sebagai label konteks KPI, BUKAN authorization,
 * lihat docs/kpi/ATTRIBUTION_RULES.md) - TIDAK ADA tabel role baru untuk
 * KPI. Nullable karena leadership Manager/CEO tidak terikat satu role
 * tunggal (walau dalam praktiknya selalu terisi Manager/CEO).
 *
 * `client_id` nullable, diisi kapan pun baris ini merepresentasikan
 * kontribusi ke SATU klien tertentu yang bisa dibuktikan pada periode ini
 * (production/operational MAUPUN leadership - koreksi lanjutan 2026-09-02
 * #4: baris operasional Copywriter/Content Creator/Graphic Designer/SMO
 * SEKARANG JUGA per-klien, bukan selalu NULL - lihat ATTRIBUTION_RULES.md
 * "Atribusi per Client"). Satu user boleh punya beberapa baris client
 * berbeda per run (per role, dan/atau leadership vs operasional).
 * `component_breakdown` JSON menyimpan seluruh angka yang dipakai
 * (raw/normalized/component score, termasuk daftar content_item_id yang
 * berkontribusi) untuk formula yang "terdokumentasi" & bisa diaudit - lihat
 * FORMULAS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_kpi_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_calculation_run_id')->constrained('kpi_calculation_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('process_score', 6, 2)->nullable();
            $table->decimal('direct_outcome_score', 6, 2)->nullable();
            $table->decimal('portfolio_outcome_score', 6, 2)->nullable();
            $table->decimal('composite_score', 6, 2)->nullable();
            $table->string('coverage_status'); // full, partial, provisional, unavailable
            $table->unsignedInteger('sample_size')->default(0);
            $table->string('status_label'); // sehat, perlu_perhatian, sementara, data_belum_cukup
            $table->json('component_breakdown')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'period_start', 'period_end']);
            $table->index(['role_id']);
            $table->index(['client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_kpi_results');
    }
};
