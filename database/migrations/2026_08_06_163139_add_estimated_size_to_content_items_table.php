<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->unsignedInteger('estimated_duration_seconds')->nullable()->after('deadline_at');
            $table->unsignedInteger('estimated_slide_count')->nullable()->after('estimated_duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn(['estimated_duration_seconds', 'estimated_slide_count']);
        });
    }
};