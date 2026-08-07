<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->json('previous_snapshot')->nullable()->after('chat_history');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->dropColumn('previous_snapshot');
        });
    }
};
