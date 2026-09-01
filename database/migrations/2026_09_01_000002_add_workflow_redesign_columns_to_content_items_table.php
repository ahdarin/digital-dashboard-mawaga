<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// provisional_code: label slot ("C1"/"D3") diisi sekali saat item
// di-generate otomatis dari kuota paket, tidak pernah dihitung ulang.
// upload_deadline_at: tanggal upload yang diisi SMO setelah plan approved -
// deadline_at (kolom lama) tetap dipakai sebagai deadline produksi,
// sekarang dihitung otomatis = upload_deadline_at - 2 hari.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('provisional_code')->nullable()->after('content_plan_id');
            $table->dateTime('upload_deadline_at')->nullable()->after('deadline_at');
            $table->unique(['content_plan_id', 'provisional_code'], 'content_items_plan_provisional_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropUnique('content_items_plan_provisional_code_unique');
            $table->dropColumn(['provisional_code', 'upload_deadline_at']);
        });
    }
};
