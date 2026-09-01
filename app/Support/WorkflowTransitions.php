<?php

namespace App\Support;

class WorkflowTransitions
{
    // Status yang dianggap "selesai" - konten di status ini tidak lagi
    // dihitung sebagai pekerjaan aktif maupun bisa ditandai overdue.
    // approved/scheduled SENGAJA tidak termasuk - kalau deadline-nya lewat
    // sebelum benar-benar tayang, itu tetap dianggap terlambat.
    public const DONE_STATUSES = ['uploaded', 'cancelled'];

    // Item yang di-generate otomatis dari kuota paket klien tapi belum
    // dikirim ke produksi oleh SMO - belum masuk workflow produksi sama
    // sekali (tidak muncul di kanban, tidak bisa dipindah manual). Satu-
    // satunya jalan keluar adalah WorkflowStatusService::releaseToProduction()
    // (batch per Content Plan), bukan transition() generik per item.
    public const DRAFT_STATUS = 'draft';

    // Status yang TIDAK dihitung sebagai beban kerja/produksi aktif - baik
    // karena sudah selesai (DONE_STATUSES) maupun karena belum masuk
    // workflow produksi sama sekali (draft). Dipakai di semua tempat yang
    // menghitung "pekerjaan aktif" (beban kerja PIC, dashboard, KPI tim,
    // Next Steps, overdue) supaya slot draft yang masih kosong tidak ikut
    // terhitung. DONE_STATUSES sendiri tetap dipakai apa adanya di tempat
    // yang murni menanyakan "sudah selesai atau belum" (mis. PinService).
    public const INACTIVE_STATUSES = ['draft', 'uploaded', 'cancelled'];

    private static array $allowed = [
        'draft'          => ['brief_ready'],
        'brief_ready'    => ['in_progress', 'cancelled'],
        'in_progress'    => ['waiting_review', 'cancelled'],
        'waiting_review' => ['approved', 'revision', 'cancelled'],
        'revision'       => ['in_progress', 'cancelled'],
        'approved'       => ['scheduled', 'cancelled'],
        'scheduled'      => ['uploaded', 'cancelled'],
        'uploaded'       => [],
        'cancelled'      => [],
    ];

    private static array $labels = [
        'draft'          => 'Draf',
        'brief_ready'    => 'Brief Ready',
        'in_progress'    => 'Sedang Dikerjakan',
        'waiting_review' => 'Menunggu Persetujuan',
        'revision'       => 'Perlu Revisi',
        'approved'       => 'Disetujui',
        'scheduled'      => 'Terjadwal Tayang',
        'uploaded'       => 'Sudah Tayang',
        'cancelled'      => 'Dibatalkan',
    ];

    public static function isValid(string $from, string $to): bool
    {
        if ($from === $to) return false;
        return in_array($to, self::$allowed[$from] ?? []);
    }

    public static function nextOptions(string $from): array
    {
        return self::$allowed[$from] ?? [];
    }

    public static function label(string $status): string
    {
        return self::$labels[$status] ?? $status;
    }

    public static function labels(): array
    {
        return self::$labels;
    }
}