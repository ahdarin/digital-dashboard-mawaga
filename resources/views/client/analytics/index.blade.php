<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics | 523 Studio</title>

    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..500,0..1&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f8fc; color: #14181a; }
        .font-display { font-family: 'Fraunces', serif; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal; }
    </style>
</head>
<body class="min-h-screen">

    <header class="bg-white border-b border-[#eef0f4] px-5 py-4 sticky top-0 z-10 flex items-center gap-2.5">
        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-7 w-auto">
        <div>
            <h1 class="font-display text-base font-semibold text-[#14181a] leading-tight">523 Studio</h1>
            <p class="text-xs text-[#9aa0a4] leading-tight">Analytics</p>
        </div>
    </header>

    <nav class="bg-white border-b border-[#eef0f4] px-5 flex items-center gap-1 sticky top-[65px] z-10">
        <a href="{{ route('client.approval.index') }}"
           class="text-sm font-medium px-3 py-3 border-b-2 border-transparent text-[#9aa0a4] hover:text-[#14181a] transition-colors">
            Approval
        </a>
        <a href="{{ route('client.analytics') }}"
           class="text-sm font-medium px-3 py-3 border-b-2 border-[#044b46] text-[#044b46] transition-colors">
            Analytics
        </a>
    </nav>

    <div class="p-5 space-y-4">

        <div class="flex items-center gap-1.5">
            @foreach ([7 => '7 Hari', 30 => '30 Hari', 90 => '90 Hari'] as $value => $label)
                <a href="{{ route('client.analytics', ['period' => $value]) }}"
                   class="text-xs font-medium px-3 py-1.5 rounded-full transition-colors
                       {{ $period === $value ? 'bg-[#044b46] text-white' : 'bg-white border border-[#eef0f4] text-[#5c6266] hover:bg-[#f7f8fc]' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-3">
            @foreach ($stats as $stat)
                @continue($stat['label'] === 'Platforms Tracked')
                <div class="bg-white rounded-xl border border-[#eef0f4] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] flex items-center justify-between">
                    <div>
                        <p class="text-xs text-[#9aa0a4] mb-1">{{ $stat['label'] }}</p>
                        <p class="font-display text-2xl font-semibold text-[#14181a]">{{ $stat['value'] }}</p>
                        <p class="text-[11px] mt-1
                            {{ $stat['trend'] === 'up' ? 'text-[#0f7a5f]' : ($stat['trend'] === 'down' ? 'text-[#b3423e]' : 'text-[#9aa0a4]') }}">
                            {{ $stat['change'] }}
                        </p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-[#f0f5f4] flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[#044b46] text-[19px]">{{ $stat['icon'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-xl border border-[#eef0f4] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-sm font-semibold text-[#14181a] mb-3">Views Trend</p>
            <x-trend-chart :trend="$trend" />
        </div>

        <div class="bg-white rounded-xl border border-[#eef0f4] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-sm font-semibold text-[#14181a] mb-3">Traffic per Platform</p>
            @if ($platformBreakdown->isEmpty())
                <p class="text-sm text-[#9aa0a4] text-center py-6">Belum ada data pada periode ini.</p>
            @else
                <div class="space-y-2.5">
                    @php $maxPlatformValue = max($platformBreakdown->max('value'), 1); @endphp
                    @foreach ($platformBreakdown as $row)
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-medium text-[#14181a]">{{ $row['label'] }}</span>
                                <span class="text-[#9aa0a4]">{{ number_format($row['value']) }} views</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-[#f0f2f5] overflow-hidden">
                                <div class="h-full bg-[#044b46] rounded-full" style="width: {{ max(($row['value'] / $maxPlatformValue) * 100, 3) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="bg-white rounded-xl border border-[#eef0f4] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-sm font-semibold text-[#14181a] mb-3">Top Performing Content</p>
            @if ($topContent->isEmpty())
                <p class="text-sm text-[#9aa0a4] text-center py-6">Belum ada data pada periode ini.</p>
            @else
                <div class="space-y-3">
                    @foreach ($topContent as $content)
                        <div class="flex items-center justify-between gap-3 {{ !$loop->last ? 'pb-3 border-b border-[#eef0f4]' : '' }}">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-[#14181a] truncate">{{ $content['title'] }}</p>
                                <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $content['type'] }} &middot; {{ $content['platform'] }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-sm font-semibold text-[#14181a]">{{ number_format($content['views']) }}</p>
                                <p class="text-xs text-[#9aa0a4]">{{ $content['engagement_rate'] }}% eng.</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
</body>
</html>
