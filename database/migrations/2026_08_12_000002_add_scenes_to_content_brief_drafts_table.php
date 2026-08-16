<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->json('scenes')->nullable()->after('copywriting_script');
        });
    }

    public function down(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->dropColumn('scenes');
        });
    }
};
