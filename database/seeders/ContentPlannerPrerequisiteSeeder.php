<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Buat Client + ContentPlan bulanan yang jadi PRASYARAT ContentPlannerSeeder
 * (lihat komentar panjang di ContentPlannerSeeder - seeder itu sengaja TIDAK
 * membuatkan Client/ContentPlan sendiri, cuma resolve natural key -> id).
 *
 * Di Railway (production-like), 13 Client ini sudah dibuat manual lewat UI
 * lama sebelum ContentPlannerSeeder pernah dijalankan di sana - jadi seeder
 * ini TIDAK PERNAH perlu jalan di Railway. Ini murni buat menyamakan database
 * dev lokal yang masih kosong, supaya `db:seed --class=ContentPlannerSeeder`
 * tidak berhenti dengan "Client tidak ditemukan".
 *
 * Nama Client & kombinasi client+tahun+bulan diambil LANGSUNG dari fixture
 * `data/content_planner.php` (bukan di-hardcode ulang di sini) - supaya kalau
 * fixture berubah, prasyaratnya otomatis ikut berubah, tidak pernah nyimpang.
 *
 * Idempotent: firstOrCreate by name (Client) / by client+year+month
 * (ContentPlan) - aman dijalankan berkali-kali.
 */
class ContentPlannerPrerequisiteSeeder extends Seeder
{
    public function run(): void
    {
        $fixture = require __DIR__.'/data/content_planner.php';

        if (empty($fixture)) {
            $this->command?->warn('Fixture content_planner.php kosong - tidak ada prasyarat yang dibuat.');

            return;
        }

        $defaultCategoryId = ClientCategory::query()->value('id');
        abort_unless($defaultCategoryId, 500, 'Belum ada ClientCategory - jalankan MasterDataSeeder dulu.');

        $createdBy = User::query()->value('id');
        abort_unless($createdBy, 500, 'Belum ada User - jalankan RoleSeeder/DemoSeeder dulu sebelum seeder ini.');

        $clientNames = collect($fixture)->pluck('client_name')->unique();

        $clientIds = $clientNames->mapWithKeys(function (string $name) use ($defaultCategoryId) {
            $client = Client::firstOrCreate(
                ['name' => $name],
                ['client_category_id' => $defaultCategoryId, 'status' => 'active']
            );

            return [$name => $client->id];
        });

        $this->command?->info("{$clientIds->count()} Client siap (dibuat baru kalau belum ada).");

        $planCombos = collect($fixture)
            ->map(fn ($row) => [
                'client_id' => $clientIds[$row['client_name']],
                'year' => $row['plan_year'],
                'month' => $row['plan_month'],
            ])
            ->unique(fn ($p) => "{$p['client_id']}-{$p['year']}-{$p['month']}");

        foreach ($planCombos as $combo) {
            ContentPlan::firstOrCreate(
                ['client_id' => $combo['client_id'], 'year' => $combo['year'], 'month' => $combo['month']],
                ['created_by' => $createdBy, 'status' => 'approved']
            );
        }

        $this->command?->info("{$planCombos->count()} ContentPlan bulanan siap (dibuat baru kalau belum ada).");
        $this->command?->info('Sekarang jalankan: php artisan db:seed --class=ContentPlannerSeeder');
    }
}
