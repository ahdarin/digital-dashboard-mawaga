<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('audience_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('platform_id')->constrained('platforms');
            $table->date('snapshot_date');
            $table->integer('follower_count')->default(0);
            $table->timestamps();

            // Composite Unique Constraint wajib (Design Notes #2)
            $table->unique(['client_id', 'platform_id', 'snapshot_date'], 'audience_insights_composite_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('audience_insights');
    }
};