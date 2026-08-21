<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import roster tim REAL dari sheet GUIDE (Content Planner XLSX) ke
 * TeamMember - TERPISAH dari User (audit "Real Data Only - Team koreksi"
 * Agustus 2026): GUIDE cuma daftar nama+email per client, BUKAN roster akun
 * login. Orang di GUIDE yang belum punya User TETAP harus tercatat sebagai
 * anggota tim real, bukan dipaksa jadi akun dengan password fabrikasi.
 *
 * Client assignment TIDAK cuma diambil dari 1 baris GUIDE orang itu -
 * di-cross-reference ke SELURUH sheet client (kolom PIC/email tiap baris
 * konten) buat nangkep kerja lintas-client nyata (terbukti: "Dika" muncul
 * di 5 sheet client berbeda, bukan cuma DARWIN yang tercatat di GUIDE).
 *
 * WAJIB --dry-run dulu, sama seperti content-planner:import.
 */
class ImportContentPlannerTeam extends Command
{
    protected $signature = 'content-planner:import-team
        {path : Path ke file .xlsx, relatif ke storage/app atau absolut}
        {--dry-run : WAJIB dipakai dulu - zero database write, cuma laporan}';

    protected $description = 'Import roster tim real dari sheet GUIDE ke TeamMember (dry-run WAJIB sebelum real import)';

    private const IMPORT_SOURCE = 'content_planner_guide';

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

        $spreadsheet = IOFactory::load($path);

        $guideSheet = $spreadsheet->getSheetByName('GUIDE');
        if (! $guideSheet) {
            $this->error('Sheet GUIDE tidak ditemukan di workbook.');
            return self::FAILURE;
        }

        $people = $this->extractGuidePeople($guideSheet);
        $clientEmailMap = $this->scanContentSheetsForEmails($spreadsheet);

        $existingByEmail = TeamMember::whereNotNull('email')->pluck('id', 'email');
        $usersByEmailLower = [];
        foreach (User::all(['id', 'email']) as $u) {
            if ($u->email) $usersByEmailLower[strtolower(trim($u->email))] = $u->id;
        }
        $usersByNameLower = [];
        foreach (User::all(['id', 'name']) as $u) {
            $usersByNameLower[strtolower(trim($u->name))] = $u->id;
        }

        $rows = [];
        foreach ($people as $p) {
            $emailKey = $p['email'] ? strtolower($p['email']) : null;

            $classification = 'NEW';
            if ($emailKey === null || $p['name'] === '') {
                $classification = 'INVALID_ROW';
            } elseif (isset($existingByEmail[$p['email']])) {
                $classification = 'EXISTING';
            }

            $linkedUserId = $emailKey && isset($usersByEmailLower[$emailKey]) ? $usersByEmailLower[$emailKey] : null;

            $conflict = null;
            if (! $linkedUserId && $emailKey) {
                $nameKey = strtolower($p['name']);
                if (isset($usersByNameLower[$nameKey])) {
                    $conflict = "nama '{$p['name']}' cocok dengan User existing (id={$usersByNameLower[$nameKey]}), tapi email GUIDE ({$p['email']}) beda dari email User tsb - TIDAK di-link otomatis.";
                }
            }

            $clientIds = $emailKey ? ($clientEmailMap[$emailKey] ?? []) : [];
            // Pastikan client dari baris GUIDE sendiri ikut masuk, walau
            // kebetulan tidak ketemu lagi di scan konten (mis. semua baris
            // konten client itu untuk orang ini kena filter invalid).
            if ($p['guide_client_id'] && ! in_array($p['guide_client_id'], $clientIds, true)) {
                $clientIds[] = $p['guide_client_id'];
            }

            $rows[] = [
                'name' => $p['name'],
                'email' => $p['email'],
                'guide_client_name' => $p['guide_client_name'],
                'guide_client_id' => $p['guide_client_id'],
                'external_reference' => $p['external_reference'],
                'classification' => $classification,
                'linked_user_id' => $linkedUserId,
                'conflict' => $conflict,
                'client_ids' => array_values(array_unique($clientIds)),
            ];
        }

        $this->printSummary($rows);

        if ($dryRun) {
            $this->warn("\nIni DRY RUN - TIDAK ADA baris database yang ditulis.");
            return self::SUCCESS;
        }

        $this->performRealImport($rows);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name:string, email:?string, guide_client_name:string, guide_client_id:?int, external_reference:string}>
     */
    private function extractGuidePeople($guideSheet): array
    {
        $highestRow = $guideSheet->getHighestDataRow();
        $people = [];

        for ($r = 4; $r <= $highestRow; $r++) {
            $clientName = trim((string) $guideSheet->getCell("C{$r}")->getValue());
            $name = trim((string) $guideSheet->getCell("F{$r}")->getValue());
            $email = $this->cleanEmail((string) $guideSheet->getCell("G{$r}")->getValue());

            if ($clientName === '' && $name === '') {
                continue;
            }

            $guideClientId = ImportContentPlanner::CLIENT_SHEET_MAP[strtoupper($clientName)] ?? null;

            $people[] = [
                'name' => $name,
                'email' => $email !== '' ? $email : null,
                'guide_client_name' => $clientName,
                'guide_client_id' => $guideClientId,
                'external_reference' => "GUIDE:row{$r}",
            ];
        }

        return $people;
    }

    /**
     * Hapus karakter unicode tak-terlihat (word joiner U+2060, zero-width
     * space dkk) yang TERBUKTI nyasar ke beberapa email GUIDE (mis. LOVI/
     * THIAH) - kalau tidak dibersihkan, email tersimpan corrupt dan tidak
     * pernah bisa match apapun.
     */
    private function cleanEmail(string $raw): string
    {
        $clean = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $raw);
        return trim($clean);
    }

    /**
     * Scan SELURUH sheet client (bukan cuma baris GUIDE) buat email di
     * kolom PIC (F) tiap baris konten - membangun peta email -> client_id
     * yang mencerminkan kerja lintas-client nyata, bukan cuma 1 asosiasi
     * snapshot GUIDE.
     *
     * @return array<string, int[]> lowercase email => list client_id
     */
    private function scanContentSheetsForEmails($spreadsheet): array
    {
        $map = [];

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            if (in_array($sheetName, ImportContentPlanner::NON_CONTENT_SHEETS, true)) {
                continue;
            }

            $clientId = ImportContentPlanner::CLIENT_SHEET_MAP[strtoupper(trim($sheetName))] ?? null;
            if (! $clientId) {
                continue;
            }

            $sheet = $spreadsheet->getSheetByName($sheetName);
            $highestRow = $sheet->getHighestDataRow();

            for ($r = 7; $r <= $highestRow; $r++) {
                $code = trim((string) $sheet->getCell("C{$r}")->getValue());
                $email = $this->cleanEmail((string) $sheet->getCell("F{$r}")->getValue());
                $title = trim((string) $sheet->getCell("J{$r}")->getValue());

                if ($code === '' || $email === '') {
                    continue;
                }
                // TEMPLATE_PLACEHOLDER (Langkah "Metro Lorem Ipsum") - baris
                // contoh template, bukan bukti kerja real - jangan jadi
                // evidence asosiasi client buat siapapun.
                if (str_contains(strtolower($title), 'lorem ipsum')) {
                    continue;
                }

                $key = strtolower($email);
                $map[$key] = $map[$key] ?? [];
                if (! in_array($clientId, $map[$key], true)) {
                    $map[$key][] = $clientId;
                }
            }
        }

        return $map;
    }

    private function printSummary(array $rows): void
    {
        $byClass = [];
        foreach ($rows as $r) {
            $byClass[$r['classification']] = ($byClass[$r['classification']] ?? 0) + 1;
        }
        $linkable = collect($rows)->whereNotNull('linked_user_id')->count();
        $conflicts = collect($rows)->whereNotNull('conflict')->count();

        $this->newLine();
        $this->info('=== GUIDE TEAM IMPORT SUMMARY ===');
        $this->line('TOTAL GUIDE PEOPLE: '.count($rows));
        foreach (['NEW', 'EXISTING', 'INVALID_ROW'] as $k) {
            $this->line("{$k}: ".($byClass[$k] ?? 0));
        }
        $this->line("LINKABLE_USERS: {$linkable}");
        $this->line("EMAIL_CONFLICT: {$conflicts}");

        $this->newLine();
        foreach ($rows as $r) {
            $clients = $r['client_ids'] ? implode(',', $r['client_ids']) : '-';
            $link = $r['linked_user_id'] ? "-> User#{$r['linked_user_id']}" : ($r['conflict'] ? '-> CONFLICT' : '-> no account');
            $this->line("[{$r['classification']}] {$r['name']} <{$r['email']}> clients={$clients} {$link}");
            if ($r['conflict']) {
                $this->warn('    '.$r['conflict']);
            }
        }
    }

    private function performRealImport(array $rows): void
    {
        $created = 0;
        $existing = 0;
        $linked = 0;
        $skippedInvalid = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($rows, &$created, &$existing, &$linked, &$skippedInvalid) {
            foreach ($rows as $r) {
                if ($r['classification'] === 'INVALID_ROW') {
                    $skippedInvalid++;
                    continue;
                }

                $teamMember = TeamMember::firstOrCreate(
                    ['email' => $r['email']],
                    [
                        'name' => $r['name'],
                        'source' => self::IMPORT_SOURCE,
                        'external_reference' => $r['external_reference'],
                        'user_id' => $r['linked_user_id'],
                    ]
                );

                // Sinkronisasi system role User terhubung ke operational_role
                // (kalau sudah di-backfill) ditangani otomatis oleh
                // TeamMemberObserver - baik lewat create() di atas maupun
                // update() di bawah, jadi tidak perlu dipanggil manual di sini.
                if ($teamMember->wasRecentlyCreated) {
                    $created++;
                } else {
                    $existing++;
                    // Idempotent re-run - link user_id kalau belum ke-set
                    // sebelumnya tapi sekarang deterministic ketemu (mis.
                    // User-nya baru dibuat setelah TeamMember-nya).
                    if (! $teamMember->user_id && $r['linked_user_id']) {
                        $teamMember->update(['user_id' => $r['linked_user_id']]);
                    }
                }

                if ($r['linked_user_id']) {
                    $linked++;
                }

                foreach ($r['client_ids'] as $clientId) {
                    if (Client::find($clientId)) {
                        $teamMember->clients()->syncWithoutDetaching([$clientId]);
                    }
                }
            }
        });

        $this->newLine();
        $this->info('=== REAL IMPORT SELESAI ===');
        $this->line("TeamMember dibuat: {$created}");
        $this->line("TeamMember sudah ada sebelumnya: {$existing}");
        $this->line("Ter-link ke User: {$linked}");
        $this->line("Dilewati (invalid row): {$skippedInvalid}");
    }
}
