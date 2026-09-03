<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bobot & parameter KPI (persentase composite, bobot komponen video/desain,
 * threshold sample size, dst) HARUS configurable & versioned - TIDAK PERNAH
 * jadi magic number tersebar di banyak service (lihat docs/kpi/FORMULAS.md).
 * `config` menyimpan seluruh pohon bobot sebagai JSON tunggal, dibaca lewat
 * value object `App\Kpi\Formula\KpiFormulaConfig` (Fase 2) - service TIDAK
 * PERNAH membaca kolom lain di luar lewat objek ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_formula_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->json('config');
            $table->date('effective_from');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_formula_versions');
    }
};
