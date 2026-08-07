<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BEDA kasus dari migration-migration bermasalah sebelumnya: content_items
// sekarang tabel yang udah beneran kepake (ada Content Plan, workflow,
// dst nempel di situ). Nge-drop & rebuild tabel ini cuma buat 1 kolom
// kecil bakal ngilangin semua kerjaan yang udah ada - nggak sepadan.
// Makanya ini migration ADDITIVE biasa (ALTER TABLE ADD COLUMN), aman
// dijalankan pakai `php artisan migrate` doang, TANPA migrate:fresh,
// TANPA ngilangin data yang udah ada.
return new class extends Migration {
    public function up(): void {
        Schema::table('content_items', function (Blueprint $table) {
            // Nandain: item ini dibuat otomatis dari AI Strategy Analysis
            // yang mana. Null kalau dibuat manual (lewat form biasa).
            $table->foreignId('ai_strategy_insight_id')->nullable()
                ->after('content_plan_id')
                ->constrained('ai_strategy_insights')
                ->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_strategy_insight_id');
        });
    }
};
