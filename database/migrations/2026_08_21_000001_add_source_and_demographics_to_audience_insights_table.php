<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audience Insights real API (lihat audit "Instagram Audience Insights") -
 * additive only. audience_insights sebelumnya cuma 1 row/hari (CSV manual),
 * sekarang API bisa punya beberapa row PARALEL per hari (summary + follower/
 * reached/engaged demographics) - makanya unique identity lama
 * (client_id, platform_id, snapshot_date) diganti, bukan dihapus tanpa
 * pengganti.
 *
 * source/demographic_type SENGAJA non-null dengan default eksplisit ('legacy'/
 * 'generic') - MySQL UNIQUE mengizinkan banyak NULL, jadi kalau salah satu
 * kolom identity nullable, constraint-nya nggak benar-benar mencegah
 * duplicate. Row existing (720 baris, semua dari DemoSeeder, dibuktikan di
 * audit - TIDAK ADA row CSV asli yang provenance-nya bisa dibuktikan saat
 * migration ini ditulis) otomatis kebackfill 'legacy'/'generic' lewat
 * default kolom ini, bukan ditebak jadi 'csv_import'.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('audience_insights', function (Blueprint $table) {
            $table->string('source')->default('legacy')->after('platform_id');
            $table->string('demographic_type')->default('generic')->after('source');
            $table->integer('reach')->nullable()->after('follower_count');
            $table->json('top_countries')->nullable()->after('top_locations');
        });

        // follower_count awalnya NOT NULL DEFAULT 0 (schema lama, CSV-only -
        // CSV memang selalu wajib isi follower_count jadi 0 masuk akal di
        // sana). Untuk row API, "API gagal ambil followers_count" HARUS beda
        // dari "API bilang 0 follower" - doctrine/dbal belum terpasang jadi
        // dropDefault()/nullable()->change() tidak bisa dipakai, raw SQL.
        DB::statement('ALTER TABLE audience_insights MODIFY follower_count INT NULL DEFAULT NULL');

        // Index baru dibuat DULU, baru yang lama di-drop - MySQL menolak
        // drop index lama selagi dia masih satu-satunya index yang menopang
        // FK client_id/platform_id (error 1553). Index baru ini juga
        // berawalan client_id, jadi begitu dia ada, index lama aman di-drop.
        Schema::table('audience_insights', function (Blueprint $table) {
            $table->unique(
                ['client_id', 'platform_id', 'snapshot_date', 'source', 'demographic_type'],
                'audience_insights_identity_unique'
            );
        });

        Schema::table('audience_insights', function (Blueprint $table) {
            $table->dropUnique('audience_insights_composite_unique');
        });
    }

    public function down(): void {
        Schema::table('audience_insights', function (Blueprint $table) {
            $table->unique(['client_id', 'platform_id', 'snapshot_date'], 'audience_insights_composite_unique');
        });

        Schema::table('audience_insights', function (Blueprint $table) {
            $table->dropUnique('audience_insights_identity_unique');
            $table->dropColumn(['source', 'demographic_type', 'reach', 'top_countries']);
        });

        DB::table('audience_insights')->whereNull('follower_count')->update(['follower_count' => 0]);
        DB::statement('ALTER TABLE audience_insights MODIFY follower_count INT NOT NULL DEFAULT 0');
    }
};
