<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('client_package_id')->constrained('client_packages');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users'); // Audit persetujuan
            $table->integer('month');
            $table->integer('year');
            $table->string('status')->default('draft'); // draft, pending, approved, rejected
            $table->timestamps();
        });

        DB::statement("ALTER TABLE `content_plans` ADD CONSTRAINT `chk_content_plans_status` CHECK (`status` IN ('draft','pending','approved','rejected'))");
    }

    public function down(): void {
        Schema::dropIfExists('content_plans');
    }
};
