<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>523 Studio | Approval Queue</title>

    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f8fc; color: #14181a; }
        .font-display { font-family: 'Fraunces', serif; }
    </style>
</head>
<body class="min-h-screen">

    <header class="bg-white border-b border-[#eef0f4] px-5 py-4 sticky top-0 z-10 flex items-center gap-2.5">
        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-7 w-auto">
        <div>
            <h1 class="font-display text-base font-semibold text-[#14181a] leading-tight">523 Studio</h1>
            <p class="text-xs text-[#9aa0a4] leading-tight">Approval Queue</p>
        </div>
    </header>

    <nav class="bg-white border-b border-[#eef0f4] px-5 flex items-center gap-1 sticky top-[65px] z-10">
        <a href="{{ route('client.approval.index') }}"
           class="text-sm font-medium px-3 py-3 border-b-2 border-[#044b46] text-[#044b46] transition-colors">
            Approval
        </a>
        <a href="{{ route('client.analytics') }}"
           class="text-sm font-medium px-3 py-3 border-b-2 border-transparent text-[#9aa0a4] hover:text-[#14181a] transition-colors">
            Analytics
        </a>
    </nav>

    <div class="p-5">
        @if (session('status'))
            <div class="bg-[#f0f5f4] border border-[#dbe6e4] text-[#044b46] text-sm p-3 rounded-xl mb-4 font-medium">{{ session('status') }}</div>
        @endif

        <p class="text-sm text-[#5c6266] mb-4">{{ $pendingItems->count() }} konten menunggu persetujuan Anda</p>

        <div class="space-y-3">
            @forelse ($pendingItems as $item)
                <a href="{{ route('client.approval.show', $item) }}"
                   class="block bg-white rounded-xl border border-[#eef0f4] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] hover:shadow-[0_4px_16px_-4px_rgba(20,24,26,0.08)] transition-shadow">
                    <div class="flex justify-between items-start mb-2">
                        <span class="text-[10px] font-bold text-[#b8873a] bg-[#fdf6ec] px-2 py-1 rounded uppercase">Menunggu Persetujuan</span>
                        <span class="text-[10px] text-[#9aa0a4]">Tenggat: {{ $item->deadline_at->format('d M') }}</span>
                    </div>
                    <p class="text-sm font-semibold text-[#14181a]">{{ $item->title }}</p>
                    <p class="text-xs text-[#9aa0a4] mt-1">{{ $item->contentType->name ?? '-' }} · {{ $item->platform->name ?? '-' }}</p>
                </a>
            @empty
                <div class="text-center py-16">
                    <span class="material-symbols-outlined text-[#d4d7db] text-[32px] mb-2 block">task_alt</span>
                    <p class="text-sm text-[#9aa0a4]">Tidak ada konten yang perlu ditinjau saat ini.</p>
                    <p class="text-xs text-[#c3c7cb] mt-1">Konten baru akan muncul di sini begitu tim selesai mengerjakannya.</p>
                </div>
            @endforelse
        </div>

        @if ($reviewedItems->isNotEmpty())
            <p class="text-xs font-semibold text-[#9aa0a4] uppercase mt-8 mb-3">Menunggu Pengecekan Tim ({{ $reviewedItems->count() }})</p>
            <div class="space-y-3">
                @foreach ($reviewedItems as $item)
                    <div class="bg-white rounded-xl border border-[#eef0f4] p-4 opacity-70">
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-[10px] font-bold text-[#0f7a5f] bg-[#f0f5f4] px-2 py-1 rounded uppercase">Sudah Anda Setujui</span>
                            <span class="text-[10px] text-[#9aa0a4]">Tenggat: {{ $item->deadline_at->format('d M') }}</span>
                        </div>
                        <p class="text-sm font-semibold text-[#14181a]">{{ $item->title }}</p>
                        <p class="text-xs text-[#9aa0a4] mt-1">{{ $item->contentType->name ?? '-' }} · {{ $item->platform->name ?? '-' }} · Menunggu pengecekan tim internal</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
