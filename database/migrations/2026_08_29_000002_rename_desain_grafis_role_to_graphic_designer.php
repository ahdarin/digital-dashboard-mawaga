<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename role "Desain Grafis" -> "Graphic Designer". UPDATE di tempat
 * (bukan hapus lalu buat baru) - role_id tidak berubah, jadi setiap baris
 * user_roles & role_permissions yang sudah menunjuk ke role ini otomatis
 * ikut ke-rename tanpa kehilangan assignment/permission apa pun. Seeder
 * (RoleSeeder/PermissionSeeder/DemoSeeder/DocumentationSeeder) sudah
 * memakai nama baru untuk database yang di-seed dari kosong - migration
 * ini yang menangani database yang SUDAH terlanjur punya baris lama.
 *
 * Aman dijalankan berkali-kali: no-op kalau baris "Desain Grafis" sudah
 * tidak ada (misal database baru yang langsung di-seed dengan nama baru).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('name', 'Desain Grafis')->update(['name' => 'Graphic Designer']);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'Graphic Designer')->update(['name' => 'Desain Grafis']);
    }
};
