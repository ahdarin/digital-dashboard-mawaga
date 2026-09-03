<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = KPI global 1 user pada 1 bulan (period_start = tanggal 1
 * bulan tersebut) - satu user dengan beberapa role tetap cuma dapat SATU
 * baris per bulan, lihat App\Services\TeamPerformanceKpiCalculator.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('user_monthly_kpi_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_start');
            $table->decimal('timeliness_score', 5, 2)->nullable();
            $table->decimal('quality_score', 5, 2)->nullable();
            $table->decimal('analytics_bonus', 5, 2)->nullable();
            $table->boolean('analytics_available')->default(false);
            $table->decimal('final_score', 5, 2)->nullable();
            $table->unsignedInteger('sample_size')->default(0);
            $table->string('status');
            $table->json('breakdown')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_start']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('user_monthly_kpi_results');
    }
};
