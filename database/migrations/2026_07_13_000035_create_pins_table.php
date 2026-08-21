<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Polymorphic dari awal (pinnable_type/pinnable_id) supaya nanti bisa
    // dipakai buat pin Client/ContentPlan juga tanpa migrasi baru - untuk
    // sekarang cuma ContentItem yang dipakai (lihat PinService).
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('pins');
    }
};
