<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preservation minimal buat identitas PIC legacy dari Content Planner XLSX
 * yang TIDAK punya User account di sistem (Uun, Thiah, Joe, Aca, dkk) -
 * tanpa ini, nama/email mereka cuma ada di file XLSX + JSON dry-run report,
 * hilang permanen begitu file itu tidak tersedia lagi.
 *
 * SENGAJA cuma preservation pasif (2 kolom string nullable), BUKAN
 * assignment - current_pic_id/ContentItemAssignment TETAP null/tidak dibuat
 * kalau memang tidak ada User yang match. Kolom ini tidak pernah dipakai
 * buat resolve otorisasi/workload, cuma jejak historis buat manusia yang
 * nanti mau rekonsiliasi manual kalau orang-orang ini akhirnya dibuatkan
 * User beneran.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('external_pic_name')->nullable()->after('external_reference');
            $table->string('external_pic_email')->nullable()->after('external_pic_name');
        });
    }

    public function down(): void {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn(['external_pic_name', 'external_pic_email']);
        });
    }
};
