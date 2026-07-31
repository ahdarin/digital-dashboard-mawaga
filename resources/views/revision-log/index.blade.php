@extends('layouts.app')
@section('title', 'Revision Log')
@section('content')
<div class="p-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-[#191c1c]">Revision Log</h2>
            <p class="text-sm text-gray-500 mt-1">Daftar revisi dari seluruh content item</p>
        </div>
        <form method="GET" class="flex items-center gap-3">
            <select name="status" onchange="this.form.submit()"
                    class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]">
                <option value="open" {{ $selectedStatus === 'open' ? 'selected' : '' }}>Belum Selesai</option>
                <option value="resolved" {{ $selectedStatus === 'resolved' ? 'selected' : '' }}>Sudah Selesai</option>
                <option value="all" {{ $selectedStatus === 'all' ? 'selected' : '' }}>Semua</option>
            </select>
            <select name="client_id" onchange="this.form.submit()"
                    class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]">
                <option value="">Semua Client</option>
                @foreach ($clientOptions as $client)
                    <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>
                        {{ $client->name }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                    <th class="px-4 py-3">Content Item</th>
                    <th class="px-4 py-3">Client</th>
                    <th class="px-4 py-3">Ronde</th>
                    <th class="px-4 py-3">Catatan</th>
                    <th class="px-4 py-3">Diminta Oleh</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($revisions as $revision)
                    <tr class="border-t border-gray-100 hover:bg-gray-50 cursor-pointer"
                        onclick="window.location='{{ route('production-workflow.show', $revision->contentItem) }}'">
                        <td class="px-4 py-3 font-medium">{{ $revision->contentItem->title }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $revision->contentItem->client->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-500">Ronde {{ $revision->revision_round }}</td>
                        <td class="px-4 py-3 text-gray-600 max-w-xs truncate">{{ $revision->revision_note }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $revision->requestedBy->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs px-2 py-1 rounded-full {{ $revision->status === 'open' ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700' }}">
                                {{ $revision->status === 'open' ? 'Belum Selesai' : 'Selesai' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">Tidak ada revisi ditemukan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $revisions->links() }}
    </div>
</div>
@endsection