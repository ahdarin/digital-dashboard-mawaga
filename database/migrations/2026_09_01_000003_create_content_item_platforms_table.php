<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pivot buat dukung multi-platform per content item. Kolom lama
// content_items.platform_id (scalar) TIDAK dihapus - item lama & jalur
// import/AI-strategy lama masih baca itu; item baru dari alur Content Plan
// pakai pivot ini lewat ContentItem::platforms().
return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_item_platforms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['content_item_id', 'platform_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_platforms');
    }
};
