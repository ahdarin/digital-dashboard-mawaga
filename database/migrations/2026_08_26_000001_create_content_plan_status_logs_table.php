<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// KI-13 - riwayat keputusan Rencana Konten (Ajukan/Setujui/Tolak/Kembalikan ke
// Draf), pola sama dengan content_status_logs (konten) supaya catatan
// penolakan tidak pernah hilang walau rencana dikembalikan ke Draf dan
// diajukan ulang berkali-kali.
return new class extends Migration {
    public function up(): void {
        Schema::create('content_plan_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_plan_id')->constrained('content_plans')->onDelete('cascade');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('content_plan_status_logs');
    }
};
