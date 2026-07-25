<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: migration create_notifications_table yang sudah ada belum punya
// kolom isi pesan (cuma title). Dibutuhkan untuk popup notification biar
// bisa nampilin body kayak di desain ("Mentioned you in Q3 Campaign
// Assets: ..."). Sesuaikan nama file (timestamp prefix) sebelum dijalankan
// supaya urut setelah migration notifications yang lama.
return new class extends Migration {
    public function up(): void {
        Schema::table('notifications', function (Blueprint $table) {
            $table->text('body')->nullable()->after('type');
        });
    }

    public function down(): void {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }
};