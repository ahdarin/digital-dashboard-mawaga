<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi lanjutan KPI 2026-09-02 - instrumentasi minimal yang DIIZINKAN
 * spesifikasi ("instrumentation actor/timestamp yang benar-benar
 * diperlukan dan tidak mengubah langkah pengguna"). TIDAK ADA form/langkah
 * baru untuk pengguna - kolom ini diisi OTOMATIS di 3 titik yang sudah ada.
 *
 * Kenapa perlu: `content_publications.published_by` TIDAK selalu
 * merepresentasikan orang yang BENAR-BENAR mempublikasikan konten:
 * - `WorkflowStatusService::transition()` (uploaded) dan
 *   `ContentPublicationController::linkInstagramMedia/linkTiktokMedia`
 *   (link media unmatched) - published_by = staf yang BENAR-BENAR
 *   melakukan aksi itu di aplikasi. Reliable.
 * - `InstagramAnalyticsSyncService`/`TikTokAnalyticsSyncService::
 *   getOrCreatePublication()` - published_by = user yang KEBETULAN memicu
 *   sync (klik "Sync Now"/dijadwalkan cron), BUKAN orang yang publish
 *   konten itu di Instagram/TikTok (aksi publish-nya terjadi di platform
 *   eksternal, di luar sistem ini sama sekali). Memakai nilai ini sebagai
 *   atribusi KPI SMO akan "menebak" - dilarang eksplisit oleh keputusan
 *   produk.
 *
 * `recorded_via` membedakan dua kasus itu SECARA STRUKTURAL (bukan
 * ditebak dari kolom lain yang kebetulan mirip - api_integration_id
 * TERNYATA terisi di KEDUA kasus, jadi tidak bisa dipakai sebagai
 * pembeda). KPI SMO attribution (RoleProcessKpiService::scoreSmo(),
 * KpiRoleContextResolver) HANYA memakai publication 'manual'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publications', function (Blueprint $table) {
            $table->string('recorded_via')->default('auto_sync')->after('published_by');
        });

        DB::statement("ALTER TABLE `content_publications` ADD CONSTRAINT `chk_content_publications_recorded_via` CHECK (`recorded_via` IN ('manual','auto_sync'))");
    }

    public function down(): void
    {
        Schema::table('content_publications', function (Blueprint $table) {
            DB::statement('ALTER TABLE `content_publications` DROP CHECK `chk_content_publications_recorded_via`');
            $table->dropColumn('recorded_via');
        });
    }
};
