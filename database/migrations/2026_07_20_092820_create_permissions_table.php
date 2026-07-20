<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module'); // client, content_plan, workflow, analytics, dst
            $table->string('action');  // view, create, update, approve, manage
            $table->timestamps();

            $table->unique(['module', 'action']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('permissions');
    }
};