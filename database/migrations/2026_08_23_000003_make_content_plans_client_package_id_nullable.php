<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * client_package_id nullable (Langkah "Full Real Content Planner Migration" -
 * Option 1 disetujui Agustus 2026): workbook Content Planner adalah source
 * of truth OPERASIONAL, tapi tidak menyediakan data paket/kontrak buat
 * client baru (Oleo/Darwin/Uthie Cake/Sato/Tatitatu/Alfa Sport/Odamilk/
 * Labertha). Daripada fabricate ClientPackage/"Internal Reference" palsu
 * cuma buat memenuhi FK, kolom ini dilonggarkan jadi NULL-able.
 *
 * SEMANTIK (Langkah 2, WAJIB dipahami caller manapun yang baca kolom ini):
 * client_package_id = NULL berarti "Content Plan real ada, tapi data
 * paket/kontrak belum tercatat" - BUKAN "client tidak punya paket". Null-safe
 * behavior sudah diaudit & diperbaiki di ContentPlanController (store,
 * quickCreateUrgent) dan content-plan/show.blade.php + index.blade.php
 * (wording "Paket belum tercatat", bukan "0 Content/0 Design").
 *
 * FK ke client_packages TETAP ADA (bukan didrop) - MySQL izinkan FK column
 * nullable, cuma menolak insert non-null yang tidak match row client_packages
 * manapun, exactly seperti sebelumnya buat value yang non-null.
 *
 * Raw ALTER MODIFY (bukan Schema::table()->nullable()->change()) - konsisten
 * sama pola migration 2026_08_23_000001 (content_metrics.content_item_id):
 * doctrine/dbal belum terpasang di project ini.
 */
return new class extends Migration {
    public function up(): void {
        DB::statement('ALTER TABLE content_plans MODIFY client_package_id BIGINT UNSIGNED NULL');
    }

    /**
     * Guard eksplisit: menolak rollback kalau sudah ada ContentPlan real
     * dengan client_package_id NULL - reverting ke NOT NULL akan gagal
     * (atau lebih parah, memaksa MySQL isi 0 yang salah total, FK-invalid)
     * begitu ada baris NULL beneran. Lebih baik gagal jelas di sini daripada
     * korupsi data diam-diam.
     */
    public function down(): void {
        $nullCount = DB::table('content_plans')->whereNull('client_package_id')->count();

        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Rollback dibatalkan: {$nullCount} ContentPlan punya client_package_id NULL (data historis real). ".
                'Mengembalikan ke NOT NULL akan merusak/menghapus informasi ini. '.
                'Isi client_package_id-nya dulu secara eksplisit (atau hapus baris terkait secara sadar) sebelum rollback migration ini.'
            );
        }

        DB::statement('ALTER TABLE content_plans MODIFY client_package_id BIGINT UNSIGNED NOT NULL');
    }
};
