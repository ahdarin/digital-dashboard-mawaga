<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('content_metrics', function (Blueprint $table) {
            $table->integer('reach')->nullable()->after('saves');
            $table->integer('impressions')->nullable()->after('reach');
            $table->integer('likes')->nullable()->after('impressions');
            $table->integer('comments')->nullable()->after('likes');
            $table->integer('profile_visit')->nullable()->after('comments');
        });
    }

    public function down(): void {
        Schema::table('content_metrics', function (Blueprint $table) {
            $table->dropColumn(['reach', 'impressions', 'likes', 'comments', 'profile_visit']);
        });
    }
};
