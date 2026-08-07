<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_brief_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Brief siap pakai
            $table->string('hook_title')->nullable();
            $table->date('start_date')->nullable();
            $table->date('post_date')->nullable();
            $table->string('platform')->nullable();
            $table->string('reference_link')->nullable();
            $table->foreignId('take_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('copywriting_script')->nullable();
            $table->string('talent')->nullable();
            $table->text('properti')->nullable();

            // Fitur teknis — OUTPUT AI ini, jadi INPUT untuk AI Delay Risk (PIC 2)
            $table->integer('estimated_duration_seconds')->nullable();
            $table->integer('slide_count')->nullable();
            $table->integer('talent_count')->nullable();
            $table->integer('location_count')->nullable();
            $table->string('complexity_level')->nullable(); // simple | medium | complex

            // Status & histori diskusi
            $table->string('status')->default('draft'); // draft | discussing | finalized
            $table->json('chat_history')->nullable();
            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_brief_drafts');
    }
};
