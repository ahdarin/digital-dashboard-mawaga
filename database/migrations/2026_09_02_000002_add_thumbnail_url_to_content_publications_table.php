<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thumbnail hasil unfurl (og:image) dari post_url yang diisi manual saat
// Record Publication - beda dari thumbnail_url milik InstagramMediaSnapshot/
// TikTokVideoSnapshot (itu dari API resmi platform, ini dari link apapun
// yang di-paste staff).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_publications', function (Blueprint $table) {
            $table->string('thumbnail_url')->nullable()->after('post_url');
        });
    }

    public function down(): void
    {
        Schema::table('content_publications', function (Blueprint $table) {
            $table->dropColumn('thumbnail_url');
        });
    }
};
