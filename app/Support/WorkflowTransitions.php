<?php

namespace App\Support;

class WorkflowTransitions
{
    private static array $allowed = [
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
        'brief_ready'    => 'Siap Dikerjakan',
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