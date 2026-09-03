<?php

namespace App\Models;

use App\Kpi\Formula\KpiFormulaConfig;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Bobot & parameter KPI ter-versi - lihat docblock migration
 * `create_kpi_formula_versions_table`. `config` dibaca lewat
 * App\Kpi\Formula\KpiFormulaConfig (Fase 2), TIDAK PERNAH diakses mentah
 * (array offset) dari service manapun di luar situ.
 */
class KpiFormulaVersion extends Model
{
    use HasFactory;

    protected $fillable = ['version', 'config', 'effective_from', 'notes', 'created_by'];

    protected $casts = [
        'config' => 'array',
        'effective_from' => 'date',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function calculationRuns(): HasMany
    {
        return $this->hasMany(KpiCalculationRun::class);
    }

    /**
     * Version stabil (BUKAN bersuffix tanggal seperti versi lama) - versi
     * lama (`'default-'.now()->format('Ymd')`) berarti tiap hari yang belum
     * pernah punya formula version eksplisit akan diam-diam membuat baris
     * "default" BARU LAGI (isinya identik, tapi baris menumpuk tanpa
     * batas) - konstanta ini membuat bootstrap idempotent selamanya, bukan
     * per-hari.
     */
    private const DEFAULT_VERSION = 'default';

    /**
     * Sengaja BUKAN `now()` - versi default harus tetap berlaku untuk
     * backfill/kalkulasi PERIODE HISTORIS mana pun (mis. menghitung ulang
     * Juni 2026 di bulan September), bukan cuma bulan saat bootstrap
     * pertama terjadi. Tanggal sentinel jauh di masa lalu (bukan diturunkan
     * dari data lokal - database lokal akan dihapus, jadi TIDAK dipakai
     * sebagai acuan desain).
     */
    private const DEFAULT_EFFECTIVE_FROM = '2000-01-01';

    /**
     * Formula version yang berlaku pada tanggal $asOf - TIDAK PERNAH
     * mensyaratkan seeder/command manual dijalankan lebih dulu (keputusan
     * produk: "jangan meminta user/administrator menjalankan kalkulasi
     * manual"). Kalau belum ada satu pun baris yang berlaku, bootstrap
     * otomatis dengan bobot default (`KpiFormulaConfig::default()`) -
     * transparan bagi pengguna, tidak ada langkah setup yang terlihat.
     *
     * Concurrency-safe: `firstOrCreate` keyed ke `version` (kolom unique) -
     * kalau dua job/command pertama race dan sama-sama gagal menemukan
     * baris existing, salah satu INSERT akan kena unique constraint
     * violation (bukan silent duplicate) - ditangkap lalu dibaca ulang
     * baris yang barusan dibuat proses lain, bukan dilempar sebagai error.
     */
    public static function resolveCurrent(?Carbon $asOf = null): self
    {
        $asOf ??= now();

        $existing = static::where('effective_from', '<=', $asOf->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return static::firstOrCreate(
                ['version' => self::DEFAULT_VERSION],
                [
                    'config' => KpiFormulaConfig::default()->toArray(),
                    'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
                    'notes' => 'Dibuat otomatis saat kalkulasi KPI pertama kali berjalan - belum ada formula version tersimpan.',
                ]
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // Race: proses lain barusan berhasil insert versi default yang
            // sama persis di antara SELECT dan INSERT kita - ambil punya
            // mereka, bukan dianggap error.
            return static::where('version', self::DEFAULT_VERSION)->firstOrFail();
        }
    }
}
