<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('client_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('package_template_id')->nullable()->constrained('package_templates');

            // Mekanisme penyalinan kuota (Design Notes #3 & #5)
            $table->string('package_name_snapshot');
            $table->integer('monthly_content_quota');
            $table->integer('monthly_design_quota');

            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            // NOTE: status sengaja TIDAK diberi CHECK constraint - satu-satunya
            // nilai yang pernah terlihat di seluruh kode adalah default 'active',
            // tidak ada jalur tulis lain yang bisa dipakai buat menyimpulkan
            // daftar nilai valid yang dimaksud.
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('client_packages');
    }
};
