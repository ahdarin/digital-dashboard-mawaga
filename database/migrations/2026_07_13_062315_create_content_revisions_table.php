<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users'); // Bisa user internal mewakili klien
            $table->integer('revision_round')->default(1);
            $table->text('revision_note');
            $table->string('status')->default('open'); // open, resolved
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('content_revisions');
    }
};