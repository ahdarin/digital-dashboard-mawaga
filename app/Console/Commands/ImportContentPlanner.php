<?php

namespace App\Console\Commands;

use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import Content Planner Excel lama (workflow operasional asli agensi,
 * dipakai tim SEBELUM dashboard Laravel jadi source utama) -> ContentItem/
 * ContentWorkflow/ContentItemAssignment.
 *
 * WAJIB --dry-run dulu (zero database write) sebelum real import - lihat
 * audit "Content Planner Import" untuk struktur workbook lengkap, mapping
 * kolom, dan keputusan setiap ambiguitas (status "2 - Ready", pillar
 * forward-fill, platform dari kolom S, dst).
 *
 * Struktur workbook (dibuktikan lewat inspeksi nyata, bukan asumsi):
 * - 15 sheet per-client (nama sheet = singkatan client), header row 5
 *   IDENTIK di semua sheet.
 * - 3 sheet non-content: "📒 1. Setup" (master data planner), "GUIDE"
 *   (roster PIC + daftar client), "Locked" (definisi metrik cost/report).
 * - Kode kolom C berprefix "C" (Konten/video, PIC = "CC" di baris 4) atau
 *   "D" (Desain, PIC = "GD") - INI sumber content_type_id, BUKAN kolom
 *   "Jenis" (I) yang cuma sub-label buat baris Konten (Video/Carousel
 *   Feed/Single Feed).
 * - ~90% baris adalah template placeholder kosong (Status=Backlog TANPA
 *   judul) - BUKAN konten real, di-skip otomatis.
 *
 * Real import (tanpa --dry-run) HANYA memproses baris yang lolos SEMUA
 * validasi wajib (client+status+deadline+title+ContentPlan) - 6 pertanyaan
 * terbuka dari audit (status "2-Ready", 10 client belum ada di DB, pillar
 * forward-fill, dst) SENGAJA TIDAK dijawab sepihak di sini; baris yang
 * kena itu tetap tidak diimpor sampai ada keputusan eksplisit.
 */
class ImportContentPlanner extends Command
{
    protected $signature = 'content-planner:import
        {path : Path ke file .xlsx, relatif ke storage/app atau absolut}
        {--dry-run : WAJIB dipakai dulu - zero database write, cuma laporan}';

    protected $description = 'Import Content Planner Excel lama ke ContentItem/Workflow/Assignment (dry-run WAJIB sebelum real import)';

    public const NON_CONTENT_SHEETS = ['📒 1. Setup', 'GUIDE', 'Locked'];
    private const IMPORT_SOURCE = 'content_planner_xlsx';

    /**
     * Hasil audit Phase 7 (manual, BUKAN fuzzy match runtime) - nama sheet
     * (uppercase) => client_id. null = tidak ada Client match di database,
     * REVIEW_REQUIRED (Langkah 7: "JANGAN create Client baru otomatis").
     */
    public const CLIENT_SHEET_MAP = [
        'YASMIN' => 1, 'GGA' => 2, 'TSA' => 4, 'FTI' => 5, 'METRO' => 7,
        // SEWAJAS -> LuxSuits (client 3): CONFIRMED_ALIAS, dibuktikan lewat
        // baris J10 sheet SEWAJAS sendiri ("5 Alasan Sewa Jas di lux suit" -
        // sebut brand "lux suit" eksplisit di caption), "Sewa Jas" = sewa
        // jas/suit rental = persis brief_context LuxSuits ("formal-wear
        // rental"), dan tema konten (wisuda, "Warna Jas", "Booking Jas")
        // konsisten total. BUKAN dugaan nama doang - lihat audit alias
        // Agustus 2026. JANGAN buat Client baru "SEWAJAS".
        'SEWAJAS' => 3,
        // 8 client baru (audit Agustus 2026 - client REAL dari workbook
        // operasional, bukan dummy) - nama persis dari kolom "Nama Client"
        // sheet GUIDE, cuma di-title-case.
        'OLEO' => 8, 'DARWIN' => 9, 'UTHIE' => 10, 'SATO' => 11,
        'TATITATU' => 12, 'ALFA SPORT' => 13, 'ODAMILK' => 14, 'LABERTHA' => 15,
        // MSL SENGAJA TETAP null - sheet kosong total (0 content row), tidak
        // ada evidence operasional buat dibuatkan Client (Langkah 3).
        'MSL' => null,
    ];

    /**
     * Hasil audit Phase 10 - status spreadsheet -> current_status canonical
     * (App\Support\WorkflowTransitions). "2 - Ready" SENGAJA tidak ada di
     * sini - ambigu (bisa berarti waiting_review ATAU in_progress ATAU
     * approved, workbook tidak menyatakan eksplisit) - REVIEW_REQUIRED,
     * bukan ditebak.
     */
    private const STATUS_MAP = [
        '4 - Uploaded' => 'uploaded',
        '3 - Revisi' => 'revision',
        '1 - Backlog' => 'brief_ready',
    ];

    private array $seenKeysInBatch = [];

    /** @var array<string,int>|null Cache lookup ContentType master (id keyed by name) - dipakai sekali per run, bukan per row (Langkah "Master-Data Audit" Section 6). */
    private ?array $contentTypeIdsByName = null;

    public function handle(): int
    {
        $rawPath = $this->argument('path');
        $path = str_starts_with($rawPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rawPath)
            ? $rawPath
            : base_path($rawPath);

        if (! file_exists($path)) {
            $this->error("File tidak ditemukan: {$path}");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info("Membaca workbook: {$path}");
        $spreadsheet = IOFactory::load($path);

        [$counters, $rows] = $this->collectRows($spreadsheet);

        $this->printSummary($counters, $dryRun);
        $this->writeReport($rows, $counters, $dryRun);

        if ($dryRun) {
            $this->warn("\nIni DRY RUN - TIDAK ADA baris database yang ditulis.");
            return self::SUCCESS;
        }

        $this->performRealImport($rows);

        return self::SUCCESS;
    }

    /**
     * Baca seluruh sheet + klasifikasi tiap baris - dipakai SAMA PERSIS
     * oleh mode dry-run maupun real import, biar hasil dry-run dan
     * eksekusi asli tidak pernah berbeda logic-nya.
     */
    private function collectRows($spreadsheet): array
    {
        $counters = $this->emptyCounters();
        $rows = [];

        // Preload identity_key yang sudah pernah di-real-import sebelumnya -
        // dipakai buat klasifikasi ALREADY_IMPORTED di laporan dry-run
        // (Langkah 19), bukan cuma dicek diam-diam pas performRealImport().
        $existingReferences = ContentItem::where('import_source', self::IMPORT_SOURCE)
            ->whereNotNull('external_reference')
            ->pluck('external_reference')
            ->flip();

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            if (in_array($sheetName, self::NON_CONTENT_SHEETS, true)) {
                continue;
            }

            $sheet = $spreadsheet->getSheetByName($sheetName);
            $counters['sheets_read']++;

            $clientKey = strtoupper(trim($sheetName));
            $clientId = self::CLIENT_SHEET_MAP[$clientKey] ?? null;

            $highestRow = $sheet->getHighestDataRow();

            for ($r = 7; $r <= $highestRow; $r++) {
                $counters['rows_inspected']++;

                $code = trim((string) $sheet->getCell("C{$r}")->getValue());
                $status = trim((string) $sheet->getCell("D{$r}")->getValue());
                $picName = trim((string) $sheet->getCell("E{$r}")->getValue());
                $picEmail = trim((string) $sheet->getCell("F{$r}")->getValue());
                $deadlineRaw = $sheet->getCell("G{$r}")->getValue();
                $pillarName = trim((string) $sheet->getCell("H{$r}")->getValue());
                $jenis = trim((string) $sheet->getCell("I{$r}")->getValue());
                $title = trim((string) $sheet->getCell("J{$r}")->getValue());
                $briefCell = $sheet->getCell("K{$r}");
                $caption = trim((string) $sheet->getCell("L{$r}")->getValue());
                $uploadDateRaw = $sheet->getCell("O{$r}")->getValue();
                $uploadTimeRaw = $sheet->getCell("P{$r}")->getValue();
                $outputLinkCell = $sheet->getCell("R{$r}");
                $uploadedTiktok = trim((string) $sheet->getCell("S{$r}")->getValue());

                // Baris junk (teks instruksi template yang nyasar ke kolom
                // Status) - bukan konten, jangan hitung sama sekali.
                if (str_contains($status, 'Insert Row') || str_contains(strtolower($status), 'keep credits')) {
                    continue;
                }

                // "Dianggap konten": punya Kode DAN (punya judul ATAU sudah
                // Uploaded) - ~90% baris cuma slot Backlog kosong tanpa
                // judul, itu template placeholder, bukan data real.
                $consideredContent = $code !== '' && ($title !== '' || $status === '4 - Uploaded');
                if (! $consideredContent) {
                    $counters['rows_skipped']++;
                    continue;
                }
                $counters['rows_considered_content']++;

                $issues = [];

                // TEMPLATE_PLACEHOLDER (Langkah 13): baris yang isinya
                // literal teks contoh dari template ("Lorem ipsum dolor sit
                // amet...") - TERBUKTI di sheet METRO (2 baris, caption
                // "asasasas" juga jelas ketikan asal). Ditandai TERPISAH dari
                // "missing operational data" - ini bukan konten yang hilang,
                // memang tidak pernah diisi tim sama sekali. Prioritas
                // klasifikasi PALING TINGGI - kalau kena ini, tidak dianggap
                // valid apapun kondisi field lainnya.
                $isTemplatePlaceholder = str_contains(strtolower($title), 'lorem ipsum');
                if ($isTemplatePlaceholder) {
                    $counters['rows_template_placeholder']++;
                }

                if (! $clientId) {
                    $issues[] = 'unresolved_client';
                    $counters['rows_unresolved_client']++;
                }

                $mappedStatus = self::STATUS_MAP[$status] ?? null;
                if (! $mappedStatus) {
                    $issues[] = 'invalid_status:'.$status;
                    $counters['rows_invalid_status']++;
                }

                $deadline = $this->parseExcelDate($deadlineRaw);
                if (! $deadline) {
                    $issues[] = 'invalid_date';
                    $counters['rows_invalid_date']++;
                }

                if ($title === '') {
                    $issues[] = 'missing_title';
                }

                $picResolution = $this->resolveUser($picName, $picEmail);
                if (! $picResolution['user']) {
                    $issues[] = 'unresolved_pic';
                    $counters['rows_unresolved_pic']++;
                }

                $contentTypeId = $this->resolveContentTypeIdFromKode($code);
                if (! $contentTypeId && $code !== '') {
                    $issues[] = 'unresolved_type';
                    $counters['rows_unresolved_type']++;
                }

                // Pillar SENGAJA TIDAK forward-fill (kolom H kosong di
                // sebagian besar baris karena merge visual per-batch di
                // spreadsheet, cuma diisi eksplisit di baris pertama) -
                // exact-match doang, blank kalau kosong. Forward-fill
                // adalah asumsi yang butuh konfirmasi eksplisit (lihat
                // laporan), bukan ditebak diam-diam.
                $pillar = $pillarName !== '' ? ContentPillar::where('name', $pillarName)->first() : null;
                if ($pillarName === '') {
                    $counters['rows_unresolved_pillar']++;
                } elseif (! $pillar) {
                    $issues[] = 'unresolved_pillar:'.$pillarName;
                    $counters['rows_unresolved_pillar']++;
                }

                $contentPlan = null;
                if ($clientId && $deadline) {
                    $contentPlan = ContentPlan::where('client_id', $clientId)
                        ->where('month', $deadline->month)
                        ->where('year', $deadline->year)
                        ->first();
                    if (! $contentPlan) {
                        $issues[] = 'missing_content_plan:'.$deadline->format('Y-m');
                    }
                }

                $platform = $uploadedTiktok === '1'
                    ? Platform::where('name', 'TikTok')->first()
                    : Platform::where('name', 'Instagram')->first();

                $scheduledUploadAt = $this->parseExcelDate($uploadDateRaw);
                if ($scheduledUploadAt && is_numeric($uploadTimeRaw) && $uploadTimeRaw >= 0 && $uploadTimeRaw < 1) {
                    $secondsOfDay = (int) round($uploadTimeRaw * 86400);
                    $scheduledUploadAt = $scheduledUploadAt->copy()->startOfDay()->addSeconds($secondsOfDay);
                }

                // Unresolved PIC SENGAJA TIDAK masuk syarat $isValid (Langkah
                // 19: "Unresolved PIC TIDAK otomatis menggagalkan content
                // import jika field bisnis lain valid") - identitas legacy-nya
                // tetap dipertahankan lewat external_pic_name/email di bawah,
                // bukan dijadikan alasan tolak seluruh baris.
                $isValid = ! $isTemplatePlaceholder && $clientId && $mappedStatus && $deadline && $title !== '' && $contentPlan;
                if ($isValid) {
                    $counters['rows_valid']++;
                }

                // Identity key buat duplicate strategy (Langkah 12) DAN
                // provenance external_reference: client + kode + tahun-bulan
                // deadline - Kode ("C1","D2") TERBUKTI berulang tiap bulan
                // dalam 1 sheet yang sama, jadi title/kode saja tidak stabil.
                $periodKey = $deadline ? $deadline->format('Y-m') : 'unknown';
                $identityKey = "{$sheetName}:{$periodKey}:{$code}";

                $alreadyImported = isset($existingReferences[$identityKey]);

                $dupStatus = 'NEW';
                if ($isValid && ! $alreadyImported) {
                    if (isset($this->seenKeysInBatch[$identityKey])) {
                        $dupStatus = 'POSSIBLE_DUPLICATE_IN_FILE';
                        $counters['rows_duplicate']++;
                    } else {
                        $this->seenKeysInBatch[$identityKey] = true;
                        $counters['rows_new']++;
                    }
                }

                // Klasifikasi tunggal buat laporan (Langkah 19) - urutan
                // prioritas: placeholder > sudah diimpor > unresolved client >
                // invalid status > invalid date > missing title > missing
                // content plan > valid. Unresolved PIC BUKAN kategori
                // penolak (lihat $isValid di atas), jadi tidak muncul di sini
                // sebagai reason gagal - cuma flag informational terpisah
                // ('pic_status').
                $classification = match (true) {
                    $isTemplatePlaceholder => 'TEMPLATE_PLACEHOLDER',
                    $alreadyImported && $isValid => 'ALREADY_IMPORTED',
                    ! $clientId => 'UNRESOLVED_CLIENT',
                    ! $mappedStatus => 'INVALID_STATUS',
                    ! $deadline => 'INVALID_DATE',
                    $title === '' => 'MISSING_TITLE',
                    ! $contentPlan => 'MISSING_CONTENT_PLAN',
                    default => 'VALID_REAL',
                };
                if ($classification === 'ALREADY_IMPORTED') {
                    $counters['rows_already_imported']++;
                }

                $rows[] = [
                    'sheet' => $sheetName,
                    'row' => $r,
                    'code' => $code,
                    'title' => $title,
                    'status_raw' => $status,
                    'status_mapped' => $mappedStatus,
                    'pic_name' => $picName,
                    'pic_email' => $picEmail,
                    'pic_resolved_user_id' => $picResolution['user']?->id,
                    'pic_confidence' => $picResolution['confidence'],
                    'deadline_raw' => $deadlineRaw,
                    'deadline_parsed' => $deadline?->toDateString(),
                    'deadline_carbon' => $deadline,
                    'pillar_raw' => $pillarName,
                    'pillar_id' => $pillar?->id,
                    'jenis_raw' => $jenis,
                    'content_type_id' => $contentTypeId,
                    'client_id' => $clientId,
                    'content_plan_id' => $contentPlan?->id,
                    'platform_id' => $platform?->id,
                    'brief_url' => $this->extractHyperlink($briefCell),
                    'caption' => $caption !== '' ? $caption : null,
                    'output_link_url' => $this->extractHyperlink($outputLinkCell),
                    'scheduled_upload_at' => $scheduledUploadAt,
                    'identity_key' => $identityKey,
                    'valid' => $isValid,
                    'duplicate_status' => $dupStatus,
                    'issues' => $issues,
                    'classification' => $classification,
                    'pic_status' => $picResolution['user'] ? 'resolved' : 'unresolved',
                    'is_template_placeholder' => $isTemplatePlaceholder,
                ];
            }
        }

        return [$counters, $rows];
    }

    /**
     * Real write - HANYA baris valid=true DAN duplicate_status=NEW yang
     * diproses. Satu transaction besar buat SELURUH batch (Langkah
     * "transaction-safe") - kalau ada 1 baris gagal di tengah, semua
     * rollback, bukan nyangkut separuh.
     *
     * Idempotency (Langkah "jangan duplicate existing rows"): dicek lewat
     * external_reference (import_source+external_reference unique) SEBELUM
     * create - kalau sudah pernah diimpor (run sebelumnya), dilewati, bukan
     * bikin baris baru.
     *
     * TIDAK PERNAH menyentuh content_metrics/AudienceInsight/ApiIntegration
     * sama sekali - command ini cuma create ContentItem/ContentWorkflow/
     * ContentItemAssignment/ContentStatusLog.
     */
    private function performRealImport(array $rows): void
    {
        $batchId = (string) Str::uuid();
        $created = 0;
        $alreadyImported = 0;

        // content_status_logs.changed_by NOT NULL - untuk baris dengan PIC
        // unresolved, TIDAK ada actor asli yang bisa dicatat sebagai
        // "siapa yang mengubah status ini" (itu histori planner lama, bukan
        // aksi user di sistem ini). Fallback ke CEO pertama sebagai "actor
        // sistem" - pola SAMA PERSIS dipakai DemoSeeder ($picUser fallback).
        // TIDAK dipakai buat current_pic_id (itu tetap null kalau memang
        // unresolved - jangan salah tempatkan tanggung jawab).
        $systemActorId = User::whereHas('roles', fn ($q) => $q->where('name', 'CEO'))->first()?->id
            ?? User::first()?->id;

        if (! $systemActorId) {
            $this->error('Tidak ada user sama sekali di database - dibatalkan.');
            return;
        }

        DB::transaction(function () use ($rows, $batchId, $systemActorId, &$created, &$alreadyImported) {
            foreach ($rows as $row) {
                if (! $row['valid'] || $row['duplicate_status'] !== 'NEW') {
                    continue;
                }

                $exists = ContentItem::where('import_source', self::IMPORT_SOURCE)
                    ->where('external_reference', $row['identity_key'])
                    ->exists();
                if ($exists) {
                    $alreadyImported++;
                    continue;
                }

                $item = ContentItem::create([
                    'content_plan_id' => $row['content_plan_id'],
                    'client_id' => $row['client_id'],
                    'content_pillar_id' => $row['pillar_id'],
                    'content_type_id' => $row['content_type_id'],
                    'platform_id' => $row['platform_id'],
                    'title' => $row['title'],
                    'brief' => $row['brief_url'],
                    'caption_draft' => $row['caption'],
                    'deadline_at' => $row['deadline_carbon'],
                    'content_file_link' => $row['output_link_url'],
                    'scheduled_upload_at' => $row['scheduled_upload_at'],
                    'is_posted' => $row['status_mapped'] === 'uploaded',
                    'import_source' => self::IMPORT_SOURCE,
                    'import_batch_id' => $batchId,
                    'external_reference' => $row['identity_key'],
                    // Preservation identitas PIC legacy (Langkah 1, approved)
                    // - HANYA diisi kalau memang tidak ada User yang resolve,
                    // biar tidak redundant sama assignment yang sudah benar.
                    'external_pic_name' => ! $row['pic_resolved_user_id'] && $row['pic_name'] !== '' ? $row['pic_name'] : null,
                    'external_pic_email' => ! $row['pic_resolved_user_id'] && $row['pic_email'] !== '' ? $row['pic_email'] : null,
                ]);

                ContentWorkflow::create([
                    'content_item_id' => $item->id,
                    'current_pic_id' => $row['pic_resolved_user_id'],
                    'current_status' => $row['status_mapped'],
                    'is_overdue' => false,
                ]);

                ContentStatusLog::create([
                    'content_item_id' => $item->id,
                    'changed_by' => $row['pic_resolved_user_id'] ?? $systemActorId,
                    'from_status' => null,
                    'to_status' => $row['status_mapped'],
                    'approval_type' => null,
                    'notes' => 'Diimpor dari Content Planner lama ('.$row['sheet'].', '.$row['code'].').',
                    'changed_at' => now(),
                ]);

                if ($row['pic_resolved_user_id']) {
                    // Role assignment dari prefix Kode (C=Content Creator,
                    // D=Graphic Designer) - konsisten dengan konvensi
                    // 'content_creator'/'designer' yang sudah dipakai di
                    // seluruh sistem (lihat DemoSeeder/MasterDataSeeder).
                    $assignmentRole = str_starts_with(strtoupper($row['code']), 'D') ? 'designer' : 'content_creator';

                    ContentItemAssignment::create([
                        'content_item_id' => $item->id,
                        'user_id' => $row['pic_resolved_user_id'],
                        'assignment_role' => $assignmentRole,
                    ]);
                }

                $created++;
            }
        });

        $this->newLine();
        $this->info('=== REAL IMPORT SELESAI ===');
        $this->line("ContentItem dibuat: {$created}");
        $this->line("Sudah pernah diimpor sebelumnya (dilewati): {$alreadyImported}");
        $this->line("Import batch id: {$batchId}");
    }

    private function emptyCounters(): array
    {
        return [
            'sheets_read' => 0,
            'rows_inspected' => 0,
            'rows_considered_content' => 0,
            'rows_valid' => 0,
            'rows_new' => 0,
            'rows_existing' => 0,
            'rows_duplicate' => 0,
            'rows_skipped' => 0,
            'rows_unresolved_client' => 0,
            'rows_unresolved_pic' => 0,
            'rows_unresolved_type' => 0,
            'rows_unresolved_pillar' => 0,
            'rows_invalid_date' => 0,
            'rows_invalid_status' => 0,
            'rows_template_placeholder' => 0,
            'rows_already_imported' => 0,
        ];
    }

    private function printSummary(array $counters, bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun ? '=== DRY RUN SUMMARY ===' : '=== IMPORT SUMMARY (sebelum write) ===');
        foreach ($counters as $key => $value) {
            $this->line(sprintf('%-28s %d', $key, $value));
        }
    }

    private function writeReport(array $rows, array $counters, bool $dryRun): void
    {
        $dir = storage_path('app/import-reports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $label = $dryRun ? 'dry-run' : 'real-run';
        $date = now()->format('Ymd-His');
        $jsonPath = "{$dir}/content-planner-{$label}-{$date}.json";

        $serializableRows = array_map(function ($row) {
            $row['deadline_carbon'] = $row['deadline_carbon']?->toDateTimeString();
            $row['scheduled_upload_at'] = $row['scheduled_upload_at']?->toDateTimeString();
            return $row;
        }, $rows);

        file_put_contents($jsonPath, json_encode([
            'generated_at' => now()->toDateTimeString(),
            'mode' => $dryRun ? 'dry-run' : 'real-run',
            'counters' => $counters,
            'rows' => $serializableRows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("\nReport tersimpan:");
        $this->line("  {$jsonPath}");
    }

    private function parseExcelDate($value): ?Carbon
    {
        if (! is_numeric($value) || $value <= 0) {
            return null;
        }

        try {
            $dt = ExcelDate::excelToDateTimeObject($value);
            return Carbon::instance($dt)->setTimezone('Asia/Jakarta');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * PIC resolution: exact email -> exact normalized name. TIDAK ADA
     * fuzzy match (Langkah 8: "jangan fuzzy assign PIC dengan confidence
     * rendah").
     */
    private function resolveUser(string $name, string $email): array
    {
        $email = trim($email);
        if ($email !== '') {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
            if ($user) {
                return ['user' => $user, 'confidence' => 'exact_email'];
            }
        }

        $name = trim($name);
        if ($name !== '') {
            $user = User::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
            if ($user) {
                return ['user' => $user, 'confidence' => 'exact_name'];
            }
        }

        return ['user' => null, 'confidence' => 'unresolved'];
    }

    /**
     * ContentType dari prefix Kode ("C" = Konten/Video, "D" = Desain) -
     * TERBUKTI dari header baris 4 tiap sheet ("CC: <nama> | GD: <nama>")
     * dan validasi silang PIC per baris, BUKAN dari kolom "Jenis" (itu cuma
     * sub-label buat baris Konten). Lookup ID di-cache sekali per run
     * ($this->contentTypeIdsByName), bukan query ContentType per baris.
     */
    private function resolveContentTypeIdFromKode(string $kode): ?int
    {
        if ($kode === '') {
            return null;
        }

        $this->contentTypeIdsByName ??= ContentType::whereIn('name', ['Video', 'Desain'])->pluck('id', 'name')->all();

        $prefix = strtoupper(substr($kode, 0, 1));

        return match ($prefix) {
            'C' => $this->contentTypeIdsByName['Video'] ?? null,
            'D' => $this->contentTypeIdsByName['Desain'] ?? null,
            default => null,
        };
    }

    private function extractHyperlink(Cell $cell): ?string
    {
        $hyperlink = $cell->getHyperlink();
        $url = $hyperlink->getUrl();
        return $url !== '' ? $url : null;
    }
}
