<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Satu orang = satu record" (keputusan final user, Agustus 2026) - User
 * jadi satu-satunya entity person, TeamMember dihapus (lihat migration
 * berikutnya yang mem-backfill data dari team_members lalu men-drop
 * tabelnya). Role TETAP many-to-many lewat user_roles (RBAC multi-role,
 * lihat migration convert_user_role_to_many_to_many) - tidak ada role_id
 * yang ditambahkan lagi di sini. Tambahan di sini:
 *
 * - login_enabled: capability eksplisit "boleh coba login atau tidak" -
 *   TERPISAH dari `status` (yang tetap berarti lifecycle akun: invited/
 *   active/inactive/rejected). User real staff tanpa akses dashboard sama
 *   sekali (mis. staf yang belum pernah diundang) punya login_enabled=false
 *   supaya nggak tercampur logic dengan status.
 * - source/external_reference: provenance real (GUIDE/manual/bootstrap),
 *   mirror pola yang sama dipakai content_items.import_source/
 *   external_reference.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('login_enabled')->default(false)->after('status');
            $table->string('source')->nullable()->after('preferences');
            $table->string('external_reference')->nullable()->unique()->after('source');
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login_enabled', 'source', 'external_reference']);
        });
    }
};
