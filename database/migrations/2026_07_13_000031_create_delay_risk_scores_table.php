<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delay_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('risk_score');
            $table->string('risk_level'); // high | medium | low
            $table->string('top_factor')->nullable();
            $table->json('features_snapshot')->nullable();
            $table->timestamps();

            // dipakai bareng ContentItem::latestDelayRisk() (latestOfMany by id)
            // dan TeamPerformanceController (MAX(id) per content_item_id)
            $table->index(['content_item_id', 'id']);
        });

        DB::statement("ALTER TABLE `delay_risk_scores` ADD CONSTRAINT `chk_delay_risk_scores_risk_level` CHECK (`risk_level` IN ('high','medium','low'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('delay_risk_scores');
    }
};
