<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lacak ide mana saja dari content_ideas yang sudah "diterapkan" ke slot
// content item tertentu - applied_at saja tidak cukup begitu apply jadi
// per-ide (bukan whole-batch lagi).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_strategy_insights', function (Blueprint $table) {
            $table->json('applied_idea_indexes')->nullable()->after('applied_by');
        });
    }

    public function down(): void
    {
        Schema::table('ai_strategy_insights', function (Blueprint $table) {
            $table->dropColumn('applied_idea_indexes');
        });
    }
};
