<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_plan_id')->constrained('content_plans');
            $table->boolean('is_urgent')->default(false);
            // Nandain: item ini dibuat otomatis dari AI Strategy Analysis yang
            // mana. Null kalau dibuat manual (lewat form biasa).
            $table->foreignId('ai_strategy_insight_id')->nullable()->constrained('ai_strategy_insights')->nullOnDelete();
            // Denormalisasi sengaja untuk efisiensi kueri (Design Notes #1)
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('content_pillar_id')->nullable()->constrained('content_pillars');
            $table->foreignId('content_type_id')->nullable()->constrained('content_types');
            $table->foreignId('platform_id')->nullable()->constrained('platforms');

            $table->string('title');
            $table->text('brief')->nullable();
            $table->text('caption_draft')->nullable();
            $table->dateTime('deadline_at');
            $table->timestamp('footage_captured_at')->nullable();
            $table->dateTime('scheduled_upload_at')->nullable();
            $table->unsignedInteger('estimated_duration_seconds')->nullable();
            $table->unsignedInteger('estimated_slide_count')->nullable();
            $table->string('content_file_link')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->softDeletes(); // Aturan Soft Delete mutlak (Design Notes #3.b)
            $table->timestamps();

            // Indeks yang hilang di jalur akses utama (papan Kanban, sort deadline).
            $table->index(['client_id', 'deadline_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('content_items');
    }
};
