<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration drift fix (Recovery Investigation, Aug 2026) - beberapa migration
 * lama (create_clients_table, content_revisions/content_status_logs/
 * content_workflows) diedit di tempat SETELAH pernah dijalankan terhadap DB
 * dev asli. Laravel melacak migration by filename, bukan content hash, jadi
 * `migrate:status` melaporkan 0 pending walau kolom baru yang didefinisikan
 * di file migration saat ini TIDAK PERNAH benar-benar ter-apply ke database
 * manapun yang sudah mencatat migration itu "Ran" sebelum edit terjadi -
 * termasuk semua backup SQL & live digidaw sekarang (drift ini sudah ada
 * SEBELUM data loss/reset, bukan disebabkan proses recovery).
 *
 * Aditif murni - kolom lama (requested_by/changed_by/client_reviewed_by)
 * TIDAK dihapus (dead tapi aman buat sekarang), cuma kolom baru ditambahkan
 * + data historis di-backfill kalau ada (content_status_logs.changed_by
 * 247/247 baris terisi -> disalin ke changed_by_user_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Setiap blok dijaga Schema::hasColumn() - migration ini cuma perlu
        // benar-benar mengubah database yang DRIFT (batch lama sebelum file
        // dasar diedit, mis. live digidaw hasil recovery). Database yang
        // migrate dari kosong (digidaw_testing, instalasi baru) sudah dapat
        // kolom-kolom ini langsung dari migration dasarnya yang sudah
        // diperbarui - tanpa guard ini, ALTER di sini akan bentrok "Duplicate
        // column" di sana.
        if (! Schema::hasColumn('clients', 'portal_access_enabled')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('portal_access_enabled')->default(true)->after('status');
            });
        }

        if (! Schema::hasColumn('clients', 'portal_token')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('portal_token', 64)->nullable()->after('portal_access_enabled');
            });

            // Backfill token unik buat client existing - dipakai ulang
            // generator resmi model-nya (Client::generateUniquePortalToken())
            // supaya format & collision-check-nya identik dengan token yang
            // dibuat lewat jalur normal (Client::creating()).
            foreach (\App\Models\Client::whereNull('portal_token')->get() as $client) {
                $client->portal_token = \App\Models\Client::generateUniquePortalToken();
                $client->saveQuietly();
            }

            Schema::table('clients', function (Blueprint $table) {
                $table->string('portal_token', 64)->nullable(false)->unique()->change();
            });
        }

        if (! Schema::hasColumn('content_revisions', 'requested_by_user_id')) {
            Schema::table('content_revisions', function (Blueprint $table) {
                $table->foreignId('requested_by_user_id')->nullable()->after('requested_by')
                    ->constrained('users')->nullOnDelete();
                $table->foreignId('requested_by_client_id')->nullable()->after('requested_by_user_id')
                    ->constrained('clients')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('content_status_logs', 'changed_by_user_id')) {
            Schema::table('content_status_logs', function (Blueprint $table) {
                $table->foreignId('changed_by_user_id')->nullable()->after('changed_by')
                    ->constrained('users')->nullOnDelete();
                $table->foreignId('changed_by_client_id')->nullable()->after('changed_by_user_id')
                    ->constrained('clients')->nullOnDelete();
            });
            DB::statement('UPDATE content_status_logs SET changed_by_user_id = changed_by WHERE changed_by IS NOT NULL');
        }

        if (! Schema::hasColumn('content_workflows', 'client_reviewed_by_client_id')) {
            Schema::table('content_workflows', function (Blueprint $table) {
                $table->foreignId('client_reviewed_by_client_id')->nullable()->after('client_reviewed_by')
                    ->constrained('clients')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Sama seperti up() - dijaga hasColumn() supaya down() tidak crash
        // di database yang up()-nya tadi no-op (kolom itu milik migration
        // dasar, bukan ditambahkan migration ini).
        if (Schema::hasColumn('content_workflows', 'client_reviewed_by_client_id')) {
            Schema::table('content_workflows', function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_reviewed_by_client_id');
            });
        }

        if (Schema::hasColumn('content_status_logs', 'changed_by_user_id')) {
            Schema::table('content_status_logs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('changed_by_user_id');
                $table->dropConstrainedForeignId('changed_by_client_id');
            });
        }

        if (Schema::hasColumn('content_revisions', 'requested_by_user_id')) {
            Schema::table('content_revisions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('requested_by_user_id');
                $table->dropConstrainedForeignId('requested_by_client_id');
            });
        }

        if (Schema::hasColumn('clients', 'portal_token')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn(['portal_token', 'portal_access_enabled']);
            });
        }
    }
};
