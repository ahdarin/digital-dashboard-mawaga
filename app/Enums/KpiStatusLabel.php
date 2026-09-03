<?php

namespace App\Enums;

/**
 * Label status tampil untuk KPI individu - TIDAK PERNAH angka mentah 0
 * ditampilkan untuk data yang belum cukup. Ditentukan KpiCoverageService
 * dari kombinasi sample size + coverage status, bukan dari composite_score
 * semata.
 */
enum KpiStatusLabel: string
{
    case Sehat = 'sehat';
    case PerluPerhatian = 'perlu_perhatian';
    case Sementara = 'sementara';
    case DataBelumCukup = 'data_belum_cukup';

    public function label(): string
    {
        return match ($this) {
            self::Sehat => 'Sehat',
            self::PerluPerhatian => 'Perlu Perhatian',
            self::Sementara => 'Sementara',
            self::DataBelumCukup => 'Data Belum Cukup',
        };
    }
}
