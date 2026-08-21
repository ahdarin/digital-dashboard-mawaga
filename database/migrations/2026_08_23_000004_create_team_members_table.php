<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TeamMember != User (audit "Real Data Only - Team koreksi" Agustus 2026):
 * anggota tim/PIC agensi yang REAL (dibuktikan lewat sheet GUIDE workbook
 * Content Planner) tapi belum tentu punya akun login dashboard - GUIDE
 * cuma daftar nama+email per client, bukan roster akun sistem. Memaksa
 * bikin User (password/role) buat orang yang cuma perlu "tercatat sebagai
 * tim" adalah fabrikasi kredensial, exactly yang mau dihindari.
 *
 * user_id NULLABLE + nullOnDelete: TeamMember boleh berdiri sendiri tanpa
 * User sama sekali; kalau User-nya suatu saat dihapus, TeamMember TETAP ADA
 * (bukan ikut hilang) - orangnya tetap real, cuma akunnya yang hilang.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            // Nullable - GUIDE secara faktual selalu punya email di setiap
            // baris orang (13/13), tapi kolomnya dibuat nullable buat
            // sumber roster masa depan yang mungkin tidak selengkap itu.
            $table->string('email')->nullable();
            // Sumber data: 'content_planner_guide' (sheet GUIDE) atau
            // 'manual' (ditambah staff lewat UI nanti, bukan dari workbook).
            $table->string('source')->default('manual');
            // Identity key idempotency import (mis. "GUIDE:row6") - null
            // buat TeamMember yang dibuat manual.
            $table->string('external_reference')->nullable();
            $table->timestamps();

            $table->unique('email');
            $table->unique('external_reference');
        });

        // Pivot many-to-many, BUKAN 1 client tetap per orang - dibuktikan
        // dari data real: "Dika" (surdik2811@gmail.com) muncul sebagai PIC
        // konten di 5 sheet client berbeda (GGA/FTI/Yasmin/TSA/SEWAJAS),
        // bukan cuma 1 client GUIDE-nya (DARWIN). Snapshot GUIDE per-client
        // tidak boleh diasumsikan permanen 1:1.
        Schema::create('team_member_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_member_id', 'client_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('team_member_client');
        Schema::dropIfExists('team_members');
    }
};
