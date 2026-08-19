<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    @include('partials._theme-init-script')
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $contentItem->title }} | 523 Studio</title>

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
<body class="min-h-screen" x-data="{ showRevisionForm: false }">

    <header class="bg-[var(--surface-card)] border-b border-[var(--border)] px-5 py-4 sticky top-0 z-10 flex items-center gap-3">
        <a href="{{ route('client.approval.index') }}" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
        </a>
        <div>
            <p class="text-xs text-[var(--text-muted)]">{{ $contentItem->contentType->name ?? '-' }}</p>
            <h1 class="font-display text-base font-semibold text-[var(--text-primary)]">{{ $contentItem->title }}</h1>
        </div>
    </header>

    <div class="p-5 space-y-4">

        @if (session('status'))
            <div class="bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] text-[var(--brand)] text-sm p-3 rounded-2xl font-medium">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-[var(--danger-tint)] border border-[var(--danger-border)] text-[var(--danger-text)] text-sm p-3 rounded-2xl font-medium">{{ $errors->first() }}</div>
        @endif

        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-2">Caption / Copy</p>
            <p class="text-sm text-[var(--text-primary)] whitespace-pre-line">{{ $contentItem->caption_draft ?: 'Belum ada draft caption.' }}</p>
        </div>

        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] grid grid-cols-2 gap-4 text-xs">
            <div>
                <p class="text-[var(--text-muted)] uppercase font-semibold mb-1">Platform</p>
                <p class="text-[var(--text-primary)]">{{ $contentItem->platform->name ?? '-' }}</p>
            </div>
            <div>
                <p class="text-[var(--text-muted)] uppercase font-semibold mb-1">Deadline</p>
                <p class="text-[var(--text-primary)]">{{ $contentItem->deadline_at->format('d M Y') }}</p>
            </div>
        </div>

        @if ($alreadyReviewed)
            <div class="bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] text-[var(--brand)] text-sm p-4 rounded-2xl font-medium">
                Anda sudah menyetujui konten ini. Menunggu pengecekan akhir dari tim internal sebelum resmi ditandai Disetujui.
            </div>
        @else
            <div class="grid grid-cols-2 gap-3">
                <button x-on:click="showRevisionForm = true"
                        class="border border-[var(--border)] text-[var(--text-primary)] text-sm font-medium py-3 rounded-2xl hover:bg-[var(--surface-card)] transition-colors">
                    Request Revision
                </button>
                <form action="{{ route('client.approval.approve', $contentItem) }}" method="POST">
                    @csrf
                    <button type="submit" class="w-full bg-[var(--brand)] hover:bg-[var(--brand-dark)] active:scale-[0.98] text-white text-sm font-medium py-3 rounded-2xl transition-all">
                        Approve Asset
                    </button>
                </form>
            </div>

            <div x-show="showRevisionForm" x-transition class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]" style="display: none;">
                <form action="{{ route('client.approval.request-revision', $contentItem) }}" method="POST" class="space-y-3">
                    @csrf
                    <label for="revision_note" class="block text-xs font-semibold text-[var(--text-muted)] uppercase">Catatan Revisi</label>
                    <textarea id="revision_note" name="revision_note" required rows="3"
                              class="w-full border border-[var(--border)] rounded-2xl px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[#044b46]/10 focus:border-[#044b46]/40 transition-all"
                              placeholder="Jelaskan perubahan yang diinginkan..."></textarea>
                    <button type="submit" class="w-full bg-[var(--brand)] hover:bg-[var(--brand-dark)] active:scale-[0.98] text-white text-sm font-medium py-2.5 rounded-2xl transition-all">
                        Kirim Revisi
                    </button>
                </form>
            </div>
        @endif
    </div>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
