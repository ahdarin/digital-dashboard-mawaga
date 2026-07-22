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
        'brief_ready'    => 'Brief Ready',
        'in_progress'    => 'In Progress',
        'waiting_review' => 'Waiting Review',
        'revision'       => 'Revision',
        'approved'       => 'Approved',
        'scheduled'      => 'Scheduled',
        'uploaded'       => 'Uploaded',
        'cancelled'      => 'Cancelled',
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