<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $contentItem->title }} | 523 Studio</title>

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
<body class="min-h-screen" x-data="{ showRevisionForm: false }">

    <header class="bg-white border-b border-[#eef0f4] px-5 py-4 sticky top-0 z-10 flex items-center gap-3">
        <a href="{{ route('client.approval.index') }}" class="text-[#767c80] hover:text-[#14181a] transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
        </a>
        <div>
            <p class="text-xs text-[#767c80]">{{ $contentItem->contentType->name ?? '-' }}</p>
            <h1 class="font-display text-base font-semibold text-[#14181a]">{{ $contentItem->title }}</h1>
        </div>
    </header>

    <div class="p-5 space-y-4">

        @if (session('status'))
            <div class="bg-[#f0f5f4] border border-[#dbe6e4] text-[#044b46] text-sm p-3 rounded-2xl font-medium">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-[#fdf2f1] border border-[#f5d9d7] text-[#b3423e] text-sm p-3 rounded-2xl font-medium">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-2xl border border-[#eef0f4] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-xs font-semibold text-[#767c80] uppercase mb-2">Caption / Copy</p>
            <p class="text-sm text-[#14181a] whitespace-pre-line">{{ $contentItem->caption_draft ?: 'Belum ada draft caption.' }}</p>
        </div>

        <div class="bg-white rounded-2xl border border-[#eef0f4] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] grid grid-cols-2 gap-4 text-xs">
            <div>
                <p class="text-[#767c80] uppercase font-semibold mb-1">Platform</p>
                <p class="text-[#14181a]">{{ $contentItem->platform->name ?? '-' }}</p>
            </div>
            <div>
                <p class="text-[#767c80] uppercase font-semibold mb-1">Deadline</p>
                <p class="text-[#14181a]">{{ $contentItem->deadline_at->format('d M Y') }}</p>
            </div>
        </div>

        @if ($alreadyReviewed)
            <div class="bg-[#f0f5f4] border border-[#dbe6e4] text-[#044b46] text-sm p-4 rounded-2xl font-medium">
                Anda sudah menyetujui konten ini. Menunggu pengecekan akhir dari tim internal sebelum resmi ditandai Disetujui.
            </div>
        @else
            <div class="grid grid-cols-2 gap-3">
                <button x-on:click="showRevisionForm = true"
                        class="border border-[#eef0f4] text-[#14181a] text-sm font-medium py-3 rounded-2xl hover:bg-white transition-colors">
                    Request Revision
                </button>
                <form action="{{ route('client.approval.approve', $contentItem) }}" method="POST">
                    @csrf
                    <button type="submit" class="w-full bg-[#044b46] hover:bg-[#033b37] active:scale-[0.98] text-white text-sm font-medium py-3 rounded-2xl transition-all">
                        Approve Asset
                    </button>
                </form>
            </div>

            <div x-show="showRevisionForm" x-transition class="bg-white rounded-2xl border border-[#eef0f4] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]" style="display: none;">
                <form action="{{ route('client.approval.request-revision', $contentItem) }}" method="POST" class="space-y-3">
                    @csrf
                    <label for="revision_note" class="block text-xs font-semibold text-[#767c80] uppercase">Catatan Revisi</label>
                    <textarea id="revision_note" name="revision_note" required rows="3"
                              class="w-full border border-[#eef0f4] rounded-2xl px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[#044b46]/10 focus:border-[#044b46]/40 transition-all"
                              placeholder="Jelaskan perubahan yang diinginkan..."></textarea>
                    <button type="submit" class="w-full bg-[#044b46] hover:bg-[#033b37] active:scale-[0.98] text-white text-sm font-medium py-2.5 rounded-2xl transition-all">
                        Kirim Revisi
                    </button>
                </form>
            </div>
        @endif
    </div>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
