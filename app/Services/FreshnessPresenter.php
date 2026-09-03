<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * SYSTEM CONSISTENCY PASS (Part AN, "GLOBAL FRESHNESS") - SATU kosakata
 * "kapan data ini terakhir disegarkan dari provider" dipakai KONSISTEN di
 * semua halaman yang menampilkan state analytics/integrasi (Content
 * Detail, kartu integrasi, dst) - bukan timestamp mentah atau wording
 * beda-beda tiap halaman. Mirror pola AvailabilityPresenter (murni
 * presentation layer, BUKAN kalkulasi baru) dan window.AnalyticsSyncPanel.
 * formatFreshness() (versi JS-nya, dipakai sync panel Settings/Analytics
 * yang genuinely live-polling) - kelas ini versi SERVER-RENDERED, dipakai
 * di halaman yang tidak butuh polling JS, cukup timestamp yang sudah ada
 * saat render.
 */
final class FreshnessPresenter
{
    /**
     * null kalau belum pernah ada observasi sama sekali - caller wajib
     * tampilkan state "belum pernah disinkronkan" sendiri, method ini
     * TIDAK menebak.
     */
    public static function label(?Carbon $observedAt): ?string
    {
        if (! $observedAt) {
            return null;
        }

        if ($observedAt->isToday()) {
            return 'Data diperbarui hari ini, '.$observedAt->format('H:i');
        }

        if ($observedAt->isYesterday()) {
            return 'Data diperbarui kemarin, '.$observedAt->format('H:i');
        }

        return 'Data terakhir diperbarui '.$observedAt->translatedFormat('d M Y');
    }
}
