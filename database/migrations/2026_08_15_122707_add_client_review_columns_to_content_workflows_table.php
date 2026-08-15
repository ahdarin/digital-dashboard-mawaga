<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('content_workflows', function (Blueprint $table) {
            $table->timestamp('client_reviewed_at')->nullable()->after('current_status');
            $table->foreignId('client_reviewed_by')->nullable()->after('client_reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->string('client_review_result')->nullable()->after('client_reviewed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_reviewed_by');
            $table->dropColumn(['client_reviewed_at', 'client_review_result']);
        });
    }
};
