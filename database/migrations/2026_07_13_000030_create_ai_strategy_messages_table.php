<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
    }

    public function down(): void {
        Schema::dropIfExists('ai_strategy_messages');
    }
};
