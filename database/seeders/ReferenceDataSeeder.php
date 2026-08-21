<?php

namespace Database\Seeders;

use App\Models\ClientCategory;
use App\Models\ContentPillar;
use App\Models\ContentType;
use App\Models\Platform;
use Illuminate\Database\Seeder;

/**
 * Master/reference data yang WAJIB ada di semua environment (termasuk
 * production) - dipisah dari DemoSeeder karena sebelumnya Platform/
 * ContentType/ContentPillar/ClientCategory cuma dibuat di sana, padahal
 * DemoSeeder sengaja di-gate ke environment('local') saja. Efeknya: deploy
 * production yang jalanin migrate --seed nggak akan pernah punya baris
 * Platform sama sekali, bikin fitur Instagram (Platform::where('name',
 * 'Instagram')) gagal total. Seeder ini SELALU dipanggil DatabaseSeeder,
 * di semua environment.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // Nama pillar HARUS persis sama kayak label yang dipakai buat latih
        // model Delay Risk (lihat storage/ai/delay_risk) - kalau beda nama,
        // encoder di model nggak kenal dan hasil skornya nggak akurat.
        collect(['Education', 'Entertainment', 'Soft Selling', 'Hard Selling', 'Product Highlight', 'Information'])
            ->each(fn ($name) => ContentPillar::firstOrCreate(['name' => $name]));

        collect(['Video', 'Desain'])
            ->each(fn ($name) => ContentType::firstOrCreate(['name' => $name]));

        collect(['Instagram', 'TikTok'])
            ->each(fn ($name) => Platform::firstOrCreate(['name' => $name]));

        collect(['UMKM', 'Startup', 'Korporat', 'Retail'])
            ->each(fn ($name) => ClientCategory::firstOrCreate(['name' => $name]));
    }
}
