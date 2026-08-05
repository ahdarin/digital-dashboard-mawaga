<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nyimpen hasil generate AI Strategy Analysis per client, biar:
// 1. Nggak manggil API tiap kali halaman Analytics dibuka (mahal & lambat)
// 2. Ada histori - bisa liat rekomendasi AI dari periode-periode sebelumnya
return new class extends Migration {
    public function up(): void {
        
        Schema::create('ai_strategy_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('generated_by')->constrained('users');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('summary'); // narasi rekomendasi utama dari AI
            $table->json('action_items'); // array string
            $table->json('suggested_split')->nullable(); // [{"label":"...","value":NN}]
            $table->json('top_pillars')->nullable(); // [{"name":"...","reasoning":"..."}]
            $table->json('content_ideas')->nullable(); // [{"pillar":"...","title":"...","brief":"..."}]
            $table->unsignedTinyInteger('data_completeness_percent')->nullable(); // dihitung sistem, bukan diklaim AI
            $table->string('status')->default('completed'); // pending, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamp('applied_at')->nullable(); // kapan "Terapkan ke Content Plan" diklik
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('ai_strategy_insights');
    }
};