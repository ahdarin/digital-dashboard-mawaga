@extends('layouts.app')
@section('title', 'Publishing Tracker')
@section('content')
<div class="p-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-[#191c1c]">Publishing Tracker</h2>
            <p class="text-sm text-gray-500 mt-1">Riwayat konten yang telah dipublikasikan</p>
        </div>
        <form method="GET">
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
                    <th class="px-4 py-3">Platform</th>
                    <th class="px-4 py-3">Tanggal Publish</th>
                    <th class="px-4 py-3">Diupload Oleh</th>
                    <th class="px-4 py-3">Link</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($publications as $pub)
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium">
                            <a href="{{ route('production-workflow.show', $pub->contentItem) }}" class="hover:underline">
                                {{ $pub->contentItem->title }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $pub->contentItem->client->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $pub->platform->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $pub->published_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $pub->publishedBy->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if ($pub->post_url)
                                <a href="{{ $pub->post_url }}" target="_blank" class="text-[#044b46] hover:underline text-xs">Lihat Post →</a>
                            @else
                                <span class="text-gray-300 text-xs">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">Belum ada konten yang dipublikasikan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $publications->links() }}
    </div>
</div>
@endsection