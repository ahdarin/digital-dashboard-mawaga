<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Link referensi konten orang lain (inspirasi/pembanding) - diisi opsional
// di Info Dasar, beda dari content_file_link (hasil produksi sendiri) dan
// ContentBriefDraft.reference_link (belum pernah dipakai UI manapun).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('reference_link')->nullable()->after('brief');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('reference_link');
        });
    }
};
