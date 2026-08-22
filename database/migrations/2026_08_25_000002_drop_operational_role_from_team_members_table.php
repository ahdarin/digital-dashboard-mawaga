<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Satu konsep Role" (keputusan final user) - team_members.role_id (FK ke
 * roles, ditambahkan migration sebelumnya) sudah di-backfill dan
 * diverifikasi 14/14 cocok dengan kolom string operational_role ini.
 * Menyimpan keduanya sekaligus = dual source lagi (persis yang dihindari) -
 * kolom string ini dilepas sekarang, role_id jadi satu-satunya.
 *
 * down() sengaja MENOLAK rollback kalau ada TeamMember dengan role_id
 * terisi tapi tidak punya padanan nama di roles - itu berarti rollback
 * akan kehilangan informasi role (jadi NULL), bukan sekadar balik ke
 * kolom lama.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropColumn('operational_role');
        });
    }

    public function down(): void {
        Schema::table('team_members', function (Blueprint $table) {
            $table->string('operational_role')->nullable()->after('name');
        });

        DB::statement("
            UPDATE team_members
            JOIN roles ON roles.id = team_members.role_id
            SET team_members.operational_role = roles.name
        ");
    }
};
