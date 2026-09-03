<?php

namespace App\Http\Controllers;

use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\Platform;
use App\Rules\AssignedClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * KF3xx — Audience Dashboard (PRD 7.3.2)
 * Insight audience client: followers growth, gender, rentang usia,
 * lokasi/top city, jam aktif.
 *
 * Sama seperti Content Analytics, halaman ini WAJIB pilih client dulu
 * (biar nggak ngagregat data followers dari klien yang beda-beda niche
 * jadi satu, yang nggak ada gunanya buat dibaca).
 */
class AudienceController extends Controller
{
    /**
     * KF3xx — Import Audience Data
     * Format CSV yang diharapkan (baris pertama = header):
     *
     *   platform,snapshot_date,follower_count,gender_male_pct,
     *   age_13_17_pct,age_18_24_pct,age_25_34_pct,age_35_44_pct,age_45_plus_pct,
     *   location_1,location_1_pct,location_2,location_2_pct,location_3,location_3_pct
     *
     * Catatan format:
     * - gender_female_pct DIHITUNG OTOMATIS (100 - gender_male_pct), nggak
     *   perlu diisi manual - biar nggak ada kasus totalnya bukan 100%.
     * - age_*_pct dan location_*_pct: kolom yang dikosongin dianggap 0,
     *   jumlah age_*_pct idealnya 100 (tapi nggak divalidasi ketat - ini
     *   data manual dari Insights platform, seringkali totalnya nggak
     *   pas 100% karena pembulatan).
     * - active_hours (jam aktif) SENGAJA TIDAK ada di CSV ini - datanya
     *   terlalu detail (24 kolom) buat diisi manual tiap bulan. Kalau
     *   sebelumnya sudah ada snapshot di tanggal yang sama, active_hours
     *   lama TETAP DIPERTAHANKAN (nggak ketimpa jadi kosong).
     * - 1 baris = 1 snapshot (biasanya sebulan sekali per platform).
     */
    public function importCsv(Request $request)
    {
        // Phase L (re-audit) - client_id lewat form field, bukan
        // route-model-binding (client.scope middleware tidak bisa dipasang
        // di /audience/import) - tanpa AssignedClient, SMO ter-scope bisa
        // MENULIS data audiens client manapun cuma dengan ganti client_id di
        // form, sama kelas bug dengan KI-09 (Import CSV Performa) tapi ini
        // sisi tulis, bukan cuma baca.
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id', new AssignedClient],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $client = Client::findOrFail($validated['client_id']);

        $syncLog = \App\Models\AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'imported_by' => auth()->id(),
            'source_type' => 'audience_csv_import',
            'status' => 'pending',
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            $syncLog->update(['status' => 'failed']);
            return back()->with('import_error', 'File CSV kosong atau formatnya nggak kebaca.');
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), $header);
        $required = ['platform', 'snapshot_date', 'follower_count'];
        $missingColumns = array_diff($required, $header);

        if (! empty($missingColumns)) {
            fclose($handle);
            $syncLog->update(['status' => 'failed']);
            return back()->with('import_error', 'Kolom CSV tidak lengkap. Wajib ada: '.implode(', ', $required).'. Yang hilang: '.implode(', ', $missingColumns));
        }

        $successCount = 0;
        $skippedRows = [];
        $rowNumber = 1;
        $platformIdsUsed = [];
        $importedSnapshotDates = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));

            $platformName = trim($data['platform'] ?? '');
            if ($platformName === '') {
                $skippedRows[] = "Baris {$rowNumber}: kolom platform kosong";
                continue;
            }
            $platform = Platform::where('name', $platformName)->first();
            if (! $platform) {
                $skippedRows[] = "Baris {$rowNumber}: platform '{$platformName}' tidak dikenali";
                continue;
            }
            $platformIdsUsed[$platform->id] = true;

            try {
                $snapshotDate = Carbon::parse($data['snapshot_date']);
            } catch (\Exception $e) {
                $skippedRows[] = "Baris {$rowNumber}: format tanggal '{$data['snapshot_date']}' tidak valid";
                continue;
            }

            $genderMale = isset($data['gender_male_pct']) && $data['gender_male_pct'] !== ''
                ? (float) $data['gender_male_pct'] : null;
            $genderBreakdown = $genderMale !== null
                ? ['male' => $genderMale, 'female' => round(100 - $genderMale, 2)]
                : null;

            $ageKeys = ['13-17' => 'age_13_17_pct', '18-24' => 'age_18_24_pct', '25-34' => 'age_25_34_pct', '35-44' => 'age_35_44_pct', '45+' => 'age_45_plus_pct'];
            $ageBreakdown = [];
            foreach ($ageKeys as $label => $col) {
                if (isset($data[$col]) && $data[$col] !== '') {
                    $ageBreakdown[$label] = (float) $data[$col];
                }
            }
            $ageBreakdown = ! empty($ageBreakdown) ? $ageBreakdown : null;

            $topLocations = [];
            foreach ([1, 2, 3] as $i) {
                $cityCol = "location_{$i}";
                $pctCol = "location_{$i}_pct";
                if (! empty($data[$cityCol] ?? null)) {
                    $topLocations[] = [
                        'city' => trim($data[$cityCol]),
                        'percentage' => (float) ($data[$pctCol] ?? 0),
                    ];
                }
            }
            $topLocations = ! empty($topLocations) ? $topLocations : null;

            $updateData = [
                'follower_count' => (int) ($data['follower_count'] ?? 0),
            ];
            // Cuma timpa breakdown kalau ADA nilainya di CSV - biar nggak
            // ngosongin data lama pas CSV cuma isi follower_count doang
            if ($genderBreakdown !== null) $updateData['gender_breakdown'] = $genderBreakdown;
            if ($ageBreakdown !== null) $updateData['age_breakdown'] = $ageBreakdown;
            if ($topLocations !== null) $updateData['top_locations'] = $topLocations;

            // source=csv_import + demographic_type=generic - identity eksplisit
            // (bukan API punya banyak row/hari, CSV cuma 1) - baris ini TIDAK
            // PERNAH menimpa row source=instagram_api di tanggal yang sama
            // karena source ikut jadi bagian unique key.
            AudienceInsight::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'platform_id' => $platform->id,
                    'snapshot_date' => $snapshotDate->toDateString(),
                    'source' => AudienceInsight::SOURCE_CSV,
                    'demographic_type' => AudienceInsight::TYPE_GENERIC,
                ],
                $updateData
            );

            $successCount++;
            $importedSnapshotDates[] = $snapshotDate;
        }

        fclose($handle);

        $syncLog->update([
            'status' => empty($skippedRows) || $successCount > 0 ? 'success' : 'failed',
            'platform_id' => count($platformIdsUsed) === 1 ? array_key_first($platformIdsUsed) : null,
        ]);

        $message = "{$successCount} baris berhasil diimport.";
        if (! empty($skippedRows)) {
            $message .= ' '.count($skippedRows).' baris dilewati.';
        }

        // KPI Fase 4 (koreksi lanjutan #3) - audience insight diperbarui
        // (import CSV manual), jadwalkan kalkulasi ulang untuk SETIAP bulan
        // yang tercakup tanggal snapshot yang diimpor (CSV sering berisi
        // data historis - bukan cuma bulan berjalan).
        if ($successCount > 0) {
            $earliest = min($importedSnapshotDates);
            $latest = max($importedSnapshotDates);
            \App\Kpi\Services\KpiRecalculationTrigger::scheduleForDateRange($earliest, $latest);
        }

        return back()
            ->with('import_success', $message)
            ->with('import_skipped', $skippedRows);
    }
}