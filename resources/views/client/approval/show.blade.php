<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $contentItem->title }} | 523 Studio</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen" x-data="{ showRevisionForm: false }">

    <header class="bg-white border-b border-gray-100 px-5 py-4 sticky top-0 z-10 flex items-center gap-3">
        <a href="{{ route('client.approval.index') }}" class="text-gray-400">←</a>
        <div>
            <p class="text-xs text-gray-400">{{ $contentItem->contentType->name ?? '-' }}</p>
            <h1 class="text-sm font-bold text-gray-900">{{ $contentItem->title }}</h1>
        </div>
    </header>

    <div class="p-5 space-y-4">

        @if (session('status'))
            <div class="bg-teal-50 text-[#0d8276] text-sm p-3 rounded-xl">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-50 text-red-600 text-sm p-3 rounded-xl">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-xl border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-400 uppercase mb-2">Caption / Copy</p>
            <p class="text-sm text-gray-700 whitespace-pre-line">{{ $contentItem->caption_draft ?: 'Belum ada draft caption.' }}</p>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 p-4 grid grid-cols-2 gap-4 text-xs">
            <div>
                <p class="text-gray-400 uppercase font-semibold mb-1">Platform</p>
                <p class="text-gray-700">{{ $contentItem->platform->name ?? '-' }}</p>
            </div>
            <div>
                <p class="text-gray-400 uppercase font-semibold mb-1">Deadline</p>
                <p class="text-gray-700">{{ $contentItem->deadline_at->format('d M Y') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <button x-on:click="showRevisionForm = true"
                    class="border border-gray-200 text-gray-700 text-sm font-semibold py-3 rounded-xl">
                Request Revision
            </button>
            <form action="{{ route('client.approval.approve', $contentItem) }}" method="POST">
                @csrf
                <button type="submit" class="w-full bg-[#0d8276] text-white text-sm font-semibold py-3 rounded-xl">
                    Approve Asset
                </button>
            </form>
        </div>

        <div x-show="showRevisionForm" x-transition class="bg-white rounded-xl border border-gray-100 p-4" style="display: none;">
            <form action="{{ route('client.approval.request-revision', $contentItem) }}" method="POST" class="space-y-3">
                @csrf
                <label class="block text-xs font-semibold text-gray-500 uppercase">Catatan Revisi</label>
                <textarea name="revision_note" required rows="3"
                          class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[#0d8276]"
                          placeholder="Jelaskan perubahan yang diinginkan..."></textarea>
                <button type="submit" class="w-full bg-[#0d8276] text-white text-sm font-semibold py-2.5 rounded-xl">
                    Kirim Revisi
                </button>
            </form>
        </div>
    </div>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>