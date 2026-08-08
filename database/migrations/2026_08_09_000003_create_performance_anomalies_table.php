<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // spike | drop
            $table->integer('percent_change'); // vs rata-rata baseline, bisa negatif (drop)
            $table->unsignedInteger('views_on_date');
            $table->unsignedInteger('baseline_avg_views');
            $table->date('detected_date');
            $table->timestamps();

            // dipakai AiStrategyService::buildPerformanceSummary() buat narik
            // anomali dalam rentang periode 1 client tertentu
            $table->index(['content_item_id', 'detected_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_anomalies');
    }
};
