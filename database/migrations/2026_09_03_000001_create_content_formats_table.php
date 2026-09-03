<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SYSTEM CONSISTENCY PASS (Part B/E) - "Production Type" (ContentType:
 * Desain/Video, "bagaimana konten dikerjakan?") dan "Content Format"
 * (single-post/carousel/video, "dalam format apa konten dipublikasikan?")
 * adalah DUA DIMENSI BEDA - ContentType TIDAK diganti, ini master BARU
 * buat dimensi kedua yang sebelumnya cuma kolom string bebas
 * (content_items.content_format, lihat migration 2026_08_27_000001) dan
 * belum pernah genuinely dipakai (0 baris terisi di production saat pass
 * ini ditulis). Slug STABIL dipakai sebagai kunci resolusi (bukan asumsi
 * ID) - lihat App\Services\ContentFormatResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_formats', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Seed 3 nilai kanonis langsung di migration (bukan cuma seeder) -
        // supaya deploy manapun (termasuk yang tidak pernah menjalankan
        // MasterDataSeeder) tetap punya master ini begitu migrate selesai.
        // insertOrIgnore + unique(slug) = idempotent, aman dijalankan ulang.
        DB::table('content_formats')->insertOrIgnore([
            ['name' => 'Single Post', 'slug' => 'single-post', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Carousel', 'slug' => 'carousel', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Video', 'slug' => 'video', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_formats');
    }
};
