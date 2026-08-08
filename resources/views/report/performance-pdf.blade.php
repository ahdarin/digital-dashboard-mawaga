<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #191c1c; }
        h1 { font-size: 18px; margin-bottom: 4px; color: #044b46; }
        p.subtitle { color: #666; margin-top: 0; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 11px; }
        th { background: #f2f4f2; }
        .section-title { font-size: 13px; font-weight: bold; margin-top: 24px; margin-bottom: 8px; color: #044b46; }
        .badge { padding: 2px 6px; border-radius: 4px; font-size: 10px; background: #e6f2f0; color: #044b46; }
    </style>
</head>
<body>
    <h1>Content Performance Report</h1>
    <p class="subtitle">{{ $client_name }} &middot; {{ $period_start }} - {{ $period_end }}</p>

    <table style="margin-bottom: 10px;">
        <tr>
            <th>Total Views</th>
            <th>Avg. Engagement Rate</th>
            <th>Content Count</th>
            <th>Platforms Tracked</th>
        </tr>
        <tr>
            <td>{{ number_format($total_views) }}</td>
            <td>{{ $avg_engagement }}%</td>
            <td>{{ $content_count }}</td>
            <td>{{ $platform_count }}</td>
        </tr>
    </table>

    <div class="section-title">Top Performing Content</div>
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Platform</th>
                <th>Content Type</th>
                <th>Views</th>
                <th>Engagement Rate</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($top_content as $c)
                <tr>
                    <td>{{ $c['title'] }}</td>
                    <td>{{ $c['platform'] }}</td>
                    <td>{{ $c['type'] }}</td>
                    <td>{{ number_format($c['views']) }}</td>
                    <td><span class="badge">{{ $c['engagement_rate'] }}%</span></td>
                </tr>
            @empty
                <tr><td colspan="5">Belum ada data performa pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">Breakdown per Platform</div>
    <table>
        <thead>
            <tr>
                <th>Platform</th>
                <th>Total Views</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($platform_breakdown as $p)
                <tr>
                    <td>{{ $p['label'] }}</td>
                    <td>{{ number_format($p['value']) }}</td>
                </tr>
            @empty
                <tr><td colspan="2">Belum ada data.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>