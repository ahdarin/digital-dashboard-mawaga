<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PerformanceReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $reportData) {}

    public function array(): array
    {
        $rows = [];

        // Baris ringkasan di atas
        $rows[] = [
            'RINGKASAN PERFORMA',
            $this->reportData['client_name'],
            $this->reportData['period_start'].' - '.$this->reportData['period_end'],
            '',
        ];
        $rows[] = ['Total Views', 'Avg. Engagement Rate', 'Jumlah Konten', 'Platform Terlacak'];
        $rows[] = [
            $this->reportData['total_views'],
            $this->reportData['avg_engagement'].'%',
            $this->reportData['content_count'],
            $this->reportData['platform_count'],
        ];
        $rows[] = ['', '', '', '']; // spacer

        $rows[] = ['TOP PERFORMING CONTENT'];
        $rows[] = ['Judul', 'Platform', 'Jenis Produksi', 'Format Konten', 'Views', 'Engagement Rate'];
        foreach ($this->reportData['top_content'] as $c) {
            $rows[] = [$c['title'], $c['platform'], $c['production_type'], $c['content_format'], $c['views'], $c['engagement_rate'].'%'];
        }

        $rows[] = ['', '', '', '']; // spacer

        $rows[] = ['BREAKDOWN PER PLATFORM'];
        $rows[] = ['Platform', 'Total Views'];
        foreach ($this->reportData['platform_breakdown'] as $p) {
            $rows[] = [$p['label'], $p['value']];
        }

        return $rows;
    }

    public function headings(): array
    {
        return []; // heading manual sudah ditulis di baris pertama array()
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['bold' => true]],
            6 => ['font' => ['bold' => true]],
            7 => ['font' => ['bold' => true]],
        ];
    }
}