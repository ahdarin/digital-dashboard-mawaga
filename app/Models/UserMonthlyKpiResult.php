<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserMonthlyKpiResult extends Model
{
    use HasFactory;

    // Kode status internal - dipetakan ke label Bahasa Indonesia lewat
    // statusLabel(), JANGAN ditampilkan mentah ke view.
    public const STATUS_SEMENTARA = 'sementara';
    public const STATUS_SANGAT_BAIK = 'sangat_baik';
    public const STATUS_BAIK = 'baik';
    public const STATUS_PERLU_PERHATIAN = 'perlu_perhatian';
    public const STATUS_PERLU_EVALUASI = 'perlu_evaluasi';

    protected $fillable = [
        'user_id', 'period_start',
        'timeliness_score', 'quality_score', 'analytics_bonus', 'analytics_available',
        'final_score', 'sample_size', 'status', 'breakdown', 'calculated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'timeliness_score' => 'float',
        'quality_score' => 'float',
        'analytics_bonus' => 'float',
        'final_score' => 'float',
        'analytics_available' => 'boolean',
        'breakdown' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Data cukup = minimal 3 content DAN minimal 3 di antaranya punya data
     * waktu yang bisa dinilai (lihat TeamPerformanceKpiCalculator) - satu-
     * satunya syarat Nilai KPI ditampilkan sebagai skor bernilai (bukan
     * "Data sementara").
     */
    public function isSufficient(): bool
    {
        return $this->status !== self::STATUS_SEMENTARA;
    }

    public function statusLabel(): string
    {
        return self::labelForStatus($this->status);
    }

    public static function labelForStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_SEMENTARA => 'Data sementara',
            self::STATUS_SANGAT_BAIK => 'Sangat baik',
            self::STATUS_BAIK => 'Baik',
            self::STATUS_PERLU_PERHATIAN => 'Perlu perhatian',
            self::STATUS_PERLU_EVALUASI => 'Perlu evaluasi',
            default => $status,
        };
    }

    public static function statusFromScore(float $score): string
    {
        return match (true) {
            $score >= 80 => self::STATUS_SANGAT_BAIK,
            $score >= 70 => self::STATUS_BAIK,
            $score >= 60 => self::STATUS_PERLU_PERHATIAN,
            default => self::STATUS_PERLU_EVALUASI,
        };
    }

    /**
     * Label kualitatif dari Nilai KPI SAJA - beda dari statusLabel() yang
     * ikut mempertimbangkan kecukupan sampel ("Data sementara"). Tampilan
     * (tabel Daftar Anggota, ringkasan profil) SELALU pakai ini supaya
     * tidak pernah menyebut istilah "cukup/tidak cukup" yang ambigu -
     * jumlah content tetap terlihat transparan lewat kolom Konten sendiri.
     */
    public function scoreLabel(): string
    {
        return self::labelForStatus(self::statusFromScore($this->final_score));
    }
}
