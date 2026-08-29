<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gabungkan `name` dan `brand_name` jadi satu kolom (`name` saja) - keputusan
 * pemilik produk: dua field ini membingungkan saat menambah client baru,
 * dan dalam proses bisnis 523 Studio satu client cuma punya satu nama.
 *
 * Baris lama (kalau ada) di-backfill dulu: kalau brand_name terisi dan beda
 * dari name, brand_name yang dipakai sebagai nilai final `name` - itu yang
 * selama ini benar-benar tampil ke user di seluruh UI (bukan `name`, yang
 * kadang masih menyimpan nama badan hukum resmi seperti "PT ..."). Baru
 * setelah backfill selesai, kolom brand_name dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE clients SET name = brand_name WHERE brand_name IS NOT NULL AND brand_name <> '' AND brand_name <> name");

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('brand_name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('brand_name')->nullable()->after('name');
        });

        DB::statement('UPDATE clients SET brand_name = name');

        Schema::table('clients', function (Blueprint $table) {
            $table->string('brand_name')->nullable(false)->change();
        });
    }
};
