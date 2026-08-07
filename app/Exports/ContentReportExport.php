<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use App\Support\WorkflowTransitions;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ContentReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $reportData) {}

    public function array(): array
    {
        $rows = [];

        // Baris ringkasan di atas
        $rows[] = [
            'RINGKASAN',
            $this->reportData['client_name'],
            $this->reportData['period_start'] . ' - ' . $this->reportData['period_end'],
            '', '',
        ];
        $rows[] = ['Total Item', 'Selesai', 'Overdue', 'Revisi Aktif', 'Total Revisi'];
        $rows[] = [
            $this->reportData['total'],
            $this->reportData['done'],
            $this->reportData['overdue'],
            $this->reportData['in_revision'],
            $this->reportData['total_revisions'],
        ];
        $rows[] = ['', '', '', '', '']; // spacer

        foreach ($this->reportData['items'] as $item) {
            $rows[] = [
                $item->title,
                $item->client->name ?? '-',
                $item->workflow ? WorkflowTransitions::label($item->workflow->current_status) : '-',
                $item->deadline_at->format('d M Y'),
                $item->revisions->count(),
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Judul', 'Client', 'Status', 'Deadline', 'Jumlah Revisi'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['bold' => true]],
        ];
    }
}