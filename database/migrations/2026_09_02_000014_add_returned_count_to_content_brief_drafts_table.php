<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instrumentasi untuk "First-pass acceptance" (Process KPI Copywriter,
 * PROCESS_METRICS.md) - tidak ada event historis yang menandai "brief
 * dikembalikan sebelum diterima" hari ini. Kolom baru, default 0 untuk
 * semua baris lama (BUKAN backfill tebakan dari `previous_snapshot`, yang
 * maknanya berbeda - fitur revert AI, bukan sinyal penolakan). Increment
 * eksplisit ditambahkan di titik yang relevan (BriefGenerationService/
 * ContentBriefController) saat brief yang sudah finalized dibuka lagi untuk
 * revisi - dilakukan terpisah dari migration ini, di luar Fase 1 (KPI Fase 2
 * baca kolom ini sebagai 0/N, dengan sample_size=0 baris lama diperlakukan
 * "belum ada event tercatat", bukan "tidak pernah dikembalikan").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->unsignedInteger('returned_count')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('content_brief_drafts', function (Blueprint $table) {
            $table->dropColumn('returned_count');
        });
    }
};
