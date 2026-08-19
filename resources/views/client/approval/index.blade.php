<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    @include('partials._theme-init-script')
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>523 Studio | Approval Queue</title>

    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        @include('partials._theme-tokens')
        body { font-family: 'Inter', sans-serif; background-color: var(--surface-page); color: var(--text-primary); }
        .font-display { font-family: 'Fraunces', serif; }
    </style>
</head>
<body class="min-h-screen">

    <header class="bg-[var(--surface-card)] border-b border-[var(--border)] px-5 py-4 sticky top-0 z-10 flex items-center gap-2.5">
        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-7 w-auto">
        <div>
            <h1 class="font-display text-base font-semibold text-[var(--text-primary)] leading-tight">523 Studio</h1>
            <p class="text-xs text-[var(--text-muted)] leading-tight">Approval Queue</p>
        </div>
    </header>

    <nav class="bg-[var(--surface-card)] border-b border-[var(--border)] px-5 flex items-center gap-1 sticky top-[65px] z-10">
        <a href="{{ route('client.approval.index') }}"
           class="text-sm font-medium px-3 py-3 border-b-2 border-[var(--brand)] text-[var(--brand)] transition-colors">
            Approval
        </a>
        <a href="{{ route('client.analytics') }}"
           class="text-sm font-medium px-3 py-3 border-b-2 border-transparent text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors">
            Analytics
        </a>
    </nav>

    <div class="p-5">
        @if (session('status'))
            <div class="bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] text-[var(--brand)] text-sm p-3 rounded-2xl mb-4 font-medium">{{ session('status') }}</div>
        @endif

        <p class="text-sm text-[var(--text-secondary)] mb-4">{{ $pendingItems->count() }} konten menunggu persetujuan Anda</p>

        <div class="space-y-3">
            @forelse ($pendingItems as $item)
                <a href="{{ route('client.approval.show', $item) }}"
                   class="block bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] hover:shadow-[0_4px_16px_-4px_rgba(20,24,26,0.08)] transition-shadow">
                    <div class="flex justify-between items-start mb-2">
                        <span class="text-[10px] font-bold text-[var(--warning-text)] bg-[var(--warning-tint)] px-2 py-1 rounded uppercase">Menunggu Persetujuan</span>
                        <span class="text-[10px] text-[var(--text-muted)]">Tenggat: {{ $item->deadline_at->format('d M') }}</span>
                    </div>
                    <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $item->title }}</p>
                    <p class="text-xs text-[var(--text-muted)] mt-1">{{ $item->contentType->name ?? '-' }} · {{ $item->platform->name ?? '-' }}</p>
                </a>
            @empty
                <div class="text-center py-16">
                    <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[32px] mb-2 block">task_alt</span>
                    <p class="text-sm text-[var(--text-muted)]">Tidak ada konten yang perlu ditinjau saat ini.</p>
                    <p class="text-xs text-[var(--text-muted)] mt-1">Konten baru akan muncul di sini begitu tim selesai mengerjakannya.</p>
                </div>
            @endforelse
        </div>

        @if ($reviewedItems->isNotEmpty())
            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mt-8 mb-3">Menunggu Pengecekan Tim ({{ $reviewedItems->count() }})</p>
            <div class="space-y-3">
                @foreach ($reviewedItems as $item)
                    <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 opacity-70">
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-[10px] font-bold text-[var(--success-text)] bg-[var(--success-tint)] px-2 py-1 rounded uppercase">Sudah Anda Setujui</span>
                            <span class="text-[10px] text-[var(--text-muted)]">Tenggat: {{ $item->deadline_at->format('d M') }}</span>
                        </div>
                        <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $item->title }}</p>
                        <p class="text-xs text-[var(--text-muted)] mt-1">{{ $item->contentType->name ?? '-' }} · {{ $item->platform->name ?? '-' }} · Menunggu pengecekan tim internal</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
