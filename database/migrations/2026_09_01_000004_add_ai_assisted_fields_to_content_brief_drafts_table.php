<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Map per-field ("copywriting_script": true, "talent": false, dst) yang
// mencatat field brief mana yang terakhir diisi lewat tombol "Isi dengan
// AI" vs diketik manual - dipakai buat "pakai atau tidaknya AI dalam
// menyusun brief" tanpa kehilangan info per-field seperti satu flag tunggal.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->json('ai_assisted_fields')->nullable()->after('complexity_level');
        });
    }

    public function down(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->dropColumn('ai_assisted_fields');
        });
    }
};
