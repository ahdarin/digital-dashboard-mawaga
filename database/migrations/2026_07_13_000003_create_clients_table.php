<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_category_id')->constrained('client_categories');
            $table->string('name');
            $table->string('brand_name');
            $table->string('color', 7)->nullable();
            $table->string('logo_path')->nullable(); // logo brand client, disimpan di storage/app/public/client-logos
            $table->string('asset_link')->nullable(); // link folder aset konten/desain client di Google Drive
            $table->string('status')->default('active'); // active, past_due, paused
            $table->timestamps();
        });

        // SCHEMA-01 - kolom status varchar tanpa constraint bisa menyimpang senyap.
        DB::statement("ALTER TABLE `clients` ADD CONSTRAINT `chk_clients_status` CHECK (`status` IN ('active','past_due','paused'))");
    }

    public function down(): void {
        Schema::dropIfExists('clients');
    }
};
