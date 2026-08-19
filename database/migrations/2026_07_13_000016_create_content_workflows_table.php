<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_workflows', function (Blueprint $table) {
            $table->id();
            // Relasi 1:1 ditegakkan dengan Unique Constraint (Design Notes #2 & #5)
            $table->foreignId('content_item_id')->unique()->constrained('content_items')->onDelete('cascade');
            $table->foreignId('current_pic_id')->nullable()->constrained('users');
            $table->string('current_status')->default('brief_ready');
            $table->boolean('is_overdue')->default(false); // Kolom derived (Design Notes #4)
            $table->timestamp('client_reviewed_at')->nullable();
            $table->foreignId('client_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_review_result')->nullable();
            $table->timestamps();

            $table->index(['current_status', 'is_overdue']);
        });

        DB::statement("ALTER TABLE `content_workflows` ADD CONSTRAINT `chk_content_workflows_current_status` CHECK (`current_status` IN ('brief_ready','in_progress','waiting_review','revision','approved','scheduled','uploaded','cancelled'))");
    }

    public function down(): void {
        Schema::dropIfExists('content_workflows');
    }
};
