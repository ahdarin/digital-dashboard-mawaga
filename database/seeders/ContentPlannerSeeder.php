<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed 247 ContentItem historis dari Content Planner Excel lama, dari
 * fixture statis `database/seeders/data/content_planner.php` - BUKAN dari
 * membaca/parsing file Excel lagi.
 *
 * Kenapa fixture, bukan jalankan `content-planner:import` langsung ke
 * production: mengimpor langsung dari laptop ke database Railway lewat
 * TCP proxy publik terbukti tidak stabil (koneksi mati diam-diam di tengah
 * ~1400+ query round-trip fase klasifikasi + ~1000 query fase tulis,
 * proses menggantung tanpa timeout - lihat riwayat sesi). Fixture ini
 * adalah HASIL AKHIR yang sudah tervalidasi (247 valid, 0 unresolved_client,
 * 0 unresolved_pic) dari `content-planner:import` yang berhasil dijalankan
 * penuh di database dev lokal - seeder ini cuma menuliskannya ke database
 * manapun lewat query yang jumlahnya SANGAT SEDIKIT (lihat di bawah),
 * jauh lebih tahan terhadap koneksi yang goyang.
 *
 * Fixture menyimpan foreign key sebagai NATURAL KEY (nama client, tahun-
 * bulan rencana, nama pillar/tipe/platform, email PIC) - BUKAN id mentah -
 * karena id Client/ContentPlan/User tidak dijamin sama antar environment.
 * Seeder ini yang resolve natural key -> id lewat lookup map yang di-
 * preload SEKALI di awal (bukan query per baris).
 *
 * PRASYARAT (harus sudah ada di database tujuan SEBELUM seeder ini
 * dijalankan - seeder ini TIDAK membuatkannya):
 * - 14 Client real (Yasmin, GGA, LuxSuits, TSA, FTI UNAND, dst.)
 * - 19 ContentPlan bulanan (client+tahun+bulan yang cocok dengan fixture)
 * - Roster staf dari `content-planner:import-team` (PIC di-lookup by email;
 *   kalau belum ada, PIC ikut fallback external_pic_name - TIDAK
 *   menggagalkan seeding, tapi kualitas data PIC berkurang)
 * Kalau Client/ContentPlan yang dibutuhkan tidak ditemukan, seeder BERHENTI
 * dengan pesan jelas (bukan diam-diam melewati/menebak) - itu bug prasyarat
 * yang harus diperbaiki dulu, bukan sesuatu yang boleh terjadi diam-diam
 * pada data production.
 *
 * Idempotency: identity key sama persis dengan `content-planner:import`
 * (import_source + external_reference, constraint unique
 * content_items_import_identity_unique yang sudah ada di schema) - baris
 * yang external_reference-nya sudah ada di database tujuan otomatis
 * dilewati. Menjalankan seeder ini berkali-kali aman.
 *
 * Query per run (~247 baris fixture, TIDAK bertambah proporsional dengan
 * jumlah baris kecuali insert dipecah per 500 baris untuk dataset yang
 * jauh lebih besar):
 *   6 query preload (Client, ContentPlan, ContentPillar, ContentType,
 *   Platform, User) + 1 query cek existing external_reference + 1 batch
 *   INSERT content_items + 1 SELECT ambil id yang baru dibuat + 1 batch
 *   INSERT content_workflows + 1 batch INSERT content_item_assignments +
 *   1 batch INSERT content_status_logs = ~12 query total, TIDAK peduli
 *   247 atau 2470 baris (selama masih di bawah batas satu chunk).
 *
 * Batch insert lewat query builder (`DB::table()->insert()`), BUKAN
 * `Model::create()` - dua alasan: (1) jauh lebih sedikit round-trip query
 * dibanding create() satu-satu, (2) TIDAK memicu Eloquent event/observer
 * sama sekali - termasuk `ContentWorkflowObserver` yang men-trigger proses
 * Python (Delay Risk) SINKRON per item berstatus brief_ready. Untuk seed
 * data historis (sebagian besar sudah uploaded/lampau), memicu prediksi ML
 * real-time per baris itu tidak perlu dan cuma memperlambat - skor Delay
 * Risk bisa dihitung belakangan lewat `RecomputeDelayRiskScores` kalau
 * memang dibutuhkan.
 */
class ContentPlannerSeeder extends Seeder
{
    // WAJIB sama persis dengan ImportContentPlanner::IMPORT_SOURCE (private,
    // tidak bisa direferensikan langsung) - inilah yang menjaga identity key
    // (import_source+external_reference) tetap konsisten dengan data yang
    // sudah ada dari real import sebelumnya (kalau ada).
    private const IMPORT_SOURCE = 'content_planner_xlsx';

    private const CHUNK_SIZE = 500;

    public function run(): void
    {
        $fixture = require __DIR__ . '/data/content_planner.php';

        if (empty($fixture)) {
            $this->command?->warn('Fixture content_planner.php kosong - tidak ada yang di-seed.');
            return;
        }

        // ===== Preload lookup - SEKALI, bukan per baris =====
        $clientIds = Client::pluck('id', 'name');

        $planRows = ContentPlan::select('id', 'client_id', 'year', 'month')->get();
        $planIds = $planRows->mapWithKeys(
            fn ($p) => ["{$p->client_id}-{$p->year}-{$p->month}" => $p->id]
        );

        $pillarIds = ContentPillar::pluck('id', 'name');
        $typeIds = ContentType::pluck('id', 'name');
        $platformIds = Platform::pluck('id', 'name');

        // Email di-normalize lowercase - konsisten dengan
        // ImportContentPlanner::resolveUser() (whereRaw LOWER(email)).
        $userIdsByEmail = User::select('id', 'email')->get()
            ->mapWithKeys(fn ($u) => [strtolower($u->email) => $u->id]);

        $existingReferences = DB::table('content_items')
            ->where('import_source', self::IMPORT_SOURCE)
            ->pluck('external_reference')
            ->flip();

        // ===== Resolve fixture -> baris siap insert, validasi prasyarat =====
        $toInsert = [];
        $missingClients = [];
        $missingPlans = [];
        $unresolvedPicEmails = [];
        $skippedAlready = 0;

        foreach ($fixture as $row) {
            if (isset($existingReferences[$row['external_reference']])) {
                $skippedAlready++;
                continue;
            }

            $clientId = $clientIds[$row['client_name']] ?? null;
            if (! $clientId) {
                $missingClients[$row['client_name']] = true;
                continue;
            }

            $planKey = "{$clientId}-{$row['plan_year']}-{$row['plan_month']}";
            $planId = $planIds[$planKey] ?? null;
            if (! $planId) {
                $missingPlans["{$row['client_name']} {$row['plan_year']}-{$row['plan_month']}"] = true;
                continue;
            }

            $picUserId = null;
            if ($row['pic_email']) {
                $picUserId = $userIdsByEmail[strtolower($row['pic_email'])] ?? null;
                if (! $picUserId) {
                    // TIDAK menggagalkan baris (sama seperti importer asli -
                    // unresolved PIC bukan alasan tolak) - cuma dicatat buat
                    // dilaporkan, PIC jatuh ke fallback external_pic_email.
                    $unresolvedPicEmails[$row['pic_email']] = true;
                }
            }

            $toInsert[] = [
                'fixture' => $row,
                'client_id' => $clientId,
                'content_plan_id' => $planId,
                'pillar_id' => $row['pillar_name'] ? ($pillarIds[$row['pillar_name']] ?? null) : null,
                'content_type_id' => $row['content_type_name'] ? ($typeIds[$row['content_type_name']] ?? null) : null,
                'platform_id' => $row['platform_name'] ? ($platformIds[$row['platform_name']] ?? null) : null,
                'pic_user_id' => $picUserId,
            ];
        }

        if (! empty($missingClients)) {
            $this->command?->error('Client berikut tidak ditemukan di database - seeder dihentikan: ' . implode(', ', array_keys($missingClients)));
            $this->command?->error('Buat Client ini dulu (lewat UI atau script setup) sebelum menjalankan seeder ini lagi.');
            return;
        }

        if (! empty($missingPlans)) {
            $this->command?->error('Content Plan berikut tidak ditemukan - seeder dihentikan: ' . implode(', ', array_keys($missingPlans)));
            $this->command?->error('Buat Content Plan bulanan ini dulu sebelum menjalankan seeder ini lagi.');
            return;
        }

        if (empty($toInsert)) {
            $this->command?->info("Tidak ada baris baru - {$skippedAlready} sudah pernah di-seed sebelumnya.");
            return;
        }

        if (! empty($unresolvedPicEmails)) {
            $this->command?->warn('PIC berikut belum ada User-nya (roster belum lengkap) - baris tetap di-seed, PIC jatuh ke external_pic_email: ' . implode(', ', array_keys($unresolvedPicEmails)));
        }

        $now = Carbon::now();
        $batchId = (string) Str::uuid();

        // ===== Satu transaksi pendek untuk SEMUA batch insert - atomik:
        // kalau ada yang gagal di tengah, semua rollback, tidak ada state
        // "separuh ke-seed" yang bisa bikin re-run berikutnya salah hitung
        // idempotency. Durasi transaksi ini singkat (cuma ~4 statement
        // batch, bukan ~1000 query satu-satu), jauh lebih tahan terhadap
        // koneksi yang goyang dibanding transaksi importer asli yang bisa
        // terbuka bermenit-menit. =====
        DB::transaction(function () use ($toInsert, $now, $batchId) {
            foreach (array_chunk($toInsert, self::CHUNK_SIZE) as $chunk) {
                $contentItemRows = [];
                foreach ($chunk as $entry) {
                    $row = $entry['fixture'];
                    $contentItemRows[] = [
                        'content_plan_id' => $entry['content_plan_id'],
                        'client_id' => $entry['client_id'],
                        'content_pillar_id' => $entry['pillar_id'],
                        'content_type_id' => $entry['content_type_id'],
                        'content_format' => $row['content_format'],
                        'platform_id' => $entry['platform_id'],
                        'title' => $row['title'],
                        'brief' => $row['brief'],
                        'caption_draft' => $row['caption_draft'],
                        'deadline_at' => $row['deadline_at'],
                        'content_file_link' => $row['content_file_link'],
                        'scheduled_upload_at' => $row['scheduled_upload_at'],
                        'is_posted' => $row['is_posted'],
                        'import_source' => self::IMPORT_SOURCE,
                        'import_batch_id' => $batchId,
                        'external_reference' => $row['external_reference'],
                        'external_pic_name' => $entry['pic_user_id'] ? null : $row['external_pic_name'],
                        'external_pic_email' => $entry['pic_user_id'] ? null : ($row['external_pic_email'] ?? $row['pic_email']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('content_items')->insert($contentItemRows);

                // Ambil id yang baru dibuat lewat external_reference (unique)
                // - SATU query select-back, bukan mengandalkan lastInsertId()
                // yang tidak aman dipakai untuk multi-row insert.
                $refs = array_column($contentItemRows, 'external_reference');
                $newIds = DB::table('content_items')
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->whereIn('external_reference', $refs)
                    ->pluck('id', 'external_reference');

                $workflowRows = [];
                $assignmentRows = [];
                $statusLogRows = [];

                foreach ($chunk as $entry) {
                    $row = $entry['fixture'];
                    $itemId = $newIds[$row['external_reference']];

                    $workflowRows[] = [
                        'content_item_id' => $itemId,
                        'current_pic_id' => $entry['pic_user_id'],
                        'current_status' => $row['current_status'],
                        'is_overdue' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($entry['pic_user_id'] && $row['assignment_role']) {
                        $assignmentRows[] = [
                            'content_item_id' => $itemId,
                            'user_id' => $entry['pic_user_id'],
                            'assignment_role' => $row['assignment_role'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    $statusLogRows[] = [
                        'content_item_id' => $itemId,
                        'changed_by_user_id' => $entry['pic_user_id'],
                        'from_status' => null,
                        'to_status' => $row['current_status'],
                        'approval_type' => null,
                        'notes' => $row['status_log_notes'],
                        'changed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('content_workflows')->insert($workflowRows);
                if (! empty($assignmentRows)) {
                    DB::table('content_item_assignments')->insert($assignmentRows);
                }
                DB::table('content_status_logs')->insert($statusLogRows);
            }
        });

        $this->command?->info('=== ContentPlannerSeeder SELESAI ===');
        $this->command?->line('ContentItem dibuat: ' . count($toInsert));
        $this->command?->line("Sudah pernah di-seed sebelumnya (dilewati): {$skippedAlready}");
        $this->command?->line("Batch id: {$batchId}");
    }
}
