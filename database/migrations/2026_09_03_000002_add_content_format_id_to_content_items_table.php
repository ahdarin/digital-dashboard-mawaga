<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, nullable FK - content_items lama TETAP valid tanpa perlu
 * backfill (content_format string lama juga TIDAK disentuh/dihapus, lihat
 * docblock migration 2026_08_27_000001). content_format_id HANYA diisi
 * lewat jalur yang genuinely tahu formatnya (mis. hasil provider
 * normalization tersimpan, atau input master baru) - tidak pernah
 * ditebak dari data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->foreignId('content_format_id')->nullable()->after('content_format')
                ->constrained('content_formats')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropForeign(['content_format_id']);
            $table->dropColumn('content_format_id');
        });
    }
};
