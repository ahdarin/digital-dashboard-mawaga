<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA-01 - kolom status varchar tanpa constraint bisa menyimpang senyap
 * (mis. typo, sisa nilai lama) tanpa error. MySQL 8.0.16+ (terverifikasi
 * di lingkungan ini: 8.0.30) mendukung CHECK constraint sungguhan, jadi
 * dipakai sebagai penjaga di level DB tanpa mengubah tipe kolom/cara baca
 * di kode - seluruh perbandingan string yang sudah ada tetap jalan apa
 * adanya. Daftar nilai per kolom diverifikasi lewat grep menyeluruh atas
 * setiap penulisan literal di app/ + database/seeders/, plus nilai
 * distinct yang benar-benar ada di data seeder saat ini.
 *
 * client_packages.status sengaja TIDAK diberi constraint - satu-satunya
 * nilai yang pernah terlihat di seluruh kode adalah default 'active',
 * tidak ada jalur tulis lain yang bisa dipakai buat menyimpulkan daftar
 * nilai valid yang dimaksud, jadi mengunci constraint di sini lebih
 * berisiko salah tebak daripada membiarkannya longgar.
 */
return new class extends Migration
{
    protected array $constraints = [
        'content_workflows' => [
            'column' => 'current_status',
            'values' => ['brief_ready', 'in_progress', 'waiting_review', 'revision', 'approved', 'scheduled', 'uploaded', 'cancelled'],
        ],
        'content_plans' => [
            'column' => 'status',
            'values' => ['draft', 'pending', 'approved', 'rejected'],
        ],
        'content_revisions' => [
            'column' => 'status',
            'values' => ['open', 'in_progress', 'resolved'],
        ],
        'content_brief_drafts' => [
            'column' => 'status',
            'values' => ['draft', 'discussing', 'finalized'],
        ],
        'users' => [
            'column' => 'status',
            'values' => ['pending', 'invited', 'active', 'inactive', 'rejected'],
        ],
        'delay_risk_scores' => [
            'column' => 'risk_level',
            'values' => ['high', 'medium', 'low'],
        ],
        'analytics_sync_logs' => [
            'column' => 'status',
            'values' => ['success', 'failed', 'pending'],
        ],
        'clients' => [
            'column' => 'status',
            'values' => ['active', 'invited', 'paused', 'inactive'],
        ],
        'api_integrations' => [
            'column' => 'status',
            'values' => ['active', 'inactive'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->constraints as $table => $spec) {
            $constraintName = "chk_{$table}_{$spec['column']}";
            $inList = collect($spec['values'])->map(fn ($v) => "'{$v}'")->implode(',');

            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}` CHECK (`{$spec['column']}` IN ({$inList}))");
        }
    }

    public function down(): void
    {
        foreach ($this->constraints as $table => $spec) {
            $constraintName = "chk_{$table}_{$spec['column']}";

            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraintName}`");
        }
    }
};
