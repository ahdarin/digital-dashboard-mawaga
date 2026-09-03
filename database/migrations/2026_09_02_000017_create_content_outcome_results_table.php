<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil scoring outcome PER CONTENT ITEM (multi-platform sudah digabung
 * jadi satu skor content-level di sini - lihat ContentOutcomeScoringService,
 * Fase 2), terikat ke satu kpi_calculation_run supaya bisa diaudit lintas
 * versi formula. `component_scores`/`raw_metrics` menyimpan breakdown penuh
 * (per platform, per komponen) sebagai JSON - dipakai tampilan detail
 * analytics anggota (Fase 4) tanpa perlu tabel terpisah per komponen.
 *
 * Satu content item bisa punya DUA baris di sini per run: satu untuk
 * measurement_window='d7', satu untuk 'd30' (constraint unique below).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_outcome_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_calculation_run_id')->constrained('kpi_calculation_runs')->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->string('format_group'); // video, carousel, single_feed, unknown
            $table->string('measurement_window'); // d7, d30
            $table->string('coverage_status'); // full, partial, provisional, unavailable
            $table->unsignedInteger('peer_sample_size')->default(0);
            $table->string('peer_group_key')->nullable();
            $table->decimal('normalized_score', 6, 2)->nullable();
            $table->json('component_scores')->nullable();
            $table->json('raw_metrics')->nullable();
            $table->string('exclusion_reason')->nullable();
            $table->dateTime('computed_at');
            $table->timestamps();

            $table->unique(
                ['kpi_calculation_run_id', 'content_item_id', 'measurement_window'],
                'content_outcome_unique'
            );
            $table->index(['content_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_outcome_results');
    }
};
