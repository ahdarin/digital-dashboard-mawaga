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
        Schema::table('users', function (Blueprint $table) {
            // JSON bebas buat preferensi personal per user - dimulai dari
            // tema (Terang/Gelap/Ikut Sistem), tapi dibuat generik biar
            // preferensi lain (mis. kerapatan tabel) bisa numpang di sini
            // nanti tanpa migration baru tiap kali.
            $table->json('preferences')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
