<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * content_metrics jadi source-of-truth tunggal analytics buat data
 * Instagram real, TERMASUK post yang belum ke-link ke ContentItem internal
 * (lihat audit "Data Source Architecture" - dashboard/AI Strategy sebelumnya
 * 100% baca content_metrics via whereHas('contentItem'), jadi post unmatched
 * nggak pernah kelihatan walau insight-nya sudah berhasil ditarik dari API).
 *
 * - content_item_id jadi NULLABLE: baris metric TETAP bisa ada tanpa
 *   ContentItem (post Instagram real yang belum di-link manual/schedule-
 *   match). doctrine/dbal belum terpasang, pakai raw ALTER MODIFY (cuma
 *   ubah nullability, FK existing & data lama tidak tersentuh).
 * - client_id (baru): WAJIB ada supaya baris tanpa content_item_id tetap
 *   bisa di-scope per client (whereHas('contentItem', client_id=X) mustahil
 *   dipakai kalau content_item_id null - relasi kosong nggak pernah match).
 * - instagram_media_snapshot_id (baru): identitas stabil 1-post-1-baris
 *   buat media Instagram (matched ATAUPUN unmatched) - dipakai sebagai kunci
 *   upsert utama di InstagramAnalyticsSyncService, TERPISAH dari
 *   content_metrics_composite_unique existing (content_item_id+platform_id+
 *   metric_date) yang TETAP DIPERTAHANKAN APA ADANYA buat jalur CSV import -
 *   CSV sama sekali tidak tahu soal snapshot Instagram.
 */
return new class extends Migration {
    public function up(): void {
        DB::statement('ALTER TABLE content_metrics MODIFY content_item_id BIGINT UNSIGNED NULL');

        Schema::table('content_metrics', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('content_item_id')
                ->constrained('clients')->nullOnDelete();
            $table->foreignId('instagram_media_snapshot_id')->nullable()->after('client_id')
                ->constrained('instagram_media_snapshots')->nullOnDelete();

            $table->unique(['instagram_media_snapshot_id', 'metric_date'], 'content_metrics_snapshot_date_unique');
        });

        $this->backfillExistingRows();
    }

    /**
     * Query dashboard/AI Strategy pindah dari whereHas('contentItem',
     * client_id=X) ke where('client_id',X) langsung - baris LAMA (dibuat
     * sebelum kolom ini ada) harus di-backfill client_id-nya SEKALI di
     * sini, kalau tidak bakal "hilang" dari query baru walau datanya masih
     * ada. instagram_media_snapshot_id juga di-backfill buat baris yang
     * sudah ke-link ke publication ber-external_post_id - TANPA ini,
     * saveMetric() versi baru (kunci upsert instagram_media_snapshot_id)
     * bakal nganggep itu media BELUM PERNAH disync dan bikin baris DUPLIKAT
     * di sync berikutnya, bukan update baris lama.
     *
     * Pakai query builder mentah (bukan Eloquent model) - konvensi aman
     * buat data migration, nggak tergantung struktur model yang bisa
     * berubah di masa depan.
     */
    private function backfillExistingRows(): void
    {
        // client_id dari relasi content_item -> client (semua baris lama
        // pasti punya content_item_id, karena kolom ini baru aja jadi
        // nullable di migration ini sendiri).
        DB::statement('
            UPDATE content_metrics cm
            INNER JOIN content_items ci ON ci.id = cm.content_item_id
            SET cm.client_id = ci.client_id
            WHERE cm.client_id IS NULL
        ');

        // instagram_media_snapshot_id dari content_publications yang match
        // content_item_id + platform_id, lalu snapshot yang nunjuk balik
        // ke publication itu.
        DB::statement('
            UPDATE content_metrics cm
            INNER JOIN content_publications cp
                ON cp.content_item_id = cm.content_item_id AND cp.platform_id = cm.platform_id
            INNER JOIN instagram_media_snapshots ims ON ims.content_publication_id = cp.id
            SET cm.instagram_media_snapshot_id = ims.id
            WHERE cm.instagram_media_snapshot_id IS NULL
        ');
    }

    public function down(): void {
        Schema::table('content_metrics', function (Blueprint $table) {
            $table->dropUnique('content_metrics_snapshot_date_unique');
            $table->dropConstrainedForeignId('instagram_media_snapshot_id');
            $table->dropConstrainedForeignId('client_id');
        });

        DB::statement('ALTER TABLE content_metrics MODIFY content_item_id BIGINT UNSIGNED NOT NULL');
    }
};
