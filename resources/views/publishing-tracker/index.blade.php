@extends('layouts.app')
@section('title', 'Publishing Tracker')
@section('content')
<div class="p-8 max-w-[1400px]">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="font-display text-[28px] font-semibold text-[#14181a]">Publishing Tracker</h1>
            <p class="text-sm text-[#9aa0a4] mt-1">Riwayat konten yang telah dipublikasikan</p>
        </div>
        <form method="GET">
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
                    <th class="px-4 py-3 font-medium">Platform</th>
                    <th class="px-4 py-3 font-medium">Tanggal Publish</th>
                    <th class="px-4 py-3 font-medium">Diupload Oleh</th>
                    <th class="px-4 py-3 font-medium">Link</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($publications as $pub)
                    <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                        <td class="px-6 py-3.5 font-medium text-[#14181a]">
                            <a href="{{ route('production-workflow.show', $pub->contentItem) }}" class="hover:underline">{{ $pub->contentItem->title }}</a>
                        </td>
                        <td class="px-4 py-3.5 text-[#5c6266]">{{ $pub->contentItem->client->name ?? '-' }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266]">{{ $pub->platform->name ?? '-' }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266]">{{ $pub->published_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266]">{{ $pub->publishedBy->name ?? '-' }}</td>
                        <td class="px-4 py-3.5">
                            @if ($pub->post_url)
                                <a href="{{ $pub->post_url }}" target="_blank" class="text-[#044b46] hover:underline text-xs">Lihat Post →</a>
                            @else
                                <span class="text-[#c3c7cb] text-xs">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-10 text-center text-[#9aa0a4] text-sm">Belum ada konten yang dipublikasikan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-5">{{ $publications->links() }}</div>
</div>
@endsection