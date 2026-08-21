<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata rentang sync (default 2 bulan / historical per bulan) - error_message
 * existing semantiknya cuma buat pesan gagal, nggak pas dipakai nyimpen info
 * ini di SETIAP sync (termasuk yang sukses). Additive, nullable semua - CSV
 * import & sumber sync_log lain (source_type != api_sync) nggak kesentuh.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('analytics_sync_logs', function (Blueprint $table) {
            $table->string('sync_mode')->nullable()->after('source_type'); // default | historical
            $table->date('range_from')->nullable()->after('sync_mode');
            $table->date('range_to')->nullable()->after('range_from');
        });
    }

    public function down(): void {
        Schema::table('analytics_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['sync_mode', 'range_from', 'range_to']);
        });
    }
};
