<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Satu konsep Role" (keputusan final user, Agustus 2026) - TeamMember.role_id
 * jadi satu-satunya sumber role yang bisa diedit untuk identitas internal
 * (dipakai langsung buat authorization kalau TeamMember itu punya User
 * terkait, lihat User::effectiveRoles()). Menggantikan kolom string
 * operational_role (nullable dulu, di-backfill, baru dilepas di migration
 * berikutnya setelah data ter-verifikasi).
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('operational_role')->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
