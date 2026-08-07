@extends('layouts.app')
@section('title', 'Publishing Tracker')
@section('content')
    <div x-data="{ search: '', matches(...fields) { if (!this.search) return true; const s = this.search.toLowerCase(); return fields.some(f => f.toLowerCase().includes(s)); } }"
        class="p-8 max-w-[1400px]">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="font-display text-[28px] font-semibold text-[#14181a]">Publishing Tracker</h1>
                <p class="text-sm text-[#9aa0a4] mt-1">Riwayat konten yang telah dipublikasikan</p>
            </div>
            <div class="flex items-center gap-3">
                <select name="platform_id" form="filter-client-form" onchange="this.form.submit()"
                    class="border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                    <option value="">Semua Platform</option>
                    @foreach ($platformOptions as $platform)
                        <option value="{{ $platform->id }}" {{ (string) $selectedPlatformId === (string) $platform->id ? 'selected' : '' }}>{{ $platform->name }}</option>
                    @endforeach
                </select>
                <select name="client_id" form="filter-client-form" onchange="this.form.submit()"
                    class="border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                    <option value="">Semua Client</option>
                    @foreach ($clientOptions as $client)
                        <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
                    @endforeach
                </select>
                <form id="filter-client-form" method="GET" action="{{ route('publishing-tracker.index') }}"></form>

                <div class="relative">
                    <span
                        class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[19px]">search</span>
                    <input x-model="search"
                        class="pl-10 pr-4 h-[40px] bg-white border border-[#eef0f4] rounded-lg text-sm focus:outline-none focus:border-[#044b46]/40 w-64"
                        placeholder="Cari konten..." type="text">
                </div>
            </div>
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
                        <tr x-show="matches('{{ addslashes($pub->contentItem->title) }}', '{{ addslashes($pub->contentItem->client->name ?? '') }}')"
                            class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                            <td class="px-6 py-3.5 font-medium text-[#14181a]">
                                <a href="{{ route('content-items.show', $pub->contentItem) }}"
                                    class="hover:underline">{{ $pub->contentItem->title }}</a>
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $pub->contentItem->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $pub->platform->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $pub->published_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $pub->publishedBy->name ?? '-' }}</td>
                            <td class="px-4 py-3.5">
                                @if ($pub->post_url)
                                    <a href="{{ $pub->post_url }}" target="_blank"
                                        class="inline-flex items-center gap-1 bg-[#044b46] text-white text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-[#033b37] transition-colors">
                                        <span class="material-symbols-outlined text-[13px]">open_in_new</span> Lihat Post
                                    </a>
                                @else
                                    <span class="text-[#c3c7cb] text-xs">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-[#9aa0a4] text-sm">Belum ada konten yang
                                dipublikasikan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5">{{ $publications->links() }}</div>
    </div>
@endsection