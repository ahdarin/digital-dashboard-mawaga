<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #191c1c; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        p.subtitle { color: #666; margin-top: 0; margin-bottom: 20px; }
        .summary { display: flex; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 11px; }
        th { background: #f2f4f2; }
        .badge { padding: 2px 6px; border-radius: 4px; font-size: 10px; }
    </style>
</head>
<body>
    <h1>Laporan Progres Operasional</h1>
    <p class="subtitle">{{ $client_name }} · {{ $period_start }} - {{ $period_end }}</p>

    <table style="margin-bottom: 20px;">
        <tr>
            <th>Total Item</th>
            <th>Selesai</th>
            <th>Overdue</th>
            <th>Revisi Aktif</th>
            <th>Total Revisi</th>
        </tr>
        <tr>
            <td>{{ $total }}</td>
            <td>{{ $done }}</td>
            <td>{{ $overdue }}</td>
            <td>{{ $in_revision }}</td>
            <td>{{ $total_revisions }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Judul</th>
                <th>Client</th>
                <th>Status</th>
                <th>Deadline</th>
                <th>Jumlah Revisi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item->title }}</td>
                    <td>{{ $item->client->name ?? '-' }}</td>
                    <td>{{ $item->workflow->current_status ?? '-' }}</td>
                    <td>{{ $item->deadline_at->format('d M Y') }}</td>
                    <td>{{ $item->revisions->count() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>