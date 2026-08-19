<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients');
            $table->foreignId('platform_id')->nullable()->constrained('platforms'); // nullable: 1 sesi import bisa nyakup >1 platform sekaligus
            $table->foreignId('api_integration_id')->nullable()->constrained('api_integrations');
            $table->foreignId('imported_by')->constrained('users');
            $table->string('source_type'); // csv, api
            $table->string('status')->default('pending'); // success, failed
            $table->timestamps();
        });

        DB::statement("ALTER TABLE `analytics_sync_logs` ADD CONSTRAINT `chk_analytics_sync_logs_status` CHECK (`status` IN ('success','failed','pending'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sync_logs');
    }
};
