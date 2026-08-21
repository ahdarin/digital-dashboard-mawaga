<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->onDelete('cascade');
            // Actor eksplisit: internal user ATAU client, tidak pernah dua-duanya
            // (sama seperti content_status_logs.changed_by_*).
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->integer('revision_round')->default(1);
            $table->text('revision_note');
            $table->string('status')->default('open'); // open, in_progress, resolved
            $table->timestamps();
        });

        DB::statement("ALTER TABLE `content_revisions` ADD CONSTRAINT `chk_content_revisions_status` CHECK (`status` IN ('open','in_progress','resolved'))");
    }

    public function down(): void {
        Schema::dropIfExists('content_revisions');
    }
};
