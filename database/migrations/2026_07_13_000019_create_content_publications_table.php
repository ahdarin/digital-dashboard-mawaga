<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->onDelete('cascade');
            $table->foreignId('platform_id')->constrained('platforms');
            $table->foreignId('published_by')->constrained('users');
            $table->dateTime('published_at');
            $table->string('post_url')->nullable();
            $table->text('caption_final')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('content_publications');
    }
};
