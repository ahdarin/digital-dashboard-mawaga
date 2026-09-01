<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Status baru 'draft' - item yang sudah di-generate otomatis dari kuota
// paket klien tapi belum dikirim ke produksi oleh SMO (lihat perombakan
// alur Content Plan). Belum ada baris yang pakai nilai ini sampai
// ContentPlanItemGeneratorService mulai jalan (fase berikutnya).
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE `content_workflows` DROP CHECK `chk_content_workflows_current_status`');
        DB::statement("ALTER TABLE `content_workflows` ADD CONSTRAINT `chk_content_workflows_current_status` CHECK (`current_status` IN ('draft','brief_ready','in_progress','waiting_review','revision','approved','scheduled','uploaded','cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `content_workflows` DROP CHECK `chk_content_workflows_current_status`');
        DB::statement("ALTER TABLE `content_workflows` ADD CONSTRAINT `chk_content_workflows_current_status` CHECK (`current_status` IN ('brief_ready','in_progress','waiting_review','revision','approved','scheduled','uploaded','cancelled'))");
    }
};
