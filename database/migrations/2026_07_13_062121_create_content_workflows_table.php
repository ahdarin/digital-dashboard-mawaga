<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_workflows', function (Blueprint $table) {
            $table->id();
            // Relasi 1:1 ditegakkan dengan Unique Constraint (Design Notes #2 & #5)
            $table->foreignId('content_item_id')->unique()->constrained('content_items')->onDelete('cascade');
            $table->foreignId('current_pic_id')->nullable()->constrained('users');
            $table->string('current_status')->default('planned');
            $table->boolean('is_overdue')->default(false); // Kolom derived (Design Notes #4)
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('content_workflows');
    }
};
