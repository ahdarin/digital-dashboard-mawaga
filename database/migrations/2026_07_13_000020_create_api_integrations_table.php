<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('api_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('platform_id')->constrained('platforms');
            $table->string('integration_name'); // Meta Graph API, TikTok API
            $table->text('access_token')->nullable(); // Enkripsi dilakukan di level Model Laravel
            $table->text('refresh_token')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE `api_integrations` ADD CONSTRAINT `chk_api_integrations_status` CHECK (`status` IN ('active','inactive'))");
    }

    public function down(): void {
        Schema::dropIfExists('api_integrations');
    }
};
