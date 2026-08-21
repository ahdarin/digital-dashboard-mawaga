<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation migration (merge main - konsolidasi migrasi tim) - main
 * meng-konsolidasi seluruh migration users lama jadi 1 file dasar
 * (2026_07_13_000006_create_users_table.php) yang sekaligus membuat
 * `email` NULLABLE (Client Owner sekarang login WhatsApp-only, nggak wajib
 * punya email) - perubahan ini nggak ketangkep migration manapun yang
 * BENERAN dijalankan ulang di existing DB (file konsolidasi itu ditandai
 * "sudah jalan" lewat reconciliation, bukan dieksekusi lagi, karena tabel
 * `users` sudah ada dari migration lama).
 *
 * Migration additive INI yang nutup gap-nya - biar existing DB (upgrade)
 * dan fresh install (lewat file konsolidasi main) sama-sama berakhir di
 * `email` nullable. Aman dijalankan di fresh install juga (kolom memang
 * sudah nullable dari awal di sana, MODIFY ke NULL lagi no-op).
 *
 * doctrine/dbal belum terpasang - pakai raw SQL, bukan ->nullable()->change().
 */
return new class extends Migration {
    public function up(): void {
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');
    }

    public function down(): void {
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NOT NULL');
    }
};
