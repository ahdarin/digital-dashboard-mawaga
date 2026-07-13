<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('package_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Paket Basic, Paket Growth, Custom
            $table->integer('monthly_content_quota');
            $table->integer('monthly_design_quota');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('package_templates');
    }
};
