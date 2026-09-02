<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// og:image URL dari CDN Instagram/TikTok sering lebih dari 255 karakter
// (token signature, cache-busting query params) - VARCHAR(255) bikin insert
// gagal dengan "Data too long for column 'thumbnail_url'" saat submit
// publikasi. Pakai raw SQL (bukan ->change()) supaya tidak perlu doctrine/dbal.
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE content_publications MODIFY thumbnail_url TEXT NULL');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE content_publications MODIFY thumbnail_url VARCHAR(255) NULL');
    }
};
