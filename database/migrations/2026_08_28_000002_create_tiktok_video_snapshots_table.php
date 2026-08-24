<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache/snapshot video TikTok hasil sync (mirror instagram_media_snapshots -
 * lihat 2026_08_20_000003_create_instagram_media_snapshots_table.php) -
 * dipakai halaman "Unmatched TikTok Video" biar TIDAK perlu live-fetch ke
 * API tiap kali dibuka.
 *
 * TABEL TERPISAH dari instagram_media_snapshots, SENGAJA - diaudit dulu
 * (Langkah "TikTok Official API Integration" Section 14): InstagramMediaSnapshot
 * py kolom media_type/media_product_type yang taksonomi Meta murni
 * (IMAGE/CAROUSEL_ALBUM/VIDEO x FEED/REELS/STORY) dan TIDAK punya padanan
 * TikTok yang bersih (video TikTok cuma 1 bentuk: video, tidak ada varian
 * carousel/story lewat API ini). Memaksa TikTok masuk kolom itu berarti
 * kolom itu jadi bermakna ganda (Instagram taxonomy ATAU "video" literal
 * TikTok), atau exception rules per platform di satu model - keduanya lebih
 * berisiko regresi Instagram dekat freeze dibanding bikin tabel baru yang
 * jelas 1 platform = 1 bentuk. Struktur (unique key, match_status,
 * content_publication_id, last_fetched_at) tetap MIRROR persis instagram_media_
 * snapshots biar polanya konsisten dibaca, cuma field kontennya beda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_video_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_integration_id')->constrained('api_integrations')->cascadeOnDelete();
            $table->string('external_post_id'); // TikTok video id
            $table->string('share_url', 500)->nullable();
            $table->text('title')->nullable(); // video.list field "title"
            $table->text('video_description')->nullable(); // video.list field "video_description" - caption sebenarnya
            $table->integer('duration')->nullable(); // detik
            $table->string('cover_image_url', 1000)->nullable();
            $table->string('match_status')->default('unmatched'); // unmatched | matched | ambiguous
            $table->foreignId('content_publication_id')->nullable()->constrained('content_publications')->nullOnDelete();
            $table->dateTime('published_at')->nullable(); // create_time video
            $table->timestamp('last_fetched_at');
            $table->timestamps();

            $table->unique(['api_integration_id', 'external_post_id'], 'tiktok_video_snapshots_integration_post_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_video_snapshots');
    }
};
