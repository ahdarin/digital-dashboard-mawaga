<?php

namespace Database\Seeders;

use App\Models\ClientCategory;
use App\Models\ContentPillar;
use App\Models\ContentType;
use App\Models\PackageTemplate;
use App\Models\Platform;
use Illuminate\Database\Seeder;

/**
 * Data pilihan dasar (dulu cuma ada implisit di dalam DemoSeeder) - dipisah
 * jadi seeder sendiri biar bisa jalan di environment MANAPUN (termasuk
 * staging/production yang tidak pernah menjalankan DemoSeeder), soalnya
 * tanpa ini form Tambah Konten/Content Plan/Tambah Klien dkk nggak punya
 * satu pun pilihan di dropdown-nya sampai ada yang isi manual lewat Master
 * Data satu-satu.
 *
 * Nama pillar HARUS persis sama kayak label yang dipakai buat latih model
 * Delay Risk (lihat storage/ai/delay_risk) - kalau beda nama, encoder di
 * model nggak kenal dan hasil skornya nggak akurat. Nilai di sini SENGAJA
 * disamakan dengan yang dulunya ada di DemoSeeder (bukan didefinisikan
 * ulang independen), biar tetap satu sumber kebenaran.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        collect(['Education', 'Entertainment', 'Soft Selling', 'Hard Selling', 'Product Highlight', 'Information'])
            ->each(fn ($name) => ContentPillar::firstOrCreate(['name' => $name]));

        collect(['Video', 'Desain'])
            ->each(fn ($name) => ContentType::firstOrCreate(['name' => $name]));

        collect(['Instagram', 'TikTok'])
            ->each(fn ($name) => Platform::firstOrCreate(['name' => $name]));

        // 'Institusi' ditambah Agustus 2026 - client institusi pendidikan
        // (FTI UNAND, Yasmin IBS) tidak pas dikategorikan UMKM/Korporat.
        collect(['UMKM', 'Startup', 'Korporat', 'Retail', 'Institusi'])
            ->each(fn ($name) => ClientCategory::firstOrCreate(['name' => $name]));

        // Paket - belum ada preseden data sebelumnya (fitur baru), jadi
        // dibikin 4 tier yang wajar buat agensi social media: dari client
        // baru/UMKM sampai korporat/institusi besar yang butuh volume
        // konten tinggi.
        $packages = [
            ['name' => 'Paket Starter', 'monthly_content_quota' => 6, 'monthly_design_quota' => 4],
            ['name' => 'Paket Growth', 'monthly_content_quota' => 10, 'monthly_design_quota' => 8],
            ['name' => 'Paket Premium', 'monthly_content_quota' => 16, 'monthly_design_quota' => 12],
            ['name' => 'Paket Enterprise', 'monthly_content_quota' => 24, 'monthly_design_quota' => 20],
        ];

        foreach ($packages as $package) {
            PackageTemplate::firstOrCreate(
                ['name' => $package['name']],
                [
                    'monthly_content_quota' => $package['monthly_content_quota'],
                    'monthly_design_quota' => $package['monthly_design_quota'],
                    'is_active' => true,
                ]
            );
        }
    }
}
