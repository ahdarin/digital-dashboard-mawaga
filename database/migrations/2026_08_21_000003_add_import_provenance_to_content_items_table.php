<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance minimum (Langkah 13, "Content Planner Import") - biar bisa
 * tahu ContentItem mana yang berasal dari import planner lama, dan biar
 * importer bisa idempotent (jalan ulang tidak duplicate) lewat
 * external_reference sebagai identity key nyata ("TSA:2026-06:C1"),
 * bukan cuma title/tanggal yang bisa berubah.
 *
 * Additive, semua nullable - ContentItem yang dibuat manual/AI Strategy
 * (bukan dari import) tetap punya kolom ini kosong, tidak kesentuh.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('import_source')->nullable()->after('ai_strategy_insight_id');
            $table->uuid('import_batch_id')->nullable()->after('import_source');
            $table->string('external_reference')->nullable()->after('import_batch_id');

            $table->unique(['import_source', 'external_reference'], 'content_items_import_identity_unique');
        });
    }

    public function down(): void {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropUnique('content_items_import_identity_unique');
            $table->dropColumn(['import_source', 'import_batch_id', 'external_reference']);
        });
    }
};
