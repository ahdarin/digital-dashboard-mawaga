@extends('layouts.app')
@section('title', 'Revision Log')
@section('content')
<div class="p-8 max-w-[1400px]">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="font-display text-[28px] font-semibold text-[#14181a]">Revision Log</h1>
            <p class="text-sm text-[#9aa0a4] mt-1">Daftar revisi dari seluruh content item</p>
        </div>
        <form method="GET" class="flex items-center gap-2.5">
            <select name="status" onchange="this.form.submit()"
                    class="border border-[#eef0f4] rounded-lg px-3.5 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                <option value="open" {{ $selectedStatus === 'open' ? 'selected' : '' }}>Belum Selesai</option>
                <option value="resolved" {{ $selectedStatus === 'resolved' ? 'selected' : '' }}>Sudah Selesai</option>
                <option value="all" {{ $selectedStatus === 'all' ? 'selected' : '' }}>Semua</option>
            </select>
            <select name="client_id" onchange="this.form.submit()"
                    class="border border-[#eef0f4] rounded-lg px-3.5 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                <option value="">Semua Client</option>
                @foreach ($clientOptions as $client)
                    <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium">Content Item</th>
                    <th class="px-4 py-3 font-medium">Client</th>
                    <th class="px-4 py-3 font-medium">Ronde</th>
                    <th class="px-4 py-3 font-medium">Catatan</th>
                    <th class="px-4 py-3 font-medium">Diminta Oleh</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($revisions as $revision)
                    <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] cursor-pointer transition-colors"
                        onclick="window.location='{{ route('production-workflow.show', $revision->contentItem) }}'">
                        <td class="px-6 py-3.5 font-medium text-[#14181a]">{{ $revision->contentItem->title }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266]">{{ $revision->contentItem->client->name ?? '-' }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266]">Ronde {{ $revision->revision_round }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266] max-w-xs truncate">{{ $revision->revision_note }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266]">{{ $revision->requestedBy->name ?? '-' }}</td>
                        <td class="px-4 py-3.5">
                            <span class="text-xs px-2 py-1 rounded-full {{ $revision->status === 'open' ? 'bg-[#fdf6ec] text-[#8a6423]' : 'bg-[#f0f5f4] text-[#0f7a5f]' }}">
                                {{ $revision->status === 'open' ? 'Belum Selesai' : 'Selesai' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-10 text-center text-[#9aa0a4] text-sm">Tidak ada revisi ditemukan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-5">{{ $revisions->links() }}</div>
</div>
@endsection