<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->onDelete('cascade');
            // Actor eksplisit: internal user ATAU client, tidak pernah dua-duanya.
            // changed_by_user_id diisi kalau perubahan dari staf internal,
            // changed_by_client_id diisi kalau dari Client Portal (mis. request
            // revisi) - salah satu SELALU null.
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('changed_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('approval_type')->nullable(); // Peleburan tabel approval (Design Notes #5)
            $table->text('notes')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('content_status_logs');
    }
};
