<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Polymorphic dari awal (pinnable_type/pinnable_id) supaya nanti bisa
        // dipakai buat pin Client/ContentPlan juga tanpa migrasi baru - untuk
        // sekarang cuma ContentItem yang dipakai (lihat PinService).
        Schema::create('pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pinnable_type');
            $table->unsignedBigInteger('pinnable_id');
            $table->timestamps();

            $table->unique(['user_id', 'pinnable_type', 'pinnable_id']);
            $table->index(['pinnable_type', 'pinnable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pins');
    }
};
