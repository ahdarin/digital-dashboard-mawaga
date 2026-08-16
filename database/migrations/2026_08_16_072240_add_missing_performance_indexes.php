<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Indeks yang hilang di jalur akses utama (papan Kanban, sort deadline,
// badge notifikasi belum dibaca, lookup absensi harian) - lihat hasil audit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_workflows', function (Blueprint $table) {
            $table->index(['current_status', 'is_overdue']);
        });

        Schema::table('content_items', function (Blueprint $table) {
            $table->index(['client_id', 'deadline_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_read', 'created_at']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::table('content_workflows', function (Blueprint $table) {
            $table->dropIndex(['current_status', 'is_overdue']);
        });

        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'deadline_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_read', 'created_at']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });
    }
};
