<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles');
            $table->string('name');
            // users HANYA internal staff (523 Studio) - login Google OAuth,
            // jadi email selalu wajib & unik. Client BUKAN User sama sekali
            // (lihat clients.portal_token) - tidak ada lagi client_id/
            // phone_number di sini.
            $table->string('email')->unique();
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_url')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('pending'); // pending, invited, active, inactive, rejected
            // JSON bebas buat preferensi personal per user - dimulai dari tema
            // (Terang/Gelap/Ikut Sistem), dibuat generik biar preferensi lain
            // bisa numpang di sini nanti tanpa migration baru tiap kali.
            $table->json('preferences')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `chk_users_status` CHECK (`status` IN ('pending','invited','active','inactive','rejected'))");
    }

    public function down(): void {
        Schema::dropIfExists('users');
    }
};
