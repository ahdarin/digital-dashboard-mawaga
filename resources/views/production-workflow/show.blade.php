{{-- resources/views/production-workflow/show.blade.php --}}
@extends('layouts.app')
@section('title', $contentItem->title)

@section('content')
@php
    $workflow = $contentItem->workflow;
    $statusLabels = \App\Support\WorkflowTransitions::labels();
    $openRevisions = $contentItem->revisions->where('status', 'open');
@endphp

<div class="p-8 max-w-6xl">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('production-workflow.index') }}" class="text-gray-400 hover:text-gray-600">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <p class="text-xs text-gray-400">{{ $contentItem->client->name ?? '-' }} / Item #{{ $contentItem->id }}</p>
                <h2 class="text-xl font-bold text-[#191c1c]">{{ $contentItem->title }}</h2>
            </div>
        </div>
        <span class="text-xs font-semibold px-3 py-1.5 rounded-full bg-[#044b46]/10 text-[#044b46]">
            {{ $statusLabels[$workflow->current_status] ?? $workflow->current_status }}
        </span>
    </div>

    @if (session('status'))
        <div class="bg-teal-50 text-[#044b46] text-sm p-3 rounded-lg mb-6">{{ session('status') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Kolom Kiri: Brief & Publikasi --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Creative Brief --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-bold text-gray-700 mb-3">Creative Brief</h3>
                <p class="text-sm text-gray-600 whitespace-pre-line mb-4">{{ $contentItem->brief ?: 'Belum ada brief.' }}</p>
                <div class="grid grid-cols-2 gap-4 text-xs">
                    <div>
                        <p class="text-gray-400 uppercase font-semibold mb-1">Platform</p>
                        <p class="text-gray-700">{{ $contentItem->platform->name ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-400 uppercase font-semibold mb-1">Deadline</p>
                        <p class="text-gray-700">{{ $contentItem->deadline_at->format('d M Y, H:i') }}</p>
                    </div>
                </div>
            </div>

            {{-- Revision Thread --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-bold text-gray-700 mb-4">Revision Log ({{ $contentItem->revisions->count() }})</h3>

                <div class="space-y-3 mb-4">
                    @forelse ($contentItem->revisions as $revision)
                        <div class="border border-gray-100 rounded-lg p-3 {{ $revision->status === 'open' ? 'bg-orange-50' : 'bg-gray-50' }}">
                            <div class="flex items-center justify-between mb-1">
                                <p class="text-xs font-semibold text-gray-700">
                                    Ronde {{ $revision->revision_round }} — {{ $revision->requestedBy->name ?? '-' }}
                                </p>
                                <span class="text-[10px] px-2 py-0.5 rounded-full {{ $revision->status === 'open' ? 'bg-orange-200 text-orange-800' : 'bg-green-100 text-green-700' }}">
                                    {{ $revision->status }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-600">{{ $revision->revision_note }}</p>

                            @if ($revision->status === 'open')
                                <form action="{{ route('content-revision.resolve', [$contentItem, $revision]) }}" method="POST" class="mt-2">
                                    @csrf @method('PATCH')
                                    <button class="text-[10px] font-semibold text-[#044b46] hover:underline">Tandai Selesai</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 italic">Belum ada revisi.</p>
                    @endforelse
                </div>

                <form action="{{ route('content-revision.store', $contentItem) }}" method="POST" class="flex gap-2">
                    @csrf
                    <input type="text" name="revision_note" required placeholder="Tulis catatan revisi..."
                           class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]">
                    <button type="submit" class="bg-[#044b46] text-white text-xs font-semibold px-4 py-2 rounded-lg hover:bg-[#044b46]/90">
                        Kirim
                    </button>
                </form>
            </div>

            {{-- Publishing Form (hanya tampil kalau belum posted) --}}
            @if (!$contentItem->is_posted)
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 class="text-sm font-bold text-gray-700 mb-4">Catat Publikasi</h3>
                    <form action="{{ route('content-publication.store', $contentItem) }}" method="POST" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-gray-500 uppercase mb-1">Platform</label>
                                <select name="platform_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]">
                                    <option value="{{ $contentItem->platform_id }}">{{ $contentItem->platform->name ?? '-' }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-gray-500 uppercase mb-1">Tanggal Publish</label>
                                <input type="datetime-local" name="published_at" required
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-gray-500 uppercase mb-1">Link Post</label>
                            <input type="url" name="post_url" placeholder="https://..."
                                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-gray-500 uppercase mb-1">Caption Final</label>
                            <textarea name="caption_final" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]"></textarea>
                        </div>
                        <button type="submit" class="bg-[#044b46] text-white text-xs font-semibold px-4 py-2 rounded-lg hover:bg-[#044b46]/90">
                            Simpan & Tandai Uploaded
                        </button>
                    </form>
                </div>
            @else
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 class="text-sm font-bold text-gray-700 mb-3">Publikasi</h3>
                    @foreach ($contentItem->publications as $pub)
                        <div class="text-xs text-gray-600 border-b border-gray-100 py-2 last:border-0">
                            <p class="font-semibold">{{ $pub->platform->name }} — {{ $pub->published_at->format('d M Y, H:i') }}</p>
                            @if ($pub->post_url)
                                <a href="{{ $pub->post_url }}" target="_blank" class="text-[#044b46] underline">{{ $pub->post_url }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Kolom Kanan: PIC & Status History --}}
        <div class="space-y-6">

            {{-- PIC --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-bold text-gray-700 mb-3">PIC Penugasan</h3>
                <div class="space-y-3">
                    @forelse ($contentItem->assignments as $assignment)
                        <div class="flex items-center gap-2">
                            @if ($assignment->user->avatar_url)
                                <img src="{{ $assignment->user->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                            @else
                                <div class="w-8 h-8 rounded-full bg-[#044b46] text-white text-xs font-bold flex items-center justify-center">
                                    {{ strtoupper(substr($assignment->user->name, 0, 1)) }}
                                </div>
                            @endif
                            <div>
                                <p class="text-xs font-semibold text-gray-800">{{ $assignment->user->name }}</p>
                                <p class="text-[10px] text-gray-400">{{ ucwords(str_replace('_', ' ', $assignment->assignment_role)) }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 italic">Belum ada PIC.</p>
                    @endforelse
                </div>
            </div>

            {{-- Status History --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-bold text-gray-700 mb-3">Status History</h3>
                <div class="space-y-3">
                    @forelse ($contentItem->statusLogs->sortByDesc('changed_at') as $log)
                        <div class="flex gap-3">
                            <div class="w-2 h-2 rounded-full bg-[#044b46] mt-1.5 flex-shrink-0"></div>
                            <div>
                                <p class="text-xs text-gray-700">
                                    <span class="font-semibold">{{ $log->from_status ? ($statusLabels[$log->from_status] ?? $log->from_status) : 'Dibuat' }}</span>
                                    →
                                    <span class="font-semibold">{{ $statusLabels[$log->to_status] ?? $log->to_status }}</span>
                                </p>
                                <p class="text-[10px] text-gray-400">
                                    {{ $log->changedBy->name ?? '-' }} · {{ $log->changed_at->format('d M, H:i') }}
                                </p>
                                @if ($log->notes)
                                    <p class="text-[10px] text-gray-500 italic mt-0.5">{{ $log->notes }}</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 italic">Belum ada histori.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection