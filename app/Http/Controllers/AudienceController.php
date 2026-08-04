<?php

namespace App\Http\Controllers;

use App\Models\AudienceInsight;
use App\Models\AnalyticsSyncLog;
use App\Models\Client;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * NOTE: method index() yang dulu di sini SUDAH DIPINDAH jadi tab "Audience"
 * di AnalyticsController@index (bagian dari penyederhanaan: Overview,
 * Table, Audience sekarang 1 halaman/1 client-selector, bukan 3 halaman
 * terpisah). Controller ini sekarang cuma nyisa importCsv() - dipanggil
 * dari form yang ada di dalam tab Audience.
 */
class AudienceController extends Controller
{
    public function importCsv(Request $request)
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $client = Client::findOrFail($validated['client_id']);

        $syncLog = AnalyticsSyncLog::create([
            'client_id' => $client->id,
            'imported_by' => auth()->id(),
            'source_type' => 'csv_import',
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
            $platform = Platform::firstOrCreate(['name' => $platformName]);
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
            if ($genderBreakdown !== null) $updateData['gender_breakdown'] = $genderBreakdown;
            if ($ageBreakdown !== null) $updateData['age_breakdown'] = $ageBreakdown;
            if ($topLocations !== null) $updateData['top_locations'] = $topLocations;

            AudienceInsight::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'platform_id' => $platform->id,
                    'snapshot_date' => $snapshotDate->toDateString(),
                ],
                $updateData
            );

            $successCount++;
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

        return back()
            ->with('import_success', $message)
            ->with('import_skipped', $skippedRows);
    }
}