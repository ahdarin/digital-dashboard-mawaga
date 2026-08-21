<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('generated_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('cascade');
            $table->foreignId('generated_by')->constrained('users');
            $table->string('report_type'); // monthly_summary, campaign_report
            $table->date('period_start');
            $table->date('period_end');
            $table->string('file_path')->nullable(); // Lokasi penyimpanan PDF/Excel
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('generated_reports');
    }
};
