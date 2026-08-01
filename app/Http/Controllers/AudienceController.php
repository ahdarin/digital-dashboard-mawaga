<?php

namespace App\Http\Controllers;

use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\Platform;
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
    public function index(Request $request)
    {
        $clientOptions = Client::where('status', 'active')->get();
        $selectedClientId = $request->input('client_id');

        if (! $selectedClientId) {
            return view('audience.index', [
                'noClientSelected' => true,
                'clientOptions' => $clientOptions,
                'selectedClientId' => null,
            ]);
        }

        $client = Client::findOrFail($selectedClientId);

        $platforms = Platform::whereHas('audienceInsights', fn ($q) => $q->where('client_id', $client->id))->get();

        // Kalau client belum punya insight sama sekali di platform manapun
        if ($platforms->isEmpty()) {
            return view('audience.index', [
                'noInsightData' => true,
                'clientOptions' => $clientOptions,
                'selectedClientId' => $selectedClientId,
                'client' => $client,
            ]);
        }

        $selectedPlatformId = $request->input('platform_id', $platforms->first()->id);
        $platform = $platforms->firstWhere('id', (int) $selectedPlatformId) ?? $platforms->first();

        $period = (int) $request->input('period', 30);
        $period = in_array($period, [7, 30, 90]) ? $period : 30;

        // Snapshot terbaru -> buat gender/usia/lokasi/jam aktif (data breakdown
        // sifatnya "kondisi saat ini", bukan historis harian)
        $latestSnapshot = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->latest('snapshot_date')
            ->first();

        // Histori followers -> buat trend chart growth
        $start = Carbon::now()->subDays($period - 1)->startOfDay();
        $history = AudienceInsight::where('client_id', $client->id)
            ->where('platform_id', $platform->id)
            ->where('snapshot_date', '>=', $start)
            ->orderBy('snapshot_date')
            ->get();

        $followerTrend = $history->map(fn ($row) => [
            'label' => Carbon::parse($row->snapshot_date)->translatedFormat('d M'),
            'value' => (int) $row->follower_count,
        ])->values();

        $firstCount = $history->first()->follower_count ?? 0;
        $lastCount = $history->last()->follower_count ?? ($latestSnapshot->follower_count ?? 0);
        $growth = $firstCount > 0 ? round((($lastCount - $firstCount) / $firstCount) * 100, 1) : null;

        $genderBreakdown = $latestSnapshot->gender_breakdown ?? [];
        $ageBreakdown = $latestSnapshot->age_breakdown ?? [];
        $topLocations = collect($latestSnapshot->top_locations ?? [])->sortByDesc('percentage')->values();

        // Jam aktif: key jam (0-23) -> value, disiapkan buat bar chart 24 kolom
        $activeHoursRaw = $latestSnapshot->active_hours ?? [];
        $activeHours = collect(range(0, 23))->map(function ($hour) use ($activeHoursRaw) {
            return [
                'label' => str_pad($hour, 2, '0', STR_PAD_LEFT).':00',
                'value' => (int) ($activeHoursRaw[$hour] ?? $activeHoursRaw[(string) $hour] ?? 0),
            ];
        });
        $peakHour = $activeHours->sortByDesc('value')->first();

        return view('audience.index', compact(
            'client', 'clientOptions', 'selectedClientId',
            'platforms', 'platform', 'selectedPlatformId', 'period',
            'latestSnapshot', 'followerTrend', 'growth', 'lastCount',
            'genderBreakdown', 'ageBreakdown', 'topLocations',
            'activeHours', 'peakHour'
        ));
    }

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
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $client = Client::findOrFail($validated['client_id']);

        $syncLog = \App\Models\AnalyticsSyncLog::create([
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
            // Cuma timpa breakdown kalau ADA nilainya di CSV - biar nggak
            // ngosongin data lama pas CSV cuma isi follower_count doang
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