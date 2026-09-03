<?php

namespace App\Enums;

/**
 * Status kecukupan data untuk SATU angka/skor - dipakai di seluruh KPI
 * engine (Fase 2) & tampilan (Fase 4). Aturan keras: missing metric TIDAK
 * PERNAH jadi 0, partial TIDAK PERNAH diam-diam disebut full, dan skor
 * dengan coverage tidak mencukupi TIDAK PERNAH memengaruhi KPI final.
 */
enum CoverageStatus: string
{
    case Full = 'full';
    case Partial = 'partial';
    case Provisional = 'provisional';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Lengkap',
            self::Partial => 'Sebagian',
            self::Provisional => 'Sementara',
            self::Unavailable => 'Belum Tersedia',
        };
    }

    /**
     * Urutan "seberapa bisa dipercaya" - dipakai untuk menggabungkan
     * beberapa coverage status jadi satu (composite selalu ikut yang PALING
     * lemah di antara komponen penyusunnya, tidak pernah dibulatkan ke atas).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Full => 3,
            self::Partial => 2,
            self::Provisional => 1,
            self::Unavailable => 0,
        };
    }

    public static function weakest(self ...$statuses): self
    {
        return collect($statuses)->sortBy(fn (self $s) => $s->rank())->first() ?? self::Unavailable;
    }
}
