<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->string('type'); // overdue, revision, approval
            $table->text('body')->nullable();

            // Kolom Polymorphic Deep-link (Kembali diaktifkan)
            $table->string('related_type')->nullable(); // App\Models\ContentItem, dll.
            $table->unsignedBigInteger('related_id')->nullable();

            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_read', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('notifications');
    }
};
