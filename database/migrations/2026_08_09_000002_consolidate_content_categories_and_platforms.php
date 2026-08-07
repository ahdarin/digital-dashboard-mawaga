<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agency cuma bikin konten Video/Desain lewat Instagram/TikTok. Data
     * lama (Copywriting/Carousel/Facebook/LinkedIn) di-reassign ke
     * kategori/platform terdekat berdasarkan sinyal data (item Copywriting
     * & Carousel semuanya punya slide_count terisi & duration_seconds
     * kosong -> konten berbasis slide, bukan video -> Desain; item
     * Facebook & LinkedIn semuanya bertipe Design/Copywriting/Carousel,
     * bukan video -> Instagram, bukan TikTok), baru row lamanya dihapus.
     */
    public function up(): void
    {
        $contentTypes = DB::table('content_types')->pluck('id', 'name');
        $platforms = DB::table('platforms')->pluck('id', 'name');

        // Rename 'Design' -> 'Desain' in place (id tetap sama, item yang
        // sudah pakai 'Design' otomatis ikut tanpa perlu reassign).
        $desainId = $contentTypes['Desain'] ?? null;
        if (! $desainId && $contentTypes->has('Design')) {
            $desainId = $contentTypes['Design'];
            DB::table('content_types')->where('id', $desainId)->update(['name' => 'Desain']);
        }

        if ($desainId) {
            foreach (['Copywriting', 'Carousel'] as $name) {
                if ($contentTypes->has($name)) {
                    DB::table('content_items')
                        ->where('content_type_id', $contentTypes[$name])
                        ->update(['content_type_id' => $desainId]);

                    DB::table('content_types')->where('id', $contentTypes[$name])->delete();
                }
            }
        }

        $instagramId = $platforms['Instagram'] ?? null;

        // Semua tabel yang punya foreign key platform_id -> platforms.id
        // (lihat migration create_*_table masing-masing) - harus direassign
        // dulu sebelum row Facebook/LinkedIn boleh dihapus.
        $platformReferencingTables = [
            'content_items', 'audience_insights', 'api_integrations',
            'analytics_sync_logs', 'content_publications', 'content_metrics',
        ];

        if ($instagramId) {
            foreach (['Facebook', 'LinkedIn'] as $name) {
                if ($platforms->has($name)) {
                    foreach ($platformReferencingTables as $table) {
                        DB::table($table)
                            ->where('platform_id', $platforms[$name])
                            ->update(['platform_id' => $instagramId]);
                    }

                    DB::table('platforms')->where('id', $platforms[$name])->delete();
                }
            }
        }
    }

    /**
     * Tidak reversible dengan aman - setelah reassign, kategori/platform
     * asli tiap content item sudah tidak bisa direkonstruksi lagi.
     */
    public function down(): void
    {
        //
    }
};
