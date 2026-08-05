<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ini fitur baru (fitur diskusi/chat), jadi wajar butuh tabel baru.
// Kolom tambahan di ai_strategy_insights pakai ALTER TABLE biasa
// (additive) - aman, TIDAK PERLU migrate:fresh.
return new class extends Migration {
    public function up(): void {
        Schema::create('ai_strategy_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_strategy_insight_id')->constrained('ai_strategy_insights')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users'); // null kalau role = assistant
            $table->string('role'); // user, assistant, system (buat catatan "analisis diperbarui")
            $table->text('message');
            $table->timestamps();
        });

        Schema::table('ai_strategy_insights', function (Blueprint $table) {
            // Data mentah performa (JSON dari buildPerformanceSummary) -
            // disimpen biar pas user diskusi/nanya lebih lanjut, AI masih
            // bisa ngerujuk ke angka aslinya, bukan cuma hasil ringkasan
            // yang udah "dikompres" jadi summary/action_items.
            $table->json('performance_data')->nullable()->after('period_end');
        });
    }

    public function down(): void {
        Schema::dropIfExists('ai_strategy_messages');
        Schema::table('ai_strategy_insights', function (Blueprint $table) {
            $table->dropColumn('performance_data');
        });
    }
};