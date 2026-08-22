<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Excel Coverage Audit" membuktikan kolom Excel I/"Jenis" punya informasi
 * bisnis nyata yang tidak terwakili ContentType (Desain/Video): 39 baris
 * Desain real punya subtype "Single Feed"/"Carousel Feed". ContentType
 * TETAP menjawab "Desain atau Video?" (arsitektur tidak diubah) -
 * content_format menjawab pertanyaan terpisah "format output-nya apa?".
 * Nullable string murni (bukan master table baru) - sengaja, jumlah nilai
 * canonical dari sumber saat ini kecil (Video/Single Feed/Carousel Feed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('content_format')->nullable()->after('content_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('content_format');
        });
    }
};
