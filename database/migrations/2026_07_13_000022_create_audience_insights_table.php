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
            $table->json('gender_breakdown')->nullable();   // {"male": 45, "female": 55}
            $table->json('age_breakdown')->nullable();       // {"18-24": 30, "25-34": 40, ...}
            $table->json('top_locations')->nullable();       // [{"city": "Jakarta", "percentage": 35}, ...]
            $table->json('active_hours')->nullable();        // {"0": 5, "1": 3, ..., "23": 8}
            $table->timestamps();

            $table->unique(['client_id', 'platform_id', 'snapshot_date'], 'audience_insights_composite_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('audience_insights');
    }
};
