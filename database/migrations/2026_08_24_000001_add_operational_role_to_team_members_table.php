<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role OPERASIONAL real (CEO/Manager/Desain Grafis/SMO/Content Creator) -
 * TERPISAH dari users.roles (authentication/permission). TeamMember boleh
 * tidak punya User sama sekali, jadi role operasionalnya tidak bisa
 * disimpan lewat users.role_id - lihat audit "Final Fix Batch (revisi
 * ROLE)" Agustus 2026.
 *
 * String polos nullable (bukan enum/CHECK constraint) - konsisten dengan
 * pola roles.name/content_types.name/platforms.name di skema ini, yang
 * semuanya simpan label display apa adanya tanpa constraint rigid.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('team_members', function (Blueprint $table) {
            $table->string('operational_role')->nullable()->after('name');
        });
    }

    public function down(): void {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropColumn('operational_role');
        });
    }
};
