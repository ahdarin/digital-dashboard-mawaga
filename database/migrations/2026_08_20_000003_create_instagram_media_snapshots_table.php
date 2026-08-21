<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache/snapshot media Instagram hasil sync (default 2 bulan / historical
 * per bulan) - dipakai halaman "Unmatched Instagram Media" biar TIDAK perlu
 * live-fetch ke API tiap kali dibuka (lihat diskusi optimasi sync).
 *
 * Dibuat sebagai tabel baru SETELAH diaudit: content_publications TIDAK BISA
 * dipakai untuk ini karena content_item_id-nya NOT NULL by design (memang
 * cuma boleh nampung publikasi yang SUDAH ke-link ke content_item) - bikin
 * dummy content_item cuma buat nampung media unmatched adalah anti-pattern
 * yang eksplisit dihindari di sini.
 *
 * Bukan cuma nyimpen yang unmatched - SEMUA media yang pernah kelihatan di
 * sync (matched/unmatched/ambiguous) di-upsert ke sini, biar begitu status
 * matching-nya berubah (mis. di-manual-link), baris yang sama tinggal
 * di-update, bukan nyisa data basi.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('instagram_media_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_integration_id')->constrained('api_integrations')->cascadeOnDelete();
            $table->string('external_post_id');
            $table->string('permalink', 500)->nullable();
            $table->text('caption')->nullable();
            $table->string('media_type')->nullable();
            $table->string('media_product_type')->nullable();
            $table->dateTime('published_at')->nullable();
            // CDN URL Instagram bertanda-tangan & biasanya kadaluarsa setelah
            // beberapa waktu - disimpan apa adanya, otomatis ter-refresh tiap
            // media ini muncul lagi di sync berikutnya (bukan dijamin selalu valid).
            $table->string('thumbnail_url', 1000)->nullable();
            $table->string('match_status')->default('unmatched'); // unmatched | matched | ambiguous
            $table->foreignId('content_publication_id')->nullable()->constrained('content_publications')->nullOnDelete();
            $table->timestamp('last_fetched_at');
            $table->timestamps();

            $table->unique(['api_integration_id', 'external_post_id'], 'instagram_media_snapshots_integration_post_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('instagram_media_snapshots');
    }
};
