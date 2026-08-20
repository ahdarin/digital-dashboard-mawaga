<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            // Level & catatan hasil analisis kelayakan AI (deadline vs
            // kompleksitas produksi, beban kerja PIC minggu itu) - dihitung
            // ulang tiap generate/regenerate, bukan field yang diedit manual.
            $table->string('feasibility_level')->nullable()->after('complexity_level');
            $table->text('feasibility_notes')->nullable()->after('feasibility_level');
        });
    }

    public function down(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->dropColumn(['feasibility_level', 'feasibility_notes']);
        });
    }
};
