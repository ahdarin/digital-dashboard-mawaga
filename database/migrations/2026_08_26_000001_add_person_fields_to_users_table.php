<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Satu orang = satu record" (keputusan final user, Agustus 2026) - User
 * jadi satu-satunya entity person, TeamMember dihapus (lihat migration
 * berikutnya yang mem-backfill data dari team_members lalu men-drop
 * tabelnya). Tambahan di sini:
 *
 * - role_id: FK ke roles, SATU role per User (ganti user_roles many-to-many
 *   - pivot lama tidak dihapus di migration ini, cuma berhenti dipakai;
 *   di-drop terpisah setelah semua authorization path terverifikasi).
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
            $table->foreignId('role_id')->nullable()->after('client_id')->constrained('roles')->nullOnDelete();
            $table->boolean('login_enabled')->default(false)->after('status');
            $table->string('source')->nullable()->after('preferences');
            $table->string('external_reference')->nullable()->unique()->after('source');
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login_enabled', 'source', 'external_reference']);
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
