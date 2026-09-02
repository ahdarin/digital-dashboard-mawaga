<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASS 1B - shortcode Instagram Graph API (fields=...,shortcode - field
 * dokumentasi resmi, scope instagram_business_basic yang sudah dipakai
 * app ini). Kode pendek permalink (mis. "Cx1Y2z3") - stabil permanen
 * per media, TIDAK PERNAH berubah setelah publish, beda dari
 * thumbnail_url/media_url yang TTL-nya terbatas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_media_snapshots', function (Blueprint $table) {
            $table->string('shortcode')->nullable()->after('permalink');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_media_snapshots', function (Blueprint $table) {
            $table->dropColumn('shortcode');
        });
    }
};
