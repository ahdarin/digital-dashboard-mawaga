<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_item_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users');
            $table->string('assignment_role'); // content_creator, designer, mso
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('content_item_assignments');
    }
};