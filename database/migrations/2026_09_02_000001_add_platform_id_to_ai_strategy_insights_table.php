<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 4.1 - AI Strategy sekarang mengikuti platform filter global
// (Instagram/TikTok/All), bukan lagi selalu "gabungan semua platform".
// platform_id NULL punya makna bisnis eksplisit "All Platforms" - BUKAN
// "belum diisi" - jadi restrictOnDelete() (bukan nullOnDelete()) supaya
// insight yang benar-benar spesifik ke 1 platform TIDAK PERNAH diam-diam
// berubah makna jadi "All Platforms" hanya karena baris Platform itu
// terhapus (platforms pada dasarnya tidak pernah dihapus di app ini,
// tapi semantik ini harus benar terlepas dari itu).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_strategy_insights', function (Blueprint $table) {
            $table->foreignId('platform_id')->nullable()->after('client_id')
                ->constrained('platforms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_strategy_insights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_id');
        });
    }
};
