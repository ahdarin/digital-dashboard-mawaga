<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->unique()->after('email');
            $table->string('google_id')->nullable()->unique()->after('phone_number');
            $table->foreignId('client_id')->nullable()->after('role_id')->constrained('clients')->nullOnDelete();
            $table->string('status')->default('pending')->after('is_active');
            // status: pending, invited, active, inactive, rejected

            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['phone_number', 'google_id', 'status']);
        });
    }
};