<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>523 Studio | Approval Queue</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

    <header class="bg-white border-b border-gray-100 px-5 py-4 sticky top-0 z-10">
        <h1 class="text-lg font-bold text-gray-900">523 Studio</h1>
        <p class="text-xs text-gray-400">Approval Queue</p>
    </header>

    <div class="p-5">
        @if (session('status'))
            <div class="bg-teal-50 text-[#0d8276] text-sm p-3 rounded-xl mb-4">{{ session('status') }}</div>
        @endif

        <p class="text-sm text-gray-500 mb-4">{{ $items->count() }} konten menunggu persetujuan Anda</p>

        <div class="space-y-3">
            @forelse ($items as $item)
                <a href="{{ route('client.approval.show', $item) }}"
                   class="block bg-white rounded-xl border border-gray-100 p-4 hover:shadow-sm transition-shadow">
                    <div class="flex justify-between items-start mb-2">
                        <span class="text-[10px] font-bold text-orange-600 bg-orange-50 px-2 py-1 rounded uppercase">Waiting Review</span>
                        <span class="text-[10px] text-gray-400">Due: {{ $item->deadline_at->format('d M') }}</span>
                    </div>
                    <p class="text-sm font-bold text-gray-900">{{ $item->title }}</p>
                    <p class="text-xs text-gray-400 mt-1">{{ $item->contentType->name ?? '-' }} · {{ $item->platform->name ?? '-' }}</p>
                </a>
            @empty
                <div class="text-center py-16">
                    <p class="text-sm text-gray-400">Tidak ada konten yang perlu ditinjau saat ini.</p>
                </div>
            @endforelse
        </div>
    </div>
</body>
</html>