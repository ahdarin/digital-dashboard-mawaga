<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom tambahan buat integrasi Instagram API nyata (bukan cuma UI placeholder
 * lagi). Additive-only, nullable semua - CSV import & fitur lain yang sudah
 * jalan sama sekali nggak kesentuh.
 *
 * - api_integrations: identitas akun yang lagi konek + status sync terakhir.
 *   status TETAP pakai 'active'/'inactive' existing (ada CHECK constraint di
 *   DB), detail error taruh di last_error - bukan nambah state baru.
 * - content_publications: sudah persis mekanisme "content_platform_publications"
 *   yang dibutuhkan buat map 1 content_item ke banyak platform, tinggal kurang
 *   external_post_id biar sync berikutnya nggak perlu cocokin caption lagi.
 * - analytics_sync_logs: ringkasan hasil sync (jumlah berhasil/dilewati/pesan
 *   error) - tabel existing cuma punya kolom status, belum ada tempat nyimpen
 *   detail ini.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('api_integrations', function (Blueprint $table) {
            $table->string('external_account_id')->nullable()->after('status');
            $table->string('external_username')->nullable()->after('external_account_id');
            $table->timestamp('last_synced_at')->nullable()->after('external_username');
            $table->text('last_error')->nullable()->after('last_synced_at');
        });

        Schema::table('content_publications', function (Blueprint $table) {
            $table->string('external_post_id')->nullable()->after('platform_id');
            $table->foreignId('api_integration_id')->nullable()->after('external_post_id')
                ->constrained('api_integrations')->nullOnDelete();

            $table->unique(['platform_id', 'external_post_id'], 'content_publications_platform_external_post_unique');
        });

        Schema::table('analytics_sync_logs', function (Blueprint $table) {
            $table->unsignedInteger('synced_count')->nullable()->after('status');
            $table->unsignedInteger('skipped_count')->nullable()->after('synced_count');
            $table->text('error_message')->nullable()->after('skipped_count');
        });
    }

    public function down(): void {
        Schema::table('content_publications', function (Blueprint $table) {
            $table->dropUnique('content_publications_platform_external_post_unique');
            $table->dropConstrainedForeignId('api_integration_id');
            $table->dropColumn('external_post_id');
        });

        Schema::table('api_integrations', function (Blueprint $table) {
            $table->dropColumn(['external_account_id', 'external_username', 'last_synced_at', 'last_error']);
        });

        Schema::table('analytics_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['synced_count', 'skipped_count', 'error_message']);
        });
    }
};
