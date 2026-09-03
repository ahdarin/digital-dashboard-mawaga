<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak SETIAP eksekusi kalkulasi KPI - dipakai command manual, job
 * terjadwal, dan recalculation. TIDAK ADA unique constraint per periode:
 * menjalankan ulang periode yang sama MEMBUAT baris run baru (histori penuh
 * tersimpan, "jangan menimpa hasil formula versi lama tanpa histori") -
 * KpiCalculationService (Fase 2) yang menentukan run mana yang "current"
 * (run status=completed TERBARU per period_start+period_end+formula_version).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_calculation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_formula_version_id')->constrained('kpi_formula_versions');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['period_start', 'period_end', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_calculation_runs');
    }
};
