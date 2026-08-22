<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * "Satu orang = satu record" (keputusan final Agustus 2026) - TeamMember
 * dan team_member_client dihapus permanen setelah seluruh data (roster,
 * role, client assignment, PIC assignment) terverifikasi ter-migrasi ke
 * users/user_client_assignments/content_item_assignments (lihat script
 * migrasi data satu-kali yang sudah dijalankan & diverifikasi sebelum
 * migration ini dibuat). Backup SQL tersimpan di
 * ../523studio-backups/pre_drop_teammember_*.sql sebelum migration ini
 * dijalankan.
 *
 * Urutan drop: pivot dulu (team_member_client) baru parent (team_members) -
 * FK constraint mengharuskan ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('team_member_client');
        Schema::dropIfExists('team_members');
    }

    public function down(): void
    {
        // Rekonstruksi skema (bukan data - data historis ada di backup SQL
        // terpisah, lihat docblock di atas) supaya rollback tetap mungkin
        // dalam keadaan darurat tanpa kehilangan struktur tabel.
        Schema::create('team_members', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('source')->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('team_member_client', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('team_member_id')->constrained('team_members')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamps();
        });
    }
};
